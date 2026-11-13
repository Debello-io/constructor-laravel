<?php

namespace ConstructorIO\Laravel\Services;

use ConstructorIO\Laravel\Concerns\HandlesStorefrontFailures;
use ConstructorIO\Laravel\DataTransferObjects\QuizQuestion;
use ConstructorIO\Laravel\DataTransferObjects\QuizResults;
use ConstructorIO\Laravel\Exceptions\InvalidArgumentException;
use ConstructorIO\Laravel\Http\ApiClient;
use ConstructorIO\Laravel\Http\Path;
use ConstructorIO\Laravel\Support\ConstructorConfig;
use ConstructorIO\Laravel\Support\ParameterBuilder;
use ConstructorIO\Laravel\Support\ResultTransformer;

/**
 * Quizzes: guided product finders ("Which grill is right for you?") built in
 * the Constructor dashboard. Ask one question at a time, then show the
 * products that match the answers.
 *
 *     $quizzes = Constructor::quizzes();
 *
 *     $step = $quizzes->getNextQuestion('grill-finder');                 // first question
 *     $step = $quizzes->getNextQuestion('grill-finder', [[1], [3, 4]], [ // after two answers
 *         'quiz_session_id' => $step->quizSessionId,
 *         'quiz_version_id' => $step->quizVersionId,
 *     ]);
 *
 *     if ($step->isComplete()) {
 *         $results = $quizzes->getResults('grill-finder', [[1], [3, 4]], [], [...]);
 *     }
 *
 * Answers are a list with one entry per question answered, in order:
 *
 *     [1]           single select: the chosen option ID
 *     [3, 4]        multiple select: every chosen option ID
 *     []  or null   a skipped select question
 *     true / false  open text: whether the shopper answered (the text itself
 *                   is never sent; Constructor doesn't store it)
 *     'seen'        a cover page (QuizService::COVER_PAGE_SEEN)
 *
 * Quiz requests are storefront reads: they carry the shopper's identity and,
 * by default, return a fallback instead of throwing (see throw_exceptions).
 *
 * @see https://docs.constructor.com/reference/configuration-quizzes
 * @see https://docs.constructor.com/reference/v1-quizzes-get-next-question
 * @see https://docs.constructor.com/reference/v1-quizzes-get-quiz-results
 */
class QuizService
{
    use HandlesStorefrontFailures;

    /** The answer that moves past a cover page. */
    public const COVER_PAGE_SEEN = 'seen';

    protected readonly ConstructorConfig $config;

    public function __construct(
        protected readonly ApiClient $api,
        protected readonly ResultTransformer $transformer,
    ) {
        $this->config = $api->config();
    }

    /**
     * The next unanswered question, or the first one when $answers is empty.
     *
     * Options:
     *   quiz_session_id  string  The shopper's quiz session. Constructor assigns one
     *                            on the first call; pass it back on every later call.
     *   quiz_version_id  string  The quiz version from the first call, so a quiz
     *                            edited mid-session doesn't change under the shopper.
     *   section          string  Index section
     *   parameters       array   Any other API parameter
     * plus identity overrides (see ParameterBuilder).
     *
     * @param  list<mixed>  $answers  See the class docblock.
     * @param  array<string, mixed>  $options
     */
    public function getNextQuestion(string $quizId, array $answers = [], array $options = []): QuizQuestion
    {
        return $this->attempt('quiz next question', function () use ($quizId, $answers, $options) {
            $response = $this->api->getStorefront(
                '/v1/quizzes/'.Path::segment($quizId).'/next',
                $this->quizParameters($answers, $options),
                ParameterBuilder::identity($options),
                $this->config->quizzesBaseUrl,
            );

            return $this->toQuizQuestion($quizId, $response);
        }, fn () => QuizQuestion::unavailable($quizId));
    }

    /**
     * Products matching the shopper's answers.
     *
     * Options: quiz_session_id, quiz_version_id, section, page, per_page
     * (default 24, max 200), range_filters, parameters, plus identity overrides.
     *
     * @param  list<mixed>  $answers  Every answer so far (see the class docblock). Required.
     * @param  array<string, mixed>  $filters  Narrow the results further: ['brand' => ['Weber']]
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidArgumentException If no answers are given.
     */
    public function getResults(string $quizId, array $answers, array $filters = [], array $options = []): QuizResults
    {
        // Constructor requires at least one answer; without one the request can
        // only fail, so say so before sending it.
        if ($answers === []) {
            throw new InvalidArgumentException('Quiz results need at least one answer.');
        }

        return $this->attempt('quiz results', function () use ($quizId, $answers, $filters, $options) {
            $parameters = $this->quizParameters($answers, $options) + [
                'num_results_per_page' => ParameterBuilder::perPage($options),
                'page' => ParameterBuilder::page($options),
            ];

            $filterParameter = ParameterBuilder::filters($filters, $options['range_filters'] ?? []);
            if ($filterParameter !== []) {
                $parameters['filters'] = $filterParameter;
            }

            $response = $this->api->getStorefront(
                '/v1/quizzes/'.Path::segment($quizId).'/results',
                $parameters,
                ParameterBuilder::identity($options),
                $this->config->quizzesBaseUrl,
            );

            return $this->toQuizResults($quizId, $response, $options);
        }, fn () => QuizResults::empty($quizId));
    }

    /**
     * The results page settings from the dashboard: title, description and
     * the "you chose ..." summary, per device.
     *
     * Options: quiz_version_id, section.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed> `results_config`, `quiz_id`, `quiz_version_id` and `metadata`; empty on failure.
     *
     * @see https://docs.constructor.com/reference/v1-quizzes-get-quiz-results-config
     */
    public function getResultsConfig(string $quizId, array $options = []): array
    {
        return $this->attempt('quiz results config', fn () => $this->api->getStorefront(
            '/v1/quizzes/'.Path::segment($quizId).'/results_config',
            array_filter([
                'section' => $options['section'] ?? $this->config->defaultSection,
                'quiz_version_id' => $options['quiz_version_id'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''),
            ParameterBuilder::identity($options),
            $this->config->quizzesBaseUrl,
        ), fn () => []);
    }

    /**
     * Turn answers into Constructor's `a` parameter: one value per question,
     * with the options for one question joined by commas.
     *
     *     [[1], [3, 4], null, true, 'seen']   a=1&a=3,4&a=&a=true&a=seen
     *
     * @param  list<mixed>  $answers
     * @return list<string>
     */
    public static function encodeAnswers(array $answers): array
    {
        return array_map(fn (mixed $answer) => match (true) {
            $answer === null => '',
            is_bool($answer) => $answer ? 'true' : 'false',
            is_array($answer) => implode(',', array_map(
                'strval',
                array_filter($answer, fn ($option) => is_scalar($option) && $option !== ''),
            )),
            is_scalar($answer) => (string) $answer,
            default => throw new InvalidArgumentException('Each quiz answer must be a list of option IDs, a boolean, a string or null.'),
        }, $answers);
    }

    /**
     * Parameters shared by the next-question and results endpoints.
     *
     * @param  list<mixed>  $answers
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function quizParameters(array $answers, array $options): array
    {
        $parameters = array_filter([
            'section' => $options['section'] ?? $this->config->defaultSection,
            'quiz_session_id' => $options['quiz_session_id'] ?? null,
            'quiz_version_id' => $options['quiz_version_id'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        if ($answers !== []) {
            $parameters['a'] = self::encodeAnswers($answers);
        }

        return array_merge($parameters, $options['parameters'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function toQuizQuestion(string $quizId, array $response): QuizQuestion
    {
        $question = is_array($response['next_question'] ?? null) ? $response['next_question'] : null;

        if ($question !== null) {
            // The guide's examples use `options`; the OpenAPI schema says
            // `option`. Accept either and always expose `options` (empty for
            // open text and cover pages).
            $options = $question['options'] ?? $question['option'] ?? [];
            $question['options'] = is_array($options) ? array_values(array_filter($options, 'is_array')) : [];
            unset($question['option']);
        }

        return new QuizQuestion(
            quizId: (string) ($response['quiz_id'] ?? $quizId),
            question: $question,
            // `version_id` is the deprecated name for the same value.
            quizVersionId: $this->stringOrNull($response['quiz_version_id'] ?? $response['version_id'] ?? null),
            quizSessionId: $this->stringOrNull($response['quiz_session_id'] ?? null),
            totalQuestions: isset($response['total_questions']) ? (int) $response['total_questions'] : null,
            raw: $response,
        );
    }

    /**
     * The OpenAPI schema puts `results` at the top level, while the tracking
     * guide reads `response.total_num_results`, as on every other result
     * endpoint. Read both shapes.
     *
     * @see https://docs.constructor.com/docs/integrating-with-constructor-behavioral-tracking-data-driven-event-tracking
     *
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $options
     */
    protected function toQuizResults(string $quizId, array $response, array $options): QuizResults
    {
        $body = is_array($response['response'] ?? null) ? $response['response'] : $response;
        $results = is_array($body['results'] ?? null) ? $body['results'] : [];
        $products = $this->transformer->products($results);
        $filterExpression = $body['filter_expression'] ?? $response['filter_expression'] ?? null;

        return new QuizResults(
            quizId: (string) ($response['quiz_id'] ?? $quizId),
            products: $products,
            total: (int) ($body['total_num_results'] ?? count($products)),
            page: ParameterBuilder::page($options),
            perPage: ParameterBuilder::perPage($options),
            facets: $this->transformer->facets(is_array($body['facets'] ?? null) ? $body['facets'] : []),
            filterExpression: is_array($filterExpression) ? $filterExpression : null,
            resultId: $this->stringOrNull($response['result_id'] ?? null),
            quizVersionId: $this->stringOrNull($response['quiz_version_id'] ?? $response['version_id'] ?? null),
            quizSessionId: $this->stringOrNull($response['quiz_session_id'] ?? null),
            metadata: $this->transformer->metadata($response),
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && $value !== '' ? (string) $value : null;
    }
}
