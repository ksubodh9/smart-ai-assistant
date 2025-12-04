<?php
return [
    // Middleware applied to package routes
    'middleware' => ['web', 'auth'],

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
