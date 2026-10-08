<?php

namespace Subodh\SmartAiAssistant\Resolution\Guards;

use Subodh\SmartAiAssistant\Core\Contracts\ResolutionGuard;
use Subodh\SmartAiAssistant\Core\Data\ConversationContext;
use Subodh\SmartAiAssistant\Core\Data\Resolution;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;
use Subodh\SmartAiAssistant\Support\ResponseCatalog;

/**
 * "Never loop": if an answer would be identical to the previous one, exit
 * cleanly instead. The memory is cleared on exit, so asking a third time
 * gets the answer again.
 */
class LoopGuard implements ResolutionGuard
{
    public const STATE_KEY = 'smart_assistant_last_response_hash';

    public function __construct(private readonly ResponseCatalog $responses)
    {
    }

    public function apply(Resolution $resolution, StructuredProblem $problem, ConversationContext $context): Resolution
    {
        if (!in_array($resolution->outcome, [Resolution::ANSWERED, Resolution::UNRESOLVED], true)) {
            return $resolution;
        }

        $hash = md5(json_encode($resolution->answers));

        if ($context->state->get(self::STATE_KEY) === $hash) {
            $context->state->forget(self::STATE_KEY);

            return new Resolution(
                outcome: Resolution::EXIT,
                source: 'exit',
                answers: $this->responses->answers('loop_exit'),
                provenance: ['guard' => 'loop'],
            );
        }

        $context->state->put(self::STATE_KEY, $hash);

        return $resolution;
    }
}
