<?php

namespace Subodh\SmartAiAssistant\Core\Contracts;

use Subodh\SmartAiAssistant\Core\Data\ToolResult;
use Subodh\SmartAiAssistant\Core\Data\UserContext;

/**
 * Read access to one piece of host data (e.g. a transaction's status) for
 * the assistant. Hosts implement tools; the package never reads host tables.
 *
 * The package calls authorize() first and execute() only when it returns true,
 * both with the server-side UserContext. Tools return display-safe values
 * only: mask anything personal before it goes into the ToolResult.
 *
 * Tools are registered in config('smart-ai-assistant.data_tools') and run when
 * the 'data_tools' capability is on.
 */
interface DataTool
{
    /**
     * Short machine name, e.g. 'transaction_status' (used in logs and stored provenance).
     */
    public function name(): string;

    /**
     * What the tool answers, in one sentence.
     */
    public function description(): string;

    /**
     * The arguments, all required: argument name => description. Values come
     * from StructuredProblem::$entities with the same name (config
     * understanding.entities), so a tool runs only when every one was found.
     *
     * @return array<string, string>
     */
    public function argumentSchema(): array;

    /**
     * Whether this user may see the data the arguments point to. Return false
     * both for "not allowed" and "does not exist"; the user gets the same reply.
     *
     * @param  array<string, string>  $arguments
     */
    public function authorize(UserContext $user, array $arguments): bool;

    /**
     * @param  array<string, string>  $arguments
     */
    public function execute(UserContext $user, array $arguments): ToolResult;
}
