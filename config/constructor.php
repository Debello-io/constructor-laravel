<?php

/*
|--------------------------------------------------------------------------
| Constructor for Laravel
|--------------------------------------------------------------------------
|
| Every value can be set from your .env file. The comments explain what
| each one is for and link to the Constructor documentation that defines
| it, so you can check a value without leaving this file.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | Constructor uses three different credentials. They are NOT
    | interchangeable:
    |
    | api_key        Public. Identifies your index ("key_..."). Sent on every
    |                request as the `key` query parameter.
    |
    | api_token      Secret. Created in the dashboard under Integration >
    |                API Integration. Used ONLY for catalog management
    |                (uploads, items, tasks) and offline behavioral actions.
    |                Sent with HTTP Basic auth.
    |
    | backend_token  Secret. The "security token" Constructor gives you for a
    |                backend integration. Sent as the `x-cnstrc-token` header
    |                on search, browse, autocomplete, recommendation and agent
    |                requests. Without it, requests from your servers are
    |                likely to be throttled. Ask your Constructor contact.
    |
    | @see https://docs.constructor.com/reference/main-authentication
    | @see https://docs.constructor.com/docs/integrating-with-constructor-direct-api-integration-required-parameters
    |
    */

    'api_key' => env('CONSTRUCTOR_API_KEY'),

    'api_token' => env('CONSTRUCTOR_API_TOKEN'),

    'backend_token' => env('CONSTRUCTOR_BACKEND_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Client Identifier (`c` parameter)
    |--------------------------------------------------------------------------
    |
    | Identifies where requests come from in Constructor's reporting. The
    | documented format for a backend integration is:
    |
    |     cio-be-web-{company}     (website)
    |     cio-be-mobile-{company}  (server-side calls for a mobile app)
    |
    | Leave empty to generate "cio-be-web-{slug of APP_NAME}".
    |
    */

    'client_identifier' => env('CONSTRUCTOR_CLIENT_IDENTIFIER'),

    /*
    |--------------------------------------------------------------------------
    | API Hosts
    |--------------------------------------------------------------------------
    |
    | Only change these if your Constructor contact gives you different hosts.
    | Every host must be https (plain http is only accepted for localhost).
    |
    | search_base_url   Search, browse, autocomplete, recommendations,
    |                   catalog management and offline behavioral actions.
    | agent_base_url    AI Shopping Agent and Product Insights Agent.
    | quizzes_base_url  Quizzes (next question and results).
    | image_search_base_url
    |                   Image Search (search by photo).
    | catalog_batching_base_url
    |                   The Catalog Batching Service, for frequent small item
    |                   and variation updates such as price and stock.
    |
    | @see https://docs.constructor.com/reference/v1-quizzes-get-next-question
    | @see https://docs.constructor.com/reference/v2-image-search-retrieve-search-results
    | @see https://docs.constructor.com/reference/v2-batching-items-update-items
    |
    */

    'search_base_url' => env('CONSTRUCTOR_SEARCH_BASE_URL', 'https://ac.cnstrc.com'),

    'agent_base_url' => env('CONSTRUCTOR_AGENT_BASE_URL', 'https://agent.cnstrc.com'),

    'quizzes_base_url' => env('CONSTRUCTOR_QUIZZES_BASE_URL', 'https://quizzes.cnstrc.com'),

    'image_search_base_url' => env('CONSTRUCTOR_IMAGE_SEARCH_BASE_URL', 'https://image-search.cnstrc.com'),

    'catalog_batching_base_url' => env('CONSTRUCTOR_CATALOG_BATCHING_BASE_URL', 'https://batching.catalog.cnstrc.com'),

    /*
    |--------------------------------------------------------------------------
    | Default Section
    |--------------------------------------------------------------------------
    |
    | The index section used when a call does not specify one. Most catalogs
    | keep their products in "Products".
    |
    */

    'default_section' => env('CONSTRUCTOR_SECTION', 'Products'),

    /*
    |--------------------------------------------------------------------------
    | Shopper Identity
    |--------------------------------------------------------------------------
    |
    | Personalization and analytics depend on every server-side request
    | carrying the same identifiers as the Constructor beacon running in the
    | shopper's browser:
    |
    |   i   anonymous client ID, read from the `ConstructorioID_client_id` cookie
    |   s   session number, read from the `ConstructorioID_session_id` cookie
    |   ui  your own user ID, sent only for signed-in shoppers
    |
    | generate_client_id  When the beacon has not created the client ID cookie
    |                     yet (first page view), generate a UUID, send it, and
    |                     set the cookie so the beacon adopts the same value.
    |
    | send_user_id        Send the authenticated user's ID (auth()->id()) as
    |                     `ui`. Use Constructor::resolveUserIdUsing() to send a
    |                     different or hashed identifier instead.
    |
    | require_consent     Treat shoppers as NOT consenting until you register
    |                     Constructor::resolveConsentUsing(). Without consent,
    |                     `i` is omitted and no client ID cookie is set. Turn
    |                     this on for sites that need opt-in consent (e.g. GDPR).
    |                     `ui`, `s`, IP and user agent are still sent; Constructor's
    |                     documentation only requires omitting `i`.
    |
    | @see https://docs.constructor.com/docs/integrating-with-constructor-retrieving-results-integration-approaches-backend-integration-options
    |
    */

    'identity' => [
        'generate_client_id' => env('CONSTRUCTOR_GENERATE_CLIENT_ID', true),
        'send_user_id' => env('CONSTRUCTOR_SEND_USER_ID', true),
        'require_consent' => env('CONSTRUCTOR_REQUIRE_CONSENT', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Client
    |--------------------------------------------------------------------------
    |
    | timeout         Seconds to wait for a normal API response.
    | stream_timeout  Seconds to allow a streaming AI agent response to run.
    | retry_attempts  Total attempts for a request (1-5). Only connection
    |                 failures, 429 and 5xx responses are retried, and only for
    |                 reads. 4xx errors and catalog writes are never retried.
    | retry_delay     Milliseconds before the first retry. Doubles each time,
    |                 or follows Retry-After on a 429; never more than 5 seconds.
    |
    | The hosts below must use https: requests carry secret tokens.
    |
    */

    'timeout' => env('CONSTRUCTOR_TIMEOUT', 10),

    'stream_timeout' => env('CONSTRUCTOR_STREAM_TIMEOUT', 120),

    'retry_attempts' => env('CONSTRUCTOR_RETRY_ATTEMPTS', 2),

    'retry_delay' => env('CONSTRUCTOR_RETRY_DELAY', 100),

    /*
    |--------------------------------------------------------------------------
    | Error Handling
    |--------------------------------------------------------------------------
    |
    | By default, storefront reads (search, browse, autocomplete,
    | recommendations) log the failure and return an empty result, so a
    | Constructor outage degrades a page instead of breaking it.
    |
    | Set to true to throw ConstructorException instead, e.g. in tests or
    | when you want to render your own fallback.
    |
    | AI agent and catalog calls always throw.
    |
    */

    'throw_exceptions' => env('CONSTRUCTOR_THROW_EXCEPTIONS', false),

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Log channel for API failures. Null uses your default channel. Shopper
    | identifiers and credentials are never written to the log.
    |
    */

    'log_channel' => env('CONSTRUCTOR_LOG_CHANNEL'),

    /*
    |--------------------------------------------------------------------------
    | AI Shopping Agent & Product Insights Agent
    |--------------------------------------------------------------------------
    |
    | domain                 The agent domain configured for your index
    |                        (required for the Shopping Agent).
    | guard                  Ask Constructor to screen agent conversations for
    |                        harmful or off-topic content. The API default is
    |                        off; this package turns it on.
    | num_result_events      Maximum product batches in one Shopping Agent reply.
    | num_results_per_event  Maximum products per batch.
    |
    | @see https://docs.constructor.com/reference/v1-asa-retrieve-intent
    |
    */

    'agent' => [
        'domain' => env('CONSTRUCTOR_AGENT_DOMAIN'),
        'guard' => env('CONSTRUCTOR_AGENT_GUARD', true),
        'num_result_events' => env('CONSTRUCTOR_AGENT_NUM_RESULT_EVENTS', 5),
        'num_results_per_event' => env('CONSTRUCTOR_AGENT_NUM_RESULTS_PER_EVENT', 4),
    ],

    /*
    |--------------------------------------------------------------------------
    | Catalog Management
    |--------------------------------------------------------------------------
    |
    | Defaults for catalog uploads, item updates and task polling. These calls
    | authenticate with `api_token`.
    |
    | notification_email  Constructor emails this address if an upload fails.
    |                     Several addresses can be comma-separated.
    | force               Process uploads even if they would remove a large
    |                     share of the catalog (Constructor's safety check).
    | batch_size          Records sent per /v2/items, /v2/variations or
    |                     /v2/item_groups request (max 10,000).
    | upload_timeout      Seconds to allow a catalog file upload to take.
    | task_poll_interval  Seconds between task status checks.
    | task_timeout        Seconds to wait for a task before giving up.
    |
    | @see https://docs.constructor.com/reference/v1-catalog-create-or-replace-catalog
    |
    */

    'catalog' => [
        'notification_email' => env('CONSTRUCTOR_NOTIFICATION_EMAIL'),
        'force' => env('CONSTRUCTOR_FORCE_UPLOAD', false),
        'batch_size' => env('CONSTRUCTOR_CATALOG_BATCH_SIZE', 1000),
        'upload_timeout' => env('CONSTRUCTOR_UPLOAD_TIMEOUT', 300),
        'task_poll_interval' => env('CONSTRUCTOR_TASK_POLL_INTERVAL', 10),
        'task_timeout' => env('CONSTRUCTOR_TASK_TIMEOUT', 600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Laravel Scout
    |--------------------------------------------------------------------------
    |
    | Settings for the "constructor" Scout engine (SCOUT_DRIVER=constructor).
    |
    | sync_mode  How a model save is sent to Constructor:
    |              replace  PUT /v2/items: the item is replaced with exactly
    |                       what the model sends.
    |              patch    PATCH /v2/items: only the fields the model sends
    |                       change; fields set by other feeds (inventory,
    |                       merchandising data) are kept. Missing items are
    |                       created.
    |
    */

    'scout' => [
        'sync_mode' => env('CONSTRUCTOR_SCOUT_SYNC_MODE', 'replace'),
    ],

];
