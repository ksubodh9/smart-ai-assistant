<?php

namespace Subodh\SmartAiAssistant\Resolution\Guards;

use Subodh\SmartAiAssistant\Core\Contracts\ResolutionGuard;
use Subodh\SmartAiAssistant\Core\Data\ConversationContext;
use Subodh\SmartAiAssistant\Core\Data\Resolution;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;
use Subodh\SmartAiAssistant\Support\ResponseCatalog;

/**
 * "Prompt only once": the same canned reply is not sent twice in a row;
 * the user gets the exit message instead.
 *
 * The remembered reply is only replaced by another canned reply, so it
 * survives answered messages in between (pinned as a known gap in the tests).
 */
class ClarifyOnceGuard implements ResolutionGuard
{
    public const STATE_KEY = 'smart_assistant_last_response';

    public function __construct(private readonly ResponseCatalog $responses)
    {
    }

    public function apply(Resolution $resolution, StructuredProblem $problem, ConversationContext $context): Resolution
    {
        if (!in_array($resolution->outcome, [Resolution::CLARIFY, Resolution::REFUSE], true)) {
            return $resolution;
        }

        $reply = $resolution->answers['en'];

        if ($context->state->get(self::STATE_KEY) === $reply) {
            return new Resolution(
                outcome: Resolution::EXIT,
                source: 'exit',
                answers: $this->responses->answers('loop_exit'),
                provenance: ['guard' => 'clarify_once'],
            );
        }

        $context->state->put(self::STATE_KEY, $reply);

        return $resolution;
    }
}
