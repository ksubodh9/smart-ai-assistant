<?php

namespace Subodh\SmartAiAssistant\Understanding;

use Subodh\SmartAiAssistant\Core\Contracts\Interpreter;
use Subodh\SmartAiAssistant\Core\Data\IncomingMessage;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;
use Subodh\SmartAiAssistant\Support\InputClassifier;

/**
 * Interpreter backed by the deterministic InputClassifier.
 *
 * The classifier's type is kept in signals['input_type'] because it is still
 * part of the wire format and the stored conversation data.
 */
class RuleBasedInterpreter implements Interpreter
{
    private const INTENTS = [
        InputClassifier::TYPE_VALID              => StructuredProblem::INTENT_REPORT_ERROR,
        InputClassifier::TYPE_ABUSE_MILD         => StructuredProblem::INTENT_REPORT_ERROR,
        InputClassifier::TYPE_ESCALATION_REQUEST => StructuredProblem::INTENT_REQUEST_HUMAN,
        InputClassifier::TYPE_GREETING           => StructuredProblem::INTENT_GREETING,
        InputClassifier::TYPE_VAGUE              => StructuredProblem::INTENT_VAGUE,
        InputClassifier::TYPE_NOISE              => StructuredProblem::INTENT_NOISE,
        InputClassifier::TYPE_ABUSE_SEVERE       => StructuredProblem::INTENT_ABUSE,
        InputClassifier::TYPE_EMPTY              => StructuredProblem::INTENT_EMPTY,
    ];

    public function __construct(private readonly InputClassifier $classifier)
    {
    }

    public function interpret(IncomingMessage $message): StructuredProblem
    {
        $result = $this->classifier->classify($message->text);
        $type = $result['type'];

        return new StructuredProblem(
            intent: self::INTENTS[$type],
            domains: $result['category'] !== null ? [$result['category']] : [],
            signals: [
                'input_type'  => $type,
                'abuse_level' => match ($type) {
                    InputClassifier::TYPE_ABUSE_SEVERE => 'severe',
                    InputClassifier::TYPE_ABUSE_MILD   => 'mild',
                    default                            => 'none',
                },
            ],
        );
    }
}
