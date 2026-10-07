<?php

namespace Subodh\SmartAiAssistant\Knowledge;

use Subodh\SmartAiAssistant\Core\Contracts\KnowledgeSource;
use Subodh\SmartAiAssistant\Core\Data\IncomingMessage;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;

/**
 * Several knowledge sources in order of trust: the first one that finds
 * anything answers (config knowledge.sources).
 */
class CompositeKnowledgeSource implements KnowledgeSource
{
    /**
     * @param  list<KnowledgeSource>  $sources
     */
    public function __construct(private readonly array $sources)
    {
    }

    public function find(IncomingMessage $message, StructuredProblem $problem, int $limit = 1): array
    {
        foreach ($this->sources as $source) {
            $entries = $source->find($message, $problem, $limit);

            if ($entries !== []) {
                return $entries;
            }
        }

        return [];
    }
}
