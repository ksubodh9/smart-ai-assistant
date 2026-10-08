<?php

namespace Subodh\SmartAiAssistant\Persistence;

use Subodh\SmartAiAssistant\Core\Contracts\ConversationState;
use Subodh\SmartAiAssistant\Core\Contracts\ConversationStore;
use Subodh\SmartAiAssistant\Core\Contracts\Redactor;
use Subodh\SmartAiAssistant\Core\Data\ConversationContext;
use Subodh\SmartAiAssistant\Core\Data\EscalationRequest;
use Subodh\SmartAiAssistant\Core\Data\EscalationResult;
use Subodh\SmartAiAssistant\Core\Data\Resolution;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;
use Subodh\SmartAiAssistant\Core\Data\UserContext;
use Subodh\SmartAiAssistant\Models\Conversation;
use Subodh\SmartAiAssistant\Models\Message;
use Subodh\SmartAiAssistant\Support\Locales;

/**
 * Conversations in smart_ai_conversations, exchanges in smart_ai_messages.
 *
 * User-supplied text is redacted before storage; answers are not, since they
 * are authored content (and may contain helpline numbers). Guests are told
 * apart by a hash of their session id in meta['guest_key'].
 */
class EloquentConversationStore implements ConversationStore
{
    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_UNRESOLVED = 'unresolved';
    public const STATUS_ESCALATED = 'escalated';

    private const STATUS_BY_OUTCOME = [
        Resolution::ANSWERED   => self::STATUS_RESOLVED,
        Resolution::UNRESOLVED => self::STATUS_UNRESOLVED,
    ];

    /** @var array<int, Conversation> Conversations opened in this request */
    private array $open = [];

    public function __construct(
        private readonly Redactor $redactor,
        private readonly string $service,
        private readonly int $idleMinutes = 120,
        private readonly Locales $locales = new Locales(),
    ) {
    }

    public function open(?int $requestedId, UserContext $user, ?string $sessionId, ?string $pageUrl): int
    {
        $guestKey = !$user->isAuthenticated() && $sessionId !== null ? hash('sha256', $sessionId) : null;
        $conversation = $requestedId !== null ? Conversation::find($requestedId) : null;

        if (!$conversation || !$this->belongsTo($conversation, $user, $guestKey) || $this->isIdle($conversation)) {
            $conversation = Conversation::create([
                'user_id'  => $user->id,
                'service'  => $this->service,
                'status'   => self::STATUS_OPEN,
                'page_url' => $pageUrl !== null ? $this->redactor->redact($pageUrl) : null,
                'meta'     => array_filter(['guest_key' => $guestKey]),
            ]);
        }

        return ($this->open[$conversation->id] = $conversation)->id;
    }

    public function state(int $conversationId): ConversationState
    {
        return new ConversationMetaState($this->conversation($conversationId));
    }

    public function record(int $conversationId, ConversationContext $context, StructuredProblem $problem, Resolution $resolution): void
    {
        $conversation = $this->conversation($conversationId);
        $message = $context->message;
        $inputType = $problem->signals['input_type'] ?? $problem->intent;
        $category = $problem->domains[0] ?? null;

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type'     => 'user',
            'message'         => $this->redactor->redact($message->text),
            'data'            => [
                'source'     => $message->source,
                'input_type' => $inputType,
                'category'   => $category,
                'page_url'   => $message->pageUrl !== null ? $this->redactor->redact($message->pageUrl) : null,
            ],
        ]);

        // The answer as the user saw it, in the reply language
        $answer = $this->locales->pick($resolution->answers, $context->locale);

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type'     => 'ai',
            'message'         => $answer['text'] ?? '',
            'data'            => [
                'locale'           => $answer['locale'] ?? $context->locale,
                'source'           => $resolution->source,
                'input_type'       => $inputType,
                'category'         => $category,
                'matched_error_id' => $resolution->provenance['knowledge_id'] ?? null,
            ] + (isset($resolution->provenance['tool']) ? ['tool' => $resolution->provenance['tool']] : []),
        ]);

        // An escalated conversation stays escalated
        if ($conversation->status !== self::STATUS_ESCALATED && isset(self::STATUS_BY_OUTCOME[$resolution->outcome])) {
            $conversation->status = self::STATUS_BY_OUTCOME[$resolution->outcome];
        }

        if ($category !== null) {
            $conversation->meta = array_merge($conversation->meta ?? [], ['category' => $category]);
        }

        $conversation->touch();
    }

    public function recordEscalation(int $conversationId, EscalationRequest $request, EscalationResult $result): void
    {
        $conversation = $this->conversation($conversationId);

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type'     => 'user',
            'message'         => $this->redactor->redact($request->message),
            'data'            => [
                'source'        => 'escalation',
                'error_context' => $request->errorContext !== null ? $this->redactor->redact($request->errorContext) : null,
                'attachments'   => count($request->attachments),
                'page_url'      => $request->pageUrl !== null ? $this->redactor->redact($request->pageUrl) : null,
            ],
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type'     => 'system',
            'message'         => $result->message,
            'data'            => [
                'escalation_status' => $result->status,
                'reference'         => $result->reference,
            ],
        ]);

        if ($result->isCreated()) {
            $conversation->status = self::STATUS_ESCALATED;
            $conversation->meta = array_merge($conversation->meta ?? [], ['escalation_reference' => $result->reference]);
        }

        $conversation->touch();
    }

    private function belongsTo(Conversation $conversation, UserContext $user, ?string $guestKey): bool
    {
        if ($user->isAuthenticated()) {
            return (string) $conversation->user_id === $user->id;
        }

        return $conversation->user_id === null
            && $guestKey !== null
            && hash_equals($conversation->meta['guest_key'] ?? '', $guestKey);
    }

    private function isIdle(Conversation $conversation): bool
    {
        return $conversation->updated_at->lt(now()->subMinutes($this->idleMinutes));
    }

    private function conversation(int $conversationId): Conversation
    {
        return $this->open[$conversationId] ??= Conversation::findOrFail($conversationId);
    }
}
