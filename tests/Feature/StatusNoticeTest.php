<?php

namespace Subodh\SmartAiAssistant\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Subodh\SmartAiAssistant\Models\Conversation;
use Subodh\SmartAiAssistant\Models\Message;
use Subodh\SmartAiAssistant\Models\StatusNotice;
use Subodh\SmartAiAssistant\Notices\StatusNoticeManager;
use Subodh\SmartAiAssistant\Tests\TestCase;

/**
 * Known issues per service: shown when the widget opens (GET /notices) and
 * as the first reply to a message about that service, once per conversation.
 */
class StatusNoticeTest extends TestCase
{
    use RefreshDatabase;

    private string $sessionId;

    private ?int $conversationId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessionId = Str::random(40);
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

    private function notice(string $service, array $messages, $endsAt = null, $startsAt = null): StatusNotice
    {
        return app(StatusNoticeManager::class)->post($service, $messages, $endsAt, $startsAt);
    }

    public function test_a_message_about_a_service_with_a_notice_gets_the_notice_first(): void
    {
        $notice = $this->notice('AEPS', ['en' => 'Cash deposit is down for all banks. We expect it back by 6 PM.']);

        $this->send('cash deposit kiya bank account me nahi aaya', ['locale' => 'en'])
            ->assertOk()
            ->assertJson([
                'blocks'  => [[
                    'type' => 'text',
                    'locale' => 'en',
                    'text' => "This looks related to a known issue with **AEPS**:\nCash deposit is down for all banks. We expect it back by 6 PM.",
                ]],
                'actions' => [],
                'meta'    => ['source' => 'status_notice', 'category' => 'AEPS'],
            ]);

        // Stored with the notice id; the conversation is neither resolved nor unresolved
        $this->assertSame([$notice->id], Message::where('sender_type', 'ai')->sole()->data['notices']);
        $this->assertSame('open', Conversation::sole()->status);
    }

    public function test_the_notice_is_shown_once_per_conversation(): void
    {
        $this->notice('AEPS', ['en' => 'Cash deposit is down.']);

        $this->send('cash deposit kiya bank account me nahi aaya')->assertJsonPath('meta.source', 'status_notice');
        $this->send('cash deposit kiya bank account me nahi aaya')->assertJsonPath('meta.source', 'unknown');
    }

    public function test_a_vague_service_message_gets_the_notice_instead_of_a_question(): void
    {
        $this->notice('PAYOUT', ['en' => 'Payouts are delayed today.']);

        $this->send('payout issue')
            ->assertOk()
            ->assertJsonPath('meta.source', 'status_notice')
            ->assertJsonPath('meta.category', 'PAYOUT');
    }

    public function test_a_request_for_a_human_gets_the_notice_and_keeps_the_ticket_button(): void
    {
        $this->notice('PAYOUT', ['en' => 'Payouts are delayed today.']);

        $this->send('ye payout chalu karo')
            ->assertOk()
            ->assertJsonPath('meta.source', 'status_notice')
            ->assertJsonPath('actions.0.id', 'escalate');
    }

    public function test_the_notice_follows_the_reply_language(): void
    {
        $this->notice('AEPS', ['en' => 'Cash deposit is down.', 'hi' => 'कैश डिपॉज़िट अभी बंद है।']);

        $this->send('cash deposit kiya bank account me nahi aaya', ['locale' => 'hi'])
            ->assertOk()
            ->assertJsonPath('blocks.0.locale', 'hi')
            ->assertJsonPath('blocks.0.text', "This looks related to a known issue with **AEPS**:\nकैश डिपॉज़िट अभी बंद है।");
    }

    public function test_notices_in_one_reply_fall_back_per_notice_when_a_language_is_missing(): void
    {
        $this->notice('AEPS', ['en' => 'Deposits are slow.']);
        $this->notice('AEPS', ['en' => 'Withdrawals are down.', 'hi' => 'निकासी अभी बंद है।']);

        $this->send('cash deposit kiya bank account me nahi aaya', ['locale' => 'hi'])
            ->assertOk()
            ->assertJsonPath('blocks.0.locale', 'hi')
            ->assertJsonPath('blocks.0.text', "This looks related to a known issue with **AEPS**:\nनिकासी अभी बंद है।\n\n"
                . "This looks related to a known issue with **AEPS**:\nDeposits are slow.");
    }

    public function test_the_eval_command_ignores_live_notices(): void
    {
        $this->notice('AEPS', ['en' => 'Cash deposit is down.']);
        $file = tempnam(sys_get_temp_dir(), 'eval') . '.csv';
        file_put_contents($file, "Query,Expected,Category\ncash deposit kiya bank account me nahi aaya,unknown,AEPS\n");

        try {
            $this->artisan('smart-ai:eval', ['file' => $file])
                ->doesntExpectOutputToContain('status_notice')
                ->assertSuccessful();
        } finally {
            @unlink($file);
        }
    }

    public function test_other_services_and_inactive_notices_are_not_shown(): void
    {
        $this->notice('PAN', ['en' => 'PAN portal is down.']);
        $this->notice('AEPS', ['en' => 'Ended.'], now()->subMinute());
        $this->notice('AEPS', ['en' => 'Not started yet.'], null, now()->addHour());

        $this->send('cash deposit kiya bank account me nahi aaya')->assertJsonPath('meta.source', 'unknown');
    }

    public function test_messages_without_a_category_are_not_matched(): void
    {
        $this->notice('AEPS', ['en' => 'Cash deposit is down.']);

        $this->send('hello')->assertJsonPath('meta.source', 'greeting');
        $this->send('the screen shows a blank page after login')->assertJsonPath('meta.source', 'unknown');
    }

    public function test_the_capability_switch_turns_notices_off(): void
    {
        config(['smart-ai-assistant.capabilities.status_notices' => false]);
        $this->notice('AEPS', ['en' => 'Cash deposit is down.']);

        $this->send('cash deposit kiya bank account me nahi aaya')->assertJsonPath('meta.source', 'unknown');
        $this->getJson('/smart-assistant/notices')->assertOk()->assertExactJson(['protocol' => 1, 'notices' => []]);
    }

    public function test_notices_endpoint_lists_active_notices_newest_first_in_the_reply_language(): void
    {
        $older = $this->notice('PAN', ['en' => 'PAN portal is slow.']);
        $newer = $this->notice('AEPS', ['en' => 'Cash deposit is down.', 'hi' => 'कैश डिपॉज़िट अभी बंद है।']);
        $this->notice('DMT', ['en' => 'Ended.'], now()->subMinute());

        $this->getJson('/smart-assistant/notices?locale=hi')
            ->assertOk()
            ->assertExactJson([
                'protocol' => 1,
                'notices'  => [
                    ['id' => $newer->id, 'service' => 'AEPS', 'blocks' => [[
                        'type' => 'text', 'format' => 'basic', 'locale' => 'hi', 'text' => "**Known issue: AEPS**\nकैश डिपॉज़िट अभी बंद है।",
                    ]]],
                    // No Hindi text: the notice falls back to English
                    ['id' => $older->id, 'service' => 'PAN', 'blocks' => [[
                        'type' => 'text', 'format' => 'basic', 'locale' => 'en', 'text' => "**Known issue: PAN**\nPAN portal is slow.",
                    ]]],
                ],
            ]);
    }

    public function test_notices_endpoint_shows_at_most_max_shown(): void
    {
        config(['smart-ai-assistant.status_notices.max_shown' => 1]);
        $this->notice('PAN', ['en' => 'One.']);
        $this->notice('AEPS', ['en' => 'Two.']);

        $this->getJson('/smart-assistant/notices')->assertOk()->assertJsonCount(1, 'notices')->assertJsonPath('notices.0.service', 'AEPS');
    }

    public function test_notices_endpoint_uses_the_package_middleware_and_rate_limiter(): void
    {
        $this->assertSame(
            ['web', 'throttle:smart-assistant'],
            app('router')->getRoutes()->getByName('smart-assistant.notices')->gatherMiddleware()
        );
    }

    public function test_a_notice_without_an_end_time_ends_after_the_default_duration(): void
    {
        $this->freezeSecond();
        $notice = $this->notice('AEPS', ['en' => 'Down.']);

        $this->assertEquals(now()->addMinutes(240), $notice->ends_at);

        $this->travel(241)->minutes();
        $this->getJson('/smart-assistant/notices')->assertJsonCount(0, 'notices');
    }

    public function test_ending_a_notice_takes_it_down_and_keeps_the_row(): void
    {
        $this->notice('AEPS', ['en' => 'Down.']);

        $this->assertSame(1, app(StatusNoticeManager::class)->end('AEPS'));

        $this->getJson('/smart-assistant/notices')->assertJsonCount(0, 'notices');
        $this->assertSame(1, StatusNotice::count());
    }

    public function test_widget_passes_the_notices_endpoint_and_renders_the_container(): void
    {
        $html = view('smart-ai-assistant::components.widget')->render();

        $this->assertStringContainsString('"notices":"\/smart-assistant\/notices"', $html);
        $this->assertStringContainsString('id="sa-notices"', $html);
    }
}
