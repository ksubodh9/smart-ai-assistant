<?php

namespace Subodh\SmartAiAssistant\Support;

/**
 * The assistant's fixed reply texts.
 *
 * Each key maps to an answer per locale; hosts override single keys through
 * config('smart-ai-assistant.responses'). Prefixes are English-only strings
 * with a :category placeholder.
 */
class ResponseCatalog
{
    public const DEFAULTS = [
        // Canned replies per input type (not stored)
        'empty'        => ['en' => 'Please type your issue message.'],
        'noise'        => ['en' => 'I am ready to help. Please state your issue.'],
        'greeting'     => ['en' => 'Hello. Please state the issue you are facing.'],
        'vague'        => ['en' => 'Please specify the error message or the service you are having trouble with.'],
        'abuse_severe' => ['en' => 'Support is available for technical issues. Please keep the conversation respectful.'],

        // The user asked for a human
        'escalation'   => ['en' => "Your request has been noted. Please use the 'Raise Ticket' option to connect with our support team, or call our helpline for immediate assistance."],

        // Label of the "raise a ticket" action offered with unresolved replies
        'escalate_action' => ['en' => 'Raise ticket'],

        // A guest tried to raise a support request
        'escalation_login_required' => ['en' => 'Please log in to contact support.'],

        // No knowledge matched
        'unknown'      => ['en' => "this specific error is not yet documented.\n\nIf this issue is urgent, please use the 'Raise Ticket' option to contact support."],

        // The same guidance would be repeated
        'loop_exit'    => ['en' => "I've shared all available guidance for this issue.\nPlease contact support if further assistance is required."],

        // Prepended to the English answer when a category was detected
        'kb_prefix'      => "I understand you are facing a **:category** issue.\n\n",
        'unknown_prefix' => 'I understand you are facing a **:category** issue, but ',
    ];

    private array $responses;

    public function __construct(array $overrides = [])
    {
        $this->responses = array_replace(self::DEFAULTS, $overrides);
    }

    /**
     * @return array{en: string, hi: ?string}
     */
    public function answers(string $key): array
    {
        $answers = $this->responses[$key];

        return ['en' => $answers['en'], 'hi' => $answers['hi'] ?? null];
    }

    /**
     * The prefix for a detected category, or '' when there is none.
     */
    public function prefix(string $key, ?string $category): string
    {
        return $category !== null ? strtr($this->responses[$key], [':category' => $category]) : '';
    }
}
