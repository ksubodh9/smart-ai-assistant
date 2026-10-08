<?php

namespace Subodh\SmartAiAssistant\Strategies;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Subodh\SmartAiAssistant\Core\Contracts\DataTool;
use Subodh\SmartAiAssistant\Core\Contracts\ResolutionStrategy;
use Subodh\SmartAiAssistant\Core\Data\ConversationContext;
use Subodh\SmartAiAssistant\Core\Data\Resolution;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;
use Subodh\SmartAiAssistant\Core\Data\ToolResult;
use Subodh\SmartAiAssistant\Support\ResponseCatalog;
use Throwable;

/**
 * Answer from host data: the first configured DataTool whose arguments were
 * all found in the message (StructuredProblem::$entities) runs, for logged-in
 * users only, and only after the tool authorizes the user.
 *
 * "Not allowed" and "not found" get the same reply, so nobody can learn
 * whether someone else's reference exists. Every call is logged with the
 * user, tool and outcome (not the result).
 */
class DataToolStrategy implements ResolutionStrategy
{
    public function __construct(
        private readonly Container $container,
        private readonly ResponseCatalog $responses,
    ) {
    }

    public function resolve(StructuredProblem $problem, ConversationContext $context): ?Resolution
    {
        if ($problem->intent !== StructuredProblem::INTENT_REPORT_ERROR
            || $problem->entities === []
            || !$context->user->isAuthenticated()) {
            return null;
        }

        foreach ($this->tools() as $tool) {
            $arguments = array_intersect_key($problem->entities, $tool->argumentSchema());

            if (count($arguments) === count($tool->argumentSchema())) {
                return $this->run($tool, $arguments, $context);
            }
        }

        return null;
    }

    public function capabilities(): array
    {
        return ['data_tools'];
    }

    /**
     * The configured tools, built only when a message has entities.
     *
     * @return list<DataTool>
     */
    private function tools(): array
    {
        $tools = [];

        foreach ((array) config('smart-ai-assistant.data_tools', []) as $class) {
            $tool = $this->container->make($class);

            if (!$tool instanceof DataTool) {
                throw new InvalidArgumentException("{$class} must implement " . DataTool::class);
            }

            $tools[] = $tool;
        }

        return $tools;
    }

    private function run(DataTool $tool, array $arguments, ConversationContext $context): Resolution
    {
        $user = $context->user;
        $provenance = ['strategy' => 'data_tool', 'tool' => $tool->name()];

        try {
            $result = $tool->authorize($user, $arguments) ? $tool->execute($user, $arguments) : null;
        } catch (Throwable $e) {
            // Details go through the host's exception handling; the audit line names the class only
            report($e);
            $this->audit($tool, $user->id, 'failed', get_class($e));

            return new Resolution(
                outcome: Resolution::UNRESOLVED,
                source: 'data_tool',
                answers: $this->responses->answers('tool_failed'),
                persist: true,
                provenance: $provenance,
            );
        }

        $this->audit($tool, $user->id, $result === null ? 'denied' : $result->status);

        if ($result === null || !$result->isFound()) {
            return new Resolution(
                outcome: Resolution::UNRESOLVED,
                source: 'data_tool',
                answers: $this->responses->answers('tool_not_found'),
                persist: true,
                provenance: $provenance,
            );
        }

        $blocks = [['type' => 'key_value', 'title' => $result->title, 'items' => $result->items]];
        if ($result->note !== null) {
            $blocks[] = ['type' => 'text', 'format' => 'plain', 'text' => $result->note];
        }

        return new Resolution(
            outcome: Resolution::ANSWERED,
            source: 'data_tool',
            answers: [$context->locale => $this->plainText($result)],
            persist: true,
            provenance: $provenance,
            blocks: $blocks,
        );
    }

    /**
     * The result as text, for widgets that only read answer_en and for the
     * stored conversation.
     */
    private function plainText(ToolResult $result): string
    {
        $lines = ["**{$result->title}**"];

        foreach ($result->items as $item) {
            $lines[] = "{$item['label']}: {$item['value']}";
        }

        if ($result->note !== null) {
            $lines[] = '';
            $lines[] = $result->note;
        }

        return implode("\n", $lines);
    }

    /**
     * Ids and outcome only: arguments and results can identify customers.
     */
    private function audit(DataTool $tool, ?string $userId, string $outcome, ?string $error = null): void
    {
        Log::info('Smart assistant data tool', array_filter([
            'tool'    => $tool->name(),
            'user_id' => $userId,
            'outcome' => $outcome,
            'error'   => $error,
        ], fn ($value) => $value !== null));
    }
}
