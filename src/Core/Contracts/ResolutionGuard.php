<?php

namespace Subodh\SmartAiAssistant\Core\Contracts;

use Subodh\SmartAiAssistant\Core\Data\ConversationContext;
use Subodh\SmartAiAssistant\Core\Data\Resolution;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;

/**
 * A conversation policy applied to every resolution (see ASSISTANT_BEHAVIOR.md),
 * so strategies do not each re-implement "never loop" or "prompt once".
 */
interface ResolutionGuard
{
    /**
     * Return the resolution unchanged, or a replacement (e.g. an exit message).
     */
    public function apply(Resolution $resolution, StructuredProblem $problem, ConversationContext $context): Resolution;
}
