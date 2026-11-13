<?php

namespace ConstructorIO\Laravel\Exceptions;

/**
 * Constructor rejected the credentials (HTTP 401 or 403).
 *
 * 401: the API key or API token is wrong.
 * 403: the token is valid but lacks the permission (scope) for this endpoint.
 */
class AuthenticationException extends ApiException {}
