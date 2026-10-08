<?php

namespace Subodh\SmartAiAssistant\Strategies;

use Subodh\SmartAiAssistant\Core\Contracts\KnowledgeSource;
use Subodh\SmartAiAssistant\Core\Contracts\ResolutionStrategy;
use Subodh\SmartAiAssistant\Core\Data\ConversationContext;
use Subodh\SmartAiAssistant\Core\Data\Resolution;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;

/**
 * Answer a reported error from the knowledge base, as written (every
 * translation the entry has).
 */
class KnowledgeLookupStrategy implements ResolutionStrategy
{
    public function __construct(private readonly KnowledgeSource $knowledge)
    {
    }

    public function resolve(StructuredProblem $problem, ConversationContext $context): ?Resolution
    {
        if ($problem->intent !== StructuredProblem::INTENT_REPORT_ERROR) {
            return null;
        }

        $entry = $this->knowledge->find($context->message, $problem)[0] ?? null;

        if ($entry === null) {
            return null;
        }

        return new Resolution(
            outcome: Resolution::ANSWERED,
            source: 'kb',
            answers: array_filter($entry->content, fn ($text) => $text !== null && $text !== ''),
            persist: true,
            provenance: ['strategy' => 'knowledge_lookup', 'knowledge_id' => $entry->id],
        );
    }

    public function capabilities(): array
    {
        return ['knowledge'];
    }
}
