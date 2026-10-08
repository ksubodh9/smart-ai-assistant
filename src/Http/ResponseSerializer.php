<?php

namespace Subodh\SmartAiAssistant\Http;

use Subodh\SmartAiAssistant\Core\Data\Resolution;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;
use Subodh\SmartAiAssistant\Support\Locales;
use Subodh\SmartAiAssistant\Support\ResponseCatalog;

/**
 * The /help wire format (protocol 1, PLATFORM_PLAN.md section 6.1):
 *
 *   {protocol, conversation_id, blocks[], actions[], meta{source, input_type, category, locale}}
 *
 * plus the legacy fields {source, answer_en, answer_hi, input_type, category?}
 * for widgets released before blocks; "category" there is only present for
 * stored exchanges. The legacy fields go away one release after blocks.
 */
class ResponseSerializer
{
    public const PROTOCOL = 1;

    /**
     * Outcomes after which the widget may offer to raise a ticket: nothing
     * answered, the user asked for a human, or the assistant gave up (an exit
     * must always leave a way to reach support).
     */
    private const ESCALATE_AFTER = [Resolution::UNRESOLVED, Resolution::ESCALATE, Resolution::EXIT];

    public function __construct(
        private readonly ResponseCatalog $responses,
        private readonly Locales $locales,
    ) {
    }

    /**
     * @param  string  $locale  The reply language (see Support\Locales::choose)
     */
    public function toArray(Resolution $resolution, StructuredProblem $problem, int $conversationId, string $locale): array
    {
        $inputType = $resolution->outcome === Resolution::EXIT
            ? 'loop_exit'
            : ($problem->signals['input_type'] ?? $problem->intent);

        $data = [
            'protocol'        => self::PROTOCOL,
            'conversation_id' => $conversationId,
            'blocks'          => $this->blocks($resolution, $locale),
            'actions'         => $this->actions($resolution, $locale),
            'meta'            => [
                'source'     => $resolution->source,
                'input_type' => $inputType,
                'category'   => $problem->domains[0] ?? null,
                'locale'     => $locale,
            ],
            // Legacy fields
            'source'          => $resolution->source,
            'answer_en'       => $resolution->answers['en'] ?? $this->locales->pick($resolution->answers, $locale)['text'] ?? '',
            'answer_hi'       => $resolution->answers['hi'] ?? null,
            'input_type'      => $inputType,
        ];

        if ($resolution->persist) {
            $data['category'] = $problem->domains[0] ?? null;
        }

        return $data;
    }

    /**
     * One text block in the reply language (or the language it falls back
     * to, named in the block). "basic" format allows **bold** and line breaks
     * only; the widget renders it as text, never as HTML.
     */
    private function blocks(Resolution $resolution, string $locale): array
    {
        if ($resolution->blocks !== []) {
            return $resolution->blocks;
        }

        $answer = $this->locales->pick($resolution->answers, $locale);

        return $answer === null
            ? []
            : [['type' => 'text', 'format' => 'basic', 'locale' => $answer['locale'], 'text' => $answer['text']]];
    }

    private function actions(Resolution $resolution, string $locale): array
    {
        if (!in_array($resolution->outcome, self::ESCALATE_AFTER, true)
            || !config('smart-ai-assistant.capabilities.escalation', true)) {
            return [];
        }

        return [[
            'type'    => 'action',
            'id'      => 'escalate',
            'label'   => $this->locales->pick($this->responses->answers('escalate_action'), $locale)['text'] ?? 'Raise ticket',
            'confirm' => true,
        ]];
    }
}
