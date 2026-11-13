<?php

namespace ConstructorIO\Laravel\Http;

/**
 * Encodes values that go into a URL path, such as a search query or an ID.
 *
 * rawurlencode() alone is not enough: it leaves "." untouched, so a query of
 * ".." would become /v1/search/.. and could be resolved by a server or proxy
 * as the parent path. Dot-only segments are therefore fully percent-encoded.
 */
final class Path
{
    public static function segment(string|int $value): string
    {
        $value = (string) $value;

        if (trim($value, '.') === '') {
            return str_repeat('%2E', strlen($value));
        }

        return rawurlencode($value);
    }
}
