<?php

namespace ConstructorIO\Laravel\Console;

use ConstructorIO\Laravel\Exceptions\ApiException;
use ConstructorIO\Laravel\Exceptions\AuthenticationException;
use ConstructorIO\Laravel\Exceptions\ConfigurationException;
use ConstructorIO\Laravel\Exceptions\ConstructorException;
use ConstructorIO\Laravel\Exceptions\RateLimitException;
use ConstructorIO\Laravel\Http\ApiClient;
use ConstructorIO\Laravel\Services\CatalogService;
use ConstructorIO\Laravel\Support\ConstructorConfig;
use Illuminate\Console\Command;

/**
 * Check the package's configuration and credentials, without printing secrets.
 *
 *     php artisan constructor:verify            # configuration plus live checks
 *     php artisan constructor:verify --offline  # configuration only
 *
 * Live checks make one storefront request (the category tree, which records
 * no shopper behaviour) and, when an API token is set, one catalog read.
 * Exits with 1 if any check fails; warnings don't fail it.
 */
class VerifyCommand extends Command
{
    private const PASS = '<fg=green;options=bold>PASS</>';

    private const WARN = '<fg=yellow;options=bold>WARN</>';

    private const FAIL = '<fg=red;options=bold>FAIL</>';

    private const INFO = '<fg=blue>INFO</>';

    private const SKIP = '<fg=gray>SKIP</>';

    protected $signature = 'constructor:verify
        {--offline : Only check the configuration; send no requests to Constructor}';

    protected $description = 'Check the Constructor configuration and credentials';

    /** @var list<array{string, string, string}> */
    private array $results = [];

    private bool $failed = false;

    public function handle(): int
    {
        try {
            // Validates the config too (https hosts), so resolve it here.
            $config = $this->laravel->make(ConstructorConfig::class);
        } catch (ConfigurationException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->checkConfiguration($config);

        if (! $this->option('offline')) {
            $this->checkStorefront($config);
            $this->checkCatalog($config);
            $this->checkAgent($config);
        }

        $this->table(['Check', 'Result', 'Details'], $this->results);

        if ($this->failed) {
            $this->components->error('Some checks failed. See the details above.');

            return self::FAILURE;
        }

        $this->components->info('Constructor is configured correctly.');

        return self::SUCCESS;
    }

    private function checkConfiguration(ConstructorConfig $config): void
    {
        if ($config->apiKey === null) {
            $this->recordFailure('API key', 'CONSTRUCTOR_API_KEY is not set. Find it in the Constructor dashboard under Integration > API Integration.');
        } else {
            $this->record('API key', self::PASS, $this->maskApiKey($config->apiKey));
        }

        if ($config->backendToken === null) {
            $this->record('Backend token', self::WARN, 'CONSTRUCTOR_BACKEND_TOKEN is not set. Requests from your servers are likely to be throttled (429). Ask your Constructor contact for it.');
        } else {
            $this->record('Backend token', self::PASS, 'Set');
        }

        $this->record(
            'API token',
            $config->apiToken === null ? self::INFO : self::PASS,
            $config->apiToken === null ? 'Not set. Only needed for catalog management.' : 'Set',
        );

        // Constructor reports integrations by this value; backend
        // integrations are expected to use cio-be-web-{company}.
        // @see https://docs.constructor.com/docs/integrating-with-constructor-direct-api-integration-required-parameters
        $this->record(
            'Client identifier',
            str_starts_with($config->clientIdentifier, 'cio-be-') ? self::PASS : self::WARN,
            str_starts_with($config->clientIdentifier, 'cio-be-')
                ? $config->clientIdentifier
                : "{$config->clientIdentifier}: backend integrations should use cio-be-web-{company} (CONSTRUCTOR_CLIENT_IDENTIFIER).",
        );

        $this->record('Default section', self::INFO, $config->defaultSection);

        // ConstructorConfig refuses non-https hosts, so reaching here means they are fine.
        $this->record('API hosts', self::PASS, implode(', ', array_map(
            fn (string $url) => (string) parse_url($url, PHP_URL_HOST),
            array_unique([$config->searchBaseUrl, $config->agentBaseUrl, $config->quizzesBaseUrl, $config->catalogBatchingBaseUrl]),
        )).' (https)');

        $this->record(
            'Agent domain',
            self::INFO,
            $config->agentDomain ?? 'Not set. Only needed for the AI Shopping Agent.',
        );
    }

    /**
     * One storefront read, sent like the package's own storefront calls
     * (API key, backend token, client identifier).
     *
     * @see https://docs.constructor.com/reference/v1-browse-get-browse-groups
     */
    private function checkStorefront(ConstructorConfig $config): void
    {
        if ($config->apiKey === null) {
            $this->record('Storefront request', self::SKIP, 'Needs the API key.');

            return;
        }

        try {
            $this->laravel->make(ApiClient::class)->getStorefront('/browse/groups', [
                'section' => $config->defaultSection,
                'fmt_options' => ['groups_max_depth' => 1],
            ]);

            $this->record('Storefront request', self::PASS, $config->backendToken === null
                ? 'Constructor accepted the API key.'
                : 'Constructor accepted the API key and backend token.');
        } catch (AuthenticationException $e) {
            $this->recordFailure('Storefront request', "Rejected ({$e->getStatusCode()}). Check that CONSTRUCTOR_API_KEY and CONSTRUCTOR_BACKEND_TOKEN belong to the same index.");
        } catch (RateLimitException) {
            $this->recordFailure('Storefront request', $config->backendToken === null
                ? 'Throttled (429). Set CONSTRUCTOR_BACKEND_TOKEN so Constructor recognises your servers.'
                : 'Throttled (429) even with a backend token. Check it is the token for this API key.');
        } catch (ApiException $e) {
            $this->recordFailure('Storefront request', "Constructor answered {$e->getStatusCode()}. If it is 400, check that the \"{$config->defaultSection}\" section (CONSTRUCTOR_SECTION) exists in your index.");
        } catch (ConstructorException $e) {
            $this->recordFailure('Storefront request', $e->getMessage());
        }
    }

    private function checkCatalog(ConstructorConfig $config): void
    {
        if ($config->apiKey === null || $config->apiToken === null) {
            $this->record('Catalog credentials', self::SKIP, 'Needs the API key and API token.');

            return;
        }

        try {
            if ($this->laravel->make(CatalogService::class)->verifyCredentials()) {
                $this->record('Catalog credentials', self::PASS, 'Constructor accepted the API token.');
            } else {
                $this->recordFailure('Catalog credentials', 'Constructor rejected the API token (401/403). It must be an API token for the same index as CONSTRUCTOR_API_KEY, with catalog permissions.');
            }
        } catch (ConstructorException $e) {
            $this->recordFailure('Catalog credentials', $e->getMessage());
        }
    }

    private function checkAgent(ConstructorConfig $config): void
    {
        if ($config->agentDomain === null) {
            return;
        }

        // Every Shopping Agent request runs Constructor's AI model, and the
        // Product Insights Agent needs a real item ID, so neither is cheap or
        // generic enough to call from here.
        $this->record('AI agents', self::SKIP, 'Not called: each agent request runs the AI model. Try Constructor::agent()->getProductQuestions(\'<item id>\') in Tinker.');
    }

    /**
     * The API key is public (browsers send it), but there's no need to print
     * all of it: enough to recognise which index is configured.
     */
    private function maskApiKey(string $apiKey): string
    {
        return strlen($apiKey) >= 12 ? substr($apiKey, 0, 4).'…'.substr($apiKey, -4) : 'Set';
    }

    private function record(string $check, string $result, string $details): void
    {
        $this->results[] = [$check, $result, $details];
    }

    private function recordFailure(string $check, string $details): void
    {
        $this->failed = true;
        $this->record($check, self::FAIL, $details);
    }
}
