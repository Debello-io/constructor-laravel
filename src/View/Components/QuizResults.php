<?php

namespace ConstructorIO\Laravel\View\Components;

use ConstructorIO\Laravel\DataTransferObjects\QuizResults as QuizResultsDto;

/**
 * The container around a quiz's results:
 *
 *     <x-constructor::quiz-results :results="$results">
 *         <x-constructor::item :product="$product">...</x-constructor::item>  (one per product)
 *     </x-constructor::quiz-results>
 *
 * Renders data-cnstrc-quizzes with the quiz, version and session IDs, the
 * result ID and the result count.
 *
 * @see https://docs.constructor.com/docs/integrating-with-constructor-behavioral-tracking-data-driven-event-tracking
 */
class QuizResults extends TrackingComponent
{
    public function __construct(
        public QuizResultsDto $results,
        string $tag = 'div',
    ) {
        parent::__construct($tag);
    }

    public function trackingAttributes(): array
    {
        return [
            'data-cnstrc-quizzes' => '',
            'data-cnstrc-quiz-id' => self::text($this->results->quizId),
            'data-cnstrc-quiz-version-id' => self::text($this->results->quizVersionId),
            'data-cnstrc-quiz-session-id' => self::text($this->results->quizSessionId),
            'data-cnstrc-result-id' => self::text($this->results->resultId),
            'data-cnstrc-num-results' => self::count($this->results->total),
        ];
    }
}
