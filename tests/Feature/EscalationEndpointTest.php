<?php

namespace Subodh\SmartAiAssistant\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Subodh\SmartAiAssistant\Core\Contracts\EscalationChannel;
use Subodh\SmartAiAssistant\Core\Data\EscalationRequest;
use Subodh\SmartAiAssistant\Core\Data\EscalationResult;
use Subodh\SmartAiAssistant\Escalation\LogEscalationChannel;
use Subodh\SmartAiAssistant\Escalation\NullEscalationChannel;
use Subodh\SmartAiAssistant\Models\Conversation;
use Subodh\SmartAiAssistant\Models\Message;
use Subodh\SmartAiAssistant\Tests\TestCase;

/**
 * POST /smart-assistant/escalate: validated, server-side identity, handed to
 * the configured EscalationChannel, result returned as structured JSON.
 */
class EscalationEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RecordingChannel::$received = [];
        RecordingChannel::$result = EscalationResult::created('Ticket created.', 'CMP123', '/tickets/CMP123');
        config(['smart-ai-assistant.escalation.channel' => RecordingChannel::class]);
    }

    private function escalate(array $payload = [])
    {
        return $this->post('/smart-assistant/escalate', $payload + ['message' => 'fingerprint not captured'], [
            'Accept' => 'application/json',
        ]);
    }

    private function asUser(): self
    {
        return $this->actingAs(new GenericUser(['id' => 42, 'name' => 'Asha']));
    }

    public function test_route_uses_configured_middleware_followed_by_the_rate_limiter(): void
    {
        $this->assertSame(
            ['web', 'throttle:smart-assistant'],
            app('router')->getRoutes()->getByName('smart-assistant.escalate')->gatherMiddleware()
        );
    }

    public function test_guests_cannot_escalate(): void
    {
        $this->escalate()
            ->assertStatus(401)
            ->assertExactJson(['status' => 'rejected', 'message' => 'Please log in to contact support.']);

        $this->assertSame([], RecordingChannel::$received);
    }

    public function test_created_request_returns_the_host_reference(): void
    {
        $this->asUser()->escalate()
            ->assertStatus(201)
            ->assertExactJson([
                'conversation_id' => Conversation::sole()->id,
                'status'          => 'created',
                'message'         => 'Ticket created.',
                'reference'       => 'CMP123',
                'view_url'        => '/tickets/CMP123',
            ]);
    }

    public function test_created_request_is_recorded_and_marks_the_conversation_escalated(): void
    {
        $this->asUser()->escalate([
            'message'       => 'call me on 9876543210',
            'error_context' => 'Device not found.',
            'attachments'   => [UploadedFile::fake()->create('screen.png', 10, 'image/png')],
        ])->assertStatus(201);

        $conversation = Conversation::sole();
        $this->assertSame('escalated', $conversation->status);
        $this->assertSame('42', (string) $conversation->user_id);
        $this->assertSame('CMP123', $conversation->meta['escalation_reference']);
        $this->assertSame(RecordingChannel::$received[0]->conversationId, $conversation->id);

        [$user, $system] = $conversation->messages()->orderBy('id')->get();
        $this->assertSame('user', $user->sender_type);
        $this->assertSame('call me on [phone]', $user->message);
        $this->assertEquals(['source' => 'escalation', 'error_context' => 'Device not found.', 'attachments' => 1, 'page_url' => null], $user->data);
        $this->assertSame('system', $system->sender_type);
        $this->assertSame('Ticket created.', $system->message);
        $this->assertEquals(['escalation_status' => 'created', 'reference' => 'CMP123'], $system->data);
    }

    public function test_escalation_continues_the_users_conversation(): void
    {
        $this->asUser();
        $conversationId = $this->postJson('/smart-assistant/help', ['error_text' => 'aeps withdrawal failed'])
            ->json('conversation_id');

        $this->escalate(['conversation_id' => $conversationId])
            ->assertStatus(201)
            ->assertJson(['conversation_id' => $conversationId]);

        $this->assertSame('escalated', Conversation::sole()->status);
        $this->assertSame(4, Message::count());
    }

    public function test_another_users_conversation_id_starts_a_new_conversation(): void
    {
        $other = Conversation::create(['user_id' => 99, 'service' => 'AEPS', 'status' => 'open']);

        $response = $this->asUser()->escalate(['conversation_id' => $other->id])->assertStatus(201);

        $this->assertNotSame($other->id, $response->json('conversation_id'));
        $this->assertSame('open', $other->fresh()->status);
        $this->assertSame(0, $other->messages()->count());
    }

    public function test_request_that_was_not_created_leaves_the_status_unchanged(): void
    {
        RecordingChannel::$result = EscalationResult::throttled('Please wait 10 minutes.');

        $this->asUser()->escalate()->assertStatus(429);

        $this->assertSame('open', Conversation::sole()->status);
        $this->assertEquals(['escalation_status' => 'throttled', 'reference' => null], Message::where('sender_type', 'system')->sole()->data);
    }

    public function test_channel_receives_server_side_identity_and_the_cleaned_request(): void
    {
        $this->asUser()->escalate([
            'message'       => '  fingerprint not captured  ',
            'error_context' => 'Device not found.',
            'page_url'      => 'https://app.test/aeps/withdraw?txn=123&mobile=9876543210',
            'maddox_id'     => 'MDX0999',
            'user_id'       => 999,
        ])->assertStatus(201);

        $request = RecordingChannel::$received[0];
        $this->assertSame('42', $request->user->id);
        $this->assertSame('fingerprint not captured', $request->message);
        $this->assertSame('Device not found.', $request->errorContext);
        $this->assertSame('/aeps/withdraw', $request->pageUrl);
        $this->assertSame(['AEPS'], $request->domains);
        $this->assertSame([], $request->attachments);
    }

    public function test_attachments_are_passed_to_the_channel(): void
    {
        $this->asUser()->escalate([
            'message'     => '',
            'attachments' => [
                UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'),
                UploadedFile::fake()->create('screen.png', 100, 'image/png'),
            ],
        ])->assertStatus(201);

        $files = RecordingChannel::$received[0]->attachments;
        $this->assertSame(['receipt.pdf', 'screen.png'], array_map(fn ($f) => $f->getClientOriginalName(), $files));
        $this->assertSame('', RecordingChannel::$received[0]->message);
    }

    public function test_message_or_attachment_is_required(): void
    {
        $this->asUser()->escalate(['message' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');

        $this->assertSame([], RecordingChannel::$received);
    }

    public function test_message_length_is_limited_by_config(): void
    {
        config(['smart-ai-assistant.escalation.max_message_length' => 20]);

        $this->asUser()->escalate(['message' => str_repeat('a', 21)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');
    }

    public function test_attachment_types_are_limited_by_config(): void
    {
        $this->asUser()->escalate([
            'attachments' => [UploadedFile::fake()->create('notes.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')],
        ])->assertStatus(422)->assertJsonValidationErrors('attachments.0');
    }

    public function test_attachment_size_is_limited_by_config(): void
    {
        $this->asUser()->escalate([
            'attachments' => [UploadedFile::fake()->create('big.pdf', 2049, 'application/pdf')],
        ])->assertStatus(422)->assertJsonValidationErrors('attachments.0');
    }

    public function test_attachment_count_is_limited_by_config(): void
    {
        $this->asUser()->escalate([
            'attachments' => [
                UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('c.pdf', 10, 'application/pdf'),
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('attachments');
    }

    public function test_host_throttle_is_reported_as_429(): void
    {
        RecordingChannel::$result = EscalationResult::throttled('Please wait 10 minutes.');

        $this->asUser()->escalate()
            ->assertStatus(429)
            ->assertJson(['status' => 'throttled', 'message' => 'Please wait 10 minutes.', 'reference' => null]);
    }

    public function test_host_rejection_is_reported_as_422(): void
    {
        RecordingChannel::$result = EscalationResult::rejected('Not allowed.');

        $this->asUser()->escalate()
            ->assertStatus(422)
            ->assertJson(['status' => 'rejected', 'message' => 'Not allowed.']);
    }

    public function test_host_failure_is_reported_as_503(): void
    {
        RecordingChannel::$result = EscalationResult::failed('Try again later.');

        $this->asUser()->escalate()
            ->assertStatus(503)
            ->assertJson(['status' => 'failed', 'message' => 'Try again later.']);
    }

    public function test_default_channel_rejects_every_request(): void
    {
        config(['smart-ai-assistant.escalation.channel' => NullEscalationChannel::class]);

        $this->asUser()->escalate()
            ->assertStatus(422)
            ->assertJson(['status' => 'rejected']);
    }

    public function test_log_channel_logs_redacted_texts_and_reports_created(): void
    {
        config(['smart-ai-assistant.escalation.channel' => LogEscalationChannel::class]);
        Log::spy();

        $this->asUser()->escalate(['message' => 'call me on 9876543210'])
            ->assertStatus(201)
            ->assertJson(['status' => 'created']);

        Log::shouldHaveReceived('info')->once()->withArgs(function ($message, $context) {
            return $message === 'Smart assistant escalation'
                && $context['message'] === 'call me on [phone]'
                && $context['user_id'] === '42'
                && str_starts_with($context['reference'], 'LOG-');
        });
    }
}

class RecordingChannel implements EscalationChannel
{
    /** @var list<EscalationRequest> */
    public static array $received = [];

    public static EscalationResult $result;

    public function escalate(EscalationRequest $request): EscalationResult
    {
        self::$received[] = $request;

        return self::$result;
    }
}
