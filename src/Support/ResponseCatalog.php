<?php

namespace Subodh\SmartAiAssistant\Support;

/**
 * The assistant's fixed reply texts.
 *
 * Each key maps to its text per language code (['en' => ..., 'hi' => ...]);
 * hosts override single keys through config('smart-ai-assistant.responses').
 * A language without a text falls back as described in Support\Locales.
 */
class ResponseCatalog
{
    public const DEFAULTS = [
        // Canned replies per input type (not stored)
        'empty'        => ['en' => 'Please type your question.'],
        'noise'        => ['en' => "Sorry, I didn't catch that. Could you describe the problem?"],
        'greeting'     => ['en' => 'Hi! What can I help you with today?'],
        'vague'        => ['en' => 'Could you tell me a bit more? For example, what you were trying to do and the exact message you see.'],
        'abuse_severe' => ['en' => "I'm here to help with technical issues. Please keep the conversation respectful."],

        // The user asked for a human (the reply carries the "raise a ticket" action)
        'escalation'   => ['en' => 'Sure, I can pass this to our support team. Use the button below to raise a ticket.'],

        // Label of the "raise a ticket" action offered with unresolved replies
        'escalate_action' => ['en' => 'Raise ticket'],

        // A guest tried to raise a support request
        'escalation_login_required' => ['en' => 'Please log in to contact support.'],

        // A data tool found nothing the user may see (or the user may not see it)
        'tool_not_found' => ['en' => "I couldn't find that reference in your account. Please check the number and try again."],

        // A data tool failed
        'tool_failed' => ['en' => "I couldn't check that right now. Please try again in a few minutes."],

        // Line under an answer written from documents (:titles = their titles);
        // shown with documents.answers.show_sources
        'document_sources' => ['en' => 'Source: :titles'],

        // No knowledge matched; unknown_category when a category was detected (:category)
        'unknown'          => ['en' => "Sorry, I don't have an answer for that yet. Our support team can look into it for you."],
        'unknown_category' => ['en' => "Sorry, I don't have an answer for this **:category** query yet. Our support team can look into it for you."],

        // A known issue with a service (see smart-ai:notice); :service = its
        // category tag, :message = the notice text. status_notice is shown when
        // the widget opens, status_notice_reply answers a message about that service
        'status_notice'       => ['en' => "**Known issue: :service**\n:message"],
        'status_notice_reply' => ['en' => "This looks related to a known issue with **:service**:\n:message"],

        // The same guidance would be repeated
        'loop_exit'    => ['en' => "I've shared everything I have on this. Our support team can take it from here."],
    ];

    private array $responses;

    public function __construct(array $overrides = [])
    {
        $this->responses = array_replace(self::DEFAULTS, $overrides);
    }

    /**
     * @param  array<string, string>  $replace  Placeholder => value, e.g. [':category' => 'PAYMENTS']
     * @return array<string, string> Text per language code
     */
    public function answers(string $key, array $replace = []): array
    {
        $answers = [];

        foreach ((array) $this->responses[$key] as $locale => $text) {
            if (is_string($locale) && $text !== null && $text !== '') {
                $answers[$locale] = strtr((string) $text, $replace);
            }
        }

        return $answers;
    }
}
