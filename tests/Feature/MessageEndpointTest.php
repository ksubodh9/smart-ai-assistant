<?php

namespace Subodh\SmartAiAssistant\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Subodh\SmartAiAssistant\Core\Data\EscalationResult;
use Subodh\SmartAiAssistant\Models\Conversation;
use Subodh\SmartAiAssistant\Models\ErrorDefinition;
use Subodh\SmartAiAssistant\Models\Message;
use Subodh\SmartAiAssistant\Tests\TestCase;

/**
 * POST /smart-assistant/message: typed messages get the same pipeline as page
 * errors, and never create a ticket by themselves.
 */
class MessageEndpointTest extends TestCase
{
    use RefreshDatabase;

    private string $sessionId;

    private ?int $conversationId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessionId = Str::random(40);
        RecordingChannel::$received = [];
        RecordingChannel::$result = EscalationResult::created('Ticket created.', 'CMP1');
        config(['smart-ai-assistant.escalation.channel' => RecordingChannel::class]);

        ErrorDefinition::create([
            'service'   => 'AEPS',
            'key_text'  => 'capture timeout',
            'answer_en' => 'Clean the scanner and retry the capture.',
        ]);
    }

    private function send(string $text, array $extra = [])
    {
        $response = $this->withCredentials()
            ->withCookie(config('session.cookie'), $this->sessionId)
            ->postJson('/smart-assistant/message', array_filter([
                'text'            => $text,
                'conversation_id' => $this->conversationId,
            ] + $extra, fn ($v) => $v !== null));

        $this->conversationId = $response->json('conversation_id') ?? $this->conversationId;

        return $response;
    }

    public function test_route_uses_configured_middleware_followed_by_the_rate_limiter(): void
    {
        $this->assertSame(
            ['web', 'throttle:smart-assistant'],
            app('router')->getRoutes()->getByName('smart-assistant.message')->gatherMiddleware()
        );
    }

    public function test_typed_message_is_answered_from_the_knowledge_base(): void
    {
        $this->send('capture timeout on device')
            ->assertOk()
            ->assertJson([
                'protocol' => 1,
                'blocks'   => [['type' => 'text', 'locale' => 'en', 'text' => 'Clean the scanner and retry the capture.']],
                'actions'  => [],
                'meta'     => ['source' => 'kb'],
            ]);

        $this->assertEquals('typed', Message::where('sender_type', 'user')->sole()->data['source']);
        $this->assertSame('resolved', Conversation::sole()->status);
    }

    public function test_unresolved_typed_message_offers_a_ticket_but_creates_none(): void
    {
        $this->send('finger nahi pakad raha hai')
            ->assertOk()
            ->assertJson([
                'meta'    => ['source' => 'unknown'],
                'actions' => [['type' => 'action', 'id' => 'escalate', 'confirm' => true]],
            ]);

        $this->assertSame([], RecordingChannel::$received, 'no ticket without confirmation');
        $this->assertSame('unresolved', Conversation::sole()->status);
    }

    public function test_asking_for_a_human_offers_a_ticket(): void
    {
        $this->send('I want to talk to a human')
            ->assertJson(['meta' => ['source' => 'escalation'], 'actions' => [['id' => 'escalate']]]);

        $this->assertSame([], RecordingChannel::$received);
    }

    public function test_greeting_is_answered_once_then_exits_with_a_way_to_support(): void
    {
        $this->send('hello')->assertJson(['meta' => ['source' => 'greeting'], 'actions' => []]);

        $this->send('hello')->assertJson(['meta' => ['source' => 'exit'], 'actions' => [['id' => 'escalate']]]);
    }

    public function test_confirmed_ticket_continues_the_same_conversation(): void
    {
        $this->actingAs(new GenericUser(['id' => 3, 'name' => 'C']));
        $this->send('finger nahi pakad raha hai');

        $this->post('/smart-assistant/escalate', [
            'message'         => 'finger nahi pakad raha hai',
            'conversation_id' => $this->conversationId,
        ], ['Accept' => 'application/json'])->assertStatus(201)->assertJson(['conversation_id' => $this->conversationId]);

        $this->assertSame('escalated', Conversation::find($this->conversationId)->status);
        $this->assertSame($this->conversationId, RecordingChannel::$received[0]->conversationId);
    }

    public function test_source_defaults_to_typed_and_accepts_page_errors(): void
    {
        $this->send('capture timeout', ['source' => 'page_error'])->assertOk();

        $this->assertEquals('page_error', Message::where('sender_type', 'user')->sole()->data['source']);
    }

    public function test_validation(): void
    {
        $this->send('capture timeout', ['source' => 'robot'])->assertStatus(422)->assertJsonValidationErrors('source');
        $this->send(str_repeat('a', 1001))->assertStatus(422)->assertJsonValidationErrors('text');
        $this->postJson('/smart-assistant/message', [])->assertStatus(422)->assertJsonValidationErrors('text');
    }
}
