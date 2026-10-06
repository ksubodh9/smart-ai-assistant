<?php

namespace Subodh\SmartAiAssistant\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Subodh\SmartAiAssistant\Models\Conversation;
use Subodh\SmartAiAssistant\Models\Message;
use Subodh\SmartAiAssistant\Tests\TestCase;

class PruneConversationsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function conversationIdleFor(int $days): Conversation
    {
        $conversation = Conversation::create(['service' => 'AEPS', 'status' => 'resolved']);
        Message::create(['conversation_id' => $conversation->id, 'sender_type' => 'user', 'message' => 'x']);
        Message::create(['conversation_id' => $conversation->id, 'sender_type' => 'ai', 'message' => 'y']);

        $conversation->timestamps = false;
        $conversation->forceFill(['updated_at' => now()->subDays($days)])->save();

        return $conversation;
    }

    public function test_deletes_conversations_and_messages_older_than_the_configured_retention(): void
    {
        config(['smart-ai-assistant.conversations.retention_days' => 30]);
        $old = $this->conversationIdleFor(31);
        $recent = $this->conversationIdleFor(29);

        $this->artisan('smart-ai:prune')
            ->expectsOutputToContain('Deleted 1 conversation(s) and 2 message(s)')
            ->assertSuccessful();

        $this->assertNull(Conversation::find($old->id));
        $this->assertNotNull(Conversation::find($recent->id));
        $this->assertSame(2, Message::count());
    }

    public function test_days_option_overrides_the_config(): void
    {
        config(['smart-ai-assistant.conversations.retention_days' => 30]);
        $this->conversationIdleFor(10);

        $this->artisan('smart-ai:prune', ['--days' => 7])->assertSuccessful();

        $this->assertSame(0, Conversation::count());
        $this->assertSame(0, Message::count());
    }

    public function test_without_a_retention_period_nothing_is_deleted(): void
    {
        $this->conversationIdleFor(400);

        $this->artisan('smart-ai:prune')
            ->expectsOutputToContain('nothing deleted')
            ->assertSuccessful();

        $this->assertSame(1, Conversation::count());
    }

    public function test_rejects_an_invalid_period(): void
    {
        $this->conversationIdleFor(400);

        $this->artisan('smart-ai:prune', ['--days' => '0'])->assertFailed();
        $this->artisan('smart-ai:prune', ['--days' => 'abc'])->assertFailed();

        $this->assertSame(1, Conversation::count());
    }
}
