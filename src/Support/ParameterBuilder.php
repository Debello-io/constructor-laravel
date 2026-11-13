<?php

namespace ConstructorIO\Laravel\Support;

/**
 * Translates this package's friendly option names into Constructor's
 * query parameters, so every service accepts the same options.
 *
 * Supported options (all optional):
 *
 *   page                  int     Page number, starting at 1
 *   per_page              int     Results per page (1-200)
 *   offset                int     Results to skip. Use instead of page.
 *   section               string  Index section, default from config
 *   sort_by               string  Sort option name, e.g. "price"
 *   sort_order            string  "ascending" or "descending"
 *   range_filters         array   ['price' => ['min' => 50, 'max' => 200]]
 *   filter_match_types    array   ['color' => 'all']  (any | all | none)
 *   pre_filter_expression array|string  Filters shoppers can't see or remove
 *   fmt_options           array   Response formatting, e.g. ['groups_max_depth' => 2]
 *   hidden_fields         list    Hidden catalog fields to return (shortcut for fmt_options)
 *   variations_map        array|string  How to group variations (swatches)
 *   user_segments / us    list|string  Segments for redirect and content rules
 *   lang                  string  Query language, e.g. "en"
 *   parameters            array   Any other API parameter, passed through as-is
 *
 * Shopper identity overrides (see RequestContext):
 *
 *   user_id     string  Signed-in user ID (`ui`)
 *   client_id   string  Anonymous client ID (`i`)
 *   session_id  int     Session number (`s`)
 */
final class ParameterBuilder
{
    /** Constructor's documented maximum for num_results_per_page. */
    public const MAX_PER_PAGE = 200;

    public const DEFAULT_PER_PAGE = 24;

    /**
     * Parameters for search and browse result lists.
     *
     * @param  array<string, mixed>  $filters  ['brand' => ['Weber', 'Traeger'], 'in_stock' => true]
     * @param  array<string, mixed>  $options  See class docblock.
     * @return array<string, mixed>
     */
    public static function forResults(array $filters, array $options, string $defaultSection): array
    {
        $parameters = [
            'section' => $options['section'] ?? $defaultSection,
            'num_results_per_page' => self::perPage($options),
        ];

        if (isset($options['offset'])) {
            $parameters['offset'] = max(0, (int) $options['offset']);
        } else {
            $parameters['page'] = self::page($options);
        }

        if (isset($options['sort_by'])) {
            $parameters['sort_by'] = $options['sort_by'];
            $parameters['sort_order'] = $options['sort_order'] ?? 'descending';
        }

        return array_merge($parameters, self::shared($filters, $options));
    }

    /**
     * Parameters every result-returning endpoint accepts: filters,
     * formatting, segments and language.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function shared(array $filters, array $options): array
    {
        $parameters = [];

        $filterParameter = self::filters($filters, $options['range_filters'] ?? []);
        if ($filterParameter !== []) {
            $parameters['filters'] = $filterParameter;
        }

        if (! empty($options['filter_match_types'])) {
            $parameters['filter_match_types'] = $options['filter_match_types'];
        }

        if (! empty($options['pre_filter_expression'])) {
            $parameters['pre_filter_expression'] = self::json($options['pre_filter_expression']);
        }

        if (! empty($options['variations_map'])) {
            $parameters['variations_map'] = self::json($options['variations_map']);
        }

        $formatOptions = $options['fmt_options'] ?? [];
        if (! empty($options['hidden_fields'])) {
            $formatOptions['hidden_fields'] = array_values((array) $options['hidden_fields']);
        }
        if ($formatOptions !== []) {
            $parameters['fmt_options'] = $formatOptions;
        }

        $segments = $options['user_segments'] ?? $options['us'] ?? null;
        if (! empty($segments)) {
            $parameters['us'] = array_values((array) $segments);
        }

        if (isset($options['lang'])) {
            $parameters['lang'] = $options['lang'];
        }

        return array_merge($parameters, $options['parameters'] ?? []);
    }

    /**
     * Shopper identity overrides from the options array.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function identity(array $options): array
    {
        return array_intersect_key($options, array_flip(['user_id', 'client_id', 'session_id', 'origin_referrer']));
    }

    /**
     * Build the `filters` parameter.
     *
     * Values with the same key are ORed; different keys are ANDed. Ranges
     * use Constructor's "min-max" string form, with "-inf" / "inf" for an
     * open end. Booleans are written "True" / "False", as the API expects.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, array{min?: int|float|null, max?: int|float|null}>  $rangeFilters
     * @return array<string, list<string>>
     */
    public static function filters(array $filters, array $rangeFilters = []): array
    {
        $parameter = [];

        foreach ($filters as $name => $values) {
            $values = array_values(array_filter((array) $values, fn ($value) => $value !== null && $value !== ''));

            if ($values !== []) {
                $parameter[$name] = array_map(fn ($value) => self::filterValue($value), $values);
            }
        }

        foreach ($rangeFilters as $name => $range) {
            $min = $range['min'] ?? null;
            $max = $range['max'] ?? null;

            if ($min !== null || $max !== null) {
                $parameter[$name] = [($min ?? '-inf').'-'.($max ?? 'inf')];
            }
        }

        return $parameter;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function page(array $options): int
    {
        return max(1, (int) ($options['page'] ?? 1));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function perPage(array $options): int
    {
        return min(self::MAX_PER_PAGE, max(1, (int) ($options['per_page'] ?? self::DEFAULT_PER_PAGE)));
    }

    private static function filterValue(mixed $value): string
    {
        return is_bool($value) ? ($value ? 'True' : 'False') : (string) $value;
    }

    /**
     * Some parameters must be JSON strings. Accept arrays for convenience.
     */
    private static function json(mixed $value): string
    {
        return is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR);
    }
}
