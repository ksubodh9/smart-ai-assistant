<?php

namespace Subodh\SmartAiAssistant\Console\Commands;

use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Subodh\SmartAiAssistant\Evaluation\Evaluator;

class EvaluateQueries extends Command
{
    protected $signature = 'smart-ai:eval
        {file : CSV or Excel file; row 1 is a header. A = query text, B = expected outcome or source (optional), C = expected category (optional)}
        {--top=20 : How many of the most frequent queries to list}
        {--list=unresolved : Which outcome to list the queries of: unresolved, clarify, refuse, escalate, answered}
        {--json= : Save the headline numbers to this file, for a later --baseline}
        {--baseline= : A file saved earlier with --json; shows the change}';

    protected $description = 'Replay real queries through the assistant (nothing is stored) and report how many it answers';

    public function handle(Evaluator $evaluator)
    {
        $file = $this->argument('file');

        if (!is_file($file)) {
            $this->error("File not found: {$file}");
            return 1;
        }

        try {
            $reader = IOFactory::createReaderForFile($file);
            $reader->setReadDataOnly(true);
            $rows = $reader->load($file)->getActiveSheet()->toArray(null, true, true, true);
        } catch (\Throwable $e) {
            $this->error('Failed to read the file: ' . $e->getMessage());
            return 1;
        }

        array_shift($rows); // header

        $report = $evaluator->run(
            array_map(fn ($row) => ['text' => $row['A'] ?? '', 'expected' => $row['B'] ?? null, 'category' => $row['C'] ?? null], $rows),
            max(0, (int) $this->option('top')),
            (string) $this->option('list'),
        );

        if ($report['total'] === 0) {
            $this->warn('No queries in column A.');
            return 1;
        }

        $this->printReport($report);

        $summary = Evaluator::summary($report);

        if ($baselineFile = $this->option('baseline')) {
            $this->printComparison($baselineFile, $summary);
        }

        if ($jsonFile = $this->option('json')) {
            file_put_contents($jsonFile, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
            $this->info("Headline numbers saved to {$jsonFile}");
        }

        return 0;
    }

    private function printReport(array $report): void
    {
        $total = $report['total'];
        $pct = fn (int $count, int $of = 0) => sprintf('%.1f%%', 100 * $count / max(1, $of ?: $total));

        $this->info("Queries evaluated: {$total}");
        $this->line("Answered (knowledge base or data): {$report['answered']} ({$pct($report['answered'])})");
        $this->line("Category detected: {$report['with_category']} ({$pct($report['with_category'])})");
        $this->line("Contain a data-tool entity (e.g. a reference): {$report['with_entities']} ({$pct($report['with_entities'])})");
        $this->line("  of which unanswered here, but answerable by a data tool for a logged-in user: {$report['unresolved_with_entities']} ({$pct($report['unresolved_with_entities'])})");

        $this->newLine();
        $this->table(['Outcome', 'Queries', 'Share'], $this->rows($report['outcomes'], $pct));
        $this->table(['Source', 'Queries', 'Share'], $this->rows($report['sources'], $pct));

        if ($report['categories'] !== []) {
            $this->table(['Category', 'Queries', 'Share'], $this->rows($report['categories'], $pct));
        }

        if ($report['labelled'] > 0) {
            $this->line("Expected outcome matched: {$report['label_matches']} of {$report['labelled']} ({$pct($report['label_matches'], $report['labelled'])})");

            if ($report['mismatches'] !== []) {
                $this->table(['Expected → got', 'Queries'], array_map(
                    fn ($key, $count) => [$key, $count],
                    array_keys($report['mismatches']),
                    $report['mismatches'],
                ));
            }
        }

        if ($report['category_labelled'] > 0) {
            $this->line("Expected category matched: {$report['category_matches']} of {$report['category_labelled']} ({$pct($report['category_matches'], $report['category_labelled'])})");
        }

        if ($report['gaps'] !== []) {
            $this->newLine();
            $this->info("Most frequent '{$this->option('list')}' queries (personal data masked):");
            $this->table(['Queries', 'Source', 'Category', 'Text'], array_map(
                fn ($text, $gap) => [$gap['count'], $gap['source'], $gap['category'] ?? '-', $text],
                array_keys($report['gaps']),
                $report['gaps'],
            ));
        }
    }

    private function rows(array $counts, callable $pct): array
    {
        return array_map(fn ($key, $count) => [$key, $count, $pct($count)], array_keys($counts), $counts);
    }

    private function printComparison(string $baselineFile, array $summary): void
    {
        $baseline = is_file($baselineFile) ? json_decode((string) file_get_contents($baselineFile), true) : null;

        if (!is_array($baseline)) {
            $this->warn("Baseline not readable: {$baselineFile}");
            return;
        }

        $rows = [];
        foreach ($summary as $metric => $value) {
            $before = $baseline[$metric] ?? null;
            $rows[] = [
                $metric,
                $before ?? '-',
                $value,
                is_numeric($before) ? sprintf('%+.1f', $value - $before) : '-',
            ];
        }

        $this->newLine();
        $this->info("Compared with {$baselineFile}:");
        $this->table(['Metric', 'Before', 'Now', 'Change'], $rows);
    }
}
