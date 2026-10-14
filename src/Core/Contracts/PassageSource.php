<?php

namespace Subodh\SmartAiAssistant\Core\Contracts;

use Subodh\SmartAiAssistant\Core\Data\Passage;

/**
 * Finds document passages relevant to a message.
 *
 * Kept apart from KnowledgeSource on purpose: a KnowledgeEntry is an answer
 * the user may see as written, a Passage is material an answer can be
 * grounded in. Implementations must only return passages of active document
 * versions whose audience is in $audiences, filtered in the query itself.
 */
interface PassageSource
{
    /**
     * @param  list<string>  $audiences  Document audiences the user may see, e.g. ['public'] for guests
     * @return list<Passage> Best first; only passages scoring at least the source's minimum
     */
    public function search(string $text, array $audiences, int $limit = 3): array;
}
