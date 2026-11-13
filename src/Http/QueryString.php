<?php

namespace ConstructorIO\Laravel\Http;

/**
 * Builds query strings the way Constructor's API expects them.
 *
 * PHP's http_build_query() and Guzzle both turn lists into "us[0]=a&us[1]=b",
 * which Constructor does not understand. The API reference uses OpenAPI
 * "deepObject, explode" style instead:
 *
 *     ['us' => ['vip', 'east']]                    us=vip&us=east
 *     ['filters' => ['color' => ['red', 'blue']]]  filters[color]=red&filters[color]=blue
 *     ['fmt_options' => ['hidden_fields' => ['x']]] fmt_options[hidden_fields]=x
 *
 * Other rules:
 *   - null values are left out
 *   - booleans become "true" / "false"
 *   - everything is percent-encoded per RFC 3986 (spaces become %20)
 */
final class QueryString
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function build(array $parameters): string
    {
        $pairs = [];

        foreach ($parameters as $name => $value) {
            self::appendPairs($pairs, (string) $name, $value);
        }

        return implode('&', $pairs);
    }

    /**
     * Add "name=value" pairs for one parameter, recursing into arrays.
     *
     * @param  list<string>  $pairs
     */
    private static function appendPairs(array &$pairs, string $name, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                // A list repeats the same name; a map nests the key in brackets.
                $itemName = array_is_list($value) ? $name : "{$name}[{$key}]";

                self::appendPairs($pairs, $itemName, $item);
            }

            return;
        }

        $pairs[] = self::encodeName($name).'='.rawurlencode(self::stringify($value));
    }

    private static function stringify(mixed $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }

    /**
     * Percent-encode a parameter name, keeping its deepObject brackets readable.
     */
    private static function encodeName(string $name): string
    {
        return str_replace(['%5B', '%5D'], ['[', ']'], rawurlencode($name));
    }
}
