<?php

namespace Subodh\SmartAiAssistant\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Subodh\SmartAiAssistant\Tests\TestCase;

/**
 * MaddoxPay routing rules tuned on the labelled ticket sheet (step 11): requests
 * only a person can carry out go to escalation with their category, a bare
 * service name gets one "tell me more", test messages and sign-offs get canned
 * replies. Each case is a real phrasing (or close to one).
 */
class RealQueryRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function send(string $text)
    {
        return $this->withCredentials()
            ->withCookie(config('session.cookie'), Str::random(40))
            ->postJson('/smart-assistant/message', ['text' => $text]);
    }

    public static function routed(): array
    {
        return [
            // Activation, cancellation, installation, account changes → escalation, category kept
            'enable after the service'  => ['please pan card service enable', 'escalation', 'PAN'],
            'short activation request'  => ['plz act aadhar pay', 'escalation', 'AADHAAR_PAY'],
            'service then active'       => ['active pan service on my id', 'escalation', 'PAN'],
            'Hinglish chalu karo'       => ['ye payout chalu karo', 'escalation', 'PAYOUT'],
            'cancel a token'            => ['pan card tokan cancel karna hai', 'escalation', 'PAN'],
            'driver installation'       => ['please mantra l1 device installation', 'escalation', 'DEVICE'],
            'forgotten password'        => ['sir may aeps onboard password bhul gaya hu', 'escalation', 'KYC'],
            'agent id request'          => ['aajent id mang rha he', 'escalation', null],
            // Only a service name → ask once for details, category kept
            'service and issue'         => ['payout issue', 'vague', 'PAYOUT'],
            'service only'              => ['pan card', 'vague', 'PAN'],
            'abbreviation only'         => ['nsdl', 'vague', 'PAN'],
            // Test messages, mashing and sign-offs
            'test message'              => ['testing done by abhishek', 'noise', null],
            'keyboard mashing'          => ['rghdhd', 'noise', null],
            'sign-off'                  => ['okkk', 'greeting', null],
            'polite opener'             => ['dear support team', 'greeting', null],
            // Questions and status reports stay with the knowledge base (not escalation)
            'how to activate'           => ['how to activate aeps service', 'unknown', 'AEPS'],
            'not active is a status'    => ['aeps service not active', 'unknown', 'AEPS'],
            'cancelled, refund pending' => ['ticket cancel kiya tha refund nahi aaya irctc', 'unknown', 'IRCTC'],
            'rejected is a status'      => ['onboarding application rejected', 'unknown', 'KYC'],
            // Category order: cash deposit is AEPS, not a payout to a bank account
            'cash deposit is AEPS'      => ['cash deposit kiya bank account me nahi aaya', 'unknown', 'AEPS'],
            'micro ATM over recharge'   => ['m atm not connected for mobile', 'unknown', 'MATM'],
        ];
    }

    #[DataProvider('routed')]
    public function test_real_phrasings_are_routed(string $text, string $source, ?string $category): void
    {
        $this->send($text)
            ->assertOk()
            ->assertJsonPath('meta.source', $source)
            ->assertJsonPath('meta.category', $category);
    }

    public function test_an_activation_request_offers_a_ticket_without_storing_text(): void
    {
        $this->send('kindly activate my pan card service')
            ->assertJsonPath('blocks.0.text', 'Sure, I can pass this to our support team. Use the button below to raise a ticket, or call our helpline for urgent help.')
            ->assertJsonPath('actions.0.id', 'escalate');

        $this->assertDatabaseCount('smart_ai_messages', 0);
    }
}
