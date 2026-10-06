<?php

namespace Subodh\SmartAiAssistant\Core\Resolution;

use LogicException;
use Subodh\SmartAiAssistant\Core\Contracts\ResolutionGuard;
use Subodh\SmartAiAssistant\Core\Contracts\ResolutionStrategy;
use Subodh\SmartAiAssistant\Core\Data\ConversationContext;
use Subodh\SmartAiAssistant\Core\Data\Resolution;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;

/**
 * Ordered first-match over strategies, then every guard in order.
 */
final class ResolverPipeline
{
    /**
     * @param  iterable<ResolutionStrategy>  $strategies
     * @param  iterable<ResolutionGuard>  $guards
     */
    public function __construct(
        private readonly iterable $strategies,
        private readonly iterable $guards = [],
    ) {
    }

    public function resolve(StructuredProblem $problem, ConversationContext $context): Resolution
    {
        $resolution = null;

        foreach ($this->strategies as $strategy) {
            if ($resolution = $strategy->resolve($problem, $context)) {
                break;
            }
        }

        if ($resolution === null) {
            throw new LogicException('No resolution strategy handled the message; end the list with a fallback strategy.');
        }

        foreach ($this->guards as $guard) {
            $resolution = $guard->apply($resolution, $problem, $context);
        }

        return $resolution;
    }
}
