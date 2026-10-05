<?php

namespace Subodh\SmartAiAssistant\Core\Contracts;

/**
 * Masks personal data in user-supplied text before it is stored or logged
 * (and, later, before it is sent to any external model).
 */
interface Redactor
{
    public function redact(string $text): string;
}
