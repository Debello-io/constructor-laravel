<?php

namespace ConstructorIO\Laravel\DataTransferObjects;

/**
 * The next step of a quiz, from QuizService::getNextQuestion().
 *
 *     $step = Constructor::quizzes()->getNextQuestion('grill-finder', $answers, [
 *         'quiz_session_id' => $sessionId,
 *     ]);
 *
 *     if ($step->isComplete()) {
 *         return redirect()->route('quiz.results', ...);   // every question is answered
 *     }
 *
 *     $step->question['title'];
 *     $step->type();       // single | multiple | open | cover | single_filter_value | multiple_filter_values
 *     $step->options();    // [['id' => 1, 'value' => 'Charcoal', 'images' => [...]], ...]
 *
 * Keep $quizVersionId and $quizSessionId and send them with every later
 * request for this shopper's quiz, so Constructor can attribute the answers.
 *
 * @see https://docs.constructor.com/reference/v1-quizzes-get-next-question
 * @see https://docs.constructor.com/reference/configuration-quizzes
 */
class QuizQuestion
{
    /**
     * @param  string  $quizId  The quiz that was requested.
     * @param  array<string, mixed>|null  $question  Constructor's `next_question`, with `options` always set
     *                                               for option-based questions. Null once every question is answered.
     * @param  string|null  $quizVersionId  Pass back as `quiz_version_id`.
     * @param  string|null  $quizSessionId  Pass back as `quiz_session_id`.
     * @param  int|null  $totalQuestions  Questions in the quiz, when Constructor reports it.
     * @param  bool  $available  False when the request failed and this is a fallback.
     * @param  array<string, mixed>  $raw  The full response.
     */
    public function __construct(
        public readonly string $quizId,
        public readonly ?array $question,
        public readonly ?string $quizVersionId = null,
        public readonly ?string $quizSessionId = null,
        public readonly ?int $totalQuestions = null,
        public readonly bool $available = true,
        public readonly array $raw = [],
    ) {}

    /**
     * True when there are no more questions: fetch the results next.
     *
     * A failed request is never "complete", so a Constructor outage doesn't
     * send shoppers to an empty results page. Check isAvailable() for that.
     */
    public function isComplete(): bool
    {
        return $this->available && $this->question === null;
    }

    /**
     * False when the quiz couldn't be loaded (only when throw_exceptions is off).
     */
    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * The question type: single, multiple, open, cover, single_filter_value
     * or multiple_filter_values.
     */
    public function type(): ?string
    {
        $type = $this->question['type'] ?? null;

        return is_string($type) ? $type : null;
    }

    /**
     * The answer options of a select question (empty for open and cover questions).
     *
     * @return list<array<string, mixed>>
     */
    public function options(): array
    {
        return $this->question['options'] ?? [];
    }

    public function isSkippable(): bool
    {
        return (bool) ($this->question['is_skippable'] ?? false);
    }

    /**
     * @return array{quiz_id: string, question: array<string, mixed>|null, quiz_version_id: string|null, quiz_session_id: string|null, total_questions: int|null, complete: bool}
     */
    public function toArray(): array
    {
        return [
            'quiz_id' => $this->quizId,
            'question' => $this->question,
            'quiz_version_id' => $this->quizVersionId,
            'quiz_session_id' => $this->quizSessionId,
            'total_questions' => $this->totalQuestions,
            'complete' => $this->isComplete(),
        ];
    }

    /**
     * Fallback when the quiz couldn't be loaded.
     */
    public static function unavailable(string $quizId = ''): self
    {
        return new self(quizId: $quizId, question: null, available: false);
    }
}
