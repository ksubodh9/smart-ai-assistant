<?php

namespace Subodh\SmartAiAssistant\Tests\Concerns;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ZipArchive;

/**
 * Builds small document files in a temporary folder, so the tests carry no
 * binary fixtures. Call cleanUpDocuments() in tearDown().
 */
trait BuildsDocuments
{
    private ?string $documentDir = null;

    protected function documentDir(): string
    {
        if ($this->documentDir === null) {
            $this->documentDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sa-docs-' . bin2hex(random_bytes(6));
            mkdir($this->documentDir);
        }

        return $this->documentDir;
    }

    protected function cleanUpDocuments(): void
    {
        if ($this->documentDir === null) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->documentDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->documentDir);
        $this->documentDir = null;
    }

    protected function makeFile(string $name, string $content): string
    {
        $path = $this->documentDir() . DIRECTORY_SEPARATOR . $name;

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $content);

        return $path;
    }

    /**
     * @param  string  $bodyXml  Children of <w:body>, e.g. from docxParagraph()
     */
    protected function makeDocx(string $name, string $bodyXml): string
    {
        $path = $this->documentDir() . DIRECTORY_SEPARATOR . $name;
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $bodyXml . '</w:body></w:document>');
        $zip->close();

        return $path;
    }

    protected function docxParagraph(string $text, ?string $style = null, bool $listItem = false): string
    {
        $props = ($style ? '<w:pStyle w:val="' . $style . '"/>' : '')
            . ($listItem ? '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr>' : '');

        return '<w:p>' . ($props ? "<w:pPr>{$props}</w:pPr>" : '')
            . '<w:r><w:t xml:space="preserve">' . htmlspecialchars($text, ENT_XML1) . '</w:t></w:r></w:p>';
    }

    /**
     * @param  array<string, list<list<string|int|null>>>  $sheets  Sheet title => rows
     */
    protected function makeXlsx(string $name, array $sheets): string
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        foreach ($sheets as $title => $rows) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($title);
            $sheet->fromArray($rows, null, 'A1', true);
        }

        $path = $this->documentDir() . DIRECTORY_SEPARATOR . $name;
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    /**
     * A minimal valid PDF with one line of Helvetica text per page.
     *
     * @param  list<string>  $pages  ASCII text per page
     */
    protected function makePdf(string $name, array $pages): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];
        $kids = [];
        $id = 4;

        foreach ($pages as $text) {
            $stream = 'BT /F1 12 Tf 72 720 Td (' . addcslashes($text, '()\\') . ') Tj ET';
            $objects[$id] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents " . ($id + 1) . ' 0 R >>';
            $objects[$id + 1] = '<< /Length ' . strlen($stream) . " >>\nstream\n{$stream}\nendstream";
            $kids[] = "{$id} 0 R";
            $id += 2;
        }

        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($pages) . ' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= 'trailer << /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        return $this->makeFile($name, $pdf);
    }
}
