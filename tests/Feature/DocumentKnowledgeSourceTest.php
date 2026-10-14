<?php

namespace Subodh\SmartAiAssistant\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Subodh\SmartAiAssistant\Core\Contracts\PassageSource;
use Subodh\SmartAiAssistant\Knowledge\DocumentKnowledgeSource;
use Subodh\SmartAiAssistant\Models\Document;
use Subodh\SmartAiAssistant\Models\DocumentChunk;
use Subodh\SmartAiAssistant\Tests\TestCase;

/**
 * Retrieval of document passages. Truncation instead of a wrapping
 * transaction: InnoDB FULLTEXT indexes only see committed rows.
 */
class DocumentKnowledgeSourceTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        // Truncation runs before each test only; later test classes expect empty tables
        DocumentChunk::query()->delete();
        Document::query()->delete();

        parent::tearDown();
    }

    private function document(string $title, array $chunks, string $audience = 'users', string $status = 'active', string $service = 'AEPS', int $version = 1): Document
    {
        $document = Document::create([
            'service' => $service, 'title' => $title, 'file_name' => "{$title}.md", 'mime' => 'text/markdown',
            'checksum' => hash('sha256', $title . $version . $status), 'audience' => $audience, 'version' => $version, 'status' => $status,
        ]);

        foreach (array_values($chunks) as $i => [$heading, $content]) {
            DocumentChunk::create(['document_id' => $document->id, 'position' => $i + 1, 'heading' => $heading, 'content' => $content]);
        }

        return $document;
    }

    private function source(float $minScore = 0.4, array $stopwords = []): DocumentKnowledgeSource
    {
        return new DocumentKnowledgeSource('AEPS', $minScore, $stopwords);
    }

    public function test_the_passage_with_most_of_the_question_words_comes_first(): void
    {
        $this->document('Refund policy', [
            ['Refunds > Timelines', 'Refunds for failed transactions reach the bank account within 5 working days.'],
            ['Refunds > Charges', 'No charge is taken for a refund.'],
            [null, 'Contact the support team for anything else.'],
        ]);

        $passages = $this->source()->search('How many days does a refund take for a failed transaction?', Document::AUDIENCES);

        $this->assertNotEmpty($passages);
        $this->assertSame('Refunds > Timelines', $passages[0]->heading);
        $this->assertSame('Refund policy', $passages[0]->title);
        $this->assertSame(1, $passages[0]->version);
        $this->assertGreaterThan(0.5, $passages[0]->score);
    }

    public function test_mysql_finds_passages_through_the_fulltext_index(): void
    {
        $this->document('KYC guide', [[null, 'KYC verification needs a selfie and an identity document.']]);
        DB::enableQueryLog();

        $passages = $this->source()->search('kyc verification selfie', Document::AUDIENCES);

        $queries = array_column(DB::getQueryLog(), 'query');
        $this->assertCount(1, $passages);
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('MATCH(', $queries[0]);
    }

    public function test_devanagari_questions_are_found_through_the_like_search(): void
    {
        // InnoDB's FULLTEXT parser splits Devanagari words at vowel signs
        $this->document('पैन गाइड', [[null, 'पैन कार्ड सुधार के लिए फॉर्म भरें और पहचान पत्र अपलोड करें।']]);

        $passages = $this->source()->search('पैन कार्ड सुधार कैसे करें', Document::AUDIENCES);

        $this->assertCount(1, $passages);
        $this->assertSame('पैन गाइड', $passages[0]->title);
    }

    public function test_only_allowed_audiences_active_versions_and_the_configured_domain_are_searched(): void
    {
        $this->document('Public refunds', [[null, 'Refund timeline public answer.']], 'public');
        $this->document('Internal refunds', [[null, 'Refund timeline internal answer.']], 'users');
        $this->document('Old refunds', [[null, 'Refund timeline old answer.']], 'public', 'archived');
        $this->document('Other domain refunds', [[null, 'Refund timeline other answer.']], 'public', 'active', 'PAN');

        $guest = array_map(fn ($p) => $p->title, $this->source()->search('refund timeline', ['public']));
        $user = array_map(fn ($p) => $p->title, $this->source()->search('refund timeline', Document::AUDIENCES));

        $this->assertSame(['Public refunds'], $guest);
        $this->assertEqualsCanonicalizing(['Public refunds', 'Internal refunds'], $user);
    }

    public function test_passages_below_the_minimum_score_are_left_out(): void
    {
        $this->document('Refund policy', [[null, 'Refunds reach the bank account within 5 working days.']]);

        // 1 of 4 search words (refund) is in the passage
        $this->assertSame([], $this->source(0.5)->search('refund for electricity bill payment', Document::AUDIENCES));
        $this->assertCount(1, $this->source(0.25)->search('refund for electricity bill payment', Document::AUDIENCES));
    }

    public function test_stopwords_and_short_latin_words_are_not_search_words(): void
    {
        $source = $this->source(0.4, ['kya', 'hai']);

        $this->assertSame(['refund', 'status'], $source->terms('What is the refund status?'));
        $this->assertSame(['refund', 'kab', 'aayega'], $source->terms('refund kab aayega kya hai'));
        $this->assertSame(['पैन', 'कार्ड'], $source->terms('पैन कार्ड'));
        $this->assertSame([], $source->terms('hi, can you help me?'));
    }

    public function test_a_question_without_search_words_or_audiences_finds_nothing(): void
    {
        $this->document('Refund policy', [[null, 'Refunds take 5 days.']]);

        $this->assertSame([], $this->source()->search('can you help me?', Document::AUDIENCES));
        $this->assertSame([], $this->source()->search('refund', []));
    }

    public function test_like_wildcards_in_the_question_are_literal(): void
    {
        $this->document('Charges', [[null, 'Service charge is 100 rupees.']]);

        $this->assertSame([], $this->source()->search('%% __', Document::AUDIENCES));
    }

    public function test_the_container_builds_it_from_config(): void
    {
        config(['smart-ai-assistant.documents.retrieval' => ['min_score' => 0.9, 'stopwords' => ['refund']]]);
        $this->document('Refund policy', [[null, 'Refunds reach the bank within 5 working days.']]);

        $source = app(PassageSource::class);

        $this->assertInstanceOf(DocumentKnowledgeSource::class, $source);
        $this->assertSame(['bank', 'days'], $source->terms('refund bank days'));
        $this->assertSame([], $source->search('bank account days', Document::AUDIENCES)); // 2 of 3 < 0.9
    }
}
