<?php

namespace Subodh\SmartAiAssistant\Core\Contracts;

/**
 * Small key-value state that guards keep between messages (e.g. the last
 * reply sent). Session-backed for now; per conversation later.
 */
interface ConversationState
{
    public function get(string $key): mixed;

    public function put(string $key, mixed $value): void;

    public function forget(string $key): void;
}
