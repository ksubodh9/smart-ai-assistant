<?php

namespace Subodh\SmartAiAssistant\Strategies;

use Subodh\SmartAiAssistant\Core\Contracts\ResolutionStrategy;
use Subodh\SmartAiAssistant\Core\Data\ConversationContext;
use Subodh\SmartAiAssistant\Core\Data\Resolution;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;
use Subodh\SmartAiAssistant\Support\ResponseCatalog;

/**
 * Nothing else applied: say it is not documented and offer escalation.
 * Always resolves, so it belongs at the end of the strategy list.
 */
class FallbackStrategy implements ResolutionStrategy
{
    public function __construct(private readonly ResponseCatalog $responses)
    {
    }

    public function resolve(StructuredProblem $problem, ConversationContext $context): ?Resolution
    {
        $category = $problem->domains[0] ?? null;

        return new Resolution(
            outcome: Resolution::UNRESOLVED,
            source: 'unknown',
            answers: $category !== null
                ? $this->responses->answers('unknown_category', [':category' => $category])
                : $this->responses->answers('unknown'),
            persist: true,
            provenance: ['strategy' => 'fallback', 'knowledge_id' => null],
        );
    }

    public function capabilities(): array
    {
        return [];
    }
}
