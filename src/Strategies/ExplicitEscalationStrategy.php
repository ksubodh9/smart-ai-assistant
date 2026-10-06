<?php

namespace Subodh\SmartAiAssistant\Strategies;

use Subodh\SmartAiAssistant\Core\Contracts\ResolutionStrategy;
use Subodh\SmartAiAssistant\Core\Data\ConversationContext;
use Subodh\SmartAiAssistant\Core\Data\Resolution;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;
use Subodh\SmartAiAssistant\Support\ResponseCatalog;

/**
 * The user asked for a human: point them to escalation, nothing stored.
 */
class ExplicitEscalationStrategy implements ResolutionStrategy
{
    public function __construct(private readonly ResponseCatalog $responses)
    {
    }

    public function resolve(StructuredProblem $problem, ConversationContext $context): ?Resolution
    {
        if ($problem->intent !== StructuredProblem::INTENT_REQUEST_HUMAN) {
            return null;
        }

        return new Resolution(
            outcome: Resolution::ESCALATE,
            source: 'escalation',
            answers: $this->responses->answers('escalation'),
            provenance: ['strategy' => 'explicit_escalation'],
        );
    }

    public function capabilities(): array
    {
        return ['escalation'];
    }
}
