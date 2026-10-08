<?php

namespace Subodh\SmartAiAssistant\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Subodh\SmartAiAssistant\Models\ErrorDefinition;
use Subodh\SmartAiAssistant\Models\Message;
use Subodh\SmartAiAssistant\Tests\TestCase;

/**
 * Every reply is one text block in one language: the one picked in the
 * widget, else the language of the message, else the one used earlier in
 * the conversation (MaddoxPay: English, Hindi, Hinglish).
 */
class ReplyLanguageTest extends TestCase
{
    use RefreshDatabase;

    private string $sessionId;

    private ?int $conversationId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessionId = Str::random(40);
    }

    private function send(string $text, ?string $locale = null)
    {
        $response = $this->withCredentials()
            ->withCookie(config('session.cookie'), $this->sessionId)
            ->postJson('/smart-assistant/message', array_filter([
                'text'            => $text,
                'locale'          => $locale,
                'conversation_id' => $this->conversationId,
            ], fn ($v) => $v !== null));

        $this->conversationId = $response->json('conversation_id') ?? $this->conversationId;

        return $response;
    }

    private function seedCaptureTimeout(): void
    {
        ErrorDefinition::create([
            'service'   => 'AEPS',
            'key_text'  => 'capture timeout',
            'answer_en' => 'Clean the scanner and retry the capture.',
            'answer_hi' => 'स्कैनर साफ करें और फिर से प्रयास करें।',
        ]);
    }

    public function test_english_question_gets_one_english_block(): void
    {
        $this->send('How to use AEPS?')
            ->assertOk()
            ->assertJsonPath('meta.locale', 'en')
            ->assertJsonPath('blocks', [[
                'type'   => 'text',
                'format' => 'basic',
                'locale' => 'en',
                'text'   => "Sorry, I don't have an answer for this **AEPS** query yet. Our support team can look into it for you.",
            ]])
            ->assertJsonPath('actions.0.label', 'Raise ticket');
    }

    public function test_hinglish_question_gets_a_hinglish_reply_and_action(): void
    {
        $this->send('mATM par device kaise register karen')
            ->assertOk()
            ->assertJsonPath('meta.locale', 'hi-Latn')
            ->assertJsonPath('blocks.0.locale', 'hi-Latn')
            ->assertJsonPath('blocks.0.text', 'Maaf kijiye, **MATM** se jude is sawaal ki jaankari abhi mere paas nahi hai. Hamari support team ismein aapki madad kar sakti hai.')
            ->assertJsonPath('actions.0.label', 'Ticket banayein');
    }

    public function test_hindi_question_gets_a_hindi_reply(): void
    {
        $this->send('पैसा कट गया लेकिन ट्रांजैक्शन फेल')
            ->assertOk()
            ->assertJsonPath('meta.locale', 'hi')
            ->assertJsonPath('blocks.0.text', 'माफ़ कीजिए, इस बारे में अभी मेरे पास जानकारी नहीं है। हमारी सपोर्ट टीम इसमें आपकी मदद कर सकती है।')
            ->assertJsonPath('actions.0.label', 'टिकट बनाएं');
    }

    public function test_hinglish_question_gets_the_hindi_knowledge_answer(): void
    {
        $this->seedCaptureTimeout();

        $this->send('capture timeout aa raha hai')
            ->assertOk()
            ->assertJsonPath('meta.source', 'kb')
            ->assertJsonPath('meta.locale', 'hi-Latn')
            // The KB has no Hinglish column: Hinglish falls back to Hindi
            ->assertJsonPath('blocks.0.locale', 'hi')
            ->assertJsonPath('blocks.0.text', 'स्कैनर साफ करें और फिर से प्रयास करें।');

        // Stored as shown, with the language it was shown in
        $answer = Message::where('sender_type', 'ai')->sole();
        $this->assertSame('स्कैनर साफ करें और फिर से प्रयास करें।', $answer->message);
        $this->assertSame('hi', $answer->data['locale']);
    }

    public function test_a_missing_translation_falls_back_to_english(): void
    {
        ErrorDefinition::create(['service' => 'AEPS', 'key_text' => 'invalid otp', 'answer_en' => 'Request a new OTP.']);

        $this->send('invalid otp aa raha hai')
            ->assertJsonPath('meta.locale', 'hi-Latn')
            ->assertJsonPath('blocks.0.locale', 'en')
            ->assertJsonPath('blocks.0.text', 'Request a new OTP.');
    }

    public function test_the_language_picked_in_the_widget_wins(): void
    {
        $this->seedCaptureTimeout();

        $this->send('capture timeout aa raha hai', 'en')
            ->assertJsonPath('meta.locale', 'en')
            ->assertJsonPath('blocks.0.text', 'Clean the scanner and retry the capture.');

        $this->send('How to use AEPS?', 'hi')
            ->assertJsonPath('meta.locale', 'hi')
            ->assertJsonPath('actions.0.label', 'टिकट बनाएं');
    }

    public function test_auto_and_unknown_codes_follow_the_message(): void
    {
        $this->send('पैसा कट गया', 'auto')->assertJsonPath('meta.locale', 'hi');
        $this->send('How to use AEPS?', 'fr')->assertJsonPath('meta.locale', 'en');
    }

    public function test_short_messages_keep_the_language_of_the_conversation(): void
    {
        $this->send('पैसा कट गया लेकिन ट्रांजैक्शन फेल')->assertJsonPath('meta.locale', 'hi');

        // "hello" is too short to tell
        $this->send('hello')
            ->assertJsonPath('meta.locale', 'hi')
            ->assertJsonPath('blocks.0.text', 'नमस्ते! बताइए, मैं आपकी क्या मदद कर सकती हूँ?');
    }

    public function test_a_new_conversation_starts_in_the_default_language(): void
    {
        $this->send('hello')
            ->assertJsonPath('meta.locale', 'en')
            ->assertJsonPath('blocks.0.text', 'Hi! What can I help you with today?');
    }

    public function test_locale_is_limited_in_length(): void
    {
        $this->send('hello', str_repeat('x', 21))
            ->assertStatus(422)
            ->assertJsonValidationErrors('locale');
    }

    public function test_the_older_help_endpoint_accepts_a_locale_too(): void
    {
        $this->withCredentials()
            ->withCookie(config('session.cookie'), $this->sessionId)
            ->postJson('/smart-assistant/help', ['error_text' => 'Transaction failed', 'locale' => 'hi'])
            ->assertOk()
            ->assertJsonPath('meta.locale', 'hi');
    }
}
