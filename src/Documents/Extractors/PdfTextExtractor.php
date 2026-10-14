<?php

namespace Subodh\SmartAiAssistant\Documents\Extractors;

use Subodh\SmartAiAssistant\Core\Contracts\TextExtractor;
use Subodh\SmartAiAssistant\Documents\DocumentException;

/**
 * .pdf files with a text layer, through smalot/pdfparser. The library is
 * optional (LGPL-3.0); hosts that ingest PDFs install it themselves. Scanned
 * PDFs (images only) give no text: OCR is not supported.
 */
class PdfTextExtractor implements TextExtractor
{
    public function extract(string $path): string
    {
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            throw new DocumentException('Reading PDF files needs the smalot/pdfparser package: composer require smalot/pdfparser');
        }

        try {
            $pdf = (new \Smalot\PdfParser\Parser())->parseFile($path);

            // Each page starts a new paragraph
            return implode("\n\n", array_map(fn ($page) => trim($page->getText()), $pdf->getPages()));
        } catch (\Throwable $e) {
            throw new DocumentException('Could not read the PDF: ' . $e->getMessage(), 0, $e);
        }
    }
}
