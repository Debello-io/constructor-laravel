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
use Throwable;

/**
 * The one place this package talks HTTP to Constructor.
 *
 * Constructor has two kinds of endpoint, and they are called differently:
 *
 *   Storefront  search, browse, autocomplete, recommendations, AI agents.
 *               Public (no API token). Must carry the shopper's identity and
 *               the backend security token. See RequestContext.
 *
 *   Catalog     items, catalog uploads, tasks. Private. Authenticated with
 *               the API token as the HTTP Basic username, no password.
 *
 * Every response goes through throwIfFailed(), so callers get the same typed
 * exceptions whichever endpoint they use.
 *
 * @see https://docs.constructor.com/reference/main-authentication
 */
class ApiClient
{
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

    public function context(): RequestContext
    {
        return $this->context;
    }

    /**
     * GET a storefront endpoint on the search host and return the decoded JSON.
     *
     * @param  string  $path  Already-encoded path, e.g. '/v1/search/'.rawurlencode($query)
     * @param  array<string, mixed>  $query  Endpoint parameters. Shopper identity is added for you.
     * @param  array<string, mixed>  $identity  Overrides for client_id / session_id / user_id.
     * @return array<string, mixed>
     */
    public function getStorefront(string $path, array $query = [], array $identity = []): array
    {
        $url = $this->storefrontUrl($this->config->searchBaseUrl, $path, $query, $identity);

        return $this->send(fn () => $this->storefrontRequest()->get($url), $url);
    }

    /**
     * Build a full storefront URL: endpoint parameters, then `key` and the
     * shopper identity parameters (endpoint values win if both are set).
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $identity
     */
    public function storefrontUrl(string $baseUrl, string $path, array $query = [], array $identity = []): string
    {
        $parameters = array_merge(
            ['key' => $this->config->requireApiKey()],
            $this->context->queryParameters($identity),
            $query,
        );

        return $baseUrl.$path.'?'.QueryString::build($parameters);
    }

    /**
     * A pending request with timeouts, retries and backend-integration headers.
     */
    public function storefrontRequest(): PendingRequest
    {
        $this->warnIfBackendTokenMissing();

        return $this->baseRequest()->withHeaders($this->context->headers());
    }

    /**
     * Call a catalog-management endpoint with the API token.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $json  Request body, sent as JSON.
     * @return array<string, mixed>
     */
    public function catalog(string $method, string $path, array $query = [], ?array $json = null): array
    {
        $url = $this->catalogUrl($path, $query);

        // Only reads are retried. Retrying a write after a timeout could
        // create a second ingestion task for the same change.
        $retry = strtoupper($method) === 'GET';

        return $this->send(function () use ($method, $url, $json, $retry) {
            $request = $this->catalogRequest($retry);

            return $json === null
                ? $request->send($method, $url)
                : $request->send($method, $url, ['json' => $json]);
        }, $url);
    }

    /**
     * Build a catalog URL with `key` and `c` added.
     *
     * @param  array<string, mixed>  $query
     */
    public function catalogUrl(string $path, array $query = [], ?string $baseUrl = null): string
    {
        $parameters = array_merge(
            ['key' => $this->config->requireApiKey(), 'c' => $this->config->clientIdentifier],
            $query,
        );

        return ($baseUrl ?? $this->config->searchBaseUrl).$path.'?'.QueryString::build($parameters);
    }

    /**
     * A pending request authenticated with the API token (HTTP Basic,
     * token as username, empty password).
     */
    public function catalogRequest(bool $retry = true): PendingRequest
    {
        return $this->baseRequest($retry)
            ->withBasicAuth($this->config->requireApiToken(), '')
            ->acceptJson();
    }

    /**
     * Run a request, translate failures into this package's exceptions and
     * return the decoded JSON body (an empty array for an empty body).
     *
     * @param  callable(): Response  $request
     * @param  string  $url  The URL being called, used only to name the host in errors.
     * @return array<string, mixed>
     *
     * @throws ConnectionException|ApiException
     */
    public function send(callable $request, string $url = ''): array
    {
        try {
            $response = $request();
        } catch (HttpConnectionException $e) {
            throw ConnectionException::fromHttpClient($e, $url);
        } catch (RequestException $e) {
            // Only reached if a caller opted into ->throw(); normalise it anyway.
            $response = $e->response;
        }

        $this->throwIfFailed($response);

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @throws AuthenticationException|RateLimitException|ApiException
     */
    public function throwIfFailed(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $body = Str::limit($response->body(), 1000);

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
                'Constructor rate limit reached (429). Server-side requests need CONSTRUCTOR_BACKEND_TOKEN to avoid throttling.',
                $status,
                $body,
            ),
            default => new ApiException("Constructor API error ({$status}): {$body}", $status, $body),
        };
    }

    /**
     * Shared settings: timeout and a retry policy that only retries failures
     * that might succeed on a second try.
     */
    private function baseRequest(bool $retry = true): PendingRequest
    {
        $request = $this->http->timeout($this->config->timeout);

        if (! $retry) {
            return $request;
        }

        return $request
            ->retry(
                times: $this->config->retryAttempts,
                sleepMilliseconds: fn (int $attempt) => $this->config->retryDelay * (2 ** ($attempt - 1)),
                when: fn (Throwable $e) => $this->isRetryable($e),
                throw: false,
            );
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
}
