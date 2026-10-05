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

    private const FALLBACK_LIMIT = 5000;

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

        // First try a case-insensitive LIKE query where the key_text appears anywhere.
        // "%" and "_" in key_text are escaped, so they match literally.
        $query = ErrorDefinition::where('service', $this->service);
        $escapedKey = "REPLACE(REPLACE(REPLACE(LOWER(key_text), '!', '!!'), '%', '!%'), '_', '!_')";
        $pattern = $query->getConnection()->getDriverName() === 'sqlite'
            ? "'%' || {$escapedKey} || '%'"
            : "CONCAT('%', {$escapedKey}, '%')";

        $definition = $query->whereRaw("LOWER(?) LIKE {$pattern} ESCAPE '!'", [$text])
            ->orderBy('id')
            ->first();

        if ($definition) {
            return $definition;
        }

        // Fallback for collations where SQL LOWER/LIKE and PHP disagree. Capped,
        // because it loads rows into PHP.
        $lowerText = Str::lower($text);
        $candidates = ErrorDefinition::where('service', $this->service)
            ->orderBy('id')
            ->limit(self::FALLBACK_LIMIT)
            ->get();

        foreach ($candidates as $candidate) {
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
