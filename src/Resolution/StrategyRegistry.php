<?php

namespace Subodh\SmartAiAssistant\Resolution;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Subodh\SmartAiAssistant\Core\Contracts\ResolutionStrategy;

/**
 * Builds the configured strategies, in order, skipping those whose required
 * capabilities are disabled.
 */
class StrategyRegistry
{
    /**
     * @param  list<class-string<ResolutionStrategy>>  $strategies
     * @param  array<string, bool>  $capabilities
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $strategies,
        private readonly array $capabilities,
    ) {
    }

    /**
     * @return list<ResolutionStrategy>
     */
    public function strategies(): array
    {
        $enabled = array_keys(array_filter($this->capabilities));
        $result = [];

        foreach ($this->strategies as $class) {
            $strategy = $this->container->make($class);

            if (!$strategy instanceof ResolutionStrategy) {
                throw new InvalidArgumentException("{$class} must implement " . ResolutionStrategy::class);
            }

            if (array_diff($strategy->capabilities(), $enabled) === []) {
                $result[] = $strategy;
            }
        }

        return $result;
    }
}
