<?php

namespace Subodh\SmartAiAssistant\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Subodh\SmartAiAssistant\Tests\TestCase;

/**
 * Middleware and rate limiting on the package routes.
 */
class RouteProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function requireLaravelAuth($app): void
    {
        $app['config']->set('smart-ai-assistant.middleware', ['web', 'auth']);
    }

    private function ask(string $text = 'aeps withdrawal failed')
    {
        return $this->postJson('/smart-assistant/help', ['error_text' => $text]);
    }

    /**
     * Send the request with a session cookie, like the widget's same-origin fetch does.
     * The test client sends no cookies on JSON requests unless withCredentials() is used,
     * so without this every request starts a new session with a new id.
     */
    private function askInSession(string $sessionId, string $text = 'aeps withdrawal failed')
    {
        return $this->withCredentials()->withCookie(config('session.cookie'), $sessionId)->ask($text);
    }

    public function test_package_default_middleware_allows_guests(): void
    {
        // The package default is ['web']; hosts add their own auth middleware.
        $this->assertSame(['web'], config('smart-ai-assistant.middleware'));

        $this->ask()->assertOk();
    }

    #[DefineEnvironment('requireLaravelAuth')]
    public function test_configured_host_middleware_is_applied(): void
    {
        $this->ask()->assertUnauthorized();
    }

    public function test_route_uses_configured_middleware_followed_by_the_rate_limiter(): void
    {
        $this->assertSame(
            ['web', 'throttle:smart-assistant'],
            app('router')->getRoutes()->getByName('smart-assistant.help')->gatherMiddleware()
        );
    }

    public function test_requests_are_limited_per_session(): void
    {
        config(['smart-ai-assistant.rate_limit.per_session' => 3]);
        $session = Str::random(40);

        $this->askInSession($session)->assertOk();
        $this->askInSession($session, 'hello')->assertOk();
        $this->askInSession($session, 'help')->assertOk();

        $this->askInSession($session)->assertStatus(429);
    }

    public function test_sessions_on_the_same_ip_have_separate_session_limits(): void
    {
        config(['smart-ai-assistant.rate_limit.per_session' => 1]);
        $first = Str::random(40);

        $this->askInSession($first)->assertOk();
        $this->askInSession($first)->assertStatus(429);

        $this->askInSession(Str::random(40))->assertOk();
    }

    public function test_requests_are_limited_per_ip_across_sessions(): void
    {
        config([
            'smart-ai-assistant.rate_limit.per_session' => 100,
            'smart-ai-assistant.rate_limit.per_ip'      => 2,
        ]);

        $this->askInSession(Str::random(40))->assertOk();
        $this->askInSession(Str::random(40))->assertOk();

        $this->askInSession(Str::random(40))->assertStatus(429);

        // A different client IP is not affected.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->ask()->assertOk();
    }
}
