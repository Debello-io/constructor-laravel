<?php

namespace ConstructorIO\Laravel\Services;

use ConstructorIO\Laravel\Concerns\HandlesStorefrontFailures;
use ConstructorIO\Laravel\Exceptions\ApiException;
use ConstructorIO\Laravel\Exceptions\ConnectionException;
use ConstructorIO\Laravel\Exceptions\ConstructorException;
use ConstructorIO\Laravel\Exceptions\RateLimitException;
use ConstructorIO\Laravel\Http\ApiClient;
use ConstructorIO\Laravel\Http\Path;
use ConstructorIO\Laravel\Support\ConstructorConfig;
use ConstructorIO\Laravel\Support\ParameterBuilder;
use ConstructorIO\Laravel\Support\ResultTransformer;
use ConstructorIO\Laravel\Support\ServerSentEventParser;
use Illuminate\Support\Carbon;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use SensitiveParameter;

/**
 * Constructor's AI agents, served from agent.cnstrc.com.
 *
 * AI Shopping Agent (ASA): the shopper describes what they need in their own
 * words ("a smoker for a small patio under $500") and the agent replies with
 * text and batches of products. Conversations continue with a thread ID.
 *
 * Product Insights Agent (PIA): suggested questions and AI answers about one
 * product, for product detail pages.
 *
 * The Shopping Agent only speaks Server-Sent Events. askShoppingAgent()
 * waits for the whole stream and returns the result; askShoppingAgentStreaming()
 * hands you each event as it arrives so you can forward it to the browser.
 *
 * Agent calls throw ConstructorException on failure, so your UI can show an
 * error message. getProductQuestions() is the exception: it is decoration on a
 * product page, so by default it returns no questions instead of throwing.
 *
 * @see https://docs.constructor.com/reference/v1-asa-retrieve-intent
 * @see https://docs.constructor.com/reference/v1-asa-retrieve-item-questions
 * @see https://docs.constructor.com/reference/v1-asa-retrieve-item-questions-answer
 */
class AgentService
{
    use HandlesStorefrontFailures;

    /** Bytes to read from the stream at a time. */
    private const STREAM_CHUNK_SIZE = 8192;

    protected readonly ConstructorConfig $config;

    public function __construct(
        protected readonly ApiClient $api,
        protected readonly ResultTransformer $transformer,
    ) {
        $this->config = $api->config();
    }

    /*
    |--------------------------------------------------------------------------
    | AI Shopping Agent
    |--------------------------------------------------------------------------
    */

    /**
     * Ask the Shopping Agent and wait for its complete answer.
     *
     *     $reply = Constructor::agent()->askShoppingAgent('A gift for someone who loves smoking brisket');
     *     $followUp = Constructor::agent()->askShoppingAgent('Under $200 please', $reply['thread_id']);
     *
     * Options:
     *   guard                  bool         Screen the shopper's input (default from config)
     *   num_result_events      int          Maximum product batches
     *   num_results_per_event  int          Maximum products per batch
     *   pre_filter_expression  array|string Limit products, e.g. ['name' => 'in_stock', 'value' => 'True']
     *   fmt_options, hidden_fields, user_segments, parameters
     *   user_id, client_id, session_id   Identity overrides (see RequestContext)
     *
     * @param  array<string, mixed>  $options
     * @return array{
     *     message: string,
     *     products: list<array<string, mixed>>,
     *     thread_id: string|null,
     *     intent_result_id: string|null,
     *     result_ids: list<string>,
     *     follow_up_questions: list<string>,
     *     refinements: list<array{question: string, options: list<string>}>,
     *     search_requests: list<array<string, mixed>>,
     *     alternative_search_requests: list<array<string, mixed>>,
     *     articles: list<array<string, mixed>>,
     *     images: array<string, array<string, mixed>>,
     *     recipe: array<string, mixed>|null,
     *     route: string|null,
     *     _raw: list<array{event: string, data: mixed}>
     * }
     *
     * @throws ConstructorException
     */
    public function askShoppingAgent(#[SensitiveParameter] string $query, ?string $threadId = null, array $options = []): array
    {
        return $this->askShoppingAgentStreaming($query, static fn () => null, $threadId, $options);
    }

    /**
     * Ask the Shopping Agent and receive each event as it arrives.
     *
     * $onEvent is called as fn (string $event, array $data). Events, in order:
     *
     *   start              ['thread_id', 'intent_result_id']
     *   message            ['text' (this chunk), 'accumulated' (all text so far), 'route']
     *   products           ['products', 'result_id', 'search_request', 'alternative_search_requests']
     *   follow_up          ['questions' => [question], 'question', 'options']
     *   image_meta         Constructor's data: item_id, image_url, image_url_small
     *   article_reference  Constructor's data: source, title, image_url, abstract
     *   recipe_info        Constructor's data: recipe_title, prep/cook times, ingredients
     *   recipe_instructions Constructor's data: steps, text
     *   error              ['status_code', 'message'], immediately before an ApiException is thrown
     *   end                Constructor's data: intent_result_id, search_result_count
     *   complete           The final result (same shape as askShoppingAgent())
     *
     * Events this package doesn't know yet are passed through with
     * Constructor's event name and data, so new API features reach you
     * without an upgrade. Keep-alive "ping" events are not forwarded.
     *
     * @param  callable(string, array<string, mixed>): mixed  $onEvent
     * @param  array<string, mixed>  $options  Same as askShoppingAgent().
     * @return array<string, mixed> Same shape as askShoppingAgent().
     *
     * @throws ConstructorException
     */
    public function askShoppingAgentStreaming(#[SensitiveParameter] string $query, callable $onEvent, ?string $threadId = null, array $options = []): array
    {
        $options = $this->withLegacyOptionNames($options);

        $url = $this->api->storefrontUrl(
            $this->config->agentBaseUrl,
            '/v1/intent/'.Path::segment($query),
            $this->intentParameters($threadId, $options),
            ParameterBuilder::identity($options),
        );

        $result = $this->emptyShoppingAgentResult($threadId);

        $this->streamEvents($url, function (string $event, mixed $data) use (&$result, $onEvent) {
            $this->applyIntentEvent($event, is_array($data) ? $data : ['value' => $data], $result, $onEvent);
        });

        $onEvent('complete', $result);

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | Product Insights Agent
    |--------------------------------------------------------------------------
    */

    /**
     * Suggested questions shoppers might ask about a product.
     *
     * Returns ['questions' => []] if Constructor fails, so a product page
     * still renders. Set CONSTRUCTOR_THROW_EXCEPTIONS=true to throw instead.
     *
     * @param  array<string, mixed>  $options  num_results (default 5), variation_id, user_segments, identity overrides
     * @return array{questions: list<string>, _raw: array<string, mixed>}
     */
    public function getProductQuestions(string $itemId, array $options = []): array
    {
        $options = $this->withLegacyOptionNames($options);

        return $this->attempt('getProductQuestions', function () use ($itemId, $options) {
            $parameters = array_merge(
                array_filter([
                    'item_id' => $itemId,
                    'variation_id' => $options['variation_id'] ?? null,
                    'num_results' => (int) ($options['num_results'] ?? 5),
                ], fn ($value) => $value !== null && $value !== ''),
                ParameterBuilder::shared([], $options),
            );

            $response = $this->getJson('/v1/item_questions', $parameters, $options);

            return [
                'questions' => $this->questionTexts($response['questions'] ?? []),
                '_raw' => $response,
            ];
        }, fn () => ['questions' => [], '_raw' => []]);
    }

    /**
     * Ask a question about a product and get the AI's answer.
     *
     *     $answer = Constructor::agent()->askProductQuestion('Does this grill fit a 20 lb propane tank?', 'GRILL-123');
     *     echo $answer['answer'];
     *
     * @param  array<string, mixed>  $options  guard, num_results, variation_id, user_segments, identity overrides
     * @return array{
     *     answer: string,
     *     follow_up_questions: list<string>,
     *     item_results: list<array<string, mixed>>,
     *     thread_id: string|null,
     *     qna_result_id: string|null,
     *     _raw: array<string, mixed>
     * }
     *
     * @throws ConstructorException
     */
    public function askProductQuestion(#[SensitiveParameter] string $question, string $itemId, ?string $threadId = null, array $options = []): array
    {
        $options = $this->withLegacyOptionNames($options);

        $response = $this->getJson(
            '/v1/item_questions/'.Path::segment($question).'/answer',
            $this->answerParameters($itemId, $threadId, $options),
            $options,
        );

        return array_merge($this->emptyAnswerResult($threadId), [
            'answer' => (string) ($response['value'] ?? ''),
            'follow_up_questions' => $this->questionTexts($response['follow_up_questions'] ?? []),
            'item_results' => $this->transformer->products($response['item_results']['response']['results'] ?? []),
            'thread_id' => $response['thread_id'] ?? $threadId,
            'qna_result_id' => $response['qna_result_id'] ?? null,
            '_raw' => $response,
        ]);
    }

    /**
     * Ask a product question and receive the answer as it is written.
     *
     * $onEvent is called as fn (string $event, array $data) with:
     *
     *   start                ['thread_id', 'qna_result_id']
     *   message              ['text' (this chunk), 'accumulated']
     *   follow_up_questions  ['questions' => list<string>]
     *   item_results         ['products' => list of alternative / complementary products]
     *   error                ['status_code', 'message'], before an ApiException is thrown
     *   end                  Constructor's data
     *   complete             The final result (same shape as askProductQuestion())
     *
     * @param  callable(string, array<string, mixed>): mixed  $onEvent
     * @param  array<string, mixed>  $options  Same as askProductQuestion().
     * @return array<string, mixed> Same shape as askProductQuestion(), with `_raw` holding the events.
     *
     * @throws ConstructorException
     */
    public function askProductQuestionStreaming(#[SensitiveParameter] string $question, string $itemId, callable $onEvent, ?string $threadId = null, array $options = []): array
    {
        $options = $this->withLegacyOptionNames($options);

        $url = $this->api->storefrontUrl(
            $this->config->agentBaseUrl,
            '/v1/item_questions/'.Path::segment($question).'/answer',
            $this->answerParameters($itemId, $threadId, $options),
            ParameterBuilder::identity($options),
        );

        $result = $this->emptyAnswerResult($threadId);

        $this->streamEvents($url, function (string $event, mixed $data) use (&$result, $onEvent) {
            $this->applyAnswerEvent($event, is_array($data) ? $data : ['value' => $data], $result, $onEvent);
        });

        $onEvent('complete', $result);

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | Streaming
    |--------------------------------------------------------------------------
    */

    /**
     * Open an SSE stream and call $handle(event, data) for every event.
     *
     * The body is read in chunks rather than buffered, so events reach the
     * caller as soon as Constructor sends them. Keep-alive "ping" events are
     * dropped here.
     *
     * Two guarantees a caller can rely on:
     *   - The whole response must finish within stream_timeout seconds.
     *     Pings don't extend it: a stream that only ever sends keep-alives
     *     would otherwise hold the PHP worker forever.
     *   - A stream that ends before Constructor's `end` event is a failure,
     *     not a short answer. It throws instead of returning partial text.
     *
     * @param  callable(string, mixed): void  $handle
     *
     * @throws ConnectionException|ApiException
     */
    private function streamEvents(#[SensitiveParameter] string $url, callable $handle): void
    {
        $body = $this->api->openStorefrontStream($url);
        $parser = new ServerSentEventParser;
        $deadline = Carbon::now()->addSeconds($this->config->streamTimeout);
        $receivedEnd = false;

        $dispatch = function (array $events) use ($handle, &$receivedEnd): void {
            foreach ($events as $event) {
                if ($event['event'] === 'ping') {
                    continue;
                }

                $receivedEnd = $receivedEnd || $event['event'] === 'end';
                $handle($event['event'], $event['data']);
            }
        };

        try {
            while (! $body->eof()) {
                if (Carbon::now()->greaterThan($deadline)) {
                    throw new ConnectionException(
                        "The Constructor agent response exceeded {$this->config->streamTimeout} seconds (CONSTRUCTOR_STREAM_TIMEOUT)."
                    );
                }

                $dispatch($parser->feed($this->readChunk($body)));
            }

            $endedMidEvent = $parser->hasPartialEvent();
            $dispatch($parser->flush());

            if (! $receivedEnd || $endedMidEvent) {
                throw new ConnectionException('The Constructor agent stream ended before the response was complete.');
            }
        } finally {
            $body->close();
        }
    }

    /**
     * Read the next chunk of a stream.
     *
     * Guzzle throws a plain RuntimeException when the connection drops or
     * times out mid-stream; report it as a ConnectionException.
     *
     * @throws ConnectionException
     */
    private function readChunk(StreamInterface $body): string
    {
        try {
            return $body->read(self::STREAM_CHUNK_SIZE);
        } catch (RuntimeException $e) {
            throw new ConnectionException('The Constructor agent stream was interrupted: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Fold one Product Insights Agent event into the result and notify the caller.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $result
     * @param  callable(string, array<string, mixed>): mixed  $onEvent
     */
    private function applyAnswerEvent(string $event, array $data, array &$result, callable $onEvent): void
    {
        $result['_raw'][] = ['event' => $event, 'data' => $data];
        $result['thread_id'] = $data['thread_id'] ?? $result['thread_id'];
        $result['qna_result_id'] = $data['qna_result_id'] ?? $result['qna_result_id'];

        switch ($event) {
            case 'start':
                $onEvent('start', ['thread_id' => $result['thread_id'], 'qna_result_id' => $result['qna_result_id']]);
                break;

            case 'message':
                $result['answer'] .= $data['text'] ?? '';
                $onEvent('message', ['text' => $data['text'] ?? '', 'accumulated' => $result['answer']]);
                break;

            case 'follow_up_questions':
                $questions = $this->questionTexts($data['questions'] ?? []);
                $result['follow_up_questions'] = array_merge($result['follow_up_questions'], $questions);
                $onEvent('follow_up_questions', ['questions' => $questions]);
                break;

            case 'item_results':
                $products = $this->transformer->products($data['response']['results'] ?? []);
                $result['item_results'] = array_merge($result['item_results'], $products);
                $onEvent('item_results', ['products' => $products]);
                break;

            case 'server_error':
                // failWithServerError() returns `never` (it always throws), so
                // there is deliberately no `break` falling into `default`.
                $this->failWithServerError($data, $onEvent);

            default:
                // "end" and any event added to the API after this package was written.
                $onEvent($event, $data);
        }
    }

    /**
     * Fold one Shopping Agent event into the result and notify the caller.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $result
     * @param  callable(string, array<string, mixed>): mixed  $onEvent
     */
    private function applyIntentEvent(string $event, array $data, array &$result, callable $onEvent): void
    {
        $result['_raw'][] = ['event' => $event, 'data' => $data];
        $result['thread_id'] = $data['thread_id'] ?? $result['thread_id'];
        $result['intent_result_id'] = $data['intent_result_id'] ?? $result['intent_result_id'];

        switch ($event) {
            case 'start':
                $onEvent('start', ['thread_id' => $result['thread_id'], 'intent_result_id' => $result['intent_result_id']]);
                break;

            case 'message':
                $result['message'] .= $data['text'] ?? '';
                // `route` applies to this message only, e.g. "customer_support".
                $result['route'] = $data['route'] ?? $result['route'];
                $onEvent('message', [
                    'text' => $data['text'] ?? '',
                    'accumulated' => $result['message'],
                    'route' => $data['route'] ?? null,
                ]);
                break;

            case 'search_result':
                $resultId = $data['result_id'] ?? null;
                $response = $data['response'] ?? [];

                // Tag each product with its batch's result_id, needed to track clicks.
                $products = array_map(
                    fn (array $product) => $product + ['result_id' => $resultId],
                    $this->transformer->products($response['results'] ?? []),
                );

                $result['products'] = array_merge($result['products'], $products);
                if ($resultId !== null) {
                    $result['result_ids'][] = $resultId;
                }
                if (isset($response['search_request'])) {
                    $result['search_requests'][] = $response['search_request'];
                }
                $result['alternative_search_requests'] = array_merge(
                    $result['alternative_search_requests'],
                    $response['alternative_search_requests'] ?? [],
                );

                $onEvent('products', [
                    'products' => $products,
                    'result_id' => $resultId,
                    'search_request' => $response['search_request'] ?? null,
                    'alternative_search_requests' => $response['alternative_search_requests'] ?? [],
                ]);
                break;

            case 'follow_up_refinement':
                $question = (string) ($data['question'] ?? '');
                $options = array_values($data['options'] ?? []);

                $result['follow_up_questions'][] = $question;
                $result['refinements'][] = ['question' => $question, 'options' => $options];
                $onEvent('follow_up', ['questions' => [$question], 'question' => $question, 'options' => $options]);
                break;

            case 'image_meta':
                if (isset($data['item_id'])) {
                    $result['images'][(string) $data['item_id']] = [
                        'image_url' => $data['image_url'] ?? null,
                        'image_url_small' => $data['image_url_small'] ?? null,
                    ];
                }
                $onEvent('image_meta', $data);
                break;

            case 'article_reference':
                $result['articles'][] = [
                    'url' => $data['source'] ?? null,
                    'title' => $data['title'] ?? '',
                    'image_url' => $data['image_url'] ?? null,
                    'abstract' => $data['abstract'] ?? null,
                ];
                $onEvent('article_reference', $data);
                break;

            case 'recipe_info':
                $result['recipe'] = array_merge($result['recipe'] ?? [], ['info' => $data]);
                $onEvent('recipe_info', $data);
                break;

            case 'recipe_instructions':
                $result['recipe'] = array_merge($result['recipe'] ?? [], [
                    'steps' => $data['steps'] ?? [],
                    'notes' => $data['text'] ?? null,
                ]);
                $onEvent('recipe_instructions', $data);
                break;

            case 'server_error':
                // failWithServerError() returns `never` (it always throws), so
                // there is deliberately no `break` falling into `default`.
                $this->failWithServerError($data, $onEvent);

            default:
                // "end" and any event added to the API after this package was written.
                $onEvent($event, $data);
        }
    }

    /**
     * A server_error event means the turn failed even though the HTTP status
     * was 200. Tell the caller, then throw.
     *
     * @param  array<string, mixed>  $data
     * @param  callable(string, array<string, mixed>): mixed  $onEvent
     *
     * @throws ApiException
     */
    private function failWithServerError(array $data, callable $onEvent): never
    {
        $status = (int) ($data['status_code'] ?? 500);
        $message = (string) ($data['message'] ?? 'Unknown error');

        $onEvent('error', ['status_code' => $status, 'message' => $message]);

        $exception = $status === 429 ? RateLimitException::class : ApiException::class;

        throw new $exception("Constructor agent error ({$status}): {$message}", $status, (string) json_encode($data));
    }

    /*
    |--------------------------------------------------------------------------
    | Request building
    |--------------------------------------------------------------------------
    */

    /**
     * GET a JSON endpoint on the agent host.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function getJson(#[SensitiveParameter] string $path, array $parameters, array $options): array
    {
        return $this->api->getStorefront($path, $parameters, ParameterBuilder::identity($options), $this->config->agentBaseUrl);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function intentParameters(?string $threadId, array $options): array
    {
        return array_merge(
            [
                'domain' => $this->config->requireAgentDomain(),
                'guard' => (bool) ($options['guard'] ?? $this->config->agentGuard),
                'num_result_events' => (int) ($options['num_result_events'] ?? $this->config->agentNumResultEvents),
                'num_results_per_event' => (int) ($options['num_results_per_event'] ?? $this->config->agentNumResultsPerEvent),
            ],
            array_filter(['thread_id' => $threadId]),
            ParameterBuilder::shared([], $options),
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function answerParameters(string $itemId, ?string $threadId, array $options): array
    {
        return array_merge(
            [
                'item_id' => $itemId,
                'guard' => (bool) ($options['guard'] ?? $this->config->agentGuard),
            ],
            array_filter([
                'thread_id' => $threadId,
                'variation_id' => $options['variation_id'] ?? null,
                'num_results' => isset($options['num_results']) ? (int) $options['num_results'] : null,
            ]),
            ParameterBuilder::shared([], $options),
        );
    }

    /**
     * Accept the option names 1.x used for the agent service.
     *
     *   filters      -> pre_filter_expression (the agent has no shopper-facing filters)
     *   session      -> session_id
     *   instance_id  -> client_id
     *   client       -> ignored: `c` always comes from config (CONSTRUCTOR_CLIENT_IDENTIFIER)
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function withLegacyOptionNames(array $options): array
    {
        if (isset($options['filters']) && ! isset($options['pre_filter_expression'])) {
            $options['pre_filter_expression'] = $options['filters'];
        }
        unset($options['filters']);

        $options['session_id'] ??= $options['session'] ?? null;
        $options['client_id'] ??= $options['instance_id'] ?? null;

        // 1.x let callers override `c`. The client identifier now always comes
        // from config, so reporting sees one consistent value.
        unset($options['session'], $options['instance_id'], $options['client']);

        return array_filter($options, fn ($value) => $value !== null);
    }

    /**
     * Questions come back as [{"value": "..."}]. Return plain strings.
     *
     * @param  array<int, mixed>  $questions
     * @return list<string>
     */
    private function questionTexts(array $questions): array
    {
        $texts = array_map(
            fn ($question) => is_array($question) ? (string) ($question['value'] ?? '') : (string) $question,
            $questions,
        );

        return array_values(array_filter($texts, fn (string $text) => $text !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyShoppingAgentResult(?string $threadId): array
    {
        return [
            'message' => '',
            'products' => [],
            'thread_id' => $threadId,
            'intent_result_id' => null,
            'result_ids' => [],
            'follow_up_questions' => [],
            'refinements' => [],
            'search_requests' => [],
            'alternative_search_requests' => [],
            'articles' => [],
            'images' => [],
            'recipe' => null,
            'route' => null,
            '_raw' => [],
        ];
    }

    /**
     * The Product Insights answer shape, shared by the JSON and streaming calls.
     *
     * @return array<string, mixed>
     */
    private function emptyAnswerResult(?string $threadId): array
    {
        return [
            'answer' => '',
            'follow_up_questions' => [],
            'item_results' => [],
            'thread_id' => $threadId,
            'qna_result_id' => null,
            '_raw' => [],
        ];
    }
}
