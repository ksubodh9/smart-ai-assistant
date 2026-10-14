<?php

namespace Subodh\SmartAiAssistant\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Subodh\SmartAiAssistant\Core\Contracts\TextExtractor;
use Subodh\SmartAiAssistant\Models\Document;
use Subodh\SmartAiAssistant\Models\DocumentChunk;
use Subodh\SmartAiAssistant\Tests\Concerns\BuildsDocuments;
use Subodh\SmartAiAssistant\Tests\TestCase;

class IngestDocumentsCommandTest extends TestCase
{
    use BuildsDocuments;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->cleanUpDocuments();

        parent::tearDown();
    }

    private function guide(string $body = "# Refunds\nRefunds take 5 days.\n\n# Charges\nPAN costs 107."): string
    {
        return $this->makeFile('refund-guide.md', $body);
    }

    public function test_a_file_becomes_an_active_first_version_with_passages(): void
    {
        $path = $this->guide();

        $this->artisan('smart-ai:ingest', ['path' => $path])
            ->expectsOutputToContain('Added refund-guide v1: 2 passage(s), audience users.')
            ->assertExitCode(0);

        $document = Document::sole();
        $this->assertSame('refund-guide', $document->title);
        $this->assertSame('AEPS', $document->service); // host default_service
        $this->assertSame('refund-guide.md', $document->file_name);
        $this->assertSame('text/markdown', $document->mime);
        $this->assertSame(hash_file('sha256', $path), $document->checksum);
        $this->assertSame(filesize($path), $document->size);
        $this->assertSame([1, Document::ACTIVE, Document::AUDIENCE_USERS], [$document->version, $document->status, $document->audience]);

        $this->assertSame([
            ['position' => 1, 'heading' => 'Refunds', 'content' => 'Refunds take 5 days.'],
            ['position' => 2, 'heading' => 'Charges', 'content' => 'PAN costs 107.'],
        ], $document->chunks()->orderBy('position')->get(['position', 'heading', 'content'])->toArray());
    }

    public function test_the_same_file_again_changes_nothing(): void
    {
        $path = $this->guide();
        $this->artisan('smart-ai:ingest', ['path' => $path])->assertExitCode(0);

        $this->artisan('smart-ai:ingest', ['path' => $path])
            ->expectsOutputToContain('Unchanged refund-guide v1')
            ->assertExitCode(0);

        $this->assertSame(1, Document::count());
        $this->assertSame(2, DocumentChunk::count());
    }

    public function test_a_changed_file_with_the_same_title_adds_a_version_and_archives_the_old_one(): void
    {
        $path = $this->guide();
        $this->artisan('smart-ai:ingest', ['path' => $path])->assertExitCode(0);
        $this->guide("# Refunds\nRefunds now take 3 days.");

        $this->artisan('smart-ai:ingest', ['path' => $path])
            ->expectsOutputToContain('Updated refund-guide v2 (replaces v1): 1 passage(s)')
            ->assertExitCode(0);

        $this->assertSame([1 => Document::ARCHIVED, 2 => Document::ACTIVE], Document::orderBy('version')->pluck('status', 'version')->all());
        // Old passages stay, so stored answers can still name the version they came from
        $this->assertSame(3, DocumentChunk::count());
        $this->assertSame(['Refunds now take 3 days.'], Document::active()->sole()->chunks()->pluck('content')->all());
    }

    public function test_title_domain_and_audience_options(): void
    {
        $this->artisan('smart-ai:ingest', ['path' => $this->guide(), '--title' => 'Refund policy', '--domain' => 'PAN', '--audience' => 'public'])
            ->assertExitCode(0);

        $document = Document::sole();
        $this->assertSame(['Refund policy', 'PAN', 'public'], [$document->title, $document->service, $document->audience]);
    }

    public function test_titles_are_versioned_per_domain(): void
    {
        $path = $this->guide();
        $this->artisan('smart-ai:ingest', ['path' => $path, '--domain' => 'PAN'])->assertExitCode(0);
        $this->artisan('smart-ai:ingest', ['path' => $path, '--domain' => 'DMT'])->assertExitCode(0);

        $this->assertSame([1, 1], Document::active()->pluck('version')->all());
    }

    public function test_the_same_file_with_another_audience_only_changes_the_audience(): void
    {
        $path = $this->guide();
        $this->artisan('smart-ai:ingest', ['path' => $path])->assertExitCode(0);

        $this->artisan('smart-ai:ingest', ['path' => $path, '--audience' => 'public'])
            ->expectsOutputToContain('Audience changed refund-guide v1: now public')
            ->assertExitCode(0);

        $this->assertSame([[1, 'public']], Document::get()->map(fn ($d) => [$d->version, $d->audience])->all());
        $this->assertSame(2, DocumentChunk::count());
    }

    public function test_config_sets_the_default_audience(): void
    {
        config(['smart-ai-assistant.documents.audience' => 'public']);

        $this->artisan('smart-ai:ingest', ['path' => $this->guide()])->assertExitCode(0);

        $this->assertSame('public', Document::sole()->audience);
    }

    public function test_an_archived_title_comes_back_as_a_new_version(): void
    {
        $path = $this->guide();
        $this->artisan('smart-ai:ingest', ['path' => $path])->assertExitCode(0);
        Document::query()->update(['status' => Document::ARCHIVED]);

        $this->artisan('smart-ai:ingest', ['path' => $path])
            ->expectsOutputToContain('Added refund-guide v2')
            ->assertExitCode(0);

        $this->assertSame(Document::ACTIVE, Document::where('version', 2)->value('status'));
    }

    public function test_a_file_without_text_fails_and_keeps_the_active_version(): void
    {
        $path = $this->guide();
        $this->artisan('smart-ai:ingest', ['path' => $path])->assertExitCode(0);
        file_put_contents($path, "\n\n   \n");

        $this->artisan('smart-ai:ingest', ['path' => $path])
            ->expectsOutputToContain('No text found in the file')
            ->assertExitCode(1);

        $this->assertSame([1 => Document::ACTIVE], Document::pluck('status', 'version')->all());
    }

    public function test_invalid_input_is_rejected_without_storing_anything(): void
    {
        $this->artisan('smart-ai:ingest', ['path' => $this->makeFile('photo.png', 'x')])
            ->expectsOutputToContain('Unsupported file type ".png"')
            ->assertExitCode(1);

        $this->artisan('smart-ai:ingest', ['path' => $this->guide(), '--audience' => 'everyone'])
            ->expectsOutputToContain('The audience must be one of: public, users.')
            ->assertExitCode(1);

        $this->artisan('smart-ai:ingest', ['path' => $this->guide(), '--title' => str_repeat('t', 256)])
            ->expectsOutputToContain('The title must be 1 to 255 characters.')
            ->assertExitCode(1);

        $this->artisan('smart-ai:ingest', ['path' => $this->documentDir() . '/missing.md'])
            ->expectsOutputToContain('File or folder not found')
            ->assertExitCode(1);

        $this->assertSame(0, Document::count());
    }

    public function test_a_folder_ingests_every_supported_file_and_reports_failures(): void
    {
        $this->makeFile('faq.txt', 'How long do refunds take? Five days.');
        $this->makeFile('sub/charges.csv', "Service,Fee\nPAN,107\n");
        $this->makeDocx('sub/guide.docx', $this->docxParagraph('Fill form 49A.'));
        $this->makeFile('sub/~$guide.docx', 'office lock file');
        $this->makeFile('image.png', 'not a document');
        $this->makeFile('empty.md', '   ');

        $this->artisan('smart-ai:ingest', ['path' => $this->documentDir()])
            ->expectsOutputToContain('empty: No text found')
            ->expectsOutputToContain('4 file(s) processed, 1 failed (domain AEPS).')
            ->assertExitCode(1);

        $this->assertSame(['charges', 'faq', 'guide'], Document::orderBy('title')->pluck('title')->all());
        $this->assertSame('Service: PAN' . "\n" . 'Fee: 107', Document::where('title', 'charges')->sole()->chunks()->value('content'));
    }

    public function test_title_cannot_be_given_for_a_folder(): void
    {
        $this->makeFile('faq.txt', 'text');

        $this->artisan('smart-ai:ingest', ['path' => $this->documentDir(), '--title' => 'FAQ'])
            ->expectsOutputToContain('--title only works with a single file')
            ->assertExitCode(1);
    }

    public function test_a_pdf_is_ingested(): void
    {
        $this->artisan('smart-ai:ingest', ['path' => $this->makePdf('kyc.pdf', ['KYC needs a selfie and an ID.'])])->assertExitCode(0);

        $this->assertSame('application/pdf', Document::sole()->mime);
        $this->assertStringContainsString('KYC needs a selfie and an ID.', DocumentChunk::sole()->content);
    }

    public function test_hosts_add_or_remove_extractors_by_extension(): void
    {
        config(['smart-ai-assistant.documents.extractors' => ['log' => UppercaseExtractor::class, 'txt' => null]]);

        $this->artisan('smart-ai:ingest', ['path' => $this->makeFile('server.log', 'disk full')])->assertExitCode(0);
        $this->artisan('smart-ai:ingest', ['path' => $this->makeFile('notes.txt', 'x')])
            ->expectsOutputToContain('Unsupported file type ".txt"')
            ->assertExitCode(1);

        $this->assertSame('DISK FULL', DocumentChunk::sole()->content);
    }

    public function test_chunk_size_comes_from_config(): void
    {
        config(['smart-ai-assistant.documents.chunk_size' => 200]);

        $this->artisan('smart-ai:ingest', ['path' => $this->guide(str_repeat('Sentence of some length here. ', 30))])->assertExitCode(0);

        $lengths = DocumentChunk::pluck('content')->map(fn ($c) => mb_strlen($c));
        $this->assertGreaterThan(3, $lengths->count());
        $this->assertLessThanOrEqual(200, $lengths->max());
    }

    public function test_passages_have_a_fulltext_index_on_mysql(): void
    {
        $columns = collect(DB::select("SHOW INDEX FROM smart_ai_document_chunks WHERE Index_type = 'FULLTEXT'"))->pluck('Column_name')->all();

        $this->assertSame(['heading', 'content'], $columns);
    }
}

class UppercaseExtractor implements TextExtractor
{
    public function extract(string $path): string
    {
        return strtoupper(file_get_contents($path));
    }
}
