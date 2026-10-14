<?php

namespace Subodh\SmartAiAssistant\Documents\Extractors;

use DOMDocument;
use DOMElement;
use DOMNode;
use Subodh\SmartAiAssistant\Core\Contracts\TextExtractor;
use Subodh\SmartAiAssistant\Documents\DocumentException;

/**
 * .html files: visible text only, with h1–h6 as headings and block elements
 * (paragraphs, list items, table rows) as separate paragraphs.
 */
class HtmlTextExtractor implements TextExtractor
{
    private const SKIP = ['head', 'script', 'style', 'noscript', 'template', 'svg', 'iframe', 'form', 'button', 'select', 'textarea'];

    private const BLOCKS = [
        'address', 'article', 'aside', 'blockquote', 'dd', 'details', 'div', 'dl', 'dt', 'figcaption', 'figure',
        'footer', 'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'summary', 'table', 'tbody',
        'thead', 'tfoot', 'tr', 'ul', 'caption', 'br',
    ];

    /** @var list<string> */
    private array $out = [];

    private string $buffer = '';

    public function extract(string $path): string
    {
        $html = @file_get_contents($path);

        if ($html === false) {
            throw new DocumentException("Could not read {$path}.");
        }

        return $this->fromHtml(PlainTextExtractor::toUtf8($html));
    }

    public function fromHtml(string $html): string
    {
        $dom = new DOMDocument();
        // The XML declaration makes libxml read the markup as UTF-8
        $loaded = @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        if (!$loaded) {
            throw new DocumentException('Could not parse the HTML.');
        }

        $this->out = [];
        $this->buffer = '';
        $this->walk($dom->getElementsByTagName('body')->item(0) ?? $dom);
        $this->flush();

        return implode("\n\n", $this->out);
    }

    private function walk(DOMNode $node): void
    {
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                $this->buffer .= $child->textContent;
                continue;
            }

            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::SKIP, true)) {
                continue;
            }

            if (preg_match('/^h([1-6])$/', $tag, $level)) {
                $this->flush();
                $heading = $this->clean($child->textContent);

                if ($heading !== '') {
                    $this->out[] = str_repeat('#', (int) $level[1]) . ' ' . $heading;
                }
                continue;
            }

            if (in_array($tag, self::BLOCKS, true)) {
                $this->flush();
                $this->buffer = $tag === 'li' ? '- ' : '';
                $this->walk($child);
                $this->flush();
                continue;
            }

            $this->walk($child);

            // Keep table cells apart within their row
            if ($tag === 'td' || $tag === 'th') {
                $this->buffer .= ' | ';
            }
        }
    }

    private function flush(): void
    {
        $text = trim($this->clean($this->buffer), " |");

        if ($text !== '' && $text !== '-') {
            $this->out[] = $text;
        }

        $this->buffer = '';
    }

    private function clean(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
