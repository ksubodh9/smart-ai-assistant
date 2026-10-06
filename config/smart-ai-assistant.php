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

    // Stored conversations. The widget sends back the conversation id it got,
    // so one chat is one conversation; an id that belongs to someone else or
    // has been idle too long starts a new conversation.
    'conversations' => [
        // Minutes without messages after which a new conversation starts
        'idle_minutes' => 120,

        // Days to keep conversations; smart-ai:prune deletes older ones.
        // null keeps them forever (the command then does nothing).
        'retention_days' => env('SMART_AI_RETENTION_DAYS'),
    ],

    // Support requests ("Raise Ticket") sent to POST /smart-assistant/escalate.
    'escalation' => [
        // Class that hands the request to the host's support system. It must
        // implement Subodh\SmartAiAssistant\Core\Contracts\EscalationChannel.
        // The default rejects every request; LogEscalationChannel only writes
        // to the log (for development).
        'channel' => \Subodh\SmartAiAssistant\Escalation\NullEscalationChannel::class,

        // Longest message the user may type (characters)
        'max_message_length' => 1000,

        // Files the user may attach; keep these within the host's own limits
        'attachments' => [
            'mimes'     => ['jpg', 'jpeg', 'png', 'pdf'],
            'max_kb'    => 2048,
            'max_files' => 2,
        ],
    ],

    // Switches for features that are being rolled out
    'features' => [
        // true: the widget sends typed messages and attachments to
        // /smart-assistant/escalate, and 'escalation.channel' handles them.
        // false: the widget posts them to the host's own ticket endpoint as
        // before, using identity fields rendered into the page.
        'server_escalation' => env('SMART_AI_SERVER_ESCALATION', false),

        // true: typed messages go to the assistant (/smart-assistant/message)
        // first; it answers, asks once for details, or offers "Raise ticket",
        // and a ticket is only created when the user confirms.
        // false: every typed message that passes the widget's own checks
        // becomes a ticket straight away.
        'resolve_typed_messages' => env('SMART_AI_RESOLVE_TYPED_MESSAGES', false),
    ],

    // Knowledge domain searched by the knowledge base and recorded on
    // conversations (the "service" column of the KB table).
    'default_service' => env('SMART_AI_DEFAULT_SERVICE', 'general'),

    // Features the host enables. Strategies that need a disabled capability
    // are skipped.
    'capabilities' => [
        'knowledge'  => true,
        'escalation' => true,
        // Answers from host data through the data_tools below
        'data_tools' => env('SMART_AI_DATA_TOOLS', false),
    ],

    // Host DataTool classes (Subodh\SmartAiAssistant\Core\Contracts\DataTool),
    // tried in order. A tool runs for a logged-in user when every argument in
    // its argumentSchema() was found by understanding.entities, and only after
    // its authorize() allows it.
    'data_tools' => [],

    // How a message is resolved: strategies run in this order and the first
    // one that answers wins (keep a fallback last); then every guard runs.
    'resolution' => [
        'strategies' => [
            \Subodh\SmartAiAssistant\Strategies\InputGuardStrategy::class,
            \Subodh\SmartAiAssistant\Strategies\ExplicitEscalationStrategy::class,
            \Subodh\SmartAiAssistant\Strategies\DataToolStrategy::class,
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

        // Values to pick out of messages for data tools: entity name => regex.
        // The first capture group (or the whole match) is the value, e.g.
        // 'reference_id' => '/\b(TXN[0-9]{10})\b/i'.
        'entities' => [],
    ],

    // Reply texts; a key listed here replaces its default
    // (see ResponseCatalog::DEFAULTS). Answers are ['en' => ..., 'hi' => ...].
    'responses' => [],

    // The chat widget. Each section you set replaces only the keys you list;
    // missing keys keep their defaults (see Support\WidgetConfig::DEFAULTS).
    'widget' => [
        // Texts and colours (colours as #rgb or #rrggbb)
        'branding' => [
            'title'            => 'Support assistant',
            'icon'             => '🤖',
            'welcome_title'    => 'Hello!',
            'welcome_subtitle' => 'How may I assist you today?',
            'footer'           => null,  // null hides the footer
            'primary_color'    => '#667eea',
            'secondary_color'  => '#764ba2',
        ],

        // Parts of the widget to show
        'features' => [
            'page_scan'   => true,  // find error messages on the page and offer them
            'attachments' => true,  // file attach button
            'screenshot'  => true,  // screenshot button
            // Workarounds for Bootstrap modals that steal focus from the chat
            // input, and for page scripts that disable all inputs
            // (public/js/host-compat.js)
            'bootstrap_modal_compat' => false,
        ],

        // Starter questions shown under the welcome text; sent as if typed.
        // Only shown with features.resolve_typed_messages on.
        'suggestions' => [],

        // How error messages are found on the page
        'page_scan' => [
            // Element ids whose text is always an error
            'ids'             => [],
            // Containers that always hold errors
            'selectors'       => ['.alert-danger', '.smart-error'],
            // Containers that hold errors only after the ignore rules below
            'soft_selectors'  => ['.text-danger'],
            'ignore_classes'  => ['invalid-feedback', 'help-block', 'placeholder'],
            'ignore_ids'      => [],
            // JavaScript regular expressions (case-insensitive) for texts that
            // are labels or placeholders, not errors
            'ignore_patterns' => ['^(loading\.*|please\s+wait|processing)$'],
        ],
    ],

    // Stub configuration for future AI integration (V2)
    'ai' => [
        'enabled' => false,
        'driver' => env('SMART_AI_DRIVER', 'openai'), // e.g., openai, anthropic
        'api_key' => env('SMART_AI_API_KEY', null),
        // Additional driver‑specific options can be added here
    ],
];
