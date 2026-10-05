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

    // Default service identifier (AEPS)
    'default_service' => env('SMART_AI_DEFAULT_SERVICE', 'AEPS'),

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
