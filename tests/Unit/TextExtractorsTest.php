<?php

namespace Subodh\SmartAiAssistant\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Subodh\SmartAiAssistant\Documents\DocumentException;
use Subodh\SmartAiAssistant\Documents\Extractors\DocxTextExtractor;
use Subodh\SmartAiAssistant\Documents\Extractors\HtmlTextExtractor;
use Subodh\SmartAiAssistant\Documents\Extractors\PdfTextExtractor;
use Subodh\SmartAiAssistant\Documents\Extractors\PlainTextExtractor;
use Subodh\SmartAiAssistant\Documents\Extractors\SpreadsheetTextExtractor;
use Subodh\SmartAiAssistant\Tests\Concerns\BuildsDocuments;

class TextExtractorsTest extends TestCase
{
    use BuildsDocuments;

    protected function tearDown(): void
    {
        $this->cleanUpDocuments();

        parent::tearDown();
    }

    public function test_plain_text_is_read_as_utf8_whatever_the_encoding(): void
    {
        $extractor = new PlainTextExtractor();

        $this->assertSame('# Guide' . "\n" . 'पैन', $extractor->extract($this->makeFile('bom.md', "\xEF\xBB\xBF# Guide\nपैन")));
        $this->assertSame('café', $extractor->extract($this->makeFile('ansi.txt', "caf\xE9")));
        $this->assertSame('hi पैन', $extractor->extract($this->makeFile('utf16.txt', "\xFF\xFE" . mb_convert_encoding('hi पैन', 'UTF-16LE', 'UTF-8'))));
    }

    public function test_html_keeps_visible_text_headings_lists_and_table_rows(): void
    {
        $html = '<html><head><title>T</title><style>p{}</style></head><body>'
            . '<h1>Refund   policy</h1><script>alert(1)</script>'
            . '<p>Refunds take <b>5</b> days.</p>'
            . '<ul><li>Card</li><li>UPI</li></ul>'
            . '<h2>Charges</h2><table><tr><th>Service</th><th>Fee</th></tr><tr><td>PAN</td><td>₹107</td></tr></table>'
            . '<div>Line one<br>Line two</div></body></html>';

        $this->assertSame(
            "# Refund policy\n\nRefunds take 5 days.\n\n- Card\n\n- UPI\n\n## Charges\n\nService | Fee\n\nPAN | ₹107\n\nLine one\n\nLine two",
            (new HtmlTextExtractor())->extract($this->makeFile('page.html', $html))
        );
    }

    public function test_docx_paragraphs_headings_lists_and_tables(): void
    {
        $table = '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>Service</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Fee</w:t></w:r></w:p></w:tc></w:tr>'
            . '<w:tr><w:tc><w:p><w:r><w:t>PAN</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>107</w:t></w:r></w:p></w:tc></w:tr></w:tbl>';
        $deleted = '<w:p><w:r><w:t>Kept</w:t></w:r><w:del><w:r><w:delText>removed</w:delText></w:r></w:del></w:p>';
        $outline = '<w:p><w:pPr><w:outlineLvl w:val="1"/></w:pPr><w:r><w:t>Custom heading</w:t></w:r></w:p>';

        $path = $this->makeDocx('guide.docx', $this->docxParagraph('PAN guide', 'Title')
            . $this->docxParagraph('Corrections', 'Heading2')
            . $this->docxParagraph('Fill form 49A.')
            . $this->docxParagraph('')
            . $this->docxParagraph('Upload proof', null, true)
            . $table . $deleted . $outline);

        $this->assertSame(
            "# PAN guide\n\n## Corrections\n\nFill form 49A.\n\n- Upload proof\n\nService | Fee\n\nPAN | 107\n\nKept\n\n## Custom heading",
            (new DocxTextExtractor())->extract($path)
        );
    }

    public function test_a_file_that_is_not_a_docx_fails_clearly(): void
    {
        $this->expectException(DocumentException::class);

        (new DocxTextExtractor())->extract($this->makeFile('fake.docx', 'plain text'));
    }

    public function test_spreadsheet_rows_become_header_value_paragraphs_per_sheet(): void
    {
        $path = $this->makeXlsx('charges.xlsx', [
            'Charges' => [['Service', 'Fee', 'Note'], ['PAN', '107', null], [null, null, null], ['Refund', '0', 'Within 5 days']],
            'Contacts' => [['Team', 'Phone'], ['Support', '1800']],
        ]);

        $this->assertSame(
            "# Charges\n\nService: PAN\nFee: 107\n\nService: Refund\nFee: 0\nNote: Within 5 days\n\n# Contacts\n\nTeam: Support\nPhone: 1800",
            (new SpreadsheetTextExtractor())->extract($path)
        );
    }

    public function test_csv_has_no_sheet_heading(): void
    {
        $path = $this->makeFile('faq.csv', "Question,Answer\nHow long is a refund?,5 days\n");

        $this->assertSame("Question: How long is a refund?\nAnswer: 5 days", (new SpreadsheetTextExtractor())->extract($path));
    }

    public function test_pdf_text_per_page(): void
    {
        $path = $this->makePdf('guide.pdf', ['Refunds take five days.', 'Charges are listed online.']);

        $text = (new PdfTextExtractor())->extract($path);

        $this->assertStringContainsString('Refunds take five days.', $text);
        $this->assertStringContainsString("\n\nCharges are listed online.", $text);
    }

    public function test_a_broken_pdf_fails_clearly(): void
    {
        $this->expectException(DocumentException::class);
        $this->expectExceptionMessage('Could not read the PDF');

        (new PdfTextExtractor())->extract($this->makeFile('broken.pdf', 'not a pdf'));
    }
}
