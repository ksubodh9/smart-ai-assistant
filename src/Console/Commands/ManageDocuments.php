<?php

namespace Subodh\SmartAiAssistant\Console\Commands;

use Illuminate\Console\Command;
use Subodh\SmartAiAssistant\Models\Document;

class ManageDocuments extends Command
{
    protected $signature = 'smart-ai:documents
        {action=list : list, or archive (stop answering from a document)}
        {title? : Document title, for archive}
        {--domain= : Knowledge domain (service); defaults to config default_service}
        {--all : list: include archived versions}';

    protected $description = 'List ingested documents, or archive one so the assistant stops using it';

    public function handle()
    {
        $service = $this->option('domain') ?: config('smart-ai-assistant.default_service', 'general');

        return match ($this->argument('action')) {
            'list'    => $this->list($service),
            'archive' => $this->archive($service, (string) $this->argument('title')),
            default   => $this->invalidAction(),
        };
    }

    private function list(string $service): int
    {
        $documents = Document::where('service', $service)
            ->when(!$this->option('all'), fn ($query) => $query->active())
            ->withCount('chunks')
            ->orderBy('title')
            ->orderByDesc('version')
            ->get();

        if ($documents->isEmpty()) {
            $this->info("No documents in domain {$service}.");
            return 0;
        }

        $this->table(
            ['Title', 'Version', 'Status', 'Audience', 'Passages', 'File', 'Ingested'],
            $documents->map(fn (Document $d) => [
                $d->title, $d->version, $d->status, $d->audience, $d->chunks_count, $d->file_name, $d->created_at?->toDateTimeString(),
            ])->all()
        );

        return 0;
    }

    private function archive(string $service, string $title): int
    {
        if (trim($title) === '') {
            $this->error('Give the title of the document to archive.');
            return 1;
        }

        $archived = Document::active()->where('service', $service)->where('title', $title)->update(['status' => Document::ARCHIVED]);

        if ($archived === 0) {
            $this->error("No active document titled \"{$title}\" in domain {$service}.");
            return 1;
        }

        $this->info("Archived \"{$title}\"; the assistant no longer uses it. Ingest the file again to bring it back as a new version.");

        return 0;
    }

    private function invalidAction(): int
    {
        $this->error('Unknown action; use list or archive.');

        return 1;
    }
}
