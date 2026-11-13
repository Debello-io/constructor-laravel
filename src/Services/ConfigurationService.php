<?php

namespace ConstructorIO\Laravel\Services;

use ConstructorIO\Laravel\Services\Configuration\FacetConfigurationService;
use ConstructorIO\Laravel\Services\Configuration\FacetOptionService;
use ConstructorIO\Laravel\Services\Configuration\OneWaySynonymService;
use ConstructorIO\Laravel\Services\Configuration\RedirectRuleService;
use ConstructorIO\Laravel\Services\Configuration\RefinedQueryService;
use ConstructorIO\Laravel\Services\Configuration\SortOptionService;
use ConstructorIO\Laravel\Services\Configuration\SynonymGroupService;

/**
 * Merchandising configuration managed from code instead of the dashboard:
 * redirect rules, synonyms, sort options, facets and searchandising rules.
 * Requires CONSTRUCTOR_API_TOKEN. Every call throws on failure.
 *
 *     $config = Constructor::configuration();
 *     $config->redirectRules()->create(['url' => '/gift-cards', 'matches' => [['pattern' => 'gift card', 'match_type' => 'PHRASE']]]);
 *     $config->synonymGroups()->create(['bbq', 'barbecue']);
 *     $config->facets()->update('brand', ['display_name' => 'Brand']);
 *
 * Each resource uses the same method names where the API allows: list(),
 * get() (null when not found), create(), replace(), update(), delete().
 */
class ConfigurationService
{
    public function __construct(
        protected readonly RedirectRuleService $redirectRules,
        protected readonly SynonymGroupService $synonymGroups,
        protected readonly OneWaySynonymService $oneWaySynonyms,
        protected readonly SortOptionService $sortOptions,
        protected readonly FacetConfigurationService $facets,
        protected readonly FacetOptionService $facetOptions,
        protected readonly RefinedQueryService $refinedQueries,
    ) {}

    /** Searches that send shoppers to a page instead of results. */
    public function redirectRules(): RedirectRuleService
    {
        return $this->redirectRules;
    }

    /** Two-way synonyms ("bbq" = "barbecue"). */
    public function synonymGroups(): SynonymGroupService
    {
        return $this->synonymGroups;
    }

    /** One-way synonyms ("smoker" also finds "pellet grill"). */
    public function oneWaySynonyms(): OneWaySynonymService
    {
        return $this->oneWaySynonyms;
    }

    /** Sort options on results pages. */
    public function sortOptions(): SortOptionService
    {
        return $this->sortOptions;
    }

    /** Facet (filter) configurations. */
    public function facets(): FacetConfigurationService
    {
        return $this->facets;
    }

    /** How individual facet values are displayed. */
    public function facetOptions(): FacetOptionService
    {
        return $this->facetOptions;
    }

    /** Searchandising rules for search queries: slot, boost, bury, hide. */
    public function refinedQueries(): RefinedQueryService
    {
        return $this->refinedQueries;
    }
}
