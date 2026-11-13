<?php

namespace ConstructorIO\Laravel\Exceptions;

use Throwable;

/**
 * Constructor answered with an error status code.
 *
 * The status code is available from getStatusCode() (and getCode()), and the
 * response body, truncated, from getResponseBody(). The body is useful for
 * debugging validation errors (400) such as an unknown filter name.
 */
class ApiException extends ConstructorException
{
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly string $responseBody = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): string
    {
        return $this->responseBody;
    }
}
