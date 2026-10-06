<?php

namespace Subodh\SmartAiAssistant;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Subodh\SmartAiAssistant\Core\Contracts\ConversationStore;
use Subodh\SmartAiAssistant\Core\Contracts\EscalationChannel;
use Subodh\SmartAiAssistant\Core\Contracts\Interpreter;
use Subodh\SmartAiAssistant\Core\Contracts\KnowledgeSource;
use Subodh\SmartAiAssistant\Core\Contracts\Redactor;
use Subodh\SmartAiAssistant\Core\Contracts\UserContextResolver;
use Subodh\SmartAiAssistant\Core\Resolution\ResolverPipeline;
use Subodh\SmartAiAssistant\Escalation\NullEscalationChannel;
use Subodh\SmartAiAssistant\Knowledge\DatabaseKnowledgeSource;
use Subodh\SmartAiAssistant\Persistence\EloquentConversationStore;
use Subodh\SmartAiAssistant\Resolution\StrategyRegistry;
use Subodh\SmartAiAssistant\Support\DefaultRedactor;
use Subodh\SmartAiAssistant\Support\InputClassifier;
use Subodh\SmartAiAssistant\Support\LaravelAuthUserContextResolver;
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

        $this->app->bind(ResponseCatalog::class, function () {
            return new ResponseCatalog(config('smart-ai-assistant.responses', []));
        });

        $this->app->bind(Interpreter::class, RuleBasedInterpreter::class);

        $this->app->bind(KnowledgeSource::class, function () {
            return new DatabaseKnowledgeSource(config('smart-ai-assistant.default_service', 'general'));
        });

        // Conversations also hold the guards' state (see ConversationStore::state)
        $this->app->bind(ConversationStore::class, function ($app) {
            return new EloquentConversationStore(
                $app->make(Redactor::class),
                config('smart-ai-assistant.default_service', 'general'),
                (int) config('smart-ai-assistant.conversations.idle_minutes', 120),
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

        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Subodh\SmartAiAssistant\Console\Commands\SeedKbFromCsv::class,
                \Subodh\SmartAiAssistant\Console\Commands\PruneConversations::class,
            ]);
        }
    }
}
