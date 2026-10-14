<?php

namespace Subodh\SmartAiAssistant\Core\Contracts;

/**
 * Turns one file into plain UTF-8 text for document ingestion.
 *
 * Paragraphs are separated by a blank line. Headings the format knows about
 * are written as Markdown heading lines ("# Title", "## Section"), so the
 * chunker can keep passages inside one section and record where they came
 * from. Extractors are picked by file extension in
 * config('smart-ai-assistant.documents.extractors').
 *
 * Throw Documents\DocumentException when the file cannot be read.
 */
interface TextExtractor
{
    public function extract(string $path): string;
}
