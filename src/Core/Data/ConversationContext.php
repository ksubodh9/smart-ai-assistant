<?php

namespace Subodh\SmartAiAssistant\Core\Data;

use Subodh\SmartAiAssistant\Core\Contracts\ConversationState;

/**
 * Everything a strategy or guard may know about the current exchange.
 */
final class ConversationContext
{
    public function __construct(
        public readonly UserContext $user,
        public readonly IncomingMessage $message,
        public readonly ConversationState $state,
        // The language of the reply (see Support\Locales::choose)
        public readonly string $locale = 'en',
    ) {
    }
}
