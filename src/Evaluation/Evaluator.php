<?php

namespace Subodh\SmartAiAssistant\Evaluation;

use Subodh\SmartAiAssistant\Core\Contracts\Interpreter;
use Subodh\SmartAiAssistant\Core\Contracts\Redactor;
use Subodh\SmartAiAssistant\Core\Data\ConversationContext;
use Subodh\SmartAiAssistant\Core\Data\IncomingMessage;
use Subodh\SmartAiAssistant\Core\Data\Resolution;
use Subodh\SmartAiAssistant\Core\Data\UserContext;
use Subodh\SmartAiAssistant\Core\Resolution\ResolverPipeline;

/**
 * Replays queries through the configured interpreter and resolver pipeline,
 * the same way /message answers a typed message, and counts what happened.
 *
 * Nothing is stored and nothing is remembered between queries: each one is a
 * new conversation of a guest (so data tools, which need a user, do not run;
 * queries where a tool would apply are counted under "entities").
 */
class Evaluator
{
    public function __construct(
        private readonly Interpreter $interpreter,
        private readonly ResolverPipeline $pipeline,
        private readonly Redactor $redactor,
    ) {
    }

    /**
     * @param  iterable<array{text: string, expected?: ?string, category?: ?string}>  $queries
     *         expected: an outcome (answered, unresolved, clarify, refuse, escalate, exit)
     *         or a source (kb, unknown, greeting, ...); category: the expected domain tag
     * @param  int  $gapLimit  How many of the most frequent texts to keep
     * @param  string  $listOutcome  Which outcome's texts to keep in 'gaps' (default: unanswered)
     */
    public function run(iterable $queries, int $gapLimit = 20, string $listOutcome = Resolution::UNRESOLVED): array
    {
        $report = [
            'total'             => 0,
            'answered'          => 0,
            'outcomes'          => [],
            'sources'           => [],
            'with_category'     => 0,
            'categories'        => [],
            'with_entities'     => 0,
            'unresolved_with_entities' => 0,
            'labelled'          => 0,
            'label_matches'     => 0,
            'category_labelled' => 0,
            'category_matches'  => 0,
            'mismatches'        => [],
            'gaps'              => [],
        ];

        foreach ($queries as $query) {
            $text = trim((string) ($query['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $message = new IncomingMessage($text, IncomingMessage::SOURCE_TYPED);
            $context = new ConversationContext(UserContext::guest(), $message, new InMemoryConversationState());

            $problem = $this->interpreter->interpret($message);
            $resolution = $this->pipeline->resolve($problem, $context);
            $category = $problem->domains[0] ?? null;

            $report['total']++;
            $this->increment($report['outcomes'], $resolution->outcome);
            $this->increment($report['sources'], $resolution->source);

            if ($resolution->outcome === Resolution::ANSWERED) {
                $report['answered']++;
            }

            if ($category !== null) {
                $report['with_category']++;
                $this->increment($report['categories'], $category);
            }

            if ($problem->entities !== []) {
                $report['with_entities']++;

                // Guests get no data tools; a logged-in user could get these answered
                if ($resolution->outcome === Resolution::UNRESOLVED) {
                    $report['unresolved_with_entities']++;
                }
            }

            $expected = $this->label($query['expected'] ?? null);
            if ($expected !== null) {
                $report['labelled']++;

                if (in_array($expected, [strtolower($resolution->outcome), strtolower($resolution->source)], true)) {
                    $report['label_matches']++;
                } else {
                    $this->increment($report['mismatches'], "{$expected} → {$resolution->source}");
                }
            }

            $expectedCategory = $this->label($query['category'] ?? null);
            if ($expectedCategory !== null) {
                $report['category_labelled']++;

                if ($expectedCategory === strtolower((string) $category)) {
                    $report['category_matches']++;
                }
            }

            // By default the unanswered ones: the most frequent of these are the biggest gaps
            if ($resolution->outcome === $listOutcome) {
                $key = $this->normalize($text);
                $report['gaps'][$key]['count'] = ($report['gaps'][$key]['count'] ?? 0) + 1;
                $report['gaps'][$key]['category'] = $category;
                $report['gaps'][$key]['source'] = $resolution->source;
            }
        }

        arsort($report['outcomes']);
        arsort($report['sources']);
        arsort($report['categories']);
        arsort($report['mismatches']);
        uasort($report['gaps'], fn ($a, $b) => $b['count'] <=> $a['count']);
        $report['gaps'] = array_slice($report['gaps'], 0, $gapLimit, true);

        return $report;
    }

    /**
     * Headline numbers, in percent of all queries, for comparing two runs.
     *
     * @return array<string, float|int>
     */
    public static function summary(array $report): array
    {
        $percent = fn (int $count, int $of) => $of > 0 ? round(100 * $count / $of, 1) : 0.0;
        $summary = [
            'total'                  => $report['total'],
            'answered_pct'           => $percent($report['answered'], $report['total']),
            'with_category_pct'      => $percent($report['with_category'], $report['total']),
            'with_entities_pct'      => $percent($report['with_entities'], $report['total']),
            'unresolved_with_entities_pct' => $percent($report['unresolved_with_entities'], $report['total']),
        ];

        foreach ([Resolution::UNRESOLVED, Resolution::CLARIFY, Resolution::REFUSE, Resolution::ESCALATE] as $outcome) {
            $summary["{$outcome}_pct"] = $percent($report['outcomes'][$outcome] ?? 0, $report['total']);
        }

        if ($report['labelled'] > 0) {
            $summary['label_accuracy_pct'] = $percent($report['label_matches'], $report['labelled']);
        }

        if ($report['category_labelled'] > 0) {
            $summary['category_accuracy_pct'] = $percent($report['category_matches'], $report['category_labelled']);
        }

        return $summary;
    }

    private function increment(array &$counts, string $key): void
    {
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    private function label(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return $value === '' ? null : $value;
    }

    /**
     * Group spelling variants together, and never print personal data.
     */
    private function normalize(string $text): string
    {
        $text = mb_strtolower(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return mb_substr($this->redactor->redact(trim($text)), 0, 120);
    }
}
