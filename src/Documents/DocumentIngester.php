<?php

namespace Subodh\SmartAiAssistant\Documents;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Subodh\SmartAiAssistant\Core\Contracts\TextExtractor;
use Subodh\SmartAiAssistant\Documents\Extractors\DocxTextExtractor;
use Subodh\SmartAiAssistant\Documents\Extractors\HtmlTextExtractor;
use Subodh\SmartAiAssistant\Documents\Extractors\PdfTextExtractor;
use Subodh\SmartAiAssistant\Documents\Extractors\PlainTextExtractor;
use Subodh\SmartAiAssistant\Documents\Extractors\SpreadsheetTextExtractor;
use Subodh\SmartAiAssistant\Models\Document;
use Subodh\SmartAiAssistant\Models\DocumentChunk;

/**
 * Stores a file as a document version with its passages.
 *
 * A title is unique per domain: ingesting the same title again with a
 * different file adds version n+1 and archives the active one (old versions
 * and their passages are kept, so stored answers can still name them). The
 * same file again changes nothing.
 */
class DocumentIngester
{
    /** File extension => TextExtractor class */
    public const DEFAULT_EXTRACTORS = [
        'pdf'  => PdfTextExtractor::class,
        'docx' => DocxTextExtractor::class,
        'xlsx' => SpreadsheetTextExtractor::class,
        'xls'  => SpreadsheetTextExtractor::class,
        'csv'  => SpreadsheetTextExtractor::class,
        'txt'  => PlainTextExtractor::class,
        'md'   => PlainTextExtractor::class,
        'html' => HtmlTextExtractor::class,
        'htm'  => HtmlTextExtractor::class,
    ];

    private const MIME_TYPES = [
        'pdf'  => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xls'  => 'application/vnd.ms-excel',
        'csv'  => 'text/csv',
        'txt'  => 'text/plain',
        'md'   => 'text/markdown',
        'html' => 'text/html',
        'htm'  => 'text/html',
    ];

    private const INSERT_BATCH = 500;

    /**
     * @param  array<string, class-string<TextExtractor>>  $extractors  Extension => class
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $extractors,
        private readonly TextChunker $chunker,
    ) {
    }

    public function supports(string $path): bool
    {
        return isset($this->extractors[$this->extension($path)]);
    }

    /**
     * @throws DocumentException  Unsupported type, unreadable file or no text; nothing is changed then
     */
    public function ingest(string $path, string $title, string $service, string $audience): IngestResult
    {
        $title = trim($title);

        if ($title === '' || mb_strlen($title) > 255) {
            throw new DocumentException('The title must be 1 to 255 characters.');
        }

        if (!in_array($audience, Document::AUDIENCES, true)) {
            throw new DocumentException('The audience must be one of: ' . implode(', ', Document::AUDIENCES) . '.');
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new DocumentException("File not found: {$path}");
        }

        if (!$this->supports($path)) {
            throw new DocumentException('Unsupported file type ".' . $this->extension($path) . '"; supported: ' . implode(', ', array_keys($this->extractors)) . '.');
        }

        $checksum = hash_file('sha256', $path);
        $active = Document::active()->where('service', $service)->where('title', $title)->orderByDesc('version')->first();

        if ($active && $active->checksum === $checksum) {
            if ($active->audience === $audience) {
                return new IngestResult(IngestResult::UNCHANGED, $active, $active->chunks()->count());
            }

            $active->update(['audience' => $audience]);

            return new IngestResult(IngestResult::AUDIENCE_CHANGED, $active, $active->chunks()->count());
        }

        // Extract before touching the database, so a failure keeps the current version active
        $chunks = $this->chunker->chunk($this->extractor($path)->extract($path));

        if ($chunks === []) {
            throw new DocumentException('No text found in the file (scanned PDFs and images need OCR, which is not supported).');
        }

        return DB::transaction(function () use ($path, $title, $service, $audience, $checksum, $chunks) {
            $versions = Document::where('service', $service)->where('title', $title);
            $previous = (clone $versions)->active()->max('version');
            $version = (int) (clone $versions)->max('version') + 1;

            (clone $versions)->active()->update(['status' => Document::ARCHIVED]);

            $document = Document::create([
                'service'   => $service,
                'title'     => $title,
                'file_name' => basename($path),
                'mime'      => $this->mime($path),
                'size'      => (int) filesize($path),
                'checksum'  => $checksum,
                'audience'  => $audience,
                'version'   => $version,
                'status'    => Document::ACTIVE,
            ]);

            $rows = [];

            foreach ($chunks as $i => $chunk) {
                $rows[] = ['document_id' => $document->id, 'position' => $i + 1] + $chunk;
            }

            foreach (array_chunk($rows, self::INSERT_BATCH) as $batch) {
                DocumentChunk::insert($batch);
            }

            return new IngestResult(
                $previous === null ? IngestResult::CREATED : IngestResult::NEW_VERSION,
                $document,
                count($rows),
                $previous === null ? null : (int) $previous,
            );
        });
    }

    private function extractor(string $path): TextExtractor
    {
        $extractor = $this->container->make($this->extractors[$this->extension($path)]);

        if (!$extractor instanceof TextExtractor) {
            throw new DocumentException(get_class($extractor) . ' does not implement ' . TextExtractor::class . '.');
        }

        return $extractor;
    }

    private function extension(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    private function mime(string $path): string
    {
        return self::MIME_TYPES[$this->extension($path)]
            ?? (function_exists('mime_content_type') ? (mime_content_type($path) ?: null) : null)
            ?? 'application/octet-stream';
    }
}
