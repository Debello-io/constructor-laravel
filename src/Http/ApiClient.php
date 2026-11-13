<?php

namespace ConstructorIO\Laravel\Http;

use ConstructorIO\Laravel\Exceptions\ApiException;
use ConstructorIO\Laravel\Exceptions\AuthenticationException;
use ConstructorIO\Laravel\Exceptions\ConnectionException;
use ConstructorIO\Laravel\Exceptions\RateLimitException;
use ConstructorIO\Laravel\Support\ConstructorConfig;
use ConstructorIO\Laravel\Support\RequestContext;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Http\Message\StreamInterface;
use SensitiveParameter;
use Throwable;

/**
 * The one place this package sends HTTP requests to Constructor.
 *
 * Constructor has two kinds of endpoint, and they are called differently:
 *
 *   Storefront  search, browse, autocomplete, recommendations, AI agents.
 *               Public (no API token). Must carry the shopper's identity and
 *               the backend security token. See RequestContext.
 *
 *   Catalog     items, variations, item groups, catalog uploads, tasks,
 *               offline behavioral actions and the Catalog Batching Service.
 *               Private. Authenticated with the API token as the HTTP Basic
 *               username, no password.
 *
 * Every response goes through throwIfFailed(), so callers get the same typed
 * exceptions whichever endpoint they use.
 *
 * URLs built here contain shopper identifiers, so parameters that hold them
 * are marked #[SensitiveParameter] to keep them out of stack traces.
 *
 * @see https://docs.constructor.com/reference/main-authentication
 */
class ApiClient
{
    /** Longest wait between retries, in milliseconds, whatever Retry-After says. */
    private const MAX_RETRY_DELAY_MS = 5000;

    private static bool $warnedAboutBackendToken = false;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly ConstructorConfig $config,
        private readonly RequestContext $context,
    ) {}

    public function config(): ConstructorConfig
    {
        return $this->config;
    }

    /*
    |--------------------------------------------------------------------------
    | Storefront endpoints
    |--------------------------------------------------------------------------
    */

    /**
     * GET a storefront endpoint and return the decoded JSON.
     *
     * @param  string  $path  Path with dynamic segments encoded by Path::segment().
     * @param  array<string, mixed>  $query  Endpoint parameters. Shopper identity is added for you.
     * @param  array<string, mixed>  $identity  Overrides for client_id / session_id / user_id / origin_referrer.
     * @param  string|null  $baseUrl  Defaults to the search host; pass the agent host for agent endpoints.
     * @return array<string, mixed>
     */
    public function getStorefront(string $path, array $query = [], array $identity = [], ?string $baseUrl = null): array
    {
        $url = $this->storefrontUrl($baseUrl ?? $this->config->searchBaseUrl, $path, $query, $identity);

        return $this->send(fn () => $this->storefrontRequest()->acceptJson()->get($url), $url);
    }

    /**
     * Open a Server-Sent Events stream on a storefront endpoint.
     *
     * The response body is returned unread so the caller can parse events as
     * they arrive. `stream_timeout` bounds connecting and each read; the
     * caller enforces the total duration.
     *
     * @throws ConnectionException|ApiException
     */
    public function openStorefrontStream(#[SensitiveParameter] string $url): StreamInterface
    {
        try {
            $response = $this->storefrontRequest()
                ->accept('text/event-stream')
                ->timeout($this->config->streamTimeout)
                ->withOptions(['stream' => true])
                ->get($url);
        } catch (HttpConnectionException $e) {
            throw ConnectionException::fromHttpClient($e, $url);
        }

        $this->throwIfFailed($response);

        return $response->toPsrResponse()->getBody();
    }

    /**
     * Build a full storefront URL: `key`, the shopper identity parameters,
     * then the endpoint parameters.
     *
     * Endpoint parameters can't replace `key` or the identity parameters;
     * identity is changed through $identity so consent and validation apply.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $identity
     */
    public function storefrontUrl(string $baseUrl, string $path, array $query = [], array $identity = []): string
    {
        $parameters = array_merge(
            $query,
            ['key' => $this->config->requireApiKey()],
            $this->context->queryParameters($identity),
        );

        return $baseUrl.$path.'?'.QueryString::build($parameters);
    }

    /*
    |--------------------------------------------------------------------------
    | Catalog endpoints
    |--------------------------------------------------------------------------
    */

    /**
     * Call a catalog-management endpoint with the API token.
     *
     * Only reads are retried. Retrying a write after a timeout could create a
     * second ingestion task for the same change.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $json  Request body, sent as JSON.
     * @param  string|null  $baseUrl  Defaults to the search host; pass the Catalog Batching Service host for its endpoints.
     * @return array<string, mixed>
     */
    public function catalog(string $method, string $path, array $query = [], ?array $json = null, ?string $baseUrl = null): array
    {
        $url = $this->catalogUrl($path, $query, $baseUrl);
        $retry = strtoupper($method) === 'GET';

        return $this->send(function () use ($method, $url, $json, $retry) {
            $request = $this->catalogRequest($retry);

            return $json === null
                ? $request->send($method, $url)
                : $request->send($method, $url, ['json' => $json]);
        }, $url);
    }

    /**
     * Upload files as multipart form data to a catalog endpoint.
     *
     * Sent exactly once: a file stream that has been read would be empty on a
     * retry, and a retried upload could start a second ingestion task.
     *
     * @param  array<string, array{0: resource, 1: string}>  $files  [form field => [open file handle, file name]]
     * @return array<string, mixed>
     */
    public function uploadToCatalog(string $method, string $url, array $files, int $timeout): array
    {
        $request = $this->catalogRequest(retry: false)->timeout($timeout);

        foreach ($files as $fieldName => [$handle, $fileName]) {
            $request->attach($fieldName, $handle, $fileName);
        }

        // An (empty) multipart option is what makes Laravel send the attached files.
        return $this->send(fn () => $request->send($method, $url, ['multipart' => []]), $url);
    }

    /**
     * Build a catalog URL with `key` and `c` added.
     *
     * @param  array<string, mixed>  $query
     * @param  string|null  $baseUrl  Defaults to the search host.
     */
    public function catalogUrl(string $path, array $query = [], ?string $baseUrl = null): string
    {
        $parameters = array_merge(
            $query,
            ['key' => $this->config->requireApiKey(), 'c' => $this->config->clientIdentifier],
        );

        return ($baseUrl ?? $this->config->searchBaseUrl).$path.'?'.QueryString::build($parameters);
    }

    /*
    |--------------------------------------------------------------------------
    | Responses and errors
    |--------------------------------------------------------------------------
    */

    /**
     * @throws AuthenticationException|RateLimitException|ApiException
     */
    public function throwIfFailed(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();

        // The body stays out of the message: Constructor's error text can echo
        // the request (search terms, filter values), and messages end up in
        // logs and error trackers. It is available from getResponseBody().
        $body = Str::limit($response->body(), 2000);

        throw match (true) {
            $status === 401 => new AuthenticationException(
                'Constructor rejected the credentials (401). Check CONSTRUCTOR_API_KEY and, for catalog calls, CONSTRUCTOR_API_TOKEN.',
                $status,
                $body,
            ),
            $status === 403 => new AuthenticationException(
                'Constructor denied access (403). The token is valid but lacks permission for this endpoint.',
                $status,
                $body,
            ),
            $status === 429 => new RateLimitException(
                'Constructor rate limit reached (429). For storefront calls, check CONSTRUCTOR_BACKEND_TOKEN is set; for catalog calls, wait and retry.',
                $status,
                $body,
            ),
            $status >= 500 => new ApiException("Constructor is unavailable ({$status}). Try again later.", $status, $body),
            default => new ApiException("Constructor rejected the request ({$status}). See getResponseBody() for details.", $status, $body),
        };
    }

    /**
     * Reset the once-per-process warning. For tests.
     *
     * @internal
     */
    public static function flushState(): void
    {
        self::$warnedAboutBackendToken = false;
    }

    /**
     * Run a request and return the decoded JSON (an empty array for an empty body).
     *
     * @param  callable(): Response  $request
     * @return array<string, mixed>
     *
     * @throws ConnectionException|ApiException
     */
    private function send(callable $request, #[SensitiveParameter] string $url): array
    {
        try {
            $response = $request();
        } catch (HttpConnectionException $e) {
            throw ConnectionException::fromHttpClient($e, $url);
        } catch (RequestException $e) {
            $response = $e->response;
        }

        $this->throwIfFailed($response);

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * A pending request with timeouts, retries and backend-integration headers.
     */
    private function storefrontRequest(): PendingRequest
    {
        $this->warnIfBackendTokenMissing();

        return $this->baseRequest()->withHeaders($this->context->headers());
    }

    /**
     * A pending request authenticated with the API token (HTTP Basic,
     * token as username, empty password).
     */
    private function catalogRequest(bool $retry = true): PendingRequest
    {
        return $this->baseRequest($retry)
            ->withBasicAuth($this->config->requireApiToken(), '')
            ->acceptJson();
    }

    /**
     * Shared settings: timeout, and a retry policy that only retries failures
     * that might succeed on a second try.
     */
    private function baseRequest(bool $retry = true): PendingRequest
    {
        $request = $this->http->timeout($this->config->timeout);

        if (! $retry) {
            return $request;
        }

        return $request->retry(
            times: $this->config->retryAttempts,
            sleepMilliseconds: fn (int $attempt, Throwable $e) => $this->retryDelay($attempt, $e),
            when: fn (Throwable $e) => $this->isRetryable($e),
            throw: false,
        );
    }

    /**
     * Exponential backoff, or the server's Retry-After for a 429, capped so a
     * shopper's page never waits long.
     */
    private function retryDelay(int $attempt, Throwable $e): int
    {
        // Doubles each attempt: retry_delay, 2 x retry_delay, 4 x retry_delay ...
        $delay = $this->config->retryDelay * (1 << max(0, $attempt - 1));

        if ($e instanceof RequestException && is_numeric($retryAfter = $e->response->header('Retry-After'))) {
            $delay = (int) $retryAfter * 1000;
        }

        return min(self::MAX_RETRY_DELAY_MS, max(0, $delay));
    }

    /**
     * Retry connection problems, throttling (429) and server errors (5xx).
     * Never retry other 4xx errors: the same request will fail the same way.
     */
    private function isRetryable(Throwable $e): bool
    {
        if ($e instanceof HttpConnectionException) {
            return true;
        }

        if ($e instanceof RequestException) {
            return $e->response->serverError() || $e->response->status() === 429;
        }

        return false;
    }

    /**
     * Constructor throttles server-side traffic without the backend token,
     * and ignores X-Forwarded-For. Say so once per process instead of letting
     * it surface later as unexplained 429s.
     */
    private function warnIfBackendTokenMissing(): void
    {
        if ($this->config->backendToken !== null || self::$warnedAboutBackendToken) {
            return;
        }

        self::$warnedAboutBackendToken = true;

        Log::channel($this->config->logChannel)->warning(
            'CONSTRUCTOR_BACKEND_TOKEN is not set. Constructor is likely to throttle server-side requests without it. Ask your Constructor contact for the backend security token.'
        );
    }
}
