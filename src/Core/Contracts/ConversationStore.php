<?php

namespace Subodh\SmartAiAssistant\Core\Contracts;

use Subodh\SmartAiAssistant\Core\Data\ConversationContext;
use Subodh\SmartAiAssistant\Core\Data\EscalationRequest;
use Subodh\SmartAiAssistant\Core\Data\EscalationResult;
use Subodh\SmartAiAssistant\Core\Data\Resolution;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;
use Subodh\SmartAiAssistant\Core\Data\UserContext;

/**
 * Conversations: one per chat, holding the stored exchanges and the guards'
 * state.
 *
 * The conversation id comes from the browser, so it is only a hint: it is
 * honoured only when the conversation belongs to the same user (or, for
 * guests, the same session). User-supplied text is redacted before storage.
 */
interface ConversationStore
{
    /**
     * The conversation to continue: $requestedId when it belongs to the caller
     * and is still active, otherwise a new conversation.
     *
     * @param  string|null  $sessionId  Identifies guests; ignored for logged-in users
     * @return int The conversation id
     */
    public function open(?int $requestedId, UserContext $user, ?string $sessionId, ?string $pageUrl): int;

    /**
     * Guard state kept with the conversation.
     */
    public function state(int $conversationId): ConversationState;

    /**
     * Store an exchange whose Resolution::$persist is true, and set the
     * conversation status from its outcome.
     */
    public function record(int $conversationId, ConversationContext $context, StructuredProblem $problem, Resolution $resolution): void;

    /**
     * Store a support request and what the escalation channel did with it.
     */
    public function recordEscalation(int $conversationId, EscalationRequest $request, EscalationResult $result): void;
}
