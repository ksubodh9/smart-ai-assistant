<?php

namespace Subodh\SmartAiAssistant\Answering;

use Subodh\SmartAiAssistant\Core\Contracts\AnswerGenerator;
use Subodh\SmartAiAssistant\Core\Data\GeneratedAnswer;

/**
 * No LLM configured: never answers, so document answers fall through to the
 * usual fallback reply.
 */
class NullAnswerGenerator implements AnswerGenerator
{
    public function generate(string $question, array $passages, string $language): ?GeneratedAnswer
    {
        return null;
    }
}
