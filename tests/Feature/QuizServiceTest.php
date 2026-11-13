<?php

namespace ConstructorIO\Laravel\Tests\Feature;

use ConstructorIO\Laravel\Exceptions\ApiException;
use ConstructorIO\Laravel\Exceptions\ConfigurationException;
use ConstructorIO\Laravel\Exceptions\InvalidArgumentException;
use ConstructorIO\Laravel\Facades\Constructor;
use ConstructorIO\Laravel\Services\QuizService;
use ConstructorIO\Laravel\Support\ConstructorConfig;
use ConstructorIO\Laravel\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class QuizServiceTest extends TestCase
{
    private function quizzes(): QuizService
    {
        return app(QuizService::class);
    }

    /**
     * The guide's single-select example, which uses `options`.
     *
     * @see https://docs.constructor.com/reference/configuration-quizzes
     *
     * @return array<string, mixed>
     */
    private function nextQuestionResponse(): array
    {
        return [
            'next_question' => [
                'id' => 1,
                'title' => 'How do you like to cook?',
                'type' => 'single',
                'is_skippable' => true,
                'options' => [
                    ['id' => 1, 'value' => 'Charcoal', 'attribute' => ['name' => 'fuel_type', 'value' => 'Charcoal'], 'images' => null],
                    ['id' => 2, 'value' => 'Pellet', 'attribute' => ['name' => 'fuel_type', 'value' => 'Pellet'], 'images' => null],
                ],
            ],
            'total_questions' => 4,
            'version_id' => 'version-1',
            'quiz_version_id' => 'version-1',
            'quiz_session_id' => 'session-1',
            'quiz_id' => 'grill-finder',
        ];
    }

    public function test_next_question_calls_the_quizzes_host_with_answers_and_identity(): void
    {
        Http::fake(['quizzes.cnstrc.com/*' => Http::response($this->nextQuestionResponse())]);
        $this->actAsShopperRequest(['ConstructorioID_client_id' => 'client-uuid', 'ConstructorioID_session_id' => '3']);

        $step = $this->quizzes()->getNextQuestion('grill-finder', [[1], [3, 4], null, true, QuizService::COVER_PAGE_SEEN], [
            'quiz_session_id' => 'session-1',
            'quiz_version_id' => 'version-1',
        ]);

        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);

            $this->assertSame('GET', $request->method());
            $this->assertStringStartsWith('https://quizzes.cnstrc.com/v1/quizzes/grill-finder/next?', $request->url());
            $this->assertSame(['key_test'], $query['key']);
            $this->assertSame(['1', '3,4', '', 'true', 'seen'], $query['a'], 'One `a` per question; options for one question joined by commas');
            $this->assertSame(['session-1'], $query['quiz_session_id']);
            $this->assertSame(['version-1'], $query['quiz_version_id']);
            $this->assertSame(['Products'], $query['section']);
            $this->assertSame(['client-uuid'], $query['i']);
            $this->assertSame(['3'], $query['s']);
            $this->assertSame(['cio-be-web-bbq-test-store'], $query['c']);
            $this->assertSame(['backend_security_token'], $request->header('x-cnstrc-token'));
            $this->assertSame(['203.0.113.42'], $request->header('X-Forwarded-For'));
            $this->assertSame([], $request->header('Authorization'), 'The API token never goes on storefront requests');

            return true;
        });

        $this->assertFalse($step->isComplete());
        $this->assertTrue($step->isAvailable());
        $this->assertSame('single', $step->type());
        $this->assertTrue($step->isSkippable());
        $this->assertSame('Charcoal', $step->options()[0]['value']);
        $this->assertSame('version-1', $step->quizVersionId);
        $this->assertSame('session-1', $step->quizSessionId);
        $this->assertSame(4, $step->totalQuestions);
    }

    public function test_first_question_sends_no_answers(): void
    {
        Http::fake(['quizzes.cnstrc.com/*' => Http::response($this->nextQuestionResponse())]);

        $this->quizzes()->getNextQuestion('grill-finder');

        Http::assertSent(fn (Request $request) => ! isset($this->queryOf($request)['a'])
            && ! isset($this->queryOf($request)['quiz_session_id']));
    }

    public function test_quiz_id_is_one_encoded_path_segment(): void
    {
        Http::fake(['quizzes.cnstrc.com/*' => Http::response($this->nextQuestionResponse())]);

        $this->quizzes()->getNextQuestion('../admin?x=1');

        Http::assertSent(fn (Request $request) => $this->pathOf($request) === '/v1/quizzes/..%2Fadmin%3Fx%3D1/next');
    }

    public function test_options_are_read_from_the_schema_name_too(): void
    {
        $response = $this->nextQuestionResponse();
        $response['next_question']['option'] = $response['next_question']['options'];
        unset($response['next_question']['options']);
        Http::fake(['quizzes.cnstrc.com/*' => Http::response($response)]);

        $step = $this->quizzes()->getNextQuestion('grill-finder');

        $this->assertCount(2, $step->options());
        $this->assertNotNull($step->question);
        $this->assertArrayNotHasKey('option', $step->question);
    }

    public function test_a_null_next_question_means_the_quiz_is_complete(): void
    {
        Http::fake(['quizzes.cnstrc.com/*' => Http::response([
            'next_question' => null,
            'quiz_version_id' => 'version-1',
            'quiz_session_id' => 'session-1',
            'quiz_id' => 'grill-finder',
        ])]);

        $step = $this->quizzes()->getNextQuestion('grill-finder', [[1], [2]]);

        $this->assertTrue($step->isComplete());
        $this->assertSame([], $step->options());
    }

    public function test_a_failed_request_is_unavailable_not_complete(): void
    {
        $this->spyOnLog();
        Http::fake(['quizzes.cnstrc.com/*' => Http::response(['message' => 'boom'], 500)]);

        $step = $this->quizzes()->getNextQuestion('grill-finder');

        $this->assertFalse($step->isAvailable());
        $this->assertFalse($step->isComplete(), 'An outage must not send shoppers to the results page');
    }

    public function test_failures_throw_when_configured(): void
    {
        $this->reconfigure(['constructor.throw_exceptions' => true]);
        Http::fake(['quizzes.cnstrc.com/*' => Http::response(['message' => 'not found'], 404)]);

        $this->expectException(ApiException::class);

        $this->quizzes()->getNextQuestion('missing-quiz');
    }

    public function test_results_send_answers_paging_and_filters_and_map_products(): void
    {
        Http::fake(['quizzes.cnstrc.com/*' => Http::response([
            'request' => ['request_id' => 'req-1'],
            'response' => [
                'results' => [['value' => 'Pro 780', 'data' => ['id' => 'TRG-780', 'price' => 999]]],
                'total_num_results' => 30,
                'facets' => [['name' => 'brand', 'type' => 'multiple', 'options' => [['value' => 'Traeger', 'count' => 3]]]],
            ],
            'filter_expression' => ['name' => 'fuel_type', 'value' => 'Pellet'],
            'result_id' => 'quiz-result-1',
            'quiz_id' => 'grill-finder',
            'quiz_version_id' => 'version-1',
            'quiz_session_id' => 'session-1',
        ])]);

        $results = $this->quizzes()->getResults('grill-finder', [[2], [5, 6]], ['brand' => 'Traeger'], [
            'quiz_session_id' => 'session-1',
            'page' => 2,
            'per_page' => 12,
            'range_filters' => ['price' => ['max' => 1000]],
        ]);

        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);

            $this->assertSame('/v1/quizzes/grill-finder/results', $this->pathOf($request));
            $this->assertSame(['2', '5,6'], $query['a']);
            $this->assertSame(['12'], $query['num_results_per_page']);
            $this->assertSame(['2'], $query['page']);
            $this->assertSame(['Traeger'], $query['filters[brand]']);
            $this->assertSame(['-inf-1000'], $query['filters[price]']);
            $this->assertSame(['session-1'], $query['quiz_session_id']);

            return true;
        });

        $this->assertSame('TRG-780', $results->products[0]['id']);
        $this->assertSame(30, $results->total);
        $this->assertSame(3, $results->totalPages());
        $this->assertTrue($results->hasMore());
        $this->assertSame('quiz-result-1', $results->resultId);
        $this->assertSame('session-1', $results->quizSessionId);
        $this->assertSame('version-1', $results->quizVersionId);
        $this->assertSame(['name' => 'fuel_type', 'value' => 'Pellet'], $results->filterExpression);
        $this->assertArrayHasKey('brand', $results->facets);
        $this->assertSame('req-1', $results->metadata['request_id']);
    }

    public function test_results_also_read_the_top_level_shape_in_the_schema(): void
    {
        Http::fake(['quizzes.cnstrc.com/*' => Http::response([
            'results' => [['value' => 'Pro 780', 'data' => ['id' => 'TRG-780']]],
            'filter_expression' => null,
        ])]);

        $results = $this->quizzes()->getResults('grill-finder', [[2]]);

        $this->assertSame('TRG-780', $results->products[0]['id']);
        $this->assertSame(1, $results->total);
        $this->assertNull($results->filterExpression);
    }

    public function test_results_need_at_least_one_answer(): void
    {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->quizzes()->getResults('grill-finder', []);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_results_fall_back_to_empty_on_failure(): void
    {
        $this->spyOnLog();
        Http::fake(['quizzes.cnstrc.com/*' => Http::response([], 503)]);

        $results = $this->quizzes()->getResults('grill-finder', [[1]]);

        $this->assertTrue($results->isEmpty());
        $this->assertSame('grill-finder', $results->quizId);
    }

    public function test_results_config(): void
    {
        Http::fake(['quizzes.cnstrc.com/*' => Http::response([
            'results_config' => ['desktop' => ['title' => ['text' => 'Your grills', 'is_active' => true]]],
            'quiz_version_id' => 'version-1',
            'quiz_id' => 'grill-finder',
        ])]);

        $config = $this->quizzes()->getResultsConfig('grill-finder', ['quiz_version_id' => 'version-1']);

        $this->assertSame('Your grills', $config['results_config']['desktop']['title']['text']);
        Http::assertSent(fn (Request $request) => $this->pathOf($request) === '/v1/quizzes/grill-finder/results_config'
            && $this->queryOf($request)['quiz_version_id'] === ['version-1']);
    }

    public function test_unsupported_answer_values_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        QuizService::encodeAnswers([new \stdClass]);
    }

    public function test_the_quizzes_host_is_configurable_and_must_be_https(): void
    {
        $this->reconfigure(['constructor.quizzes_base_url' => 'https://quizzes.example.test/']);
        Http::fake(['quizzes.example.test/*' => Http::response($this->nextQuestionResponse())]);

        Constructor::quizzes()->getNextQuestion('grill-finder');

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://quizzes.example.test/v1/quizzes/'));

        $this->expectException(ConfigurationException::class);
        ConstructorConfig::fromArray(['quizzes_base_url' => 'http://quizzes.cnstrc.com']);
    }
}
