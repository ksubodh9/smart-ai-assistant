<?php

namespace Subodh\SmartAiAssistant\Core\Data;

/**
 * What an AnswerGenerator made of a question and its passages.
 */
final class GeneratedAnswer
{
    /**
     * @param  bool  $answerable  False when the passages do not answer the question
     * @param  list<int>  $sourceIds  Ids of the passages the answer is based on
     */
    public function __construct(
        public readonly bool $answerable,
        public readonly string $text = '',
        public readonly array $sourceIds = [],
    ) {
    }

    public static function unanswerable(): self
    {
        return new self(false);
    }
}
