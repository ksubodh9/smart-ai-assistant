<?php

namespace Subodh\SmartAiAssistant\Http;

use Subodh\SmartAiAssistant\Core\Data\Resolution;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;

/**
 * The /help wire format:
 * {conversation_id, source, answer_en, answer_hi, input_type, category?}.
 * "category" is only present for stored exchanges.
 */
class ResponseSerializer
{
    public function toArray(Resolution $resolution, StructuredProblem $problem, ?int $conversationId = null): array
    {
        $data = [
            'conversation_id' => $conversationId,
            'source'          => $resolution->source,
            'answer_en'       => $resolution->answers['en'],
            'answer_hi'       => $resolution->answers['hi'],
            'input_type'      => $resolution->outcome === Resolution::EXIT
                ? 'loop_exit'
                : ($problem->signals['input_type'] ?? $problem->intent),
        ];

        if ($resolution->persist) {
            $data['category'] = $problem->domains[0] ?? null;
        }

        return $data;
    }
}
