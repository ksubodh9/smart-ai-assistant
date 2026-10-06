<?php

namespace Subodh\SmartAiAssistant\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Subodh\SmartAiAssistant\Models\Conversation;
use Subodh\SmartAiAssistant\Models\ErrorDefinition;
use Subodh\SmartAiAssistant\Models\Message;
use Subodh\SmartAiAssistant\Tests\TestCase;

/**
 * One conversation per chat: the browser sends back the conversation id, the
 * server honours it only for its owner, and guard state lives with it.
 */
class ConversationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ErrorDefinition::create([
            'service'   => 'AEPS',
            'key_text'  => 'capture timeout',
            'answer_en' => 'Clean the scanner and retry the capture.',
        ]);
    }

    private function ask(string $text, ?int $conversationId = null, ?string $sessionId = null)
    {
        return $this->withCredentials()
            ->withCookie(config('session.cookie'), $sessionId ?? Str::random(40))
            ->postJson('/smart-assistant/help', array_filter([
                'error_text'      => $text,
                'conversation_id' => $conversationId,
            ], fn ($v) => $v !== null));
    }

    public function test_a_guest_continues_the_conversation_in_the_same_session(): void
    {
        $session = Str::random(40);
        $id = $this->ask('hello', null, $session)->json('conversation_id');

        $this->ask('hello', $id, $session)
            ->assertJson(['conversation_id' => $id, 'source' => 'exit']);
    }

    public function test_a_guest_cannot_continue_another_sessions_conversation(): void
    {
        $id = $this->ask('hello', null, Str::random(40))->json('conversation_id');

        $response = $this->ask('hello', $id, Str::random(40))->assertJson(['source' => 'greeting']);

        $this->assertNotSame($id, $response->json('conversation_id'));
    }

    public function test_a_user_cannot_continue_another_users_conversation(): void
    {
        $this->actingAs(new GenericUser(['id' => 1, 'name' => 'A']));
        $id = $this->ask('capture timeout')->json('conversation_id');

        $this->actingAs(new GenericUser(['id' => 2, 'name' => 'B']));
        $response = $this->ask('capture timeout', $id)->assertJson(['source' => 'kb']);

        $this->assertNotSame($id, $response->json('conversation_id'));
        $this->assertSame(2, Conversation::find($id)->messages()->count(), "first user's conversation untouched");
    }

    public function test_a_user_continues_their_conversation_across_sessions(): void
    {
        $this->actingAs(new GenericUser(['id' => 1, 'name' => 'A']));
        $id = $this->ask('capture timeout')->json('conversation_id');

        $this->ask('capture timeout', $id)->assertJson(['conversation_id' => $id, 'source' => 'exit']);
    }

    public function test_an_idle_conversation_is_not_continued(): void
    {
        config(['smart-ai-assistant.conversations.idle_minutes' => 30]);
        $session = Str::random(40);
        $id = $this->ask('hello', null, $session)->json('conversation_id');

        $this->travel(31)->minutes();

        $response = $this->ask('hello', $id, $session)->assertJson(['source' => 'greeting']);
        $this->assertNotSame($id, $response->json('conversation_id'));
    }

    public function test_without_a_conversation_id_every_message_starts_a_new_conversation(): void
    {
        $session = Str::random(40);

        $this->ask('hello', null, $session)->assertJson(['source' => 'greeting']);
        $this->ask('hello', null, $session)->assertJson(['source' => 'greeting']);

        $this->assertSame(2, Conversation::count());
    }

    public function test_conversation_id_must_be_an_integer(): void
    {
        $this->postJson('/smart-assistant/help', ['error_text' => 'capture timeout', 'conversation_id' => 'abc'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('conversation_id');
    }

    public function test_status_follows_the_latest_stored_outcome(): void
    {
        $session = Str::random(40);
        $id = $this->ask('capture timeout', null, $session)->json('conversation_id');
        $this->assertSame('resolved', Conversation::find($id)->status);

        $this->ask('money deducted but transaction failed', $id, $session);
        $this->assertSame('unresolved', Conversation::find($id)->status);

        $this->assertSame(4, Message::count());
    }

    public function test_an_escalated_conversation_stays_escalated(): void
    {
        $session = Str::random(40);
        $id = $this->ask('money deducted but transaction failed', null, $session)->json('conversation_id');
        Conversation::find($id)->update(['status' => 'escalated']);

        $this->ask('capture timeout', $id, $session);

        $this->assertSame('escalated', Conversation::find($id)->status);
    }

    public function test_answers_offer_no_actions(): void
    {
        $this->ask('capture timeout')->assertJson(['source' => 'kb', 'actions' => []]);
    }

    public function test_no_escalate_action_when_escalation_is_disabled(): void
    {
        config(['smart-ai-assistant.capabilities.escalation' => false]);

        $this->ask('money deducted but transaction failed')->assertJson(['source' => 'unknown', 'actions' => []]);
    }

    public function test_host_can_relabel_the_escalate_action(): void
    {
        config(['smart-ai-assistant.responses.escalate_action' => ['en' => 'Contact support']]);

        $this->ask('money deducted but transaction failed')
            ->assertJsonPath('actions.0.label', 'Contact support');
    }
}
