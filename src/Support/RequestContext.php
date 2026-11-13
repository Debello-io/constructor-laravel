<?php

namespace ConstructorIO\Laravel\Support;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Throwable;

/**
 * Describes the shopper a server-side request is being made for.
 *
 * In a backend integration your server calls Constructor on behalf of a
 * shopper's browser. Constructor still needs to know who that shopper is, so
 * every request must forward:
 *
 *   Headers      User-Agent, X-Forwarded-For, x-cnstrc-token
 *   Query string i (client ID), s (session number), ui (signed-in user ID),
 *                c (client identifier), _dt (timestamp), origin_referrer
 *
 * The `i` and `s` values must match the ones the Constructor beacon uses in
 * the browser, so they are read from the beacon's cookies. If they don't
 * match, one shopper is recorded as two and personalization suffers.
 *
 * @see https://docs.constructor.com/docs/integrating-with-constructor-direct-api-integration-required-parameters
 * @see https://docs.constructor.com/docs/integrating-with-constructor-retrieving-results-integration-approaches-backend-integration-options
 */
class RequestContext
{
    /** Cookie the beacon stores the anonymous client ID (`i`) in. */
    public const CLIENT_ID_COOKIE = 'ConstructorioID_client_id';

    /** Cookie the beacon stores the session number (`s`) in. */
    public const SESSION_ID_COOKIE = 'ConstructorioID_session_id';

    /** How long a generated client ID cookie lives, in minutes (one year). */
    private const CLIENT_ID_COOKIE_LIFETIME = 60 * 24 * 365;

    /** Request attribute used to remember a generated client ID for the rest of the request. */
    private const GENERATED_CLIENT_ID_ATTRIBUTE = 'constructor.generated_client_id';

    /** @var (Closure(Request|null): (string|int|null))|null */
    private static ?Closure $userIdResolver = null;

    /** @var (Closure(Request|null): bool)|null */
    private static ?Closure $consentResolver = null;

    public function __construct(
        private readonly Container $container,
        private readonly ConstructorConfig $config,
    ) {}

    /**
     * Decide which ID is sent as `ui` for signed-in shoppers.
     *
     * Return null for guests. Constructor recommends an anonymized identifier,
     * so you may want to hash your internal user ID:
     *
     *     Constructor::resolveUserIdUsing(fn (?Request $request) => $request?->user()
     *         ? hash_hmac('sha256', (string) $request->user()->getAuthIdentifier(), config('app.key'))
     *         : null
     *     );
     *
     * Register it once in a service provider's boot(). Resolvers are static,
     * so on Octane one registered per request would be reused for every
     * shopper the worker serves afterwards.
     *
     * @param  (Closure(Request|null): (string|int|null))|null  $resolver
     */
    public static function resolveUserIdUsing(?Closure $resolver): void
    {
        self::$userIdResolver = $resolver;
    }

    /**
     * Decide whether the shopper has consented to personalization.
     *
     * When the resolver returns false, the client ID (`i`) is left out and no
     * client ID cookie is created, as Constructor's documentation requires.
     *
     *     Constructor::resolveConsentUsing(fn (?Request $request) =>
     *         $request?->cookie('cookie_consent') === 'all'
     *     );
     *
     * @param  (Closure(Request|null): bool)|null  $resolver
     */
    public static function resolveConsentUsing(?Closure $resolver): void
    {
        self::$consentResolver = $resolver;
    }

    /**
     * Query parameters that identify the shopper and the integration.
     *
     * Values in $overrides win. Use them when the identifiers don't come from
     * this HTTP request, e.g. a mobile app that sends its SDK's client and
     * session IDs to your API: ['client_id' => ..., 'session_id' => ..., 'user_id' => ...].
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, string|int>
     */
    public function queryParameters(array $overrides = []): array
    {
        $parameters = [
            'c' => $this->config->clientIdentifier,
            'i' => $overrides['client_id'] ?? $this->clientId(),
            's' => $this->normalizeSessionNumber($overrides['session_id'] ?? null) ?? $this->sessionNumber(),
            'ui' => isset($overrides['user_id']) ? (string) $overrides['user_id'] : $this->userId(),
            '_dt' => (int) floor(microtime(true) * 1000),
            'origin_referrer' => $overrides['origin_referrer'] ?? $this->referrer(),
        ];

        return array_filter($parameters, fn ($value) => $value !== null && $value !== '');
    }

    /**
     * HTTP headers required for a backend integration.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = [
            'User-Agent' => $this->userAgent(),
            'X-Forwarded-For' => $this->ipAddress(),
            // Only ever the dedicated backend security token. Never fall back
            // to the catalog API token: they are different credentials.
            'x-cnstrc-token' => $this->config->backendToken,
        ];

        return array_filter($headers, fn ($value) => $value !== null && $value !== '');
    }

    /**
     * The anonymous client ID (`i`), or null without consent.
     *
     * Read from the beacon's cookie. On a first visit, before the beacon has
     * run, a UUID is generated and set as the cookie so the beacon adopts it.
     */
    public function clientId(): ?string
    {
        $request = $this->request();

        if (! $this->hasConsent($request)) {
            return null;
        }

        $fromCookie = $this->readBeaconCookie(self::CLIENT_ID_COOKIE);
        if ($fromCookie !== null) {
            return $fromCookie;
        }

        if ($request === null || ! $this->config->generateClientId) {
            return null;
        }

        return $this->generateClientId($request);
    }

    /**
     * The session number (`s`).
     *
     * The beacon owns this value. Until its cookie exists, Constructor's
     * documentation says to send 1.
     */
    public function sessionNumber(): ?int
    {
        if ($this->request() === null) {
            return null;
        }

        return $this->normalizeSessionNumber($this->readBeaconCookie(self::SESSION_ID_COOKIE)) ?? 1;
    }

    /**
     * Your user ID (`ui`), only for signed-in shoppers.
     */
    public function userId(): ?string
    {
        $request = $this->request();

        if (self::$userIdResolver !== null) {
            $userId = (self::$userIdResolver)($request);

            return $userId === null ? null : (string) $userId;
        }

        if (! $this->config->sendUserId || ! $this->container->bound('auth')) {
            return null;
        }

        try {
            $userId = $this->container->make('auth')->id();
        } catch (Throwable) {
            // No auth guard can be resolved in this context (e.g. a queue worker).
            return null;
        }

        return $userId === null ? null : (string) $userId;
    }

    /**
     * The shopper's IP address. A single address, never a comma-separated chain.
     *
     * Uses Laravel's Request::ip(), which honours your TrustProxies
     * middleware. Behind a load balancer or CDN, configure trusted proxies
     * or every request will appear to come from the proxy.
     */
    public function ipAddress(): ?string
    {
        return $this->request()?->ip();
    }

    /**
     * The shopper's browser User-Agent, forwarded unchanged.
     */
    public function userAgent(): ?string
    {
        return $this->request()?->userAgent();
    }

    /**
     * The page the shopper is on (`origin_referrer`).
     *
     * Constructor wants the URL of the page that triggered the request. For a
     * server-rendered page that is the current URL. For an AJAX or fetch call
     * (e.g. autocomplete as the shopper types) it is the page that made the
     * call, which the browser sends as the Referer header.
     */
    public function referrer(): ?string
    {
        $request = $this->request();

        if ($request === null) {
            return null;
        }

        if ($request->ajax() || $request->expectsJson() || $request->prefetch()) {
            return $request->headers->get('referer');
        }

        return $request->fullUrl();
    }

    /**
     * The current HTTP request, or null outside one (console, queue).
     */
    protected function request(): ?Request
    {
        if (! $this->container->bound('request')) {
            return null;
        }

        $request = $this->container->make('request');

        // Artisan commands and queue workers get a placeholder request
        // (127.0.0.1, "Symfony" user agent) that must not be forwarded as a
        // shopper. Under php-fpm we are never in the console. Long-running
        // servers such as Octane run from the console, so there we accept a
        // request once the router has matched it to a route.
        $isConsole = method_exists($this->container, 'runningInConsole') && $this->container->runningInConsole();

        if ($isConsole && $request->route() === null) {
            return null;
        }

        return $request;
    }

    /**
     * Read a cookie the beacon set, straight from the Cookie header.
     *
     * The beacon's cookies are plain text. Laravel's EncryptCookies middleware
     * would fail to decrypt them and hand us null, so we parse the raw header
     * instead. Reading from the request (not $_COOKIE) keeps this correct on
     * long-running servers such as Octane.
     */
    protected function readBeaconCookie(string $name): ?string
    {
        $header = $this->request()?->headers->get('cookie');

        if ($header === null || $header === '') {
            return null;
        }

        foreach (explode(';', $header) as $pair) {
            [$cookieName, $value] = array_pad(explode('=', trim($pair), 2), 2, '');

            if ($cookieName === $name && $value !== '') {
                return urldecode($value);
            }
        }

        return null;
    }

    /**
     * Create a client ID and queue it as the beacon's cookie.
     *
     * The value is remembered on the request so several Constructor calls in
     * one page view share the same ID.
     */
    private function generateClientId(Request $request): string
    {
        $existing = $request->attributes->get(self::GENERATED_CLIENT_ID_ATTRIBUTE);
        if (is_string($existing)) {
            return $existing;
        }

        $clientId = (string) Str::uuid();
        $request->attributes->set(self::GENERATED_CLIENT_ID_ATTRIBUTE, $clientId);

        if ($this->container->bound('cookie')) {
            // httpOnly must be false: the beacon's JavaScript has to read it.
            Cookie::queue(Cookie::make(
                name: self::CLIENT_ID_COOKIE,
                value: $clientId,
                minutes: self::CLIENT_ID_COOKIE_LIFETIME,
                httpOnly: false,
                sameSite: 'lax',
            ));
        }

        return $clientId;
    }

    private function hasConsent(?Request $request): bool
    {
        return self::$consentResolver === null || (bool) (self::$consentResolver)($request);
    }

    /**
     * `s` must be a positive integer. Ignore anything else, such as a Laravel
     * session ID passed by mistake.
     */
    private function normalizeSessionNumber(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }
}
