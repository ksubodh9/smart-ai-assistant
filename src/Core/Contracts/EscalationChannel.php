<?php

namespace Subodh\SmartAiAssistant\Core\Contracts;

use Subodh\SmartAiAssistant\Core\Data\EscalationRequest;
use Subodh\SmartAiAssistant\Core\Data\EscalationResult;

/**
 * Hands a conversation to humans: a support ticket, a callback request, a
 * live-chat handoff, ... The host implements it with its own system.
 *
 * The package has validated the texts and attachments against its config and
 * resolved the user server-side. Implementations still apply the host's own
 * rules (authorization, per-user limits) and report them through the result
 * instead of throwing.
 */
interface EscalationChannel
{
    public function escalate(EscalationRequest $request): EscalationResult;
}
