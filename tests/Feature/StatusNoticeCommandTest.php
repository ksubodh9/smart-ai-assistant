<?php

namespace Subodh\SmartAiAssistant\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Subodh\SmartAiAssistant\Models\StatusNotice;
use Subodh\SmartAiAssistant\Tests\TestCase;

/**
 * smart-ai:notice: post, list and end known issues from the console.
 */
class StatusNoticeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_stores_the_texts_and_the_default_end_time(): void
    {
        $this->freezeSecond();

        $this->artisan('smart-ai:notice', [
            'action'  => 'post',
            'service' => 'AEPS',
            'message' => 'Cash deposit is down.',
            '--text'  => ['hi:कैश डिपॉज़िट अभी बंद है।', 'hi-Latn:Cash deposit abhi band hai.'],
        ])->assertSuccessful();

        $notice = StatusNotice::sole();
        $this->assertSame('AEPS', $notice->service);
        $this->assertSame([
            'en' => 'Cash deposit is down.',
            'hi' => 'कैश डिपॉज़िट अभी बंद है।',
            'hi-Latn' => 'Cash deposit abhi band hai.',
        ], $notice->messages);
        $this->assertEquals(now()->addMinutes(240), $notice->ends_at);
        $this->assertSame('console', $notice->posted_by);
    }

    public function test_post_with_minutes_zero_lasts_until_ended(): void
    {
        $this->artisan('smart-ai:notice', ['action' => 'post', 'service' => 'AEPS', 'message' => 'Down.', '--minutes' => '0'])
            ->assertSuccessful();

        $this->assertNull(StatusNotice::sole()->ends_at);
    }

    public function test_post_with_minutes_sets_the_end_time(): void
    {
        $this->freezeSecond();

        $this->artisan('smart-ai:notice', ['action' => 'post', 'service' => 'AEPS', 'message' => 'Down.', '--minutes' => '30'])
            ->assertSuccessful();

        $this->assertEquals(now()->addMinutes(30), StatusNotice::sole()->ends_at);
    }

    public function test_post_warns_when_the_service_is_not_a_category(): void
    {
        $this->artisan('smart-ai:notice', ['action' => 'post', 'service' => 'NOPE', 'message' => 'Down.'])
            ->expectsOutputToContain('is not a category in understanding.categories')
            ->assertSuccessful();
    }

    public function test_post_rejects_missing_or_bad_input(): void
    {
        $this->artisan('smart-ai:notice', ['action' => 'post', 'service' => 'AEPS'])
            ->expectsOutputToContain('at least one language')
            ->assertFailed();

        $this->artisan('smart-ai:notice', ['action' => 'post', 'message' => 'Down.'])
            ->expectsOutputToContain('1 to 64 characters')
            ->assertFailed();

        $this->artisan('smart-ai:notice', ['action' => 'post', 'service' => 'AEPS', 'message' => 'Down.', '--text' => ['no-colon']])
            ->expectsOutputToContain('code:text')
            ->assertFailed();

        $this->artisan('smart-ai:notice', ['action' => 'post', 'service' => 'AEPS', 'message' => 'Down.', '--minutes' => 'soon'])
            ->expectsOutputToContain('whole number')
            ->assertFailed();

        $this->artisan('smart-ai:notice', ['action' => 'post', 'service' => 'AEPS', 'message' => str_repeat('x', 501)])
            ->expectsOutputToContain('longer than 500')
            ->assertFailed();

        $this->assertSame(0, StatusNotice::count());
    }

    public function test_list_shows_active_notices_and_all_with_the_flag(): void
    {
        StatusNotice::create(['service' => 'AEPS', 'messages' => ['en' => 'Active one.']]);
        StatusNotice::create(['service' => 'PAN', 'messages' => ['en' => 'Ended one.'], 'ends_at' => now()->subMinute()]);

        $this->artisan('smart-ai:notice')
            ->expectsOutputToContain('Active one.')
            ->doesntExpectOutputToContain('Ended one.')
            ->assertSuccessful();

        $this->artisan('smart-ai:notice', ['action' => 'list', '--all' => true])
            ->expectsOutputToContain('Ended one.')
            ->assertSuccessful();
    }

    public function test_end_by_service_id_or_all(): void
    {
        $aeps = StatusNotice::create(['service' => 'AEPS', 'messages' => ['en' => 'A.']]);
        $pan = StatusNotice::create(['service' => 'PAN', 'messages' => ['en' => 'B.']]);
        StatusNotice::create(['service' => 'DMT', 'messages' => ['en' => 'C.']]);

        $this->artisan('smart-ai:notice', ['action' => 'end', 'service' => 'AEPS'])->assertSuccessful();
        $this->assertNotNull($aeps->fresh()->ends_at);
        $this->assertNull($pan->fresh()->ends_at);

        $this->artisan('smart-ai:notice', ['action' => 'end', '--id' => $pan->id])->assertSuccessful();
        $this->assertNotNull($pan->fresh()->ends_at);

        $this->artisan('smart-ai:notice', ['action' => 'end', '--all' => true])->assertSuccessful();
        $this->assertSame(0, StatusNotice::active()->count());

        $this->artisan('smart-ai:notice', ['action' => 'end', 'service' => 'AEPS'])
            ->expectsOutputToContain('No active notice matched')
            ->assertFailed();
    }

    public function test_end_needs_a_target_and_unknown_actions_fail(): void
    {
        $this->artisan('smart-ai:notice', ['action' => 'end'])->expectsOutputToContain('Name a service')->assertFailed();
        $this->artisan('smart-ai:notice', ['action' => 'nope'])->expectsOutputToContain('Unknown action')->assertFailed();
    }
}
