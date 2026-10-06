<?php

namespace Subodh\SmartAiAssistant\Escalation;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Subodh\SmartAiAssistant\Core\Contracts\EscalationChannel;
use Subodh\SmartAiAssistant\Core\Contracts\Redactor;
use Subodh\SmartAiAssistant\Core\Data\EscalationRequest;
use Subodh\SmartAiAssistant\Core\Data\EscalationResult;

/**
 * Development channel: writes the request to the log (texts redacted, files
 * listed by name only) and reports it as created.
 */
class LogEscalationChannel implements EscalationChannel
{
    public function __construct(private readonly Redactor $redactor)
    {
    }

    public function escalate(EscalationRequest $request): EscalationResult
    {
        $reference = 'LOG-' . Str::upper(Str::random(8));

        Log::info('Smart assistant escalation', [
            'reference'     => $reference,
            'user_id'       => $request->user->id,
            'message'       => $this->redactor->redact($request->message),
            'error_context' => $request->errorContext !== null ? $this->redactor->redact($request->errorContext) : null,
            'page_url'      => $request->pageUrl,
            'domains'       => $request->domains,
            'attachments'   => array_map(fn ($file) => $file->getClientOriginalName(), $request->attachments),
        ]);

        return EscalationResult::created('Your request has been logged.', $reference);
    }
}
