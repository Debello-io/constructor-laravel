<?php

namespace ConstructorIO\Laravel\DataTransferObjects;

/**
 * One Agent Insights answer, from InsightsService: an AI search overview, a
 * chat turn, a product answer, a curated recommendation set or a listing
 * refinement. Every mode returns the same shape.
 *
 *     $overview = Constructor::insights()->searchOverview('smoker for a small patio');
 *
 *     if ($overview->hasContent()) {
 *         $overview->message;      // the written overview
 *         $overview->products();   // the products it recommends
 *     }
 *
 * Status:
 *   complete     Constructor finished the answer.
 *   partial      The answer was cut short (the stream was truncated or hit
 *                a server error), so it may be incomplete.
 *   declined     Constructor judged the input better served by traditional
 *                search (HTTP 422). Show your normal results instead.
 *   unavailable  The request failed and failures are logged rather than
 *                thrown, or there was nothing to ask.
 *
 * For tracking, send $resultId with agent events (the JavaScript client's
 * tracker module), and each product's `result_id` with clicks on it.
 *
 * @see https://docs.constructor.com/reference/v1-asa-retrieve-agent-insights-search
 * @see https://docs.constructor.com/docs/products-ai-shopping-agents-learn-about-ai-shopping-agent-implement-ai-shopping-agent
 */
class AgentInsight
{
    public const STATUS_COMPLETE = 'complete';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_UNAVAILABLE = 'unavailable';

    /**
     * @param  string  $mode  search, chat, qna, recommendations or query_refinement.
     * @param  string  $status  One of the STATUS_* constants.
     * @param  string|null  $resultId  The agent insight result ID, for tracking.
     * @param  string|null  $threadId  Pass back as `thread_id` to continue the conversation.
     * @param  string  $message  The agent's text.
     * @param  string|null  $route  Where to send the shopper next, e.g. `customer_support`.
     * @param  list<array{result_id: string|null, title: string|null, text: string|null, request: array<string, mixed>|null, products: list<array<string, mixed>>, listing: array<string, mixed>|null}>  $itemResults
     *                                                                                                                                                                                                                 Each set of products, with the agent's title and explanation for it.
     * @param  list<array{url: string|null, title: string, image_url: string|null, abstract: string|null}>  $articles  Knowledge base articles the agent cited.
     * @param  list<array<string, mixed>>  $images  Images the agent referenced (`item_id`, `image_url`).
     * @param  list<array<string, mixed>>  $recipes  Recipes, for domains configured for them.
     * @param  array{question: string|null, options: list<string>, choices: list<array<string, mixed>>}|null  $followUp
     *                                                                                                                   The suggested next question or refinement chips.
     * @param  array<string, mixed>  $raw  The full response.
     */
    public function __construct(
        public readonly string $mode,
        public readonly string $status,
        public readonly ?string $resultId = null,
        public readonly ?string $threadId = null,
        public readonly string $message = '',
        public readonly ?string $route = null,
        public readonly array $itemResults = [],
        public readonly array $articles = [],
        public readonly array $images = [],
        public readonly array $recipes = [],
        public readonly ?array $followUp = null,
        public readonly array $raw = [],
    ) {}

    /**
     * Constructor declined the input (HTTP 422): render traditional results.
     */
    public static function declined(string $mode): self
    {
        return new self($mode, self::STATUS_DECLINED);
    }

    /**
     * Nothing to show: the request failed (and failures are logged), or
     * there was no input to answer.
     */
    public static function unavailable(string $mode): self
    {
        return new self($mode, self::STATUS_UNAVAILABLE);
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETE;
    }

    /**
     * The answer may be cut short. Show it, but don't rely on it being whole.
     */
    public function isPartial(): bool
    {
        return $this->status === self::STATUS_PARTIAL;
    }

    public function isDeclined(): bool
    {
        return $this->status === self::STATUS_DECLINED;
    }

    /**
     * True when there is something to render: text, products or a follow-up.
     */
    public function hasContent(): bool
    {
        return $this->message !== '' || $this->itemResults !== [] || $this->followUp !== null;
    }

    /**
     * Every product from every set, in order. Each carries the `result_id`
     * of the set it came from, for click tracking.
     *
     * @return list<array<string, mixed>>
     */
    public function products(): array
    {
        return array_merge([], ...array_column($this->itemResults, 'products'));
    }

    public function hasProducts(): bool
    {
        return $this->products() !== [];
    }

    /**
     * Answers to offer the shopper next, as plain text.
     *
     * @return list<string>
     */
    public function followUpOptions(): array
    {
        return $this->followUp['options'] ?? [];
    }

    /**
     * Refinement chips that each apply one filter value, as
     * ['label' => 'Black', 'filter' => ['name' => 'color', 'value' => ['black']]].
     *
     * @return list<array<string, mixed>>
     */
    public function followUpChoices(): array
    {
        return $this->followUp['choices'] ?? [];
    }

    /**
     * query_refinement only: the refined listing's page context. Send it as
     * the next request's listing (with the same thread ID); the service keeps
     * no filter state between requests.
     *
     * @return array<string, mixed>|null
     */
    public function refinedListing(): ?array
    {
        foreach ($this->itemResults as $set) {
            $pageContext = $set['listing']['page_context'] ?? null;
            if (is_array($pageContext)) {
                return $pageContext;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'status' => $this->status,
            'result_id' => $this->resultId,
            'thread_id' => $this->threadId,
            'message' => $this->message,
            'route' => $this->route,
            'item_results' => $this->itemResults,
            'articles' => $this->articles,
            'images' => $this->images,
            'recipes' => $this->recipes,
            'follow_up' => $this->followUp,
        ];
    }
}
