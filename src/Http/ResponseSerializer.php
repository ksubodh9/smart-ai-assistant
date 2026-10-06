<?php

namespace Subodh\SmartAiAssistant\Http;

use Subodh\SmartAiAssistant\Core\Data\Resolution;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;
use Subodh\SmartAiAssistant\Support\ResponseCatalog;

/**
 * The /help wire format (protocol 1, PLATFORM_PLAN.md section 6.1):
 *
 *   {protocol, conversation_id, blocks[], actions[], meta{source, input_type, category}}
 *
 * plus the legacy fields {source, answer_en, answer_hi, input_type, category?}
 * for widgets released before blocks; "category" there is only present for
 * stored exchanges. The legacy fields go away one release after blocks.
 */
class ResponseSerializer
{
    public const PROTOCOL = 1;

    /** Outcomes after which the widget may offer to raise a ticket */
    private const ESCALATE_AFTER = [Resolution::UNRESOLVED, Resolution::ESCALATE];

    public function __construct(private readonly ResponseCatalog $responses)
    {
    }

    public function toArray(Resolution $resolution, StructuredProblem $problem, int $conversationId): array
    {
        $inputType = $resolution->outcome === Resolution::EXIT
            ? 'loop_exit'
            : ($problem->signals['input_type'] ?? $problem->intent);

        $data = [
            'protocol'        => self::PROTOCOL,
            'conversation_id' => $conversationId,
            'blocks'          => $this->blocks($resolution),
            'actions'         => $this->actions($resolution),
            'meta'            => [
                'source'     => $resolution->source,
                'input_type' => $inputType,
                'category'   => $problem->domains[0] ?? null,
            ],
            // Legacy fields
            'source'          => $resolution->source,
            'answer_en'       => $resolution->answers['en'],
            'answer_hi'       => $resolution->answers['hi'],
            'input_type'      => $inputType,
        ];

        if ($resolution->persist) {
            $data['category'] = $problem->domains[0] ?? null;
        }

        return $data;
    }

    /**
     * One text block per answer language. "basic" format allows **bold** and
     * line breaks only; the widget renders it as text, never as HTML.
     */
    private function blocks(Resolution $resolution): array
    {
        $blocks = [];

        foreach ($resolution->answers as $locale => $text) {
            if ($text !== null && $text !== '') {
                $blocks[] = ['type' => 'text', 'format' => 'basic', 'locale' => $locale, 'text' => $text];
            }
        }

        return $blocks;
    }

    private function actions(Resolution $resolution): array
    {
        if (!in_array($resolution->outcome, self::ESCALATE_AFTER, true)
            || !config('smart-ai-assistant.capabilities.escalation', true)) {
            return [];
        }

        return [[
            'type'    => 'action',
            'id'      => 'escalate',
            'label'   => $this->responses->answers('escalate_action')['en'],
            'confirm' => true,
        ]];
    }
}
