<?php

namespace ConstructorIO\Laravel\DataTransferObjects;

/**
 * Products that match a shopper's quiz answers, from QuizService::getResults().
 *
 *     $results = Constructor::quizzes()->getResults('grill-finder', $answers, [], [
 *         'quiz_session_id' => $sessionId,
 *         'quiz_version_id' => $versionId,
 *     ]);
 *
 *     foreach ($results->products as $product) {
 *         echo $product['name'];
 *     }
 *
 * Put $resultId, $quizId, $quizVersionId and $quizSessionId on the page so
 * the beacon can attribute clicks and add-to-carts to the quiz.
 *
 * @see https://docs.constructor.com/reference/v1-quizzes-get-quiz-results
 */
class QuizResults
{
    /**
     * @param  string  $quizId  The quiz that was requested.
     * @param  list<array<string, mixed>>  $products  Matching products (see ResultTransformer::product()).
     * @param  int  $total  Total matching products.
     * @param  int  $page  Current page (1-based).
     * @param  int  $perPage  Results per page.
     * @param  array<string, array<string, mixed>>  $facets  Facets, when Constructor returns them (see ResultTransformer::facets()).
     * @param  array<string, mixed>|null  $filterExpression  The filter Constructor built from the answers.
     * @param  string|null  $resultId  Constructor's ID for this response, needed for tracking.
     * @param  string|null  $quizVersionId  The quiz version that produced the results.
     * @param  string|null  $quizSessionId  The shopper's quiz session.
     * @param  array<string, mixed>  $metadata  request, request_id, result_id and experiments.
     */
    public function __construct(
        public readonly string $quizId,
        public readonly array $products,
        public readonly int $total = 0,
        public readonly int $page = 1,
        public readonly int $perPage = 24,
        public readonly array $facets = [],
        public readonly ?array $filterExpression = null,
        public readonly ?string $resultId = null,
        public readonly ?string $quizVersionId = null,
        public readonly ?string $quizSessionId = null,
        public readonly array $metadata = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->products === [];
    }

    public function hasResults(): bool
    {
        return ! $this->isEmpty();
    }

    public function totalPages(): int
    {
        return $this->perPage > 0 ? (int) ceil($this->total / $this->perPage) : 0;
    }

    public function hasMore(): bool
    {
        return $this->page < $this->totalPages();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'quiz_id' => $this->quizId,
            'products' => $this->products,
            'total' => $this->total,
            'page' => $this->page,
            'per_page' => $this->perPage,
            'facets' => $this->facets,
            'filter_expression' => $this->filterExpression,
            'result_id' => $this->resultId,
            'quiz_version_id' => $this->quizVersionId,
            'quiz_session_id' => $this->quizSessionId,
            'metadata' => $this->metadata,
        ];
    }

    public static function empty(string $quizId = ''): self
    {
        return new self(quizId: $quizId, products: []);
    }
}
