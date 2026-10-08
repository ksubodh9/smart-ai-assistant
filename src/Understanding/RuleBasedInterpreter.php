<?php

namespace Subodh\SmartAiAssistant\Understanding;

use Subodh\SmartAiAssistant\Core\Contracts\Interpreter;
use Subodh\SmartAiAssistant\Core\Data\IncomingMessage;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;
use Subodh\SmartAiAssistant\Support\InputClassifier;
use Subodh\SmartAiAssistant\Support\Locales;

/**
 * Interpreter backed by the deterministic InputClassifier.
 *
 * The classifier's type is kept in signals['input_type'] because it is still
 * part of the wire format and the stored conversation data. signals['language']
 * is the language the message is written in (null when it cannot be told).
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

    /**
     * @param  array<string, string>  $entityPatterns  Entity name => regex (config
     *         understanding.entities); the first capture group, or the whole match, is the value
     */
    public function __construct(
        private readonly InputClassifier $classifier,
        private readonly array $entityPatterns = [],
        private readonly ?Locales $locales = null,
    ) {
    }

    public function interpret(IncomingMessage $message): StructuredProblem
    {
        $result = $this->classifier->classify($message->text);
        $type = $result['type'];

        return new StructuredProblem(
            intent: self::INTENTS[$type],
            domains: $result['category'] !== null ? [$result['category']] : [],
            entities: $this->entities($message->text),
            signals: [
                'input_type'  => $type,
                'abuse_level' => match ($type) {
                    InputClassifier::TYPE_ABUSE_SEVERE => 'severe',
                    InputClassifier::TYPE_ABUSE_MILD   => 'mild',
                    default                            => 'none',
                },
                'language'    => $this->locales?->detect($message->text),
            ],
        );
    }

    /**
     * @return array<string, string> The first match of each configured entity
     */
    private function entities(string $text): array
    {
        $entities = [];

        foreach ($this->entityPatterns as $name => $pattern) {
            // A broken host pattern must not break the assistant; @ hides the warning
            if (@preg_match($pattern, $text, $match) === 1) {
                $entities[$name] = $match[1] ?? $match[0];
            }
        }

        return $entities;
    }
}
