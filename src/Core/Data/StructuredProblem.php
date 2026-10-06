<?php

namespace Subodh\SmartAiAssistant\Core\Data;

/**
 * What the user means, as understood by an Interpreter.
 *
 * Resolution strategies work from this instead of raw text, so a rule-based
 * interpreter can later be joined by an AI one without touching them.
 */
final class StructuredProblem
{
    public const INTENT_REPORT_ERROR = 'report_error';
    public const INTENT_REQUEST_HUMAN = 'request_human';
    public const INTENT_GREETING = 'greeting';
    public const INTENT_VAGUE = 'vague';
    public const INTENT_NOISE = 'noise';
    public const INTENT_ABUSE = 'abuse';
    public const INTENT_EMPTY = 'empty';

    /**
     * @param  list<string>  $domains  Host-defined tags, e.g. ['PAYMENTS']
     * @param  array<string, mixed>  $entities  Extracted values, e.g. ['reference_id' => '...']
     * @param  array<string, mixed>  $signals  e.g. ['abuse_level' => 'mild', 'input_type' => 'abuse_mild']
     */
    public function __construct(
        public readonly string $intent,
        public readonly array $domains = [],
        public readonly array $entities = [],
        public readonly array $signals = [],
        public readonly float $confidence = 1.0,
        public readonly string $interpretedBy = 'rules',
    ) {
    }
}
