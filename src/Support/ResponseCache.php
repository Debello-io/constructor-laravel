<?php

namespace ConstructorIO\Laravel\Support;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

/**
 * Opt-in cache for storefront responses that are the same for every shopper
 * (category tree, facet lists, collections). Off unless
 * CONSTRUCTOR_CACHE_ENABLED=true; see the `cache` block in config/constructor.php.
 *
 * Keys are a hash of the operation, a hash of the API key, the default
 * section and the caller's options, so two indexes, sections or option sets
 * never share an entry. Raw keys and tokens never appear in a cache key.
 *
 * flush() works on every cache store: keys include a "generation" number,
 * and flushing increments it. Old entries are never read again and expire
 * on their own TTL. No cache tags needed.
 *
 * A broken cache store must not take a page down, so store errors are logged
 * and the response is fetched from Constructor as if caching were off.
 */
final class ResponseCache
{
    /** Response types with their own TTL override (cache.ttl_overrides). */
    public const TYPES = ['categories', 'facets', 'collections'];

    public function __construct(
        private readonly ConstructorConfig $config,
        private readonly CacheFactory $cache,
    ) {}

    public function enabled(): bool
    {
        return $this->config->cacheEnabled;
    }

    /**
     * Return the cached value, or compute it and store it when $shouldStore
     * says the value is safe to keep (it isn't a fallback for a failure).
     *
     * @template T
     *
     * @param  array<array-key, mixed>  $keyParts  Everything the response depends on.
     * @param  Closure(): T  $compute
     * @param  Closure(): bool  $shouldStore  Called after $compute.
     * @return T
     */
    public function remember(string $operation, string $type, array $keyParts, Closure $compute, Closure $shouldStore): mixed
    {
        try {
            $store = $this->store();
            $key = $this->key($store, $operation, $keyParts);
            $hit = $store->get($key);
        } catch (Throwable $e) {
            $this->warn($operation, $e);

            return $compute();
        }

        // Values are wrapped so a cached null (an unknown collection) is still a hit.
        if (is_array($hit) && array_key_exists('value', $hit)) {
            return $hit['value'];
        }

        $value = $compute();

        if ($shouldStore()) {
            try {
                $store->put($key, ['value' => $value], $this->ttl($type));
            } catch (Throwable $e) {
                $this->warn($operation, $e);
            }
        }

        return $value;
    }

    /**
     * Make every cached response stale, for example after changing the
     * category tree or facet settings in the dashboard.
     */
    public function flush(): void
    {
        $this->store()->increment($this->generationKey());
    }

    /** Seconds to keep one type of response. */
    public function ttl(string $type): int
    {
        return $this->config->cacheTtlOverrides[$type] ?? $this->config->cacheTtl;
    }

    private function store(): Repository
    {
        return $this->cache->store($this->config->cacheStore);
    }

    /**
     * @param  array<array-key, mixed>  $keyParts
     *
     * @throws JsonException When an option can't be encoded (then nothing is cached).
     */
    private function key(Repository $store, string $operation, array $keyParts): string
    {
        $fingerprint = hash('sha256', json_encode([
            $operation,
            hash('sha256', (string) $this->config->apiKey),
            $this->config->searchBaseUrl,
            $this->config->defaultSection,
            self::normalize($keyParts),
        ], JSON_THROW_ON_ERROR));

        $generation = (int) $store->get($this->generationKey(), 0);

        return "{$this->config->cachePrefix}:{$generation}:{$operation}:{$fingerprint}";
    }

    private function generationKey(): string
    {
        return "{$this->config->cachePrefix}:generation";
    }

    /**
     * Sort map keys at every level, so ['a' => 1, 'b' => 2] and
     * ['b' => 2, 'a' => 1] share an entry. Lists keep their order.
     */
    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::normalize(...), $value);
    }

    private function warn(string $operation, Throwable $e): void
    {
        Log::channel($this->config->logChannel)->warning("Constructor response cache unavailable for {$operation}; fetching from Constructor.", [
            'operation' => $operation,
            'exception' => $e::class,
        ]);
    }
}
