<?php

namespace Subodh\SmartAiAssistant\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Subodh\SmartAiAssistant\Models\Document;
use Subodh\SmartAiAssistant\Tests\TestCase;

class ManageDocumentsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function document(string $title, int $version, string $status, string $service = 'AEPS'): Document
    {
        return Document::create([
            'service' => $service, 'title' => $title, 'file_name' => "{$title}.pdf", 'mime' => 'application/pdf',
            'checksum' => str_repeat((string) $version, 64), 'audience' => 'users', 'version' => $version, 'status' => $status,
        ]);
    }

    public function test_list_shows_active_documents_of_the_domain(): void
    {
        $this->document('Refund policy', 1, Document::ARCHIVED);
        $this->document('Refund policy', 2, Document::ACTIVE);
        $this->document('KYC guide', 1, Document::ACTIVE, 'PAN');

        $this->artisan('smart-ai:documents')
            ->expectsTable(
                ['Title', 'Version', 'Status', 'Audience', 'Passages', 'File', 'Ingested'],
                [['Refund policy', 2, 'active', 'users', 0, 'Refund policy.pdf', Document::where('version', 2)->value('created_at')->toDateTimeString()]]
            )
            ->assertExitCode(0);
    }

    public function test_list_all_includes_archived_versions(): void
    {
        $this->document('Refund policy', 1, Document::ARCHIVED);
        $this->document('Refund policy', 2, Document::ACTIVE);

        $this->artisan('smart-ai:documents', ['--all' => true])
            ->expectsOutputToContain('archived')
            ->assertExitCode(0);
    }

    public function test_list_with_no_documents(): void
    {
        $this->artisan('smart-ai:documents', ['--domain' => 'PAN'])
            ->expectsOutputToContain('No documents in domain PAN.')
            ->assertExitCode(0);
    }

    public function test_archive_stops_using_a_document(): void
    {
        $this->document('Refund policy', 1, Document::ACTIVE);
        $other = $this->document('Refund policy', 1, Document::ACTIVE, 'PAN');

        $this->artisan('smart-ai:documents', ['action' => 'archive', 'title' => 'Refund policy'])
            ->expectsOutputToContain('Archived "Refund policy"')
            ->assertExitCode(0);

        $this->assertSame(0, Document::active()->where('service', 'AEPS')->count());
        $this->assertSame(Document::ACTIVE, $other->fresh()->status);
    }

    public function test_archive_needs_an_existing_active_title(): void
    {
        $this->artisan('smart-ai:documents', ['action' => 'archive'])->assertExitCode(1);
        $this->artisan('smart-ai:documents', ['action' => 'archive', 'title' => 'Nope'])
            ->expectsOutputToContain('No active document titled "Nope" in domain AEPS.')
            ->assertExitCode(1);
    }

    public function test_unknown_action_fails(): void
    {
        $this->artisan('smart-ai:documents', ['action' => 'delete'])->assertExitCode(1);
    }
}
