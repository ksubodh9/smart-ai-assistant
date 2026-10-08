<?php

namespace Subodh\SmartAiAssistant\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Subodh\SmartAiAssistant\Support\InputClassifier;

/**
 * Characterization tests: these pin the classifier's CURRENT behaviour so
 * refactoring cannot change it silently. Cases marked "KNOWN BUG" record
 * wrong behaviour on purpose; fix them in a dedicated commit that updates
 * the expectation here (see PLATFORM_PLAN.md, step 1).
 */
class InputClassifierTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: ?string, 3: bool, 4: bool}>
     *         input, type, category, should_process, should_escalate
     */
    public static function inputs(): array
    {
        return [
            // Empty
            'empty string'           => ['', 'empty', null, false, false],
            'whitespace only'        => ['   ', 'empty', null, false, false],

            // Greetings
            'hello with punctuation' => ['Hello!', 'greeting', null, false, false],
            'namaste'                => ['namaste', 'greeting', null, false, false],
            'good morning'           => ['good morning', 'greeting', null, false, false],
            // KNOWN BUG: the 1-2 character noise rule runs before the greeting check.
            'hi (known bug)'         => ['hi', 'noise', null, false, false],
            'yo (known bug)'         => ['yo', 'noise', null, false, false],

            // Vague
            'help'                   => ['help', 'vague', null, false, false],
            'help me'                => ['help me', 'vague', null, false, false],
            'not working'            => ['not working', 'vague', null, false, false],
            'please help'            => ['please help', 'vague', null, false, false],
            'hinglish vague'         => ['kuch gadbad hai', 'vague', null, false, false],

            // Noise
            'test'                   => ['test', 'noise', null, false, false],
            'testing'                => ['testing', 'noise', null, false, false],
            'digits'                 => ['123', 'noise', null, false, false],
            'digits with space'      => ['12345 678', 'noise', null, false, false],
            'abc'                    => ['abc', 'noise', null, false, false],
            'repeated chars'         => ['aaaa', 'noise', null, false, false],
            'punctuation only'       => ['!!!', 'noise', null, false, false],
            'two chars'              => ['ok', 'noise', null, false, false],

            // Severe abuse
            'english profanity'      => ['fuck this', 'abuse_severe', null, false, false],
            'insult'                 => ['you bastard', 'abuse_severe', null, false, false],
            'threat'                 => ['i will kill you', 'abuse_severe', null, false, false],
            'hindi slur'             => ['chutiya app', 'abuse_severe', null, false, false],

            // Escalation requests
            'talk to a human'        => ['talk to a human', 'escalation_request', null, true, true],
            'call me back'           => ['please call me back', 'escalation_request', null, true, true],
            'escalate'               => ['I want to escalate this', 'escalation_request', null, true, true],
            'not helping'            => ['this is not helping', 'escalation_request', null, true, true],

            // Mild abuse is processed. KNOWN BUG: category detection is skipped for it.
            'mild abuse loses category (known bug)' => ['this app is useless and my aeps failed', 'abuse_mild', null, true, false],
            'mild abuse with recharge'              => ['damn my recharge failed', 'abuse_mild', null, true, false],

            // Valid input with category tagging
            'pan'                    => ['PAN card correction status', 'valid', 'PAN', true, false],
            'aeps'                   => ['aeps withdrawal failed', 'valid', 'AEPS', true, false],
            'recharge'               => ['mobile recharge not done', 'valid', 'RECHARGE', true, false],
            'payout'                 => ['payout to bank pending', 'valid', 'PAYOUT', true, false],
            'kyc'                    => ['ekyc pending', 'valid', 'KYC', true, false],
            'irctc'                  => ['irctc booking failed', 'valid', 'IRCTC', true, false],
            'hinglish aeps'          => ['aeps me paisa kat gaya', 'valid', 'AEPS', true, false],
            'no category'            => ['money deducted but transaction failed', 'valid', null, true, false],

            // Keywords match whole words only, with an optional plural "s"
            'device has no category'     => ['my device is not detected', 'valid', null, true, false],
            'RD service is a device issue' => ['Error 1001: RD service not running', 'valid', 'DEVICE', true, false],
            'company is not pan'         => ['company name is wrong', 'valid', null, true, false],
            'video is not vi'            => ['video kyc not opening', 'valid', 'KYC', true, false],
            'plural keyword'             => ['two recharges failed', 'valid', 'RECHARGE', true, false],
            'keyword in punctuation'     => ['status (aeps)?', 'valid', 'AEPS', true, false],
            'uppercase vi'               => ['VI recharge failed', 'valid', 'RECHARGE', true, false],
            // Was a known bug: "ticket" was an IRCTC keyword, so support-ticket questions were tagged IRCTC
            'raise ticket has no category' => ['how to raise ticket', 'valid', null, true, false],

            // Devanagari is text, not noise (Unicode-aware patterns)
            'hindi sentence'             => ['पैसा कट गया लेकिन ट्रांजैक्शन फेल', 'valid', null, true, false],
            'hindi pan'                  => ['मेरा पैन कार्ड', 'valid', null, true, false],
            'two hindi characters'       => ['है', 'noise', null, false, false],
            'hindi danda only'           => ['।।।', 'noise', null, false, false],
            'invalid utf-8'              => ["\xC3\x28 aeps failed", 'noise', null, false, false],
        ];
    }

    #[DataProvider('inputs')]
    public function test_it_classifies_input(
        string $input,
        string $type,
        ?string $category,
        bool $shouldProcess,
        bool $shouldEscalate
    ): void {
        $result = self::maddoxPayClassifier()->classify($input);

        $this->assertSame($type, $result['type'], 'type');
        $this->assertSame($category, $result['category'], 'category');
        $this->assertSame($shouldProcess, $result['should_process'], 'should_process');
        $this->assertSame($shouldEscalate, $result['should_escalate'], 'should_escalate');
    }

    /**
     * @return array<string, array{0: string, 1: string}> input, type
     */
    public static function genericInputs(): array
    {
        return [
            'english greeting'       => ['hello', 'greeting'],
            'english vague'          => ['help me', 'vague'],
            'english severe abuse'   => ['fuck this', 'abuse_severe'],
            'english mild abuse'     => ['useless app, payment failed', 'abuse_mild'],
            'escalation'             => ['talk to a human', 'escalation_request'],
            'noise'                  => ['test', 'noise'],
            // Host vocabulary is not built in
            'hindi greeting is text' => ['namaste', 'valid'],
            'hinglish vague is text' => ['kuch gadbad hai', 'valid'],
            'hindi slur is not built in' => ['chutiya app', 'valid'],
        ];
    }

    #[DataProvider('genericInputs')]
    public function test_package_defaults_are_generic_english(string $input, string $type): void
    {
        $result = (new InputClassifier())->classify($input);

        $this->assertSame($type, $result['type']);
        $this->assertNull($result['category'], 'no categories by default');
    }

    public function test_configured_patterns_replace_only_the_types_they_name(): void
    {
        $classifier = new InputClassifier(['greeting' => ['/^salaam$/iu']]);

        $this->assertSame('greeting', $classifier->classify('salaam')['type']);
        $this->assertSame('valid', $classifier->classify('hello')['type'], 'default greetings replaced');
        $this->assertSame('vague', $classifier->classify('help me')['type'], 'other types keep defaults');
    }

    public function test_first_matching_category_wins(): void
    {
        $classifier = new InputClassifier([], ['CARDS' => ['card'], 'PAYMENTS' => ['payment', 'card']]);

        $this->assertSame('CARDS', $classifier->classify('card payment failed')['category']);
        $this->assertSame('PAYMENTS', $classifier->classify('two payments failed')['category']);
    }

    public function test_escalation_requests_and_vague_input_keep_their_category(): void
    {
        $classifier = new InputClassifier(
            ['vague' => ['/^(card|refund)\s*(issue)?$/iu']],
            ['CARDS' => ['card'], 'PAYMENTS' => ['refund']],
        );

        // The category goes with the ticket, and tells a later clarify step what to ask about
        $this->assertSame(['escalation_request', 'CARDS'], array_values(array_intersect_key(
            $classifier->classify('my card is blocked, call me back'),
            ['type' => 1, 'category' => 1]
        )));
        $this->assertSame(['vague', 'PAYMENTS'], array_values(array_intersect_key(
            $classifier->classify('refund issue'),
            ['type' => 1, 'category' => 1]
        )));
        // Canned types other than vague still carry none
        $this->assertNull($classifier->classify('hello')['category']);
    }

    private static function maddoxPayClassifier(): InputClassifier
    {
        $config = require __DIR__ . '/../Fixtures/maddoxpay-config.php';

        return new InputClassifier($config['understanding']['patterns'], $config['understanding']['categories']);
    }
}
