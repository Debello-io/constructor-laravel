<?php

namespace ConstructorIO\Laravel\Testing;

use ConstructorIO\Laravel\Http\ApiClient;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use SensitiveParameter;

/**
 * The API client used while Constructor::fake() is active.
 *
 * It never opens a connection: every call answers as if Constructor had
 * found nothing, so the real services still run their own code (argument
 * checks, result mapping) and return their natural empty results. It also
 * needs no credentials, so tests don't have to configure any.
 *
 * - Storefront reads and catalog calls return an empty JSON object.
 * - Agent streams contain a single `end` event (an empty, complete answer).
 * - Catalog tasks report DONE at once, so waitForTaskCompletion() never waits.
 *
 * @internal Installed by ConstructorFake; not meant to be used directly.
 */
class OfflineApiClient extends ApiClient
{
    public function getStorefront(string $path, array $query = [], array $identity = [], ?string $baseUrl = null): array
    {
        return [];
    }

    public function postStorefront(
        string $baseUrl,
        string $path,
        #[SensitiveParameter] string $body,
        string $contentType,
        array $query = [],
        array $identity = [],
    ): array {
        return [];
    }

    public function openStorefrontStream(#[SensitiveParameter] string $url): StreamInterface
    {
        return Utils::streamFor("event: end\ndata: {}\n\n");
    }

    /**
     * No API key or shopper identity: nothing is sent, so nothing is added,
     * and no client ID cookie is queued.
     */
    public function storefrontUrl(string $baseUrl, string $path, array $query = [], array $identity = []): string
    {
        return $baseUrl.$path;
    }

    public function catalog(string $method, string $path, array $query = [], ?array $json = null, ?string $baseUrl = null): array
    {
        if (strtoupper($method) === 'GET' && str_starts_with($path, '/v1/tasks/')) {
            return ['id' => (int) substr($path, strlen('/v1/tasks/')), 'status' => 'DONE', 'result' => ['index_built' => true]];
        }

        return [];
    }

    public function uploadToCatalog(string $method, string $url, array $files, int $timeout): array
    {
        return [];
    }

    public function userProfile(string $method, string $path, array $json): array
    {
        return [];
    }

    public function uploadToUserProfile(string $method, string $path, array $files, int $timeout): array
    {
        return [];
    }

    public function catalogUrl(string $path, array $query = [], ?string $baseUrl = null): string
    {
        return ($baseUrl ?? $this->config()->searchBaseUrl).$path;
    }
}
