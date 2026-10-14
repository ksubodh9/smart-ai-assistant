<?php

namespace Subodh\SmartAiAssistant\Console\Commands;

use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Subodh\SmartAiAssistant\Documents\DocumentException;
use Subodh\SmartAiAssistant\Documents\DocumentIngester;
use Subodh\SmartAiAssistant\Documents\IngestResult;

class IngestDocuments extends Command
{
    protected $signature = 'smart-ai:ingest
        {path : A file, or a folder whose supported files are all ingested (subfolders included)}
        {--title= : Document title (single file only); defaults to the file name without extension. The same title again adds a new version}
        {--domain= : Knowledge domain (service); defaults to config default_service}
        {--audience= : Who may get answers from it: public (guests too) or users (logged-in only); defaults to config documents.audience}';

    protected $description = 'Store documents (PDF, DOCX, XLSX/CSV, TXT/MD, HTML) as searchable passages for the assistant';

    public function handle(DocumentIngester $ingester)
    {
        $path = rtrim($this->argument('path'), '/\\');
        $service = $this->option('domain') ?: config('smart-ai-assistant.default_service', 'general');
        $audience = $this->option('audience') ?: config('smart-ai-assistant.documents.audience', 'users');

        if (is_dir($path)) {
            if ($this->option('title')) {
                $this->error('--title only works with a single file; folder files are titled by their file names.');
                return 1;
            }

            $files = $this->filesIn($path, $ingester);

            if ($files === []) {
                $this->warn("No supported files in {$path}.");
                return 1;
            }
        } elseif (is_file($path)) {
            $files = [$path];
        } else {
            $this->error("File or folder not found: {$path}");
            return 1;
        }

        $failed = 0;

        foreach ($files as $file) {
            $title = $this->option('title') ?: pathinfo($file, PATHINFO_FILENAME);

            try {
                $this->report($ingester->ingest($file, $title, $service, $audience));
            } catch (DocumentException $e) {
                $failed++;
                $this->error("{$title}: {$e->getMessage()}");
            }
        }

        if (count($files) > 1) {
            $this->info(sprintf('%d file(s) processed, %d failed (domain %s).', count($files), $failed, $service));
        }

        return $failed > 0 ? 1 : 0;
    }

    private function report(IngestResult $result): void
    {
        $document = $result->document;
        $name = "{$document->title} v{$document->version}";

        $this->line(match ($result->status) {
            IngestResult::CREATED          => "<info>Added</info> {$name}: {$result->chunks} passage(s), audience {$document->audience}.",
            IngestResult::NEW_VERSION      => "<info>Updated</info> {$name} (replaces v{$result->previousVersion}): {$result->chunks} passage(s), audience {$document->audience}.",
            IngestResult::AUDIENCE_CHANGED => "<info>Audience changed</info> {$name}: now {$document->audience} (same file).",
            default                        => "Unchanged {$name}: same file as the active version.",
        });
    }

    /**
     * @return list<string>
     */
    private function filesIn(string $dir, DocumentIngester $ingester): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            // Skip Office lock files (~$name.docx) and hidden files
            if ($file->isFile() && !preg_match('/^(~\$|\.)/', $file->getFilename()) && $ingester->supports($file->getPathname())) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
