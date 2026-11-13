<?php

namespace ConstructorIO\Laravel\Concerns;

use Closure;
use ConstructorIO\Laravel\Support\ParameterBuilder;
use ConstructorIO\Laravel\Support\ResponseCache;

/**
 * Caches a storefront read when CONSTRUCTOR_CACHE_ENABLED=true.
 *
 * Only for responses that are the same for every shopper. The shopper
 * identity overrides (user_id, client_id, session_id, origin_referrer) are
 * left out of the key on purpose: these endpoints don't vary by shopper, and
 * a per-shopper key would make the cache useless. Everything else the caller
 * passes, including user segments and fmt_options, is part of the key.
 *
 * A result is only stored if no request behind it failed, so an outage is
 * never cached as "no categories". Callers opt out with 'cache' => false.
 *
 * Use together with HandlesStorefrontFailures, which counts fallbacks.
 *
 * @property-read ResponseCache $responseCache
 */
trait CachesResponses
{
    /** How many times attempt() has returned a fallback (HandlesStorefrontFailures). */
    abstract protected function fallbacksServed(): int;

    /**
     * @template T
     *
     * @param  'categories'|'facets'|'collections'  $type  Picks the TTL (cache.ttl_overrides).
     * @param  array<string, mixed>  $options  The caller's options.
     * @param  Closure(): T  $call  The uncached read, including its attempt() wrapper.
     * @param  array<array-key, mixed>  $arguments  Other arguments the response depends on.
     * @param  (Closure(mixed): mixed)|null  $prepareForStorage  See ResponseCache::remember().
     * @return T
     */
    protected function cached(string $operation, string $type, array $options, Closure $call, array $arguments = [], ?Closure $prepareForStorage = null): mixed
    {
        if (! $this->responseCache->enabled() || ($options['cache'] ?? true) === false) {
            return $call();
        }

        $keyOptions = array_diff_key($options, ParameterBuilder::identity($options), ['cache' => true]);
        $fallbacksBefore = $this->fallbacksServed();

        return $this->responseCache->remember(
            $operation,
            $type,
            ['options' => $keyOptions, 'arguments' => $arguments],
            $call,
            fn () => $this->fallbacksServed() === $fallbacksBefore,
            $prepareForStorage,
        );
    }
}
