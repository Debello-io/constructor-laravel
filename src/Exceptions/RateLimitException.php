<?php

namespace ConstructorIO\Laravel\Exceptions;

/**
 * Constructor throttled the request (HTTP 429).
 *
 * For storefront traffic this usually means the `x-cnstrc-token` header is
 * missing or wrong: set CONSTRUCTOR_BACKEND_TOKEN.
 */
class RateLimitException extends ApiException {}
