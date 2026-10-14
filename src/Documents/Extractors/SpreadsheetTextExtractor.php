<?php

namespace Subodh\SmartAiAssistant\Documents\Extractors;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Subodh\SmartAiAssistant\Core\Contracts\TextExtractor;
use Subodh\SmartAiAssistant\Documents\DocumentException;

/**
 * .xlsx, .xls and .csv files. The first non-empty row of each sheet is the
 * header; every later row becomes one paragraph of "Header: value" lines, so
 * a passage keeps the meaning of its cells. Sheet names are headings when a
 * workbook has more than one sheet.
 */
class SpreadsheetTextExtractor implements TextExtractor
{
    public function extract(string $path): string
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
        } catch (\Throwable $e) {
            throw new DocumentException('Could not read the spreadsheet: ' . $e->getMessage(), 0, $e);
        }

        $sheets = $spreadsheet->getAllSheets();
        $out = [];

        foreach ($sheets as $sheet) {
            $rows = array_values(array_filter(
                array_map(
                    fn ($row) => array_map(fn ($cell) => trim(preg_replace('/\s+/u', ' ', (string) $cell) ?? ''), $row),
                    $sheet->toArray(null, true, true, false)
                ),
                fn ($row) => implode('', $row) !== ''
            ));

            if ($rows === []) {
                continue;
            }

            if (count($sheets) > 1) {
                $out[] = '# ' . $sheet->getTitle();
            }

            $header = array_shift($rows);

            foreach ($rows as $row) {
                $lines = [];

                foreach ($row as $i => $value) {
                    if ($value !== '') {
                        $label = $header[$i] ?? '';
                        $lines[] = $label !== '' ? "{$label}: {$value}" : $value;
                    }
                }

                $out[] = implode("\n", $lines);
            }
        }

        return implode("\n\n", $out);
    }
}
