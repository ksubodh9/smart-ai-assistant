<?php

namespace Subodh\SmartAiAssistant\Strategies;

use Subodh\SmartAiAssistant\Core\Contracts\KnowledgeSource;
use Subodh\SmartAiAssistant\Core\Contracts\ResolutionStrategy;
use Subodh\SmartAiAssistant\Core\Data\ConversationContext;
use Subodh\SmartAiAssistant\Core\Data\Resolution;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;
use Subodh\SmartAiAssistant\Support\ResponseCatalog;

/**
 * Answer a reported error from the knowledge base.
 */
class KnowledgeLookupStrategy implements ResolutionStrategy
{
    public function __construct(
        private readonly KnowledgeSource $knowledge,
        private readonly ResponseCatalog $responses,
    ) {
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
            answers: [
                'en' => $this->responses->prefix('kb_prefix', $problem->domains[0] ?? null) . $entry->content['en'],
                'hi' => $entry->content['hi'] ?? '',
            ],
            persist: true,
            provenance: ['strategy' => 'knowledge_lookup', 'knowledge_id' => $entry->id],
        );
    }

    public function capabilities(): array
    {
        return ['knowledge'];
    }
}
