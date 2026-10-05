<?php

namespace Subodh\SmartAiAssistant\Core\Data;

/**
 * One piece of knowledge returned by a KnowledgeSource.
 */
final class KnowledgeEntry
{
    /**
     * @param  string  $sourceId  Which KnowledgeSource produced it
     * @param  array<string, string|null>  $content  Answer per locale, e.g. ['en' => '...', 'hi' => '...']
     * @param  list<string>  $domains
     */
    public function __construct(
        public readonly int|string $id,
        public readonly string $sourceId,
        public readonly string $key,
        public readonly array $content,
        public readonly array $domains = [],
        public readonly float $score = 1.0,
        public readonly string $matchType = 'substring',
    ) {
    }
}
