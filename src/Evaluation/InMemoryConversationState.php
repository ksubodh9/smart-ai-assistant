<?php

namespace Subodh\SmartAiAssistant\Evaluation;

use Subodh\SmartAiAssistant\Core\Contracts\ConversationState;

/**
 * Guard state that lives only as long as one evaluated query.
 */
class InMemoryConversationState implements ConversationState
{
    private array $values = [];

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function put(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->values[$key]);
    }
}
