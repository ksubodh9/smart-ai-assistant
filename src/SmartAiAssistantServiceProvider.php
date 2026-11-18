<?php

namespace Subodh\SmartAiAssistant;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Subodh\SmartAiAssistant\Answering\AnswerCallLimiter;
use Subodh\SmartAiAssistant\Answering\AnthropicAnswerGenerator;
use Subodh\SmartAiAssistant\Answering\NullAnswerGenerator;
use Subodh\SmartAiAssistant\Core\Contracts\AnswerGenerator;
use Subodh\SmartAiAssistant\Core\Contracts\ConversationStore;
use Subodh\SmartAiAssistant\Core\Contracts\PassageSource;
use Subodh\SmartAiAssistant\Core\Contracts\EscalationChannel;
use Subodh\SmartAiAssistant\Core\Contracts\Interpreter;
use Subodh\SmartAiAssistant\Core\Contracts\KnowledgeSource;
use Subodh\SmartAiAssistant\Core\Contracts\Redactor;
use Subodh\SmartAiAssistant\Core\Contracts\StatusNoticeSource;
use Subodh\SmartAiAssistant\Core\Contracts\UserContextResolver;
use Subodh\SmartAiAssistant\Core\Resolution\ResolverPipeline;
use Subodh\SmartAiAssistant\Documents\DocumentIngester;
use Subodh\SmartAiAssistant\Documents\TextChunker;
use Subodh\SmartAiAssistant\Escalation\NullEscalationChannel;
use Subodh\SmartAiAssistant\Knowledge\CompositeKnowledgeSource;
use Subodh\SmartAiAssistant\Knowledge\DatabaseKnowledgeSource;
use Subodh\SmartAiAssistant\Knowledge\DocumentKnowledgeSource;
use Subodh\SmartAiAssistant\Knowledge\KeywordKnowledgeSource;
use Subodh\SmartAiAssistant\Notices\DatabaseStatusNoticeSource;
use Subodh\SmartAiAssistant\Notices\StatusNoticeManager;
use Subodh\SmartAiAssistant\Persistence\EloquentConversationStore;
use Subodh\SmartAiAssistant\Resolution\StrategyRegistry;
use Subodh\SmartAiAssistant\Support\DefaultRedactor;
use Subodh\SmartAiAssistant\Support\InputClassifier;
use Subodh\SmartAiAssistant\Support\LaravelAuthUserContextResolver;
use Subodh\SmartAiAssistant\Support\Locales;
use Subodh\SmartAiAssistant\Support\ResponseCatalog;
use Subodh\SmartAiAssistant\Understanding\RuleBasedInterpreter;

class SmartAiAssistantServiceProvider extends ServiceProvider
{
    /**
     * Perform post-registration booting of services.
     */
    public function boot()
    {
        // Rate limiter used by the package routes (throttle:smart-assistant)
        RateLimiter::for('smart-assistant', function (Request $request) {
            $limits = config('smart-ai-assistant.rate_limit', []);
            $sessionKey = $request->hasSession() ? $request->session()->getId() : $request->ip();

            return [
                Limit::perMinute((int) ($limits['per_session'] ?? 30))->by('smart-assistant:session:' . $sessionKey),
                Limit::perMinute((int) ($limits['per_ip'] ?? 300))->by('smart-assistant:ip:' . $request->ip()),
            ];
        });

        // Load routes
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');

        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Load views (Blade components)
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'smart-ai-assistant');

        // Publish config
        $this->publishes([
            __DIR__ . '/../config/smart-ai-assistant.php' => config_path('smart-ai-assistant.php'),
        ], 'smart-ai-assistant-config');

        // Publish public assets (JS, CSS)
        $this->publishes([
            __DIR__ . '/../public' => public_path('vendor/smart-ai-assistant'),
        ], 'smart-ai-assistant-assets');

        // Publish views
        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/smart-ai-assistant'),
        ], 'smart-ai-assistant-views');

        // Register Blade component
        Blade::component('smart-ai-assistant::components.widget', 'smart-assistant-widget');
    }

    /**
     * Register any application services.
     */
    public function register()
    {
        // Merge default config
        $this->mergeConfigFrom(
            __DIR__ . '/../config/smart-ai-assistant.php', 'smart-ai-assistant'
        );

        // Identity comes from the host through the configured resolver class
        $this->app->bind(UserContextResolver::class, function ($app) {
            return $app->make(config('smart-ai-assistant.user_resolver', LaravelAuthUserContextResolver::class));
        });

        // Where confirmed support requests go: usually the host's ticket system
        $this->app->bind(EscalationChannel::class, function ($app) {
            return $app->make(config('smart-ai-assistant.escalation.channel', NullEscalationChannel::class));
        });

        $this->app->bind(Redactor::class, function ($app) {
            return $app->make(config('smart-ai-assistant.redactor', DefaultRedactor::class));
        });

        // Vocabulary (patterns, categories, reply texts) is host config; the classes hold generic defaults
        $this->app->bind(InputClassifier::class, function () {
            return new InputClassifier(
                config('smart-ai-assistant.understanding.patterns', []),
                config('smart-ai-assistant.understanding.categories', []),
            );
        });

        // Reply languages: how a message's language is told and which translation is shown
        $this->app->bind(Locales::class, fn () => Locales::fromConfig());

        $this->app->bind(ResponseCatalog::class, function () {
            return new ResponseCatalog(config('smart-ai-assistant.responses', []));
        });

        $this->app->bind(Interpreter::class, function ($app) {
            return new RuleBasedInterpreter(
                $app->make(InputClassifier::class),
                (array) config('smart-ai-assistant.understanding.entities', []),
                $app->make(Locales::class),
            );
        });

        $this->app->bind(DatabaseKnowledgeSource::class, function () {
            return new DatabaseKnowledgeSource(config('smart-ai-assistant.default_service', 'general'));
        });

        $this->app->bind(KeywordKnowledgeSource::class, function () {
            return new KeywordKnowledgeSource(
                config('smart-ai-assistant.default_service', 'general'),
                (array) config('smart-ai-assistant.knowledge.synonyms', []),
                (bool) config('smart-ai-assistant.knowledge.typo_tolerance', true),
            );
        });

        // The configured sources in order; the first that finds anything answers
        $this->app->bind(KnowledgeSource::class, function ($app) {
            return new CompositeKnowledgeSource(array_map(
                fn ($class) => $app->make($class),
                // A host 'knowledge' block without 'sources' keeps both default sources
                (array) config('smart-ai-assistant.knowledge.sources', [DatabaseKnowledgeSource::class, KeywordKnowledgeSource::class])
            ));
        });

        // Conversations also hold the guards' state (see ConversationStore::state)
        $this->app->bind(ConversationStore::class, function ($app) {
            return new EloquentConversationStore(
                $app->make(Redactor::class),
                config('smart-ai-assistant.default_service', 'general'),
                (int) config('smart-ai-assistant.conversations.idle_minutes', 120),
                $app->make(Locales::class),
            );
        });

        // Strategies run in configured order (first match wins), then every guard
        $this->app->bind(ResolverPipeline::class, function ($app) {
            $registry = new StrategyRegistry(
                $app,
                config('smart-ai-assistant.resolution.strategies', []),
                config('smart-ai-assistant.capabilities', []),
            );

            return new ResolverPipeline(
                $registry->strategies(),
                array_map(fn ($guard) => $app->make($guard), config('smart-ai-assistant.resolution.guards', [])),
            );
        });

        // Document ingestion: extractors per file extension (host entries add or replace, null removes one)
        $this->app->bind(DocumentIngester::class, function ($app) {
            return new DocumentIngester(
                $app,
                array_filter(array_merge(DocumentIngester::DEFAULT_EXTRACTORS, (array) config('smart-ai-assistant.documents.extractors', []))),
                new TextChunker((int) config('smart-ai-assistant.documents.chunk_size', 1500)),
            );
        });

        // Document passages for grounded answers (not a KnowledgeSource: passages are not answers)
        $this->app->bind(PassageSource::class, function ($app) {
            $retrieval = (array) config('smart-ai-assistant.documents.retrieval', []);
            $class = $retrieval['source'] ?? DocumentKnowledgeSource::class;

            return $class === DocumentKnowledgeSource::class
                ? new DocumentKnowledgeSource(
                    config('smart-ai-assistant.default_service', 'general'),
                    (float) ($retrieval['min_score'] ?? 0.4),
                    (array) ($retrieval['stopwords'] ?? []),
                )
                : $app->make($class);
        });

        // 'anthropic', 'null', or a class implementing AnswerGenerator; anything else means no LLM
        $this->app->bind(AnswerGenerator::class, function ($app) {
            $config = (array) config('smart-ai-assistant.ai', []);
            $driver = (string) ($config['driver'] ?? 'null');

            if ($driver === 'anthropic') {
                return new AnthropicAnswerGenerator($config);
            }

            return class_exists($driver) && is_subclass_of($driver, AnswerGenerator::class)
                ? $app->make($driver)
                : new NullAnswerGenerator();
        });

        // Known issues per service: where active notices come from, and how they are posted
        $this->app->bind(StatusNoticeSource::class, function ($app) {
            return $app->make(config('smart-ai-assistant.status_notices.source', DatabaseStatusNoticeSource::class));
        });

        $this->app->bind(StatusNoticeManager::class, function () {
            $notices = (array) config('smart-ai-assistant.status_notices', []);

            return new StatusNoticeManager(
                array_key_exists('default_minutes', $notices) ? ($notices['default_minutes'] === null ? null : (int) $notices['default_minutes']) : 240,
                (int) ($notices['max_length'] ?? 500),
            );
        });

        $this->app->bind(AnswerCallLimiter::class, function ($app) {
            $answers = (array) config('smart-ai-assistant.documents.answers', []);

            return new AnswerCallLimiter(
                $app->make('cache.store'),
                isset($answers['daily_limit']) ? (int) $answers['daily_limit'] : null,
                isset($answers['per_user_daily_limit']) ? (int) $answers['per_user_daily_limit'] : null,
            );
        });

        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Subodh\SmartAiAssistant\Console\Commands\SeedKbFromCsv::class,
                \Subodh\SmartAiAssistant\Console\Commands\PruneConversations::class,
                \Subodh\SmartAiAssistant\Console\Commands\EvaluateQueries::class,
                \Subodh\SmartAiAssistant\Console\Commands\IngestDocuments::class,
                \Subodh\SmartAiAssistant\Console\Commands\ManageDocuments::class,
                \Subodh\SmartAiAssistant\Console\Commands\ManageNotices::class,
            ]);
        }
    }
}
