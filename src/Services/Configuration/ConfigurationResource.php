<?php

namespace ConstructorIO\Laravel\Services\Configuration;

use ConstructorIO\Laravel\Exceptions\ApiException;
use ConstructorIO\Laravel\Exceptions\InvalidArgumentException;
use ConstructorIO\Laravel\Http\ApiClient;
use ConstructorIO\Laravel\Http\Path;
use ConstructorIO\Laravel\Support\ConstructorConfig;

/**
 * Shared plumbing for the configuration (merchandising management) APIs:
 * redirect rules, synonyms, sort options, facets and searchandising rules.
 *
 * Every call goes through ApiClient::catalog(), so it is authenticated with
 * the API token (HTTP Basic), reads are retried and writes never are, and
 * failures throw typed exceptions. Single-record reads return null on 404.
 *
 * Each resource service uses the same method names where the API allows:
 * list(), get(), create(), replace() (full overwrite), update() (partial)
 * and delete().
 */
abstract class ConfigurationResource
{
    protected readonly ConstructorConfig $config;

    public function __construct(protected readonly ApiClient $api)
    {
        $this->config = $api->config();
    }

    /**
     * GET a list endpoint.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function read(string $path, array $query = []): array
    {
        return $this->api->catalog('GET', $path, $this->withoutNulls($query));
    }

    /**
     * GET one record, or null when Constructor answers 404.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     *
     * @throws ApiException For any other error status.
     */
    protected function find(string $path, array $query = []): ?array
    {
        try {
            return $this->read($path, $query);
        } catch (ApiException $e) {
            if ($e->getStatusCode() === 404) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Send a write (POST, PUT, PATCH, DELETE). Never retried.
     *
     * @param  array<string, mixed>  $query
     * @param  array<mixed>|null  $body  Sent as JSON.
     * @return array<string, mixed> Constructor's response; empty for 204 No Content.
     */
    protected function write(string $method, string $path, array $query = [], ?array $body = null): array
    {
        return $this->api->catalog($method, $path, $this->withoutNulls($query), $body);
    }

    /**
     * The `section` parameter: the caller's, or CONSTRUCTOR_SECTION.
     *
     * @return array{section: string}
     */
    protected function section(?string $section): array
    {
        return ['section' => $section ?? $this->config->defaultSection];
    }

    /**
     * Paging parameters for list endpoints: page or offset (Constructor
     * rejects both together), and per_page capped at the endpoint's limit.
     *
     * @param  array<string, mixed>  $options  page, per_page, offset
     * @return array<string, int|null>
     */
    protected function paging(array $options, int $maxPerPage): array
    {
        $offset = isset($options['offset']) ? max(0, (int) $options['offset']) : null;

        return [
            'num_results_per_page' => isset($options['per_page']) ? min($maxPerPage, max(1, (int) $options['per_page'])) : null,
            'page' => $offset === null && isset($options['page']) ? max(1, (int) $options['page']) : null,
            'offset' => $offset,
        ];
    }

    /**
     * A name used as a path segment (facet name, sort_by, phrase), encoded.
     *
     * @throws InvalidArgumentException When the name is empty.
     */
    protected function name(string $value, string $what): string
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("The {$what} can't be empty.");
        }

        return Path::segment($value);
    }

    /**
     * A numeric record ID (redirect rule, synonym group, refined query).
     * Validating also stops a crafted value from changing the request path.
     *
     * @throws InvalidArgumentException When the ID isn't a positive integer.
     */
    protected function numericId(int|string $id, string $what): int
    {
        if (is_int($id) ? $id > 0 : (ctype_digit($id) && (int) $id > 0)) {
            return (int) $id;
        }

        throw new InvalidArgumentException("Invalid {$what} ID '{$id}'. It must be a positive integer.");
    }

    /**
     * A list response with its records under $key, always present.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    protected function listResponse(array $response, string $key): array
    {
        return [
            $key => array_values(array_filter((array) ($response[$key] ?? []), 'is_array')),
            'total_count' => (int) ($response['total_count'] ?? 0),
        ];
    }

    /**
     * Records in batches no larger than the endpoint accepts.
     *
     * @param  list<array<string, mixed>>  $records
     * @param  int<1, max>  $size
     * @return list<list<array<string, mixed>>>
     */
    protected function batches(array $records, int $size): array
    {
        return array_chunk($records, $size);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function withoutNulls(array $parameters): array
    {
        return array_filter($parameters, fn ($value) => $value !== null);
    }
}
