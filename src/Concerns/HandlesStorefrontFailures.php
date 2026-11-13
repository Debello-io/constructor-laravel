<?php

namespace ConstructorIO\Laravel\Concerns;

use Closure;
use ConstructorIO\Laravel\Exceptions\ApiException;
use ConstructorIO\Laravel\Exceptions\ConfigurationException;
use ConstructorIO\Laravel\Exceptions\ConstructorException;
use ConstructorIO\Laravel\Support\ConstructorConfig;
use Illuminate\Support\Facades\Log;

/**
 * Lets storefront reads degrade gracefully.
 *
 * A search page should still render (with no results) if Constructor is
 * briefly unreachable. So by default a failed storefront call is logged and
 * the caller gets an empty result. Set CONSTRUCTOR_THROW_EXCEPTIONS=true to
 * throw instead.
 *
 * Missing configuration always throws: that is a setup mistake, not an outage.
 *
 * @property-read ConstructorConfig $config
 */
trait HandlesStorefrontFailures
{
    /**
     * @template T
     *
     * @param  string  $operation  Short name for the log, e.g. "search".
     * @param  Closure(): T  $call
     * @param  Closure(): T  $fallback  Result to return when the call fails.
     * @return T
     */
    protected function attempt(string $operation, Closure $call, Closure $fallback): mixed
    {
        try {
            return $call();
        } catch (ConfigurationException $e) {
            throw $e;
        } catch (ConstructorException $e) {
            if ($this->config->throwExceptions) {
                throw $e;
            }

            // Log what failed and why, but never shopper identifiers or credentials.
            Log::channel($this->config->logChannel)->warning("Constructor {$operation} failed: {$e->getMessage()}", [
                'operation' => $operation,
                'exception' => $e::class,
                'status' => $e instanceof ApiException ? $e->getStatusCode() : null,
            ]);

            return $fallback();
        }
    }
}
