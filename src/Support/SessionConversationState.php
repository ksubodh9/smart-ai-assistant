<?php

namespace Subodh\SmartAiAssistant\Support;

use Illuminate\Contracts\Session\Session;
use Subodh\SmartAiAssistant\Core\Contracts\ConversationState;

/**
 * Guard state kept in the Laravel session (one state per browser session).
 */
class SessionConversationState implements ConversationState
{
    public function __construct(private readonly Session $session)
    {
    }

    public function get(string $key): mixed
    {
        return $this->session->get($key);
    }

    public function put(string $key, mixed $value): void
    {
        $this->session->put($key, $value);
    }

    public function forget(string $key): void
    {
        $this->session->forget($key);
    }
}
