<?php

namespace Subodh\SmartAiAssistant\Core\Contracts;

use Subodh\SmartAiAssistant\Core\Data\GeneratedAnswer;
use Subodh\SmartAiAssistant\Core\Data\Passage;

/**
 * Writes an answer to a question from document passages only (usually with
 * an LLM). The question is already redacted.
 *
 * Return null (or throw) when no answer could be produced: the assistant then
 * replies as if there were no documents. Return GeneratedAnswer::unanswerable()
 * when the passages do not answer the question.
 *
 * Bound through config('smart-ai-assistant.ai.driver').
 */
interface AnswerGenerator
{
    /**
     * @param  list<Passage>  $passages
     * @param  string  $language  The reply language, e.g. "Hindi (hi)"
     */
    public function generate(string $question, array $passages, string $language): ?GeneratedAnswer;
}
