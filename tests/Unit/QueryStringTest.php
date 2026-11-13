<?php

namespace ConstructorIO\Laravel\Tests\Unit;

use ConstructorIO\Laravel\Http\QueryString;
use PHPUnit\Framework\TestCase;

class QueryStringTest extends TestCase
{
    public function test_scalars_are_rfc3986_encoded(): void
    {
        $this->assertSame(
            'key=key_test&term=1%2F2%22%20grate&page=2',
            QueryString::build(['key' => 'key_test', 'term' => '1/2" grate', 'page' => 2]),
        );
    }

    public function test_lists_repeat_the_parameter_name(): void
    {
        $this->assertSame('us=vip&us=east', QueryString::build(['us' => ['vip', 'east']]));
    }

    public function test_maps_use_deep_object_brackets(): void
    {
        $this->assertSame(
            'filters[color]=red&filters[color]=blue&filters[Fuel%20Type]=Gas',
            QueryString::build(['filters' => ['color' => ['red', 'blue'], 'Fuel Type' => ['Gas']]]),
        );
    }

    public function test_nested_maps_and_lists(): void
    {
        $this->assertSame(
            'fmt_options[hidden_fields]=inventory&fmt_options[hidden_fields]=store&fmt_options[groups_max_depth]=2',
            QueryString::build(['fmt_options' => ['hidden_fields' => ['inventory', 'store'], 'groups_max_depth' => 2]]),
        );
    }

    public function test_booleans_and_nulls(): void
    {
        $this->assertSame('guard=true&force=false', QueryString::build(['guard' => true, 'force' => false, 'skip' => null]));
    }
}
