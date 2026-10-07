<?php

namespace Subodh\SmartAiAssistant\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Subodh\SmartAiAssistant\Evaluation\Evaluator;
use Subodh\SmartAiAssistant\Models\Conversation;
use Subodh\SmartAiAssistant\Models\ErrorDefinition;
use Subodh\SmartAiAssistant\Models\Message;
use Subodh\SmartAiAssistant\Tests\TestCase;

/**
 * smart-ai:eval replays queries through the pipeline without side effects.
 */
class EvaluateQueriesCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        ErrorDefinition::create(['service' => 'AEPS', 'key_text' => 'capture timeout', 'answer_en' => 'Clean the scanner.']);
    }

    protected function tearDown(): void
    {
        array_map(fn ($file) => @unlink($file), $this->files);

        parent::tearDown();
    }

    private function csv(array $rows): string
    {
        $file = tempnam(sys_get_temp_dir(), 'eval') . '.csv';
        $handle = fopen($file, 'w');
        fputcsv($handle, ['Query', 'Expected', 'Category']);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        return $this->files[] = $file;
    }

    private function report(array $rows): array
    {
        return app(Evaluator::class)->run(array_map(
            fn ($row) => ['text' => $row[0], 'expected' => $row[1] ?? null, 'category' => $row[2] ?? null],
            $rows
        ));
    }

    public function test_it_counts_outcomes_sources_and_categories(): void
    {
        $report = $this->report([
            ['capture timeout again'],           // kb
            ['aeps withdrawal failed'],          // unknown, AEPS
            ['aeps withdrawal failed'],          // the same twice: no loop exit between queries
            ['hello'],                           // clarify
            ['talk to a human'],                 // escalate
            [''],                                // skipped
        ]);

        $this->assertSame(5, $report['total']);
        $this->assertSame(1, $report['answered']);
        $this->assertSame(['unresolved' => 2, 'answered' => 1, 'clarify' => 1, 'escalate' => 1], $report['outcomes']);
        $this->assertSame(2, $report['sources']['unknown']);
        $this->assertSame(['AEPS' => 2], $report['categories']);
    }

    public function test_most_frequent_unanswered_queries_are_grouped_and_masked(): void
    {
        $report = $this->report([
            ['Money  deducted, call 9876543210'],
            ['money deducted, call 9876543210'],
            ['aeps withdrawal failed'],
        ]);

        $this->assertSame([
            'money deducted, call [phone]' => ['count' => 2, 'category' => null, 'source' => 'unknown'],
            'aeps withdrawal failed'       => ['count' => 1, 'category' => 'AEPS', 'source' => 'unknown'],
        ], $report['gaps']);
    }

    public function test_another_outcome_can_be_listed(): void
    {
        $report = app(Evaluator::class)->run([['text' => 'hello'], ['text' => 'money deducted']], 20, 'clarify');

        $this->assertSame(['hello' => ['count' => 1, 'category' => null, 'source' => 'greeting']], $report['gaps']);
    }

    public function test_labels_accept_an_outcome_or_a_source(): void
    {
        $report = $this->report([
            ['capture timeout', 'kb', 'aeps'],
            ['hello', 'clarify'],
            ['money deducted', 'answered', 'PAYOUT'],
        ]);

        $this->assertSame(3, $report['labelled']);
        $this->assertSame(2, $report['label_matches']);
        $this->assertSame(['answered → unknown' => 1], $report['mismatches']);
        $this->assertSame(2, $report['category_labelled']);
        $this->assertSame(0, $report['category_matches'], 'capture timeout has no AEPS keyword; money deducted none either');
    }

    public function test_entities_are_counted_without_running_data_tools(): void
    {
        config([
            'smart-ai-assistant.capabilities.data_tools' => true,
            'smart-ai-assistant.data_tools'              => [FakeTransactionTool::class],
            'smart-ai-assistant.understanding.entities'  => ['reference_id' => '/\b(TXN\d{6})\b/i'],
        ]);
        FakeTransactionTool::$executed = [];

        $report = $this->report([['status of TXN000001']]);

        $this->assertSame(1, $report['with_entities']);
        $this->assertSame(1, $report['unresolved_with_entities'], 'a logged-in user would get this from the tool');
        $this->assertSame([], FakeTransactionTool::$executed, 'queries run as a guest');
    }

    public function test_command_prints_the_report_and_stores_nothing(): void
    {
        $file = $this->csv([['capture timeout', 'kb'], ['money deducted but failed', ''], ['hello', 'clarify']]);

        $this->artisan('smart-ai:eval', ['file' => $file])
            ->expectsOutputToContain('Queries evaluated: 3')
            ->expectsOutputToContain('Answered (knowledge base or data): 1 (33.3%)')
            ->expectsOutputToContain('Expected outcome matched: 2 of 2 (100.0%)')
            ->expectsOutputToContain("Most frequent 'unresolved' queries")
            ->assertSuccessful();

        $this->assertSame(0, Conversation::count());
        $this->assertSame(0, Message::count());
    }

    public function test_json_summary_and_baseline_comparison(): void
    {
        $baseline = tempnam(sys_get_temp_dir(), 'base') . '.json';
        $this->files[] = $baseline;
        $file = $this->csv([['capture timeout'], ['money deducted but failed']]);

        $this->artisan('smart-ai:eval', ['file' => $file, '--json' => $baseline])->assertSuccessful();

        $summary = json_decode(file_get_contents($baseline), true);
        $this->assertSame(2, $summary['total']);
        $this->assertSame(50, (int) $summary['answered_pct']);

        // Close the gap, then compare
        ErrorDefinition::create(['service' => 'AEPS', 'key_text' => 'money deducted', 'answer_en' => 'Wait for the reversal.']);

        $this->artisan('smart-ai:eval', ['file' => $file, '--baseline' => $baseline])
            ->expectsOutputToContain('Compared with')
            ->expectsTable(['Metric', 'Before', 'Now', 'Change'], [
                ['total', 2, 2, '+0.0'],
                ['answered_pct', 50, 100, '+50.0'],
                ['with_category_pct', 0, 0, '+0.0'],
                ['with_entities_pct', 0, 0, '+0.0'],
                ['unresolved_with_entities_pct', 0, 0, '+0.0'],
                ['unresolved_pct', 50, 0, '-50.0'],
                ['clarify_pct', 0, 0, '+0.0'],
                ['refuse_pct', 0, 0, '+0.0'],
                ['escalate_pct', 0, 0, '+0.0'],
            ])
            ->assertSuccessful();
    }

    public function test_missing_file_and_empty_file_fail(): void
    {
        $this->artisan('smart-ai:eval', ['file' => 'nope.csv'])->assertFailed();
        $this->artisan('smart-ai:eval', ['file' => $this->csv([])])->assertFailed();
    }
}
