<?php

namespace Subodh\SmartAiAssistant;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;

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

        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Subodh\SmartAiAssistant\Console\Commands\SeedKbFromCsv::class,
            ]);
        }
    }
}
