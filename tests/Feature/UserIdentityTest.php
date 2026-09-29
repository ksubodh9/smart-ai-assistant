<?php

namespace Subodh\SmartAiAssistant\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Subodh\SmartAiAssistant\Core\Contracts\UserContextResolver;
use Subodh\SmartAiAssistant\Core\Data\UserContext;
use Subodh\SmartAiAssistant\Models\Conversation;
use Subodh\SmartAiAssistant\Models\ErrorDefinition;
use Subodh\SmartAiAssistant\Support\LaravelAuthUserContextResolver;
use Subodh\SmartAiAssistant\Tests\TestCase;

/**
 * The identity boundary: the package learns who the user is only from the
 * configured UserContextResolver, never from the request body.
 */
class UserIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Only KB hits and misses persist a conversation; use a hit.
        ErrorDefinition::create([
            'service'   => 'AEPS',
            'key_text'  => 'capture timeout',
            'answer_en' => 'Clean the scanner and retry the capture.',
        ]);
    }

    private function ask(array $extra = [])
    {
        return $this->postJson('/smart-assistant/help', ['error_text' => 'capture timeout'] + $extra);
    }

    public function test_default_resolver_is_the_laravel_auth_resolver(): void
    {
        $this->assertInstanceOf(LaravelAuthUserContextResolver::class, app(UserContextResolver::class));
    }

    public function test_guest_conversation_has_no_user(): void
    {
        $this->ask()->assertJson(['source' => 'kb']);

        $this->assertNull(Conversation::sole()->user_id);
    }

    public function test_laravel_authenticated_user_is_recorded_by_default(): void
    {
        $this->actingAs(new GenericUser(['id' => 42, 'name' => 'Asha']));

        $this->ask()->assertJson(['source' => 'kb']);

        $this->assertSame(42, Conversation::sole()->user_id);
    }

    public function test_identity_fields_in_the_request_body_are_ignored(): void
    {
        $this->ask(['user_id' => 999, 'maddox_id' => 'MDX0999'])->assertJson(['source' => 'kb']);

        $this->assertNull(Conversation::sole()->user_id);
    }

    public function test_host_can_supply_its_own_resolver_through_config(): void
    {
        config(['smart-ai-assistant.user_resolver' => FixedUserResolver::class]);

        $this->ask()->assertJson(['source' => 'kb']);

        $this->assertSame(7, Conversation::sole()->user_id);
    }

    public function test_laravel_resolver_maps_the_authenticated_user(): void
    {
        app()->setLocale('hi');
        $request = Request::create('/');
        $request->setUserResolver(fn () => new GenericUser(['id' => 42, 'name' => 'Asha']));

        $context = (new LaravelAuthUserContextResolver())->resolve($request);

        $this->assertTrue($context->isAuthenticated());
        $this->assertSame('42', $context->id);
        $this->assertSame('Asha', $context->displayName);
        $this->assertSame('hi', $context->locale);
    }

    public function test_laravel_resolver_returns_a_guest_without_a_user(): void
    {
        $context = (new LaravelAuthUserContextResolver())->resolve(Request::create('/'));

        $this->assertFalse($context->isAuthenticated());
        $this->assertNull($context->id);
    }
}

class FixedUserResolver implements UserContextResolver
{
    public function resolve(Request $request): UserContext
    {
        return new UserContext(id: '7', displayName: 'Host User');
    }
}
