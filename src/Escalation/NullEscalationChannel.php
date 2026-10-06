<?php

namespace Subodh\SmartAiAssistant\Escalation;

use Subodh\SmartAiAssistant\Core\Contracts\EscalationChannel;
use Subodh\SmartAiAssistant\Core\Data\EscalationRequest;
use Subodh\SmartAiAssistant\Core\Data\EscalationResult;

/**
 * Default channel: escalation is not set up, so every request is rejected.
 * It never pretends a request reached anyone.
 */
class NullEscalationChannel implements EscalationChannel
{
    public function escalate(EscalationRequest $request): EscalationResult
    {
        return EscalationResult::rejected('Support requests are not available here yet. Please contact support directly.');
    }
}
