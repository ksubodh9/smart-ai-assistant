<?php

namespace Subodh\SmartAiAssistant\Documents\Extractors;

use Subodh\SmartAiAssistant\Core\Contracts\TextExtractor;
use Subodh\SmartAiAssistant\Documents\DocumentException;

/**
 * .txt and .md files, read as they are. Markdown headings already use the
 * "# Title" form the chunker understands.
 */
class PlainTextExtractor implements TextExtractor
{
    public function extract(string $path): string
    {
        $content = @file_get_contents($path);

        if ($content === false) {
            throw new DocumentException("Could not read {$path}.");
        }

        return self::toUtf8($content);
    }

    /**
     * Files saved by Windows tools are often UTF-16 (with a BOM) or
     * Windows-1252 rather than UTF-8.
     */
    public static function toUtf8(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return substr($content, 3);
        }

        if (str_starts_with($content, "\xFF\xFE")) {
            return mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16LE');
        }

        if (str_starts_with($content, "\xFE\xFF")) {
            return mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16BE');
        }

        return mb_check_encoding($content, 'UTF-8') ? $content : mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }
}
