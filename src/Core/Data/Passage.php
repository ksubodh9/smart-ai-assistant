<?php

namespace Subodh\SmartAiAssistant\Core\Data;

/**
 * One document passage found for a message. Passages are reference
 * material, not answers: they are shown to an AnswerGenerator, never
 * straight to the user.
 */
final class Passage
{
    /**
     * @param  float  $score  Share of the message's search words found in the passage (0–1)
     */
    public function __construct(
        public readonly int $id,
        public readonly int $documentId,
        public readonly string $title,
        public readonly int $version,
        public readonly ?string $heading,
        public readonly string $content,
        public readonly float $score,
    ) {
    }
}
