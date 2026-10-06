<?php

namespace Subodh\SmartAiAssistant\Persistence;

use Subodh\SmartAiAssistant\Core\Contracts\ConversationState;
use Subodh\SmartAiAssistant\Models\Conversation;

/**
 * Guard state stored in the conversation's meta['state'] (saved on every change).
 */
class ConversationMetaState implements ConversationState
{
    public function __construct(private readonly Conversation $conversation)
    {
    }

    public function get(string $key): mixed
    {
        return $this->conversation->meta['state'][$key] ?? null;
    }

    public function put(string $key, mixed $value): void
    {
        $meta = $this->conversation->meta ?? [];
        $meta['state'][$key] = $value;
        $this->conversation->meta = $meta;
        $this->conversation->save();
    }

    public function forget(string $key): void
    {
        $meta = $this->conversation->meta ?? [];
        unset($meta['state'][$key]);
        $this->conversation->meta = $meta;
        $this->conversation->save();
    }
}
