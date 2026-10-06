<?php
return [
    // Middleware applied to package routes. Keep 'web' (session + CSRF) and add
    // the host app's auth middleware, e.g. ['web', 'auth'] or ['web', 'sentinel.auth'].
    'middleware' => ['web'],

    // Class that tells the package who the current user is. It must implement
    // Subodh\SmartAiAssistant\Core\Contracts\UserContextResolver. The default
    // uses Laravel's auth guard; hosts with other authentication (e.g. Sentinel)
    // provide their own class.
    'user_resolver' => \Subodh\SmartAiAssistant\Support\LaravelAuthUserContextResolver::class,

    // Class that masks personal data (phone, email, PAN, Aadhaar, account
    // numbers) in user text before it is stored. It must implement
    // Subodh\SmartAiAssistant\Core\Contracts\Redactor.
    'redactor' => \Subodh\SmartAiAssistant\Support\DefaultRedactor::class,

    // Requests per minute to the assistant endpoints. The per-session limit is
    // the main one; the per-IP limit is a generous backstop because many users
    // can share one IP (office NAT, or a load balancer that is not trusted).
    'rate_limit' => [
        'per_session' => env('SMART_AI_RATE_LIMIT_PER_SESSION', 30),
        'per_ip'      => env('SMART_AI_RATE_LIMIT_PER_IP', 300),
    ],

    // Knowledge domain searched by the knowledge base and recorded on
    // conversations (the "service" column of the KB table).
    'default_service' => env('SMART_AI_DEFAULT_SERVICE', 'general'),

    // Features the host enables. Strategies that need a disabled capability
    // are skipped.
    'capabilities' => [
        'knowledge'  => true,
        'escalation' => true,
    ],

    // How a message is resolved: strategies run in this order and the first
    // one that answers wins (keep a fallback last); then every guard runs.
    'resolution' => [
        'strategies' => [
            \Subodh\SmartAiAssistant\Strategies\InputGuardStrategy::class,
            \Subodh\SmartAiAssistant\Strategies\ExplicitEscalationStrategy::class,
            \Subodh\SmartAiAssistant\Strategies\KnowledgeLookupStrategy::class,
            \Subodh\SmartAiAssistant\Strategies\FallbackStrategy::class,
        ],
        'guards' => [
            \Subodh\SmartAiAssistant\Resolution\Guards\ClarifyOnceGuard::class,
            \Subodh\SmartAiAssistant\Resolution\Guards\LoopGuard::class,
        ],
    ],

    // Host vocabulary for the rule-based interpreter.
    'understanding' => [
        // Regexes per input type; a type listed here replaces its default list
        // (see InputClassifier::DEFAULT_PATTERNS). Types: greeting, vague,
        // noise, abuse_mild, abuse_severe, escalation_request. Use the /u flag.
        'patterns' => [],

        // Category tag => keywords (whole words, plural "s" allowed), e.g.
        // 'PAYMENTS' => ['payment', 'refund']. The first match is the category.
        'categories' => [],
    ],

    // Reply texts; a key listed here replaces its default
    // (see ResponseCatalog::DEFAULTS). Answers are ['en' => ..., 'hi' => ...].
    'responses' => [],

    // CSS selectors used by the frontend widget to capture error text
    'error_selectors' => [
        '.alert-danger',
        '.smart-error',
        // Add more selectors as needed
    ],

    // Stub configuration for future AI integration (V2)
    'ai' => [
        'enabled' => false,
        'driver' => env('SMART_AI_DRIVER', 'openai'), // e.g., openai, anthropic
        'api_key' => env('SMART_AI_API_KEY', null),
        // Additional driver‑specific options can be added here
    ],
];
