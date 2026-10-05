<?php

namespace Subodh\SmartAiAssistant\Core\Contracts;

use Subodh\SmartAiAssistant\Core\Data\IncomingMessage;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;

/**
 * Turns a user message into a StructuredProblem. Pure: no data access.
 */
interface Interpreter
{
    public function interpret(IncomingMessage $message): StructuredProblem;
}
