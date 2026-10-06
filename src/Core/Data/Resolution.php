<?php

namespace Subodh\SmartAiAssistant\Core\Data;

/**
 * What a resolution strategy decided, before guards and serialization.
 */
final class Resolution
{
    public const ANSWERED = 'answered';
    public const UNRESOLVED = 'unresolved';
    public const CLARIFY = 'clarify';
    public const REFUSE = 'refuse';
    public const ESCALATE = 'escalate';
    public const EXIT = 'exit';

    /**
     * @param  string  $source  Wire-format source, e.g. 'kb', 'unknown', 'greeting'
     * @param  array{en: string, hi: ?string}  $answers
     * @param  bool  $persist  Whether the exchange is stored as a conversation
     * @param  array<string, mixed>  $provenance  e.g. ['strategy' => 'knowledge_lookup', 'knowledge_id' => 7]
     */
    public function __construct(
        public readonly string $outcome,
        public readonly string $source,
        public readonly array $answers,
        public readonly bool $persist = false,
        public readonly array $provenance = [],
    ) {
    }
}
