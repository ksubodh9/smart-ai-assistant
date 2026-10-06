<?php

namespace Subodh\SmartAiAssistant\Persistence;

use Subodh\SmartAiAssistant\Core\Contracts\Redactor;
use Subodh\SmartAiAssistant\Core\Data\ConversationContext;
use Subodh\SmartAiAssistant\Core\Data\Resolution;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;
use Subodh\SmartAiAssistant\Models\Conversation;
use Subodh\SmartAiAssistant\Models\Message;

/**
 * Stores an exchange as a conversation with a user and an AI message.
 *
 * User-supplied text is redacted before storage; answers are not, since they
 * are authored content (and may contain helpline numbers).
 */
class EloquentConversationStore
{
    public function __construct(
        private readonly Redactor $redactor,
        private readonly string $service,
    ) {
    }

    /**
     * @return int The new conversation id
     */
    public function record(ConversationContext $context, StructuredProblem $problem, Resolution $resolution): int
    {
        $message = $context->message;
        $storedText = $this->redactor->redact($message->text);
        $inputType = $problem->signals['input_type'] ?? $problem->intent;
        $category = $problem->domains[0] ?? null;

        $conversation = Conversation::create([
            'user_id' => $context->user->id,
            'service' => $this->service,
            'status'  => 'resolved',
            'page_url'=> $message->pageUrl !== null ? $this->redactor->redact($message->pageUrl) : null,
            'meta'    => [
                'raw_error_text' => $storedText,
                'input_type'     => $inputType,
                'category'       => $category,
            ],
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type'     => 'user',
            'message'         => $storedText,
            'data'            => [
                'input_type' => $inputType,
                'category'   => $category,
            ],
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type'     => 'ai',
            'message'         => $resolution->answers['en'] . "\n" . $resolution->answers['hi'],
            'data'            => [
                'source'           => $resolution->source,
                'input_type'       => $inputType,
                'category'         => $category,
                'matched_error_id' => $resolution->provenance['knowledge_id'] ?? null,
            ],
        ]);

        return $conversation->id;
    }
}
