<?php

namespace Subodh\SmartAiAssistant\Strategies;

use Subodh\SmartAiAssistant\Core\Contracts\ResolutionStrategy;
use Subodh\SmartAiAssistant\Core\Data\ConversationContext;
use Subodh\SmartAiAssistant\Core\Data\Resolution;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;
use Subodh\SmartAiAssistant\Support\ResponseCatalog;

/**
 * Greeting, vague, noise, empty and abusive input: a direct canned reply,
 * nothing stored.
 */
class InputGuardStrategy implements ResolutionStrategy
{
    private const INTENTS = [
        StructuredProblem::INTENT_EMPTY,
        StructuredProblem::INTENT_ABUSE,
        StructuredProblem::INTENT_NOISE,
        StructuredProblem::INTENT_GREETING,
        StructuredProblem::INTENT_VAGUE,
    ];

    public function __construct(private readonly ResponseCatalog $responses)
    {
    }

    public function resolve(StructuredProblem $problem, ConversationContext $context): ?Resolution
    {
        if (!in_array($problem->intent, self::INTENTS, true)) {
            return null;
        }

        // Replies are keyed by input type (e.g. abuse_severe), which is also the wire source
        $type = $problem->signals['input_type'] ?? $problem->intent;

        return new Resolution(
            outcome: $problem->intent === StructuredProblem::INTENT_ABUSE ? Resolution::REFUSE : Resolution::CLARIFY,
            source: $type,
            answers: $this->responses->answers($type),
            provenance: ['strategy' => 'input_guard'],
        );
    }

    public function capabilities(): array
    {
        return [];
    }
}
