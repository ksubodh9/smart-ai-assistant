<?php

namespace Subodh\SmartAiAssistant\Knowledge;

use Subodh\SmartAiAssistant\Core\Contracts\KnowledgeSource;
use Subodh\SmartAiAssistant\Core\Data\IncomingMessage;
use Subodh\SmartAiAssistant\Core\Data\KnowledgeEntry;
use Subodh\SmartAiAssistant\Core\Data\StructuredProblem;
use Subodh\SmartAiAssistant\Models\ErrorDefinition;

/**
 * Knowledge entries matched by keywords instead of exact text.
 *
 * An entry is a row of smart_ai_error_definitions with meta['match'] =
 * 'keywords' (seed with smart-ai:seed-kb --keywords). Its key_text is a list
 * of words, e.g. "pan refund"; it matches when every word occurs in the
 * message, in any order. Words are compared after lower-casing and mapping
 * spelling variants and synonyms to one word (config knowledge.synonyms:
 * word => [variants]), on both sides. When several entries match, the one
 * with the most words wins (the most specific), then the oldest.
 */
class KeywordKnowledgeSource implements KnowledgeSource
{
    public const SOURCE_ID = 'keywords';

    public const MATCH_TYPE = 'keywords';

    /** Entries loaded into PHP at most; keyword entries are hand-written, so few */
    private const LIMIT = 2000;

    /** @var array<string, string> variant => word */
    private array $canonical = [];

    /**
     * @param  array<string, list<string>>  $synonyms  word => variants (spellings, synonyms, other languages)
     * @param  bool  $typoTolerant  Let a longer word match with a small spelling mistake
     */
    public function __construct(
        private readonly string $service,
        array $synonyms = [],
        private readonly bool $typoTolerant = true,
    ) {
        foreach ($synonyms as $word => $variants) {
            $word = mb_strtolower((string) $word);
            foreach ((array) $variants as $variant) {
                $this->canonical[mb_strtolower((string) $variant)] = $word;
            }
        }
    }

    public function find(IncomingMessage $message, StructuredProblem $problem, int $limit = 1): array
    {
        $words = array_flip($this->words($message->text));

        if ($words === []) {
            return [];
        }

        $matches = [];

        foreach ($this->entries() as $definition) {
            $required = array_unique($this->words($definition->key_text));

            if ($required !== [] && $this->containsAll($words, $required)) {
                $matches[] = [count($required), $definition];
            }
        }

        // Most words first, then the oldest entry
        usort($matches, fn ($a, $b) => [$b[0], $a[1]->id] <=> [$a[0], $b[1]->id]);

        return array_map(
            fn ($match) => $this->toEntry($match[1], $match[0]),
            array_slice($matches, 0, $limit)
        );
    }

    /**
     * @param  array<string, int>  $words  Message words (as keys)
     * @param  list<string>  $required  Entry words
     */
    private function containsAll(array $words, array $required): bool
    {
        foreach ($required as $word) {
            if (!isset($words[$word]) && !$this->containsMisspelling($words, $word)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the message has the word with a small spelling mistake, e.g.
     * "incompleate" for "incomplete". Only Latin-script words of 5 or more
     * letters, with the same first letter; 1 edit up to 8 letters, 2 above.
     * Short words are too easy to confuse ("pan"/"pin").
     *
     * @param  array<string, int>  $words
     */
    private function containsMisspelling(array $words, string $word): bool
    {
        $length = strlen($word);

        if (!$this->typoTolerant || $length < 5 || !ctype_alpha($word)) {
            return false;
        }

        $maxEdits = $length > 8 ? 2 : 1;

        foreach ($words as $candidate => $_) {
            $candidate = (string) $candidate;

            if ($candidate[0] === $word[0]
                && ctype_alpha($candidate)
                && abs(strlen($candidate) - $length) <= $maxEdits
                && levenshtein($candidate, $word) <= $maxEdits) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lower-cased words (letters and digits in any script), variants mapped.
     *
     * @return list<string>
     */
    public function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{M}\p{N}]+/u', mb_strtolower($text), $matches);

        return array_map(fn ($word) => $this->canonical[$word] ?? $word, $matches[0]);
    }

    /**
     * @return iterable<ErrorDefinition>
     */
    private function entries(): iterable
    {
        return ErrorDefinition::where('service', $this->service)
            ->where('meta->match', self::MATCH_TYPE)
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();
    }

    private function toEntry(ErrorDefinition $definition, int $wordCount): KnowledgeEntry
    {
        return new KnowledgeEntry(
            id: $definition->id,
            sourceId: self::SOURCE_ID,
            key: $definition->key_text,
            content: ['en' => $definition->answer_en, 'hi' => $definition->answer_hi],
            domains: [$definition->service],
            score: (float) $wordCount,
            matchType: self::MATCH_TYPE,
        );
    }
}
