<?php

namespace Subodh\SmartAiAssistant\Http;

use Illuminate\Http\Request;
use Subodh\SmartAiAssistant\Core\Contracts\ConversationState;
use Subodh\SmartAiAssistant\Core\Contracts\ConversationStore;
use Subodh\SmartAiAssistant\Core\Contracts\Interpreter;
use Subodh\SmartAiAssistant\Core\Contracts\UserContextResolver;
use Subodh\SmartAiAssistant\Core\Data\ConversationContext;
use Subodh\SmartAiAssistant\Core\Data\IncomingMessage;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;
use Subodh\SmartAiAssistant\Core\Data\UserContext;
use Subodh\SmartAiAssistant\Core\Resolution\ResolverPipeline;
use Subodh\SmartAiAssistant\Support\Locales;

/**
 * Answers one validated user message: resolve user → open conversation →
 * interpret → choose the reply language → ResolverPipeline → store → serialize.
 *
 * Shared by /message and the older /help. Resolve it per request (method
 * injection): its collaborators depend on per-request config.
 */
class MessageResponder
{
    /** The reply language last used in the conversation, for messages too short to tell */
    public const LOCALE_STATE_KEY = 'smart_assistant_locale';

    public function __construct(
        private readonly UserContextResolver $users,
        private readonly Interpreter $interpreter,
        private readonly ResolverPipeline $pipeline,
        private readonly ConversationStore $conversations,
        private readonly ResponseSerializer $serializer,
        private readonly Locales $locales,
    ) {
    }

    /**
     * @param  string  $source  One of the IncomingMessage::SOURCE_* values
     * @param  string|null  $pageUrl  As sent; only the path is kept
     * @param  int|null  $conversationId  As sent; only honoured for its owner
     * @param  string|null  $locale  The language picked in the widget; 'auto' or null to follow the user
     */
    public function respond(Request $request, string $text, string $source, ?string $pageUrl, ?int $conversationId, ?string $locale = null): array
    {
        $user = $this->users->resolve($request);

        // Store the path only: query strings can carry transaction ids and personal data
        $pagePath = $pageUrl !== null ? (parse_url($pageUrl, PHP_URL_PATH) ?: null) : null;

        $conversationId = $this->conversations->open(
            $conversationId,
            $user,
            $request->hasSession() ? $request->session()->getId() : null,
            $pagePath,
        );

        $message = new IncomingMessage(trim($text), $source, $pagePath);
        $state = $this->conversations->state($conversationId);

        $problem = $this->interpreter->interpret($message);
        $context = new ConversationContext($user, $message, $state, $this->replyLocale($locale, $problem, $state, $user));
        $resolution = $this->pipeline->resolve($problem, $context);

        // Only meaningful exchanges are stored (not canned replies, escalation or exits)
        if ($resolution->persist) {
            $this->conversations->record($conversationId, $context, $problem, $resolution);
        }

        return $this->serializer->toArray($resolution, $problem, $conversationId, $context->locale);
    }

    private function replyLocale(?string $requested, StructuredProblem $problem, ConversationState $state, UserContext $user): string
    {
        $previous = $state->get(self::LOCALE_STATE_KEY);
        $locale = $this->locales->choose($requested, $problem->signals['language'] ?? null, $previous, $user->locale);

        if ($locale !== $previous) {
            $state->put(self::LOCALE_STATE_KEY, $locale);
        }

        return $locale;
    }
}
