<?php

namespace Subodh\SmartAiAssistant\Core\Data;

use Illuminate\Http\UploadedFile;

/**
 * A user's confirmed request to hand the conversation to humans.
 *
 * Identity comes only from $user (server-side). The texts are what the user
 * typed or picked, unredacted: the escalation channel is the host's own
 * support system, which needs the real details.
 */
final class EscalationRequest
{
    /**
     * @param  string  $message  What the user typed; '' when only files were sent
     * @param  string|null  $errorContext  Page error the user picked before typing, if any
     * @param  string|null  $pageUrl  Path only; query strings are stripped before this point
     * @param  list<UploadedFile>  $attachments  Already validated against config('escalation.attachments')
     * @param  list<string>  $domains  Host tags detected in the texts, e.g. ['PAYMENTS']
     * @param  int|null  $conversationId  The assistant conversation the request was raised from
     */
    public function __construct(
        public readonly UserContext $user,
        public readonly string $message,
        public readonly ?string $errorContext = null,
        public readonly ?string $pageUrl = null,
        public readonly array $attachments = [],
        public readonly array $domains = [],
        public readonly ?int $conversationId = null,
    ) {
    }
}
