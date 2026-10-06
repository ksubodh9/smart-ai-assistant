<?php

namespace Subodh\SmartAiAssistant\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Subodh\SmartAiAssistant\Models\Conversation;
use Subodh\SmartAiAssistant\Models\ErrorDefinition;
use Subodh\SmartAiAssistant\Tests\TestCase;

/**
 * POST /smart-assistant/help for a host that configures nothing: no
 * MaddoxPay vocabulary, English-only replies, the "general" domain.
 */
class GenericDefaultsTest extends TestCase
{
    use RefreshDatabase;

    protected function hostConfig(): array
    {
        return [];
    }

    private function ask(string $text)
    {
        return $this->withCredentials()
            ->withCookie(config('session.cookie'), Str::random(40))
            ->postJson('/smart-assistant/help', ['error_text' => $text]);
    }

    public function test_vague_reply_names_no_host_services(): void
    {
        $this->ask('help me')->assertJson([
            'source'    => 'vague',
            'answer_en' => 'Please specify the error message or the service you are having trouble with.',
        ]);
    }

    public function test_unknown_has_no_category_prefix_and_no_hindi(): void
    {
        $this->ask('aeps withdrawal failed')->assertExactJson([
            'conversation_id' => Conversation::sole()->id,
            'source'          => 'unknown',
            'answer_en'       => "this specific error is not yet documented.\n\nIf this issue is urgent, please use the 'Raise Ticket' option to contact support.",
            'answer_hi'       => null,
            'input_type'      => 'valid',
            'category'        => null,
        ]);

        $this->assertSame('general', Conversation::sole()->service);
    }

    public function test_escalation_has_no_hindi(): void
    {
        $this->ask('talk to a human')->assertJson(['source' => 'escalation', 'answer_hi' => null]);
    }

    public function test_knowledge_is_looked_up_in_the_general_domain(): void
    {
        ErrorDefinition::create(['service' => 'general', 'key_text' => 'card declined', 'answer_en' => 'Try another card.']);
        ErrorDefinition::create(['service' => 'AEPS', 'key_text' => 'capture timeout', 'answer_en' => 'x']);

        $this->ask('my card declined twice')->assertJson(['source' => 'kb', 'answer_en' => 'Try another card.']);
        $this->ask('capture timeout')->assertJson(['source' => 'unknown']);
    }

    public function test_hindi_greeting_is_not_built_in(): void
    {
        $this->ask('namaste')->assertJson(['source' => 'unknown', 'input_type' => 'valid']);
    }
}
