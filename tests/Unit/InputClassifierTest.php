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

            // KNOWN BUG: substring keyword matching ("vi" in device/service, "pan" in company, "ticket").
            'device -> RECHARGE (known bug)'      => ['my device is not detected', 'valid', 'RECHARGE', true, false],
            'RD service -> RECHARGE (known bug)'  => ['Error 1001: RD service not running', 'valid', 'RECHARGE', true, false],
            'company -> PAN (known bug)'          => ['company name mismatch', 'valid', 'PAN', true, false],
            'raise ticket -> IRCTC (known bug)'   => ['how to raise ticket', 'valid', 'IRCTC', true, false],

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
        $result = (new InputClassifier())->classify($input);

        $this->assertSame($type, $result['type'], 'type');
        $this->assertSame($category, $result['category'], 'category');
        $this->assertSame($shouldProcess, $result['should_process'], 'should_process');
        $this->assertSame($shouldEscalate, $result['should_escalate'], 'should_escalate');
    }

    public function test_non_processable_types_carry_a_canned_response(): void
    {
        $classifier = new InputClassifier();

        $this->assertSame('Please type your issue message.', $classifier->classify('')['response']);
        $this->assertSame('Hello. Please state the issue you are facing.', $classifier->classify('hello')['response']);
        $this->assertSame('I am ready to help. Please state your issue.', $classifier->classify('test')['response']);
        $this->assertSame(
            'Please specify the error message or the service (e.g., AEPS, PAN) you are having trouble with.',
            $classifier->classify('help')['response']
        );
        $this->assertSame(
            'Support is available for technical issues. Please keep the conversation respectful.',
            $classifier->classify('fuck this')['response']
        );
    }

    public function test_processable_types_have_no_canned_response(): void
    {
        $classifier = new InputClassifier();

        $this->assertNull($classifier->classify('talk to a human')['response']);
        $this->assertNull($classifier->classify('aeps withdrawal failed')['response']);
        $this->assertNull($classifier->classify('damn my recharge failed')['response']);
    }
}
