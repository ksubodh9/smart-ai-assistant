<?php

namespace Subodh\SmartAiAssistant\Core\Contracts;

use Subodh\SmartAiAssistant\Core\Data\ConversationContext;
use Subodh\SmartAiAssistant\Core\Data\Resolution;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;

/**
 * One way of resolving a problem. Strategies run in configured order and the
 * first non-null Resolution wins.
 */
interface ResolutionStrategy
{
    /**
     * @return Resolution|null Null means "not mine", so the next strategy runs
     */
    public function resolve(StructuredProblem $problem, ConversationContext $context): ?Resolution;

    /**
     * Capabilities (config 'capabilities') that must be enabled for this strategy to run.
     *
     * @return list<string>
     */
    public function capabilities(): array;
}
