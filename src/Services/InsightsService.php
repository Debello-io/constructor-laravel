<?php

namespace ConstructorIO\Laravel\Services;

use ConstructorIO\Laravel\Concerns\HandlesStorefrontFailures;
use ConstructorIO\Laravel\Concerns\ReadsEventStreams;
use ConstructorIO\Laravel\DataTransferObjects\AgentInsight;
use ConstructorIO\Laravel\Exceptions\ApiException;
use ConstructorIO\Laravel\Exceptions\ConstructorException;
use ConstructorIO\Laravel\Exceptions\InvalidArgumentException;
use ConstructorIO\Laravel\Exceptions\RateLimitException;
use ConstructorIO\Laravel\Http\ApiClient;
use ConstructorIO\Laravel\Support\ConstructorConfig;
use ConstructorIO\Laravel\Support\ParameterBuilder;
use ConstructorIO\Laravel\Support\ResultTransformer;

/**
 * Agent Insights: AI answers placed inside the normal shopping journey,
 * served from agent.cnstrc.com.
 *
 *   searchOverview()   An AI overview above search results (search bar).
 *   chat()             One turn of a conversation with the shopping agent.
 *   answerQuestion()   An answer about one product, for product pages.
 *   recommendations()  A curated, explained recommendation set for a widget.
 *   refineListing()    Turns "only white" or "under $200" into filters on a
 *                      listing page, and suggests refinement chips.
 *
 * Every mode takes the same options and returns an AgentInsight. Each method
 * has a *Streaming() twin that hands you Constructor's events as they arrive,
 * so the answer can be shown while it is being written.
 *
 * Failures: searchOverview(), recommendations() and refineListing() decorate
 * a page, so like other storefront reads they log and return an
 * "unavailable" result (set CONSTRUCTOR_THROW_EXCEPTIONS=true to throw).
 * chat() and answerQuestion() are what the shopper asked for, so they throw.
 * Constructor answers some inputs with 422 ("use traditional search"); that
 * is a normal outcome, returned as a "declined" result and never logged.
 *
 * Options shared by every mode:
 *   page_context           array   Where the shopper is. Friendly keys:
 *                                  page_type (home|plp|pdp|collection|email_campaign|cart),
 *                                  item_id, variation_id, search_term,
 *                                  group_id or collection_id (the browse filter),
 *                                  filters (['brand' => ['Weber']]),
 *                                  range_filters (['price' => ['min' => 100, 'max' => 500]]),
 *                                  filter_match_types (['brand' => 'all']).
 *                                  Constructor's own shape (browse_filter, a list
 *                                  of filter expressions) is accepted as well.
 *   thread_id              string  Continue a conversation.
 *   guard                  bool    Screen the shopper's input (default: agent.guard).
 *   num_result_events      int     Most product sets to return.
 *   num_results_per_event  int     Most products per set.
 *   fmt_options, hidden_fields     Item fields to return.
 *   qs                     array   Parameters forwarded to the search, browse and
 *                                  recommendations calls the agent makes, e.g.
 *                                  ['sort_by' => 'price']. Sent as JSON.
 *   user_segments, features, feature_variants, parameters, plus the
 *   identity overrides (user_id, client_id, session_id).
 *
 * @see https://docs.constructor.com/reference/v1-asa-retrieve-agent-insights-search
 * @see https://docs.constructor.com/reference/v1-asa-retrieve-agent-insights-chat
 * @see https://docs.constructor.com/reference/v1-asa-retrieve-agent-insights-qna
 * @see https://docs.constructor.com/reference/v1-asa-retrieve-agent-insights-recommendations
 * @see https://docs.constructor.com/reference/v1-asa-retrieve-agent-insights-query-refinement
 */
class InsightsService
{
    use HandlesStorefrontFailures;
    use ReadsEventStreams;

    public const MODE_SEARCH = 'search';

    public const MODE_CHAT = 'chat';

    public const MODE_QNA = 'qna';

    public const MODE_RECOMMENDATIONS = 'recommendations';

    public const MODE_QUERY_REFINEMENT = 'query_refinement';

    /** The page types Constructor accepts in page_context. */
    public const PAGE_TYPES = ['home', 'plp', 'pdp', 'collection', 'email_campaign', 'cart'];

    /** Constructor's limit for recommendations' default_title. */
    private const MAX_DEFAULT_TITLE_LENGTH = 100;

    /** Constructor's limit for a refined listing's search term. */
    private const MAX_LISTING_SEARCH_TERM_LENGTH = 256;

    protected readonly ConstructorConfig $config;

    public function __construct(
        protected readonly ApiClient $api,
        protected readonly ResultTransformer $transformer,
    ) {
        $this->config = $api->config();
    }

    /*
    |--------------------------------------------------------------------------
    | Search bar AI overview
    |--------------------------------------------------------------------------
    */

    /**
     * A short AI overview of what the catalog offers for a search, with the
     * few products that answer it best, for display above search results.
     *
     *     $overview = Constructor::insights()->searchOverview($query, ['page_context' => ['page_type' => 'plp']]);
     *
     * Constructor declines queries that traditional search serves better
     * (isDeclined()). It uses the index's `searchbar` domain. Leave
     * num_result_events unset unless you mean to drop product sets.
     *
     * @param  array<string, mixed>  $options  See the class docblock. page_context.page_type defaults to home.
     */
    public function searchOverview(string $query, array $options = []): AgentInsight
    {
        $query = trim($query);
        if ($query === '') {
            return AgentInsight::unavailable(self::MODE_SEARCH);
        }

        $body = $this->body($query, $this->pageContext($options['page_context'] ?? []));

        return $this->attempt(
            'searchOverview',
            fn () => $this->fetch(self::MODE_SEARCH, $body, $options),
            fn () => AgentInsight::unavailable(self::MODE_SEARCH),
        );
    }

    /**
     * Stream a search overview. Returns false when Constructor declined the
     * query (render traditional results instead). See chatStreaming() for
     * how events are delivered.
     *
     * @param  callable(string, array<string, mixed>): mixed  $onEvent
     * @param  array<string, mixed>  $options
     *
     * @throws ConstructorException
     */
    public function searchOverviewStreaming(string $query, callable $onEvent, array $options = []): bool
    {
        $body = $this->body($this->requireText($query, 'query'), $this->pageContext($options['page_context'] ?? []));

        return $this->stream(self::MODE_SEARCH, $body, $onEvent, $options);
    }

    /*
    |--------------------------------------------------------------------------
    | Conversational agent
    |--------------------------------------------------------------------------
    */

    /**
     * One turn of a conversation with the shopping agent.
     *
     *     $reply = Constructor::insights()->chat('A smoker for a small patio');
     *     $next = Constructor::insights()->chat('Electric instead', ['thread_id' => $reply->threadId]);
     *
     * Extra options:
     *   domain  string  The conversational domain configured for your index.
     *                   Defaults to CONSTRUCTOR_AGENT_DOMAIN.
     *   prices  list    Prices that apply to this shopper for this request
     *                   only (member or quantity prices): request_context.prices.
     *                   Only accepted where Constructor has enabled request context.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws ConstructorException
     */
    public function chat(string $message, array $options = []): AgentInsight
    {
        return $this->fetch(self::MODE_CHAT, $this->chatBody($message, $options), $options);
    }

    /**
     * Stream one chat turn.
     *
     * $onEvent receives Constructor's event names and data as they arrive:
     * start, message (`text`), item_results (with a ready-made `products`
     * list added), follow_up_refinement, article_reference, image_meta,
     * recipe_info, recipe_instructions and end, plus events added to the API
     * later. A server_error event is passed on and then thrown.
     *
     * Returns false when Constructor declined the input (HTTP 422).
     *
     * @param  callable(string, array<string, mixed>): mixed  $onEvent
     * @param  array<string, mixed>  $options  As chat().
     *
     * @throws ConstructorException
     */
    public function chatStreaming(string $message, callable $onEvent, array $options = []): bool
    {
        return $this->stream(self::MODE_CHAT, $this->chatBody($message, $options), $onEvent, $options);
    }

    /*
    |--------------------------------------------------------------------------
    | Product questions
    |--------------------------------------------------------------------------
    */

    /**
     * Answer a shopper's question about one product, from that product's own
     * data (attributes, description and, where ingested, reviews).
     *
     *     $answer = Constructor::insights()->answerQuestion('Does it fit a 20 lb tank?', 'WEB-SPIRIT-E325');
     *
     * Extra option: prices (see chat()). page_context.page_type defaults to pdp.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws ConstructorException
     */
    public function answerQuestion(string $question, string $itemId, array $options = []): AgentInsight
    {
        return $this->fetch(self::MODE_QNA, $this->questionBody($question, $itemId, $options), $options);
    }

    /**
     * Stream an answer about one product. See chatStreaming() for the events.
     *
     * @param  callable(string, array<string, mixed>): mixed  $onEvent
     * @param  array<string, mixed>  $options  As answerQuestion().
     *
     * @throws ConstructorException
     */
    public function answerQuestionStreaming(string $question, string $itemId, callable $onEvent, array $options = []): bool
    {
        return $this->stream(self::MODE_QNA, $this->questionBody($question, $itemId, $options), $onEvent, $options);
    }

    /*
    |--------------------------------------------------------------------------
    | Recommendations
    |--------------------------------------------------------------------------
    */

    /**
     * A curated recommendation set around one product, with an AI-written
     * title, an explanation and refinement options for the widget.
     *
     *     $set = Constructor::insights()->recommendations('WEB-SPIRIT-E325', 'complementary_pod');
     *
     * $strategy is the routing rule configured for your index key (for
     * example `complementary_pod`), not a Recommendations API pod ID.
     *
     * Extra options:
     *   refinement     string  The option the shopper picked from a previous set.
     *   default_title  string  Title for the non-personalized default set (max 100 characters).
     *
     * @param  array<string, mixed>  $options
     */
    public function recommendations(string $itemId, string $strategy, array $options = []): AgentInsight
    {
        $body = $this->recommendationsBody($itemId, $strategy, $options);

        return $this->attempt(
            'insights recommendations',
            fn () => $this->fetch(self::MODE_RECOMMENDATIONS, $body, $options),
            fn () => AgentInsight::unavailable(self::MODE_RECOMMENDATIONS),
        );
    }

    /**
     * Stream a recommendation set. See chatStreaming() for the events.
     *
     * @param  callable(string, array<string, mixed>): mixed  $onEvent
     * @param  array<string, mixed>  $options  As recommendations().
     *
     * @throws ConstructorException
     */
    public function recommendationsStreaming(string $itemId, string $strategy, callable $onEvent, array $options = []): bool
    {
        return $this->stream(self::MODE_RECOMMENDATIONS, $this->recommendationsBody($itemId, $strategy, $options), $onEvent, $options);
    }

    /*
    |--------------------------------------------------------------------------
    | Listing refinement
    |--------------------------------------------------------------------------
    */

    /**
     * Refine a product listing from what the shopper typed ("only white",
     * "under $200"), and suggest refinement chips.
     *
     *     $refined = Constructor::insights()->refineListing(['search_term' => 'grill'], 'under $500');
     *     $refined->followUpChoices();   // chips: ['label' => 'Pellet', 'filter' => [...]]
     *     $refined->refinedListing();    // send this as $listing next time
     *
     * $listing describes the listing page: search_term (a search results
     * page), group_id (a category page) or both, plus its filters. Pass the
     * previous answer's refinedListing() back, with the same thread_id: the
     * service keeps no filter state. Leave $refinement null when the page
     * opens, after a chip is applied and after the shopper changes filters.
     * Choices follow the shopper's history, so never share them between shoppers.
     *
     * @param  array<string, mixed>  $listing
     * @param  array<string, mixed>  $options  See the class docblock (page_context comes from $listing).
     */
    public function refineListing(array $listing, ?string $refinement = null, array $options = []): AgentInsight
    {
        $body = $this->refinementBody($listing, $refinement);

        return $this->attempt(
            'refineListing',
            fn () => $this->fetch(self::MODE_QUERY_REFINEMENT, $body, $options),
            fn () => AgentInsight::unavailable(self::MODE_QUERY_REFINEMENT),
        );
    }

    /**
     * Stream a listing refinement. See chatStreaming() for the events.
     *
     * @param  array<string, mixed>  $listing
     * @param  callable(string, array<string, mixed>): mixed  $onEvent
     * @param  array<string, mixed>  $options
     *
     * @throws ConstructorException
     */
    public function refineListingStreaming(array $listing, ?string $refinement, callable $onEvent, array $options = []): bool
    {
        return $this->stream(self::MODE_QUERY_REFINEMENT, $this->refinementBody($listing, $refinement), $onEvent, $options);
    }

    /*
    |--------------------------------------------------------------------------
    | Request bodies
    |--------------------------------------------------------------------------
    | Built (and validated) before any request is sent: a missing item ID or
    | strategy is a coding mistake, so it throws even when failures are logged.
    */

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function chatBody(string $message, array $options): array
    {
        return $this->body(
            $this->requireText($message, 'message'),
            $this->pageContext($options['page_context'] ?? []),
            ['domain' => (string) ($options['domain'] ?? $this->config->requireAgentDomain())],
            $this->requestContext($options),
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function questionBody(string $question, string $itemId, array $options): array
    {
        $context = $this->itemPageContext($itemId, $options);

        return $this->body($this->requireText($question, 'question'), $context, [], $this->requestContext($options));
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function recommendationsBody(string $itemId, string $strategy, array $options): array
    {
        $context = $this->itemPageContext($itemId, $options);

        $parameters = ['strategy' => $this->requireText($strategy, 'strategy')];

        if (isset($options['default_title'])) {
            $title = (string) $options['default_title'];
            if (mb_strlen($title) > self::MAX_DEFAULT_TITLE_LENGTH) {
                throw new InvalidArgumentException('default_title can be at most '.self::MAX_DEFAULT_TITLE_LENGTH.' characters.');
            }
            $parameters['default_title'] = $title;
        }

        $refinement = trim((string) ($options['refinement'] ?? ''));

        return $this->body($refinement === '' ? null : $refinement, $context, $parameters);
    }

    /**
     * page_context for the modes about one product (qna, recommendations):
     * the product is required, and the page defaults to its product page.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function itemPageContext(string $itemId, array $options): array
    {
        $context = $options['page_context'] ?? [];
        if (! is_array($context)) {
            throw new InvalidArgumentException('page_context must be an array.');
        }

        $context['item_id'] = $this->requireText($itemId, 'item ID');

        return $this->pageContext($context, 'pdp');
    }

    /**
     * @param  array<string, mixed>  $listing
     * @return array<string, mixed>
     */
    private function refinementBody(array $listing, ?string $refinement): array
    {
        if (isset($listing['page_type']) && $listing['page_type'] !== 'plp') {
            throw new InvalidArgumentException('refineListing() refines listing pages: page_type must be plp.');
        }

        $context = $this->pageContext($listing, 'plp');

        if (! isset($context['search_term']) && ($context['browse_filter']['name'] ?? null) !== 'group_id') {
            throw new InvalidArgumentException('refineListing() needs the listing\'s search_term, its group_id, or both.');
        }

        if (mb_strlen((string) ($context['search_term'] ?? '')) > self::MAX_LISTING_SEARCH_TERM_LENGTH) {
            throw new InvalidArgumentException('A listing search_term can be at most '.self::MAX_LISTING_SEARCH_TERM_LENGTH.' characters.');
        }

        $refinement = trim((string) $refinement);

        return $this->body($refinement === '' ? null : $refinement, $context);
    }

    /**
     * The request body every mode shares. Empty parts are left out.
     *
     * @param  array<string, mixed>  $pageContext
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>|null  $requestContext
     * @return array<string, mixed>
     */
    private function body(?string $shopperInput, array $pageContext, array $parameters = [], ?array $requestContext = null): array
    {
        return array_filter([
            'shopper_input' => $shopperInput,
            'page_context' => $pageContext,
            'parameters' => $parameters === [] ? null : $parameters,
            'request_context' => $requestContext,
        ], fn ($value) => $value !== null);
    }

    /**
     * Build Constructor's page_context from friendly keys, or pass its own
     * shape through.
     *
     * @param  mixed  $context  See the class docblock.
     * @return array<string, mixed>
     */
    private function pageContext(mixed $context, string $defaultPageType = 'home'): array
    {
        if (! is_array($context)) {
            throw new InvalidArgumentException('page_context must be an array.');
        }

        $pageType = (string) ($context['page_type'] ?? $defaultPageType);
        if (! in_array($pageType, self::PAGE_TYPES, true)) {
            throw new InvalidArgumentException(sprintf('page_type must be one of %s.', implode(', ', self::PAGE_TYPES)));
        }

        $pageContext = ['page_type' => $pageType];

        foreach (['item_id', 'variation_id', 'search_term'] as $key) {
            if (isset($context[$key]) && is_scalar($context[$key]) && (string) $context[$key] !== '') {
                $pageContext[$key] = (string) $context[$key];
            }
        }

        if ($pageType === 'pdp' && ! isset($pageContext['item_id'])) {
            throw new InvalidArgumentException('A pdp page_context needs an item_id.');
        }

        $browseFilter = $this->browseFilter($context);
        if ($browseFilter !== null) {
            $pageContext['browse_filter'] = $browseFilter;
        }

        $filters = $this->filterExpressions($context);
        if ($filters !== []) {
            $pageContext['filters'] = $filters;
        }

        return $pageContext;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{name: string, value: string}|null
     */
    private function browseFilter(array $context): ?array
    {
        $filter = $context['browse_filter'] ?? null;
        if (is_array($filter) && isset($filter['name'], $filter['value'])) {
            return ['name' => (string) $filter['name'], 'value' => (string) $filter['value']];
        }

        foreach (['group_id', 'collection_id'] as $name) {
            if (isset($context[$name]) && is_scalar($context[$name]) && (string) $context[$name] !== '') {
                return ['name' => $name, 'value' => (string) $context[$name]];
            }
        }

        return null;
    }

    /**
     * Filter expressions: [{name, value: [...], match_type?} | {name, range: [min, max]}].
     *
     * A list is taken to be Constructor's own expressions (for example a
     * refinedListing() sent back) and passed through. A map is the package's
     * usual ['brand' => ['Weber', 'Traeger']] form.
     *
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    private function filterExpressions(array $context): array
    {
        $filters = $context['filters'] ?? [];
        if (! is_array($filters)) {
            return [];
        }

        if (array_is_list($filters)) {
            return array_values(array_filter($filters, fn ($expression) => is_array($expression) && isset($expression['name'])));
        }

        $matchTypes = is_array($context['filter_match_types'] ?? null) ? $context['filter_match_types'] : [];
        $expressions = [];

        foreach (ParameterBuilder::filters($filters) as $name => $values) {
            $expression = ['name' => (string) $name, 'value' => $values];
            if (isset($matchTypes[$name])) {
                $expression['match_type'] = (string) $matchTypes[$name];
            }
            $expressions[] = $expression;
        }

        $ranges = is_array($context['range_filters'] ?? null) ? $context['range_filters'] : [];
        foreach ($ranges as $name => $range) {
            $min = is_array($range) ? ($range['min'] ?? null) : null;
            $max = is_array($range) ? ($range['max'] ?? null) : null;

            if ($min !== null || $max !== null) {
                $expressions[] = ['name' => (string) $name, 'range' => [(string) ($min ?? '-inf'), (string) ($max ?? 'inf')]];
            }
        }

        return $expressions;
    }

    /**
     * request_context for chat and qna: prices that apply to this shopper
     * for this request only.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>|null
     */
    private function requestContext(array $options): ?array
    {
        if (isset($options['request_context']) && is_array($options['request_context'])) {
            return $options['request_context'];
        }

        return isset($options['prices']) && is_array($options['prices'])
            ? ['prices' => array_values($options['prices'])]
            : null;
    }

    /**
     * Query parameters every mode shares. Shopper identity is added by ApiClient.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function queryParameters(array $options): array
    {
        $formatOptions = is_array($options['fmt_options'] ?? null) ? $options['fmt_options'] : [];
        if (! empty($options['hidden_fields'])) {
            $formatOptions['hidden_fields'] = array_values((array) $options['hidden_fields']);
        }

        $segments = $options['user_segments'] ?? $options['us'] ?? null;

        $parameters = array_filter([
            'thread_id' => isset($options['thread_id']) ? (string) $options['thread_id'] : null,
            'guard' => (bool) ($options['guard'] ?? $this->config->agentGuard),
            // Only sent when asked for: Constructor's own defaults suit each mode.
            'num_result_events' => isset($options['num_result_events']) ? (int) $options['num_result_events'] : null,
            'num_results_per_event' => isset($options['num_results_per_event']) ? (int) $options['num_results_per_event'] : null,
            'fmt_options' => $formatOptions === [] ? null : $formatOptions,
            'qs' => empty($options['qs']) ? null : ParameterBuilder::json($options['qs']),
            'us' => empty($segments) ? null : array_values((array) $segments),
            'features' => is_array($options['features'] ?? null) ? $options['features'] : null,
            'feature_variants' => is_array($options['feature_variants'] ?? null) ? $options['feature_variants'] : null,
        ], fn ($value) => $value !== null && $value !== '');

        return array_merge($parameters, is_array($options['parameters'] ?? null) ? $options['parameters'] : []);
    }

    private function requireText(string $value, string $what): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException("The {$what} can't be empty.");
        }

        return $value;
    }

    /*
    |--------------------------------------------------------------------------
    | Sending
    |--------------------------------------------------------------------------
    */

    /**
     * Ask for the finished answer as one JSON document.
     *
     * Agent answers take longer than a search, so this uses stream_timeout.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $options
     *
     * @throws ConstructorException
     */
    private function fetch(string $mode, array $body, array $options): AgentInsight
    {
        try {
            $response = $this->api->postStorefront(
                '/v1/agent_insights/'.$mode,
                $this->queryParameters($options),
                ParameterBuilder::identity($options),
                $body,
                $this->config->agentBaseUrl,
                $this->config->streamTimeout,
            );
        } catch (ApiException $e) {
            if ($e->getStatusCode() === 422) {
                return AgentInsight::declined($mode);
            }

            throw $e;
        }

        return $this->toInsight($mode, $response);
    }

    /**
     * Stream an answer, passing each event to $onEvent. Returns false when
     * Constructor declined the input (422, sent before the stream opens).
     *
     * @param  array<string, mixed>  $body
     * @param  callable(string, array<string, mixed>): mixed  $onEvent
     * @param  array<string, mixed>  $options
     *
     * @throws ConstructorException
     */
    private function stream(string $mode, array $body, callable $onEvent, array $options): bool
    {
        $url = $this->api->storefrontUrl(
            $this->config->agentBaseUrl,
            '/v1/agent_insights/'.$mode,
            $this->queryParameters($options),
            ParameterBuilder::identity($options),
        );

        try {
            $stream = $this->api->openStorefrontStream($url, $body);
        } catch (ApiException $e) {
            if ($e->getStatusCode() === 422) {
                return false;
            }

            throw $e;
        }

        $this->readEventStream($stream, $this->config->streamTimeout, function (string $event, mixed $data) use ($onEvent) {
            $data = is_array($data) ? $data : ['value' => $data];

            if ($event === 'item_results') {
                $data['products'] = $this->itemSet($data)['products'];
            }

            $onEvent($event, $data);

            if ($event === 'server_error') {
                $status = is_numeric($data['status_code'] ?? null) ? (int) $data['status_code'] : 500;
                $exception = $status === 429 ? RateLimitException::class : ApiException::class;

                throw new $exception("Constructor agent insights error ({$status}).", $status, (string) json_encode($data));
            }
        });

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Response mapping
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $response
     */
    private function toInsight(string $mode, array $response): AgentInsight
    {
        $status = $response['status'] ?? null;

        return new AgentInsight(
            mode: $mode,
            status: $status === AgentInsight::STATUS_PARTIAL ? AgentInsight::STATUS_PARTIAL : AgentInsight::STATUS_COMPLETE,
            resultId: self::text($response['result_id'] ?? null),
            threadId: self::text($response['thread_id'] ?? null),
            message: self::text($response['message'] ?? null) ?? '',
            route: self::text($response['route'] ?? null),
            itemResults: array_map(fn (array $set) => $this->itemSet($set), self::arrays($response['item_results'] ?? null)),
            articles: array_map(fn (array $article) => [
                'url' => self::text($article['source'] ?? null),
                'title' => self::text($article['title'] ?? null) ?? '',
                'image_url' => self::text($article['image_url'] ?? null),
                'abstract' => self::text($article['abstract'] ?? null),
            ], self::arrays($response['article_references'] ?? null)),
            images: self::arrays($response['images'] ?? null),
            recipes: self::arrays($response['recipes'] ?? null),
            followUp: $this->followUp($response['follow_up_refinement'] ?? null),
            raw: $response,
        );
    }

    /**
     * One set of products. Each product is tagged with the result ID of the
     * Constructor response it came from, which click tracking needs.
     *
     * @param  array<string, mixed>  $set
     * @return array{result_id: string|null, title: string|null, text: string|null, request: array<string, mixed>|null, products: list<array<string, mixed>>, listing: array<string, mixed>|null}
     */
    private function itemSet(array $set): array
    {
        $response = is_array($set['response'] ?? null) ? $set['response'] : [];
        $resultId = self::text($response['result_id'] ?? null) ?? self::text($set['result_id'] ?? null);

        return [
            'result_id' => $resultId,
            'title' => self::text($set['title'] ?? null),
            'text' => self::text($set['text'] ?? null),
            'request' => is_array($set['request'] ?? null) ? $set['request'] : null,
            'products' => array_map(
                fn (array $product) => $product + ['result_id' => $resultId],
                $this->transformer->products(self::arrays($response['results'] ?? null)),
            ),
            'listing' => is_array($set['listing'] ?? null) ? $set['listing'] : null,
        ];
    }

    /**
     * @return array{question: string|null, options: list<string>, choices: list<array<string, mixed>>}|null
     */
    private function followUp(mixed $refinement): ?array
    {
        if (! is_array($refinement)) {
            return null;
        }

        $options = is_array($refinement['options'] ?? null) ? $refinement['options'] : [];

        return [
            'question' => self::text($refinement['question'] ?? null),
            'options' => array_values(array_map('strval', array_filter($options, 'is_scalar'))),
            'choices' => self::arrays($refinement['choices'] ?? null),
        ];
    }

    /**
     * A scalar from the response as a string; anything else as null.
     */
    private static function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * The array entries of a list from the response; anything else is dropped.
     *
     * @return list<array<string, mixed>>
     */
    private static function arrays(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}
