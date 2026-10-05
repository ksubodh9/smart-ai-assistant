<?php

namespace Subodh\SmartAiAssistant\Knowledge;

use Illuminate\Support\Str;
use Subodh\SmartAiAssistant\Core\Contracts\KnowledgeSource;
use Subodh\SmartAiAssistant\Core\Data\IncomingMessage;
use Subodh\SmartAiAssistant\Core\Data\KnowledgeEntry;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;
use Subodh\SmartAiAssistant\Models\ErrorDefinition;

/**
 * Knowledge from the smart_ai_error_definitions table.
 *
 * An entry matches when its key_text appears anywhere in the message
 * (case-insensitive). Only entries for the configured service are searched.
 */
class DatabaseKnowledgeSource implements KnowledgeSource
{
    public const SOURCE_ID = 'database';

    public function __construct(private readonly string $service)
    {
    }

    public function find(IncomingMessage $message, StructuredProblem $problem, int $limit = 1): array
    {
        $definition = $this->findDefinition(trim($message->text));

        return $definition ? [$this->toEntry($definition)] : [];
    }

    private function findDefinition(string $text): ?ErrorDefinition
    {
        if ($text === '') {
            return null;
        }

        // First try a case-insensitive LIKE query where the key_text appears anywhere
        $definition = ErrorDefinition::where('service', $this->service)
            ->whereRaw('LOWER(?) LIKE CONCAT("%", LOWER(key_text), "%")', [$text])
            ->first();

        if ($definition) {
            return $definition;
        }

        // Fallback: fetch all definitions for the service and check with Str::contains
        $lowerText = Str::lower($text);
        foreach (ErrorDefinition::where('service', $this->service)->get() as $candidate) {
            if (Str::contains($lowerText, Str::lower($candidate->key_text))) {
                return $candidate;
            }
        }

        return null;
    }

    private function toEntry(ErrorDefinition $definition): KnowledgeEntry
    {
        return new KnowledgeEntry(
            id: $definition->id,
            sourceId: self::SOURCE_ID,
            key: $definition->key_text,
            content: ['en' => $definition->answer_en, 'hi' => $definition->answer_hi],
            domains: [$definition->service],
        );
    }
}
