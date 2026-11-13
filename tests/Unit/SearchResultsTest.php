<?php

namespace ConstructorIO\Laravel\Tests\Unit;

use ConstructorIO\Laravel\DataTransferObjects\SearchResults;
use PHPUnit\Framework\TestCase;

class SearchResultsTest extends TestCase
{
    public function test_pagination_helpers(): void
    {
        $results = new SearchResults(products: [['id' => '1']], total: 50, page: 2, perPage: 24);

        $this->assertSame(3, $results->totalPages());
        $this->assertTrue($results->hasMore());
        $this->assertSame(3, $results->nextPageNumber());
        $this->assertSame(24, $results->getOffset());
        $this->assertSame(1, $results->count());
        $this->assertFalse($results->exceedsRetrievableLimit());
    }

    public function test_pages_beyond_the_retrievable_limit_are_not_offered(): void
    {
        $results = new SearchResults(products: [], total: 250000, page: 416, perPage: 24);

        $this->assertSame(417, $results->totalPages(), 'ceil(10,000 / 24)');
        $this->assertTrue($results->hasMore());
        $this->assertTrue($results->exceedsRetrievableLimit());

        $last = new SearchResults(products: [], total: 250000, page: 417, perPage: 24);
        $this->assertFalse($last->hasMore());
        $this->assertSame(0, $last->nextPageNumber());
    }

    public function test_redirects_and_serialisation(): void
    {
        $results = new SearchResults(products: [], total: 0, page: 1, perPage: 24, resultId: 'r-1', redirect: ['url' => '/brands/weber']);

        $this->assertTrue($results->isRedirect());
        $this->assertSame('/brands/weber', $results->toArray()['redirect']['url']);
        $this->assertSame('r-1', $results->toArray()['result_id']);
        $this->assertFalse(SearchResults::empty()->isRedirect());
    }
}
