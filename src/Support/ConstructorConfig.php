<?php

namespace ConstructorIO\Laravel\Support;

use ConstructorIO\Laravel\Exceptions\ConfigurationException;
use Illuminate\Support\Str;

/**
 * Typed, validated view of config/constructor.php.
 *
 * Reading config through this object (instead of calling config() all over
 * the package) gives one place that casts .env strings to the right types
 * and explains what is missing when a value is required.
 */
final class ConstructorConfig
{
    /** More attempts than this would keep a shopper's page waiting too long. */
    private const MAX_RETRY_ATTEMPTS = 5;

    /** Constructor's limit for one /v2/items write. */
    private const MAX_ITEMS_PER_REQUEST = 10000;

    public function __construct(
        public readonly ?string $apiKey,
        public readonly ?string $apiToken,
        public readonly ?string $backendToken,
        public readonly string $clientIdentifier,
        public readonly string $searchBaseUrl,
        public readonly string $agentBaseUrl,
        public readonly string $catalogBatchingBaseUrl,
        public readonly string $defaultSection,
        public readonly bool $generateClientId,
        public readonly bool $sendUserId,
        public readonly bool $requireConsent,
        public readonly int $timeout,
        public readonly int $streamTimeout,
        public readonly int $retryAttempts,
        public readonly int $retryDelay,
        public readonly bool $throwExceptions,
        public readonly ?string $logChannel,
        public readonly ?string $agentDomain,
        public readonly bool $agentGuard,
        public readonly int $agentNumResultEvents,
        public readonly int $agentNumResultsPerEvent,
        public readonly ?string $notificationEmail,
        public readonly bool $forceUpload,
        public readonly int $uploadTimeout,
        public readonly int $taskPollInterval,
        public readonly int $taskTimeout,
        public readonly int $catalogBatchSize,
        public readonly string $scoutSyncMode,
        public readonly string $quizzesBaseUrl = 'https://quizzes.cnstrc.com',
        public readonly string $imageSearchBaseUrl = 'https://image-search.cnstrc.com',
    ) {}

    /**
     * Build from the array in config/constructor.php.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config, string $appName = 'laravel'): self
    {
        return new self(
            apiKey: self::stringOrNull($config['api_key'] ?? null),
            apiToken: self::stringOrNull($config['api_token'] ?? null),
            backendToken: self::stringOrNull($config['backend_token'] ?? null),
            clientIdentifier: self::stringOrNull($config['client_identifier'] ?? null)
                ?? 'cio-be-web-'.(Str::slug($appName) ?: 'laravel'),
            searchBaseUrl: self::secureUrl('search_base_url', $config['search_base_url'] ?? 'https://ac.cnstrc.com'),
            agentBaseUrl: self::secureUrl('agent_base_url', $config['agent_base_url'] ?? 'https://agent.cnstrc.com'),
            catalogBatchingBaseUrl: self::secureUrl(
                'catalog_batching_base_url',
                $config['catalog_batching_base_url'] ?? 'https://batching.catalog.cnstrc.com',
            ),
            defaultSection: self::stringOrNull($config['default_section'] ?? null) ?? 'Products',
            generateClientId: (bool) ($config['identity']['generate_client_id'] ?? true),
            sendUserId: (bool) ($config['identity']['send_user_id'] ?? true),
            requireConsent: (bool) ($config['identity']['require_consent'] ?? false),
            timeout: max(1, (int) ($config['timeout'] ?? 10)),
            streamTimeout: max(1, (int) ($config['stream_timeout'] ?? 120)),
            retryAttempts: min(self::MAX_RETRY_ATTEMPTS, max(1, (int) ($config['retry_attempts'] ?? 2))),
            retryDelay: max(0, (int) ($config['retry_delay'] ?? 100)),
            throwExceptions: (bool) ($config['throw_exceptions'] ?? false),
            logChannel: self::stringOrNull($config['log_channel'] ?? null),
            agentDomain: self::stringOrNull($config['agent']['domain'] ?? null),
            agentGuard: (bool) ($config['agent']['guard'] ?? true),
            agentNumResultEvents: (int) ($config['agent']['num_result_events'] ?? 5),
            agentNumResultsPerEvent: (int) ($config['agent']['num_results_per_event'] ?? 4),
            notificationEmail: self::stringOrNull($config['catalog']['notification_email'] ?? null),
            forceUpload: (bool) ($config['catalog']['force'] ?? false),
            uploadTimeout: max(1, (int) ($config['catalog']['upload_timeout'] ?? 300)),
            taskPollInterval: max(1, (int) ($config['catalog']['task_poll_interval'] ?? 10)),
            taskTimeout: max(1, (int) ($config['catalog']['task_timeout'] ?? 600)),
            // `scout.batch_size` was the 1.x/2.0-beta name; it applies to every item write.
            catalogBatchSize: min(self::MAX_ITEMS_PER_REQUEST, max(1, (int) (
                $config['catalog']['batch_size'] ?? $config['scout']['batch_size'] ?? 1000
            ))),
            scoutSyncMode: ($config['scout']['sync_mode'] ?? 'replace') === 'patch' ? 'patch' : 'replace',
            quizzesBaseUrl: self::secureUrl('quizzes_base_url', $config['quizzes_base_url'] ?? 'https://quizzes.cnstrc.com'),
            imageSearchBaseUrl: self::secureUrl(
                'image_search_base_url',
                $config['image_search_base_url'] ?? 'https://image-search.cnstrc.com',
            ),
        );
    }

    /**
     * The public API key. Every Constructor request needs it.
     *
     * @throws ConfigurationException
     */
    public function requireApiKey(): string
    {
        return $this->apiKey ?? throw new ConfigurationException(
            'CONSTRUCTOR_API_KEY is not set. Find your API key in the Constructor dashboard under Integration > API Integration.'
        );
    }

    /**
     * The secret API token. Only catalog management needs it.
     *
     * @throws ConfigurationException
     */
    public function requireApiToken(): string
    {
        return $this->apiToken ?? throw new ConfigurationException(
            'CONSTRUCTOR_API_TOKEN is not set. Catalog management requires an API token from the Constructor dashboard (Integration > API Integration).'
        );
    }

    /**
     * The agent domain. Only the AI Shopping Agent needs it.
     *
     * @throws ConfigurationException
     */
    public function requireAgentDomain(): string
    {
        return $this->agentDomain ?? throw new ConfigurationException(
            'CONSTRUCTOR_AGENT_DOMAIN is not set. The AI Shopping Agent requires the agent domain configured for your index.'
        );
    }

    /**
     * Keep the secrets out of dd(), dump(), debug bars and error pages.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $properties = get_object_vars($this);

        foreach (['apiToken', 'backendToken'] as $secret) {
            if ($properties[$secret] !== null) {
                $properties[$secret] = '********';
            }
        }

        return $properties;
    }

    /**
     * Requests carry secret tokens, so only https hosts are accepted
     * (plain http is allowed for localhost, e.g. a local mock server).
     *
     * @throws ConfigurationException
     */
    private static function secureUrl(string $key, mixed $value): string
    {
        $url = rtrim((string) $value, '/');
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true);

        if ($scheme !== 'https' && ! ($scheme === 'http' && $isLocal)) {
            throw new ConfigurationException("constructor.{$key} must be an https URL; got \"{$url}\".");
        }

        return $url;
    }

    /**
     * Treat empty strings from .env the same as "not set".
     */
    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
