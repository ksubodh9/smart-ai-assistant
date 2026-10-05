<?php

namespace Subodh\SmartAiAssistant\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Subodh\SmartAiAssistant\Core\Data\IncomingMessage;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;
use Subodh\SmartAiAssistant\Support\InputClassifier;
use Subodh\SmartAiAssistant\Understanding\RuleBasedInterpreter;

class RuleBasedInterpreterTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: list<string>, 3: string, 4: string}>
     *         input, intent, domains, input_type, abuse_level
     */
    public static function inputs(): array
    {
        return [
            'valid with category' => ['aeps withdrawal failed', StructuredProblem::INTENT_REPORT_ERROR, ['AEPS'], 'valid', 'none'],
            'valid no category'   => ['money deducted but transaction failed', StructuredProblem::INTENT_REPORT_ERROR, [], 'valid', 'none'],
            'mild abuse'          => ['damn my recharge failed', StructuredProblem::INTENT_REPORT_ERROR, [], 'abuse_mild', 'mild'],
            'severe abuse'        => ['fuck this', StructuredProblem::INTENT_ABUSE, [], 'abuse_severe', 'severe'],
            'escalation'          => ['talk to a human', StructuredProblem::INTENT_REQUEST_HUMAN, [], 'escalation_request', 'none'],
            'greeting'            => ['hello', StructuredProblem::INTENT_GREETING, [], 'greeting', 'none'],
            'vague'               => ['help me', StructuredProblem::INTENT_VAGUE, [], 'vague', 'none'],
            'noise'               => ['test', StructuredProblem::INTENT_NOISE, [], 'noise', 'none'],
            'empty'               => ['', StructuredProblem::INTENT_EMPTY, [], 'empty', 'none'],
        ];
    }

    #[DataProvider('inputs')]
    public function test_it_maps_classifier_results_to_a_structured_problem(
        string $input,
        string $intent,
        array $domains,
        string $inputType,
        string $abuseLevel
    ): void {
        $problem = (new RuleBasedInterpreter(new InputClassifier()))->interpret(new IncomingMessage($input));

        $this->assertSame($intent, $problem->intent);
        $this->assertSame($domains, $problem->domains);
        $this->assertSame($inputType, $problem->signals['input_type']);
        $this->assertSame($abuseLevel, $problem->signals['abuse_level']);
        $this->assertSame('rules', $problem->interpretedBy);
    }

    public function test_every_classifier_type_has_an_intent(): void
    {
        $types = array_filter(
            (new \ReflectionClass(InputClassifier::class))->getConstants(),
            fn ($name) => str_starts_with($name, 'TYPE_'),
            ARRAY_FILTER_USE_KEY
        );
        $intents = (new \ReflectionClassConstant(RuleBasedInterpreter::class, 'INTENTS'))->getValue();

        $this->assertEqualsCanonicalizing(array_values($types), array_keys($intents));
    }
}
