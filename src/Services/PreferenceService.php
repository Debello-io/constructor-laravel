<?php

namespace ConstructorIO\Laravel\Services;

use ConstructorIO\Laravel\Exceptions\ApiException;
use ConstructorIO\Laravel\Exceptions\ConnectionException;
use ConstructorIO\Laravel\Exceptions\InvalidArgumentException;
use ConstructorIO\Laravel\Http\ApiClient;
use ConstructorIO\Laravel\Support\ConstructorConfig;
use ConstructorIO\Laravel\Support\RequestContext;

/**
 * Shopper preferences (the User profile API): semi-permanent facts a shopper
 * has told you, such as loyalty tier, preferred fuel type or "cooks for a
 * family of six", so Constructor can personalize results and segment users.
 *
 *     // The signed-in shopper, e.g. after a preferences form:
 *     Constructor::preferences()->setForCurrentUser('fuel_type', ['pellet', 'charcoal']);
 *
 *     // A batch from a back-office job:
 *     Constructor::preferences()->setPreferences([
 *         ['user_id' => $hashedId, 'preference_key' => 'loyalty_tier', 'preference_values' => ['gold']],
 *     ]);
 *
 * Preferences are keyed by `ui`, the signed-in user ID your storefront
 * requests send. They must match exactly, so if you register
 * Constructor::resolveUserIdUsing() (for example to hash IDs), send
 * preferences with that same value. setForCurrentUser() does this for you.
 *
 * For temporary, per-request facts (campaign source, location), use the
 * `user_segments` option on search and browse calls instead.
 *
 * This is personal data. Only send preferences the shopper has agreed you
 * may use for personalization; setForCurrentUser() sends nothing without
 * consent (see Constructor::resolveConsentUsing()). Values are never logged.
 * The API documents no way to read or delete preferences: ask Constructor
 * how to handle a deletion request.
 *
 * Private endpoint: it only accepts Bearer authentication with a dashboard
 * API token that has the `user_profile.user_preferences(w)` scope
 * (CONSTRUCTOR_USER_PROFILE_TOKEN, or CONSTRUCTOR_API_TOKEN). Writes throw on
 * failure and are never retried.
 *
 * @see https://docs.constructor.com/reference/user-profile-user-preferences
 * @see https://docs.constructor.com/reference/v1-user-profile-create-preferences
 */
class PreferenceService
{
    /** Constructor accepts at most this many preferences in one JSON request. */
    public const MAX_PREFERENCES_PER_REQUEST = 100;

    private const PATH = '/v1/user_preferences';

    protected readonly ConstructorConfig $config;

    public function __construct(
        protected readonly ApiClient $api,
        protected readonly RequestContext $context,
    ) {
        $this->config = $api->config();
    }

    /**
     * Save a preference for the signed-in shopper of the current request.
     *
     * Uses the same `ui` the storefront requests send (auth()->id(), or your
     * resolveUserIdUsing() resolver).
     *
     * @param  string|list<string>  $values  One or more values for this preference.
     * @return bool False, without sending anything, when nobody is signed in
     *              or the shopper hasn't consented to personalization.
     *
     * @throws InvalidArgumentException|ApiException|ConnectionException
     */
    public function setForCurrentUser(string $preferenceKey, string|array $values): bool
    {
        $userId = $this->context->userId();

        if ($userId === null || ! $this->context->hasPersonalizationConsent()) {
            return false;
        }

        $this->setUserPreference($userId, $preferenceKey, $values);

        return true;
    }

    /**
     * Save one preference for one user.
     *
     * @param  string|int  $userId  The `ui` your storefront sends for this user.
     * @param  string|list<string>  $values
     *
     * @throws InvalidArgumentException|ApiException|ConnectionException
     */
    public function setUserPreference(string|int $userId, string $preferenceKey, string|array $values): void
    {
        $this->setPreferences([[
            'user_id' => $userId,
            'preference_key' => $preferenceKey,
            'preference_values' => (array) $values,
        ]]);
    }

    /**
     * Save many preferences, sent in batches of 100.
     *
     * Each preference:
     *   user_id            string|int  The user's `ui` (or pass `ui`)
     *   preference_key     string      The preference, e.g. "fuel_type"
     *   preference_values  list        One or more values (or pass `values`)
     *
     * Every preference is checked before anything is sent. If a batch fails,
     * the batches before it have already been saved.
     *
     * @param  list<array<string, mixed>>  $preferences
     * @return int The number of preferences sent.
     *
     * @throws InvalidArgumentException If a preference is missing a field.
     * @throws ApiException|ConnectionException
     */
    public function setPreferences(array $preferences): int
    {
        $normalized = [];
        foreach ($preferences as $preference) {
            $normalized[] = $this->normalize($preference, count($normalized));
        }

        foreach (array_chunk($normalized, self::MAX_PREFERENCES_PER_REQUEST) as $batch) {
            $this->api->userProfile('PUT', self::PATH, ['user_preferences' => $batch]);
        }

        return count($normalized);
    }

    /**
     * Upload a JSON Lines file of preferences, for backfills and full imports.
     * One preference per line, in the API's own format:
     *
     *     {"preference_key": "fuel_type", "preference_values": ["pellet"], "ui": "101"}
     *
     * Gzipped files (.jsonl.gz) work too. There is no size limit, unlike
     * setPreferences(). Run it from a queued job or command.
     *
     * @throws InvalidArgumentException If the file can't be read.
     * @throws ApiException|ConnectionException
     */
    public function uploadPreferencesFile(string $path): void
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("The preferences file \"{$path}\" doesn't exist or can't be read.");
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException("The preferences file \"{$path}\" can't be opened.");
        }

        try {
            $this->api->uploadToUserProfile('PUT', self::PATH, ['file' => [$handle, basename($path)]], $this->config->uploadTimeout);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * Check one preference and convert it to the API's field names.
     *
     * @return array{ui: string, preference_key: string, preference_values: list<string>}
     *
     * @throws InvalidArgumentException
     */
    private function normalize(mixed $preference, int $position): array
    {
        if (! is_array($preference)) {
            throw new InvalidArgumentException("Preference #{$position} must be an array.");
        }

        $userId = $preference['ui'] ?? $preference['user_id'] ?? null;
        $key = $preference['preference_key'] ?? null;
        $values = $preference['preference_values'] ?? $preference['values'] ?? null;

        if (! is_scalar($userId) || trim((string) $userId) === '') {
            throw new InvalidArgumentException("Preference #{$position} needs a user_id (the `ui` your storefront sends).");
        }

        if (! is_string($key) || trim($key) === '') {
            throw new InvalidArgumentException("Preference #{$position} needs a preference_key.");
        }

        $values = array_values(array_map(
            fn (mixed $value) => trim((string) $value),
            array_filter((array) $values, fn (mixed $value) => is_scalar($value) && trim((string) $value) !== ''),
        ));

        if ($values === []) {
            throw new InvalidArgumentException("Preference \"{$key}\" (#{$position}) needs at least one value.");
        }

        return [
            'ui' => trim((string) $userId),
            'preference_key' => trim($key),
            'preference_values' => $values,
        ];
    }
}
