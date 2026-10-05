<?php

namespace Subodh\SmartAiAssistant\Core\Contracts;

use Subodh\SmartAiAssistant\Core\Data\IncomingMessage;
use Subodh\SmartAiAssistant\Core\Data\KnowledgeEntry;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;

/**
 * Read-only retrieval of knowledge relevant to a message.
 *
 * How entries are matched (substring, keyword, semantic) is the source's own
 * business; callers only see ranked KnowledgeEntry objects.
 */
interface KnowledgeSource
{
    /**
     * @return list<KnowledgeEntry> Best match first; empty when nothing matches
     */
    public function find(IncomingMessage $message, StructuredProblem $problem, int $limit = 1): array;
}
