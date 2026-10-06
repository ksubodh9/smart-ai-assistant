<?php

namespace Subodh\SmartAiAssistant\Http;

use Illuminate\Http\Request;
use Subodh\SmartAiAssistant\Core\Contracts\ConversationStore;
use Subodh\SmartAiAssistant\Core\Contracts\Interpreter;
use Subodh\SmartAiAssistant\Core\Contracts\UserContextResolver;
use Subodh\SmartAiAssistant\Core\Data\ConversationContext;
use Subodh\SmartAiAssistant\Core\Data\IncomingMessage;
use Subodh\SmartAiAssistant\Core\Resolution\ResolverPipeline;

/**
 * Answers one validated user message: resolve user → open conversation →
 * interpret → ResolverPipeline → store → serialize.
 *
 * Shared by /message and the older /help. Resolve it per request (method
 * injection): its collaborators depend on per-request config.
 */
class MessageResponder
{
    public function __construct(
        private readonly UserContextResolver $users,
        private readonly Interpreter $interpreter,
        private readonly ResolverPipeline $pipeline,
        private readonly ConversationStore $conversations,
        private readonly ResponseSerializer $serializer,
    ) {
    }

    /**
     * @param  string  $source  One of the IncomingMessage::SOURCE_* values
     * @param  string|null  $pageUrl  As sent; only the path is kept
     * @param  int|null  $conversationId  As sent; only honoured for its owner
     */
    public function respond(Request $request, string $text, string $source, ?string $pageUrl, ?int $conversationId): array
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
        $context = new ConversationContext($user, $message, $this->conversations->state($conversationId));

        $problem = $this->interpreter->interpret($message);
        $resolution = $this->pipeline->resolve($problem, $context);

        // Only meaningful exchanges are stored (not canned replies, escalation or exits)
        if ($resolution->persist) {
            $this->conversations->record($conversationId, $context, $problem, $resolution);
        }

        return $this->serializer->toArray($resolution, $problem, $conversationId);
    }
}
