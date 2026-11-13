<?php

namespace ConstructorIO\Laravel\Tests\Unit;

use ConstructorIO\Laravel\Support\ParameterBuilder;
use PHPUnit\Framework\TestCase;

class ParameterBuilderTest extends TestCase
{
    public function test_range_filters_use_constructors_min_max_string(): void
    {
        $filters = ParameterBuilder::filters([], [
            'price' => ['min' => 50, 'max' => 200],
            'btu' => ['min' => 30000],
            'weight' => ['max' => 40],
            'ignored' => ['min' => null, 'max' => null],
        ]);

        $this->assertSame([
            'price' => ['50-200'],
            'btu' => ['30000-inf'],
            'weight' => ['-inf-40'],
        ], $filters);
    }

    public function test_filter_values_are_lists_and_booleans_are_capitalised(): void
    {
        $this->assertSame(
            ['brand' => ['Weber'], 'in_stock' => ['True'], 'fuel' => ['Gas', 'Pellet']],
            ParameterBuilder::filters(['brand' => 'Weber', 'in_stock' => true, 'fuel' => ['Gas', 'Pellet'], 'empty' => []]),
        );
    }

    public function test_results_parameters(): void
    {
        $parameters = ParameterBuilder::forResults(['brand' => ['Traeger']], [
            'page' => 3,
            'per_page' => 500,
            'sort_by' => 'price',
            'hidden_fields' => ['inventory_count'],
            'user_segments' => ['vip'],
            'pre_filter_expression' => ['name' => 'in_stock', 'value' => 'True'],
        ], 'Products');

        $this->assertSame('Products', $parameters['section']);
        $this->assertSame(3, $parameters['page']);
        $this->assertSame(200, $parameters['num_results_per_page'], 'per_page is capped at the documented maximum');
        $this->assertSame('descending', $parameters['sort_order']);
        $this->assertSame(['brand' => ['Traeger']], $parameters['filters']);
        $this->assertSame(['hidden_fields' => ['inventory_count']], $parameters['fmt_options']);
        $this->assertSame(['vip'], $parameters['us']);
        $this->assertSame('{"name":"in_stock","value":"True"}', $parameters['pre_filter_expression']);
    }

    public function test_offset_replaces_page(): void
    {
        $parameters = ParameterBuilder::forResults([], ['offset' => 48, 'page' => 3], 'Products');

        $this->assertSame(48, $parameters['offset']);
        $this->assertArrayNotHasKey('page', $parameters);
    }
}
