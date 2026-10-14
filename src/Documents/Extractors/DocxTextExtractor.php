<?php

namespace Subodh\SmartAiAssistant\Documents\Extractors;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Subodh\SmartAiAssistant\Core\Contracts\TextExtractor;
use Subodh\SmartAiAssistant\Documents\DocumentException;
use ZipArchive;

/**
 * .docx files (Word 2007+), read straight from the document XML: paragraphs,
 * headings (Heading 1–6 / Title styles or an outline level), list items and
 * tables (one row per paragraph, cells separated by "|"). Deleted tracked
 * changes and field codes are left out.
 */
class DocxTextExtractor implements TextExtractor
{
    private const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private DOMXPath $xpath;

    public function extract(string $path): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new DocumentException('Reading .docx files needs the PHP zip extension.');
        }

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new DocumentException('Could not open the .docx file (is it a Word 2007+ document?).');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new DocumentException('The .docx file has no word/document.xml.');
        }

        $dom = new DOMDocument();

        if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new DocumentException('Could not parse the .docx document XML.');
        }

        $this->xpath = new DOMXPath($dom);
        $this->xpath->registerNamespace('w', self::NS);

        $body = $this->xpath->query('//w:body')->item(0);

        return $body instanceof DOMElement ? implode("\n\n", $this->blocks($body)) : '';
    }

    /**
     * @return list<string>
     */
    private function blocks(DOMElement $parent): array
    {
        $out = [];

        foreach ($parent->childNodes as $node) {
            if (!$node instanceof DOMElement || $node->namespaceURI !== self::NS) {
                continue;
            }

            switch ($node->localName) {
                case 'p':
                    $paragraph = $this->paragraph($node);

                    if ($paragraph !== '') {
                        $out[] = $paragraph;
                    }
                    break;

                case 'tbl':
                    foreach ($this->xpath->query('w:tr', $node) as $row) {
                        $cells = [];

                        foreach ($this->xpath->query('w:tc', $row) as $cell) {
                            $cells[] = trim(implode(' ', array_map(fn ($p) => $this->text($p), iterator_to_array($this->xpath->query('.//w:p', $cell)))));
                        }

                        $line = implode(' | ', array_filter($cells, fn ($cell) => $cell !== ''));

                        if ($line !== '') {
                            $out[] = $line;
                        }
                    }
                    break;

                case 'sdt': // content controls wrap ordinary paragraphs and tables
                    foreach ($this->xpath->query('w:sdtContent', $node) as $content) {
                        array_push($out, ...$this->blocks($content));
                    }
                    break;
            }
        }

        return $out;
    }

    private function paragraph(DOMElement $p): string
    {
        $text = trim($this->text($p));

        if ($text === '') {
            return '';
        }

        if ($level = $this->headingLevel($p)) {
            return str_repeat('#', $level) . ' ' . preg_replace('/\s+/u', ' ', $text);
        }

        return $this->xpath->query('w:pPr/w:numPr', $p)->length > 0 ? '- ' . $text : $text;
    }

    private function text(DOMElement $p): string
    {
        $text = '';

        // Only runs' own text; w:delText (deleted) and w:instrText (field codes) are other elements
        foreach ($this->xpath->query('.//w:t | .//w:tab | .//w:br | .//w:cr', $p) as $node) {
            $text .= match ($node->localName) {
                't'     => $node->textContent,
                'tab'   => "\t",
                default => "\n",
            };
        }

        return $text;
    }

    private function headingLevel(DOMElement $p): int
    {
        $style = (string) $this->xpath->evaluate('string(w:pPr/w:pStyle/@w:val)', $p);

        if (preg_match('/^heading\s?([1-6])$/i', $style, $match)) {
            return (int) $match[1];
        }

        if (strcasecmp($style, 'Title') === 0) {
            return 1;
        }

        $outline = (string) $this->xpath->evaluate('string(w:pPr/w:outlineLvl/@w:val)', $p);

        // Outline levels are 0-based; 9 means body text
        return ctype_digit($outline) && (int) $outline < 6 ? (int) $outline + 1 : 0;
    }
}
