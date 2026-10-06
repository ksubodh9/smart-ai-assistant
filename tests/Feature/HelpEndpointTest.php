<?php

namespace Subodh\SmartAiAssistant\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Subodh\SmartAiAssistant\Models\Conversation;
use Subodh\SmartAiAssistant\Models\ErrorDefinition;
use Subodh\SmartAiAssistant\Models\Message;
use Subodh\SmartAiAssistant\Tests\TestCase;

/**
 * Characterization tests for POST /smart-assistant/help.
 *
 * They pin the exact JSON and persistence behaviour of ErrorHelpController.
 * Step 5 changed it on purpose: protocol 1 fields next to the legacy ones, one
 * conversation per chat holding the guard state, status from the outcome.
 * Cases marked "KNOWN BUG" / "KNOWN GAP" record current behaviour on purpose.
 */
class HelpEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const EXIT_MESSAGE = "I've shared all available guidance for this issue.\nPlease contact support if further assistance is required.";

    private const UNKNOWN_EN = "this specific error is not yet documented.\n\nIf this issue is urgent, please use the 'Raise Ticket' option to contact support.";

    private const UNKNOWN_HI = "यह त्रुटि अभी दस्तावेज़ में नहीं है। कृपया 'टिकट बनाएं' विकल्प का उपयोग करें।";

    private const ESCALATE_ACTION = ['type' => 'action', 'id' => 'escalate', 'label' => 'Raise ticket', 'confirm' => true];

    private string $sessionId;

    private ?int $conversationId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessionId = Str::random(40);
    }

    /**
     * Posts like the widget does: same-origin fetch with the browser's session
     * cookie, sending back the conversation id of the previous response.
     */
    private function ask(string $text, ?string $pageUrl = 'https://app.test/aeps?txn=1')
    {
        $response = $this->withCredentials()
            ->withCookie(config('session.cookie'), $this->sessionId)
            ->postJson('/smart-assistant/help', array_filter([
            'error_text'      => $text,
            'page_url'        => $pageUrl,
            'conversation_id' => $this->conversationId,
        ], fn ($v) => $v !== null));

        $this->conversationId = $response->json('conversation_id') ?? $this->conversationId;

        return $response;
    }

    /**
     * The full response for a reply: protocol 1 fields plus the legacy ones.
     */
    private function reply(string $source, string $inputType, string $en, ?string $hi, array $extra = []): array
    {
        $blocks = [['type' => 'text', 'format' => 'basic', 'locale' => 'en', 'text' => $en]];
        if ($hi !== null && $hi !== '') {
            $blocks[] = ['type' => 'text', 'format' => 'basic', 'locale' => 'hi', 'text' => $hi];
        }

        return array_merge([
            'protocol'        => 1,
            'conversation_id' => $this->conversationId,
            'blocks'          => $blocks,
            'actions'         => [],
            'meta'            => ['source' => $source, 'input_type' => $inputType, 'category' => null],
            'source'          => $source,
            'answer_en'       => $en,
            'answer_hi'       => $hi,
            'input_type'      => $inputType,
        ], $extra);
    }

    private function seedDefinition(array $attributes = []): ErrorDefinition
    {
        return ErrorDefinition::create(array_merge([
            'service'   => 'AEPS',
            'key_text'  => 'capture timeout',
            'answer_en' => 'Clean the scanner and retry the capture.',
            'answer_hi' => 'स्कैनर साफ करें और फिर से प्रयास करें।',
        ], $attributes));
    }

    /**
     * Canned replies keep the conversation open but store none of the text.
     */
    private function assertNoTextStored(): void
    {
        $conversation = Conversation::sole();
        $this->assertSame('open', $conversation->status);
        $keys = array_keys($conversation->meta ?? []);
        sort($keys);
        $this->assertSame(['guest_key', 'state'], $keys, 'conversation meta holds only the guest key and guard state');
        $this->assertSame(0, Message::count(), 'messages persisted');
    }

    // ---------------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------------

    public function test_error_text_is_required(): void
    {
        $this->postJson('/smart-assistant/help', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('error_text');
    }

    public function test_whitespace_only_text_fails_validation_before_classification(): void
    {
        // TrimStrings + ConvertEmptyStringsToNull run first, so the classifier's
        // "empty" branch is unreachable over HTTP.
        $this->ask('   ')
            ->assertStatus(422)
            ->assertJsonValidationErrors('error_text');
    }

    public function test_error_text_is_limited_to_1000_characters(): void
    {
        $this->ask('aeps withdrawal failed ' . str_repeat('x', 977))->assertOk();

        $this->ask('aeps withdrawal failed ' . str_repeat('x', 978))
            ->assertStatus(422)
            ->assertJsonValidationErrors('error_text');
    }

    public function test_page_url_is_limited_to_2048_characters(): void
    {
        $this->ask('aeps withdrawal failed', 'https://app.test/' . str_repeat('a', 2100))
            ->assertStatus(422)
            ->assertJsonValidationErrors('page_url');
    }

    // ---------------------------------------------------------------------
    // Non-processable input: canned reply, no text stored
    // ---------------------------------------------------------------------

    public function test_greeting_gets_canned_reply_and_its_text_is_not_stored(): void
    {
        $response = $this->ask('hello')->assertOk();

        $response->assertExactJson($this->reply('greeting', 'greeting', 'Hello. Please state the issue you are facing.', null));
        $this->assertSame(Conversation::sole()->id, $response->json('conversation_id'));

        $this->assertNoTextStored();
    }

    public function test_noise_vague_and_severe_abuse_get_canned_replies(): void
    {
        $this->ask('test')->assertOk()->assertJson([
            'source' => 'noise', 'input_type' => 'noise',
            'answer_en' => 'I am ready to help. Please state your issue.',
        ]);
        $this->ask('help me')->assertOk()->assertJson([
            'source' => 'vague', 'input_type' => 'vague',
            'answer_en' => 'Please specify the error message or the service (e.g., AEPS, PAN) you are having trouble with.',
        ]);
        $this->ask('fuck this')->assertOk()->assertJson([
            'source' => 'abuse_severe', 'input_type' => 'abuse_severe',
            'answer_en' => 'Support is available for technical issues. Please keep the conversation respectful.',
        ]);

        $this->assertNoTextStored();
    }

    public function test_hindi_only_input_is_processed_and_persisted(): void
    {
        $this->ask('पैसा कट गया लेकिन ट्रांजैक्शन फेल')
            ->assertOk()
            ->assertJson(['source' => 'unknown', 'input_type' => 'valid']);

        $this->assertSame('पैसा कट गया लेकिन ट्रांजैक्शन फेल', Message::where('sender_type', 'user')->sole()->message);
    }

    public function test_hindi_input_can_match_a_hindi_key(): void
    {
        $this->seedDefinition(['key_text' => 'पैसा कट गया']);

        $this->ask('पैसा कट गया लेकिन ट्रांजैक्शन फेल')->assertJson(['source' => 'kb']);
    }

    public function test_repeating_the_same_canned_reply_returns_exit_message(): void
    {
        $this->ask('hello')->assertJson(['source' => 'greeting']);

        $this->ask('hello')
            ->assertOk()
            ->assertExactJson($this->reply('exit', 'loop_exit', self::EXIT_MESSAGE, null, ['actions' => [self::ESCALATE_ACTION]]));

        // The canned-reply guard is not cleared on exit, so it keeps exiting.
        $this->ask('hello')->assertJson(['source' => 'exit']);
    }

    public function test_known_gap_canned_reply_guard_survives_intervening_valid_messages(): void
    {
        // Only another canned reply overwrites the guard's memory, so a greeting
        // much later in the conversation exits.
        $this->ask('hello')->assertJson(['source' => 'greeting']);
        $this->ask('money deducted but transaction failed')->assertJson(['source' => 'unknown']);

        $this->ask('hello')->assertJson(['source' => 'exit']);
    }

    public function test_different_canned_replies_do_not_trigger_exit(): void
    {
        $this->ask('hello')->assertJson(['source' => 'greeting']);
        $this->ask('help')->assertJson(['source' => 'vague']);
        $this->ask('hello')->assertJson(['source' => 'greeting']);
    }

    // ---------------------------------------------------------------------
    // Escalation request
    // ---------------------------------------------------------------------

    public function test_escalation_request_points_to_raise_ticket_and_its_text_is_not_stored(): void
    {
        $this->ask('I want to talk to a human')
            ->assertOk()
            ->assertExactJson($this->reply(
                'escalation',
                'escalation_request',
                "Your request has been noted. Please use the 'Raise Ticket' option to connect with our support team, or call our helpline for immediate assistance.",
                "आपका अनुरोध दर्ज किया गया है। कृपया 'टिकट बनाएं' विकल्प का उपयोग करें या तुरंत सहायता के लिए हमारी हेल्पलाइन पर कॉल करें।",
                ['actions' => [self::ESCALATE_ACTION]],
            ));

        $this->assertSame('open', Conversation::sole()->status);
        $this->assertSame(0, Message::count());
    }

    public function test_escalation_request_wins_over_a_knowledge_base_match(): void
    {
        $this->seedDefinition();

        $this->ask('capture timeout again, call me back')
            ->assertJson(['source' => 'escalation']);
    }

    // ---------------------------------------------------------------------
    // Knowledge base hit
    // ---------------------------------------------------------------------

    public function test_kb_hit_with_category_returns_prefixed_answer_and_persists_conversation(): void
    {
        $definition = $this->seedDefinition();

        $response = $this->ask('Biometric capture timeout, please retry')
            ->assertOk();

        $conversation = Conversation::sole();
        $expectedEn = "I understand you are facing a **AEPS** issue.\n\nClean the scanner and retry the capture.";

        $this->assertSame($conversation->id, $this->conversationId);
        $response->assertExactJson($this->reply('kb', 'valid', $expectedEn, 'स्कैनर साफ करें और फिर से प्रयास करें।', [
            'meta'     => ['source' => 'kb', 'input_type' => 'valid', 'category' => 'AEPS'],
            'category' => 'AEPS',
        ]));

        $this->assertNull($conversation->user_id);
        $this->assertSame('AEPS', $conversation->service);
        $this->assertSame('resolved', $conversation->status);
        // Only the path is stored; the query string (?txn=1) is dropped.
        $this->assertSame('/aeps', $conversation->page_url);
        // Guests are recognised by a hash of their session id, never the id itself
        $this->assertSame(hash('sha256', $this->sessionId), $conversation->meta['guest_key']);
        $this->assertSame('AEPS', $conversation->meta['category']);

        $messages = $conversation->messages()->orderBy('id')->get();
        $this->assertCount(2, $messages);

        $this->assertSame('user', $messages[0]->sender_type);
        $this->assertSame('Biometric capture timeout, please retry', $messages[0]->message);
        // assertEquals for JSON columns: MySQL does not preserve object key order.
        $this->assertEquals([
            'source'     => 'page_error',
            'input_type' => 'valid',
            'category'   => 'AEPS',
            'page_url'   => '/aeps',
        ], $messages[0]->data);

        $this->assertSame('ai', $messages[1]->sender_type);
        $this->assertSame($expectedEn . "\nस्कैनर साफ करें और फिर से प्रयास करें।", $messages[1]->message);
        $this->assertEquals([
            'source'           => 'kb',
            'input_type'       => 'valid',
            'category'         => 'AEPS',
            'matched_error_id' => $definition->id,
        ], $messages[1]->data);
    }

    public function test_kb_hit_without_category_has_no_prefix(): void
    {
        $this->seedDefinition(['key_text' => 'invalid otp', 'answer_en' => 'Request a new OTP.', 'answer_hi' => null]);

        $this->ask('Invalid OTP entered')
            ->assertOk()
            ->assertJson([
                'source'    => 'kb',
                'answer_en' => 'Request a new OTP.',
                'answer_hi' => '',
                'category'  => null,
            ]);
    }

    public function test_kb_match_is_case_insensitive_substring_of_the_input(): void
    {
        $this->seedDefinition(['key_text' => 'CAPTURE TIMEOUT']);

        $this->ask('error: Capture Timeout (code 7)')->assertJson(['source' => 'kb']);
    }

    public function test_kb_only_matches_definitions_for_the_configured_default_service(): void
    {
        $this->seedDefinition(['service' => 'PAN']);

        $this->ask('Biometric capture timeout')->assertJson(['source' => 'unknown']);

        config(['smart-ai-assistant.default_service' => 'PAN']);

        $this->ask('Biometric capture timeout!')->assertJson(['source' => 'kb']);
    }

    public function test_mild_abuse_is_still_resolved_from_the_kb_without_a_category(): void
    {
        $this->seedDefinition();

        $this->ask('useless app, capture timeout again')
            ->assertOk()
            ->assertJson([
                'source'     => 'kb',
                'answer_en'  => 'Clean the scanner and retry the capture.',
                'input_type' => 'abuse_mild',
                'category'   => null,
            ]);
    }

    public function test_like_wildcards_in_key_text_match_literally(): void
    {
        $this->seedDefinition(['key_text' => 'error_code']);

        $this->ask('got errorXcode on screen')->assertJson(['source' => 'unknown']);
        $this->ask('got error_code on screen')->assertJson(['source' => 'kb']);
    }

    public function test_a_percent_key_does_not_match_everything(): void
    {
        $this->seedDefinition(['key_text' => '100%']);

        $this->ask('withdrawal of 1000 failed')->assertJson(['source' => 'unknown']);
        $this->ask('battery at 100% but capture failed')->assertJson(['source' => 'kb']);
    }

    public function test_exclamation_marks_in_key_text_match_literally(): void
    {
        // "!" is the LIKE escape character, so it must itself be escaped.
        $this->seedDefinition(['key_text' => 'retry!']);

        $this->ask('please retry! the device')->assertJson(['source' => 'kb']);
    }

    public function test_personal_data_is_redacted_before_storage_but_not_from_matching(): void
    {
        $this->seedDefinition();

        $this->ask('capture timeout for 9876543210, pan ABCDE1234F', 'https://app.test/txn/234567890123')
            ->assertOk()
            ->assertJson(['source' => 'kb']);

        $conversation = Conversation::sole();
        $this->assertSame('/txn/[number]', $conversation->page_url);
        $message = Message::where('sender_type', 'user')->sole();
        $this->assertSame('capture timeout for [phone], pan [pan]', $message->message);
        $this->assertSame('/txn/[number]', $message->data['page_url']);
    }

    public function test_the_redactor_is_replaceable_through_config(): void
    {
        config(['smart-ai-assistant.redactor' => UppercaseRedactor::class]);

        $this->ask('aeps withdrawal failed')->assertOk();

        $this->assertSame('AEPS WITHDRAWAL FAILED', Message::where('sender_type', 'user')->sole()->message);
    }

    public function test_page_url_without_a_path_is_stored_as_null(): void
    {
        $this->ask('aeps withdrawal failed', 'https://app.test?txn=1')->assertOk();

        $this->assertNull(Conversation::sole()->page_url);
    }

    // ---------------------------------------------------------------------
    // Knowledge base miss
    // ---------------------------------------------------------------------

    public function test_kb_miss_with_category_returns_unknown_and_still_persists(): void
    {
        $response = $this->ask('aeps withdrawal failed')->assertOk();

        $response->assertExactJson($this->reply('unknown', 'valid', 'I understand you are facing a **AEPS** issue, but ' . self::UNKNOWN_EN, self::UNKNOWN_HI, [
            'actions'  => [self::ESCALATE_ACTION],
            'meta'     => ['source' => 'unknown', 'input_type' => 'valid', 'category' => 'AEPS'],
            'category' => 'AEPS',
        ]));

        // Unanswered conversations are marked as such (was a known gap: "resolved")
        $this->assertSame('unresolved', Conversation::sole()->status);
        $this->assertSame(2, Message::count());
        $this->assertNull(Message::where('sender_type', 'ai')->sole()->data['matched_error_id']);
    }

    public function test_kb_miss_without_category_has_no_prefix(): void
    {
        $this->ask('money deducted but transaction failed')
            ->assertJson([
                'source'    => 'unknown',
                'answer_en' => self::UNKNOWN_EN,
                'category'  => null,
            ]);
    }

    // ---------------------------------------------------------------------
    // Loop prevention for resolved answers
    // ---------------------------------------------------------------------

    public function test_same_answer_twice_in_a_row_returns_exit_then_resets(): void
    {
        $this->seedDefinition();

        $this->ask('capture timeout')->assertJson(['source' => 'kb']);

        $this->ask('capture timeout')
            ->assertOk()
            ->assertExactJson($this->reply('exit', 'loop_exit', self::EXIT_MESSAGE, null, ['actions' => [self::ESCALATE_ACTION]]));

        // The hash is forgotten on exit, so the third identical request is answered again.
        $this->ask('capture timeout')->assertJson(['source' => 'kb']);

        $this->assertSame(1, Conversation::count());
        $this->assertSame(4, Message::count(), 'exit responses are not stored');
    }

    public function test_loop_guard_compares_answers_not_inputs(): void
    {
        $this->seedDefinition();

        $this->ask('capture timeout')->assertJson(['source' => 'kb']);
        // Different wording, same KB answer and same (null) category -> exit.
        $this->ask('there is a capture timeout')->assertJson(['source' => 'exit']);
    }
}

class UppercaseRedactor implements \Subodh\SmartAiAssistant\Core\Contracts\Redactor
{
    public function redact(string $text): string
    {
        return strtoupper($text);
    }
}
