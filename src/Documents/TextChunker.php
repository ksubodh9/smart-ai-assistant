<?php

namespace Subodh\SmartAiAssistant\Documents;

/**
 * Splits extracted text into passages of at most $size characters.
 *
 * Passages never cross a heading, so each one belongs to a single section
 * and carries its heading path ("Refunds > Timelines"). Within a section,
 * whole paragraphs are packed together; a paragraph that is too long is
 * split at sentence ends, then at spaces, and only then mid-word.
 */
class TextChunker
{
    public const HEADING_MAX_LENGTH = 255;

    private int $size;

    public function __construct(int $size = 1500)
    {
        $this->size = max(200, $size);
    }

    /**
     * @return list<array{heading: string|null, content: string}>
     */
    public function chunk(string $text): array
    {
        $chunks = [];

        foreach ($this->sections($this->normalize($text)) as [$heading, $paragraphs]) {
            foreach ($this->pack($paragraphs, "\n\n", 0) as $content) {
                $chunks[] = ['heading' => $heading, 'content' => $content];
            }
        }

        return $chunks;
    }

    private function normalize(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            // Drop invalid bytes instead of turning them into "?"
            $substitute = mb_substitute_character();
            mb_substitute_character('none');
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            mb_substitute_character($substitute);
        }

        $text = str_replace(["\r\n", "\r", "\f"], "\n", $text);
        // Control characters other than tab and newline (e.g. from PDFs)
        $text = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000E}-\x{001F}\x{007F}\x{200B}\x{FEFF}]/u', '', $text) ?? '';

        return preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? '';
    }

    /**
     * @return list<array{0: string|null, 1: list<string>}>
     */
    private function sections(string $text): array
    {
        $sections = [];
        $headings = [];   // level => title
        $paragraphs = [];
        $lines = [];
        $inFence = false; // inside a Markdown ``` code block, "#" lines are code

        $flushParagraph = function () use (&$lines, &$paragraphs) {
            if ($lines !== []) {
                $paragraphs[] = implode("\n", $lines);
                $lines = [];
            }
        };
        $flushSection = function () use (&$sections, &$paragraphs, &$headings, $flushParagraph) {
            $flushParagraph();

            if ($paragraphs !== []) {
                $path = $headings === [] ? null : mb_substr(implode(' > ', $headings), 0, self::HEADING_MAX_LENGTH);
                $sections[] = [$path, $paragraphs];
                $paragraphs = [];
            }
        };

        foreach (explode("\n", $text) as $line) {
            $line = trim($line);

            if (str_starts_with($line, '```')) {
                $inFence = !$inFence;
            }

            if (!$inFence && preg_match('/^(#{1,6})\s+(.+?)(?:\s+#+)?$/u', $line, $match)) {
                $flushSection();
                $level = strlen($match[1]);
                $headings = array_filter($headings, fn ($l) => $l < $level, ARRAY_FILTER_USE_KEY);
                $headings[$level] = $match[2];
                ksort($headings);
                continue;
            }

            if ($line === '') {
                $flushParagraph();
            } else {
                $lines[] = $line;
            }
        }

        $flushSection();

        return $sections;
    }

    /**
     * Greedily joins parts up to the size; parts that are too long on their
     * own are split one level finer first.
     *
     * @param  list<string>  $parts
     * @return list<string>
     */
    private function pack(array $parts, string $glue, int $depth): array
    {
        $chunks = [];
        $current = '';

        foreach ($parts as $part) {
            if (mb_strlen($part) > $this->size) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }

                array_push($chunks, ...$this->pack($this->finer($part, $depth), ' ', $depth + 1));
                continue;
            }

            $candidate = $current === '' ? $part : $current . $glue . $part;

            if (mb_strlen($candidate) > $this->size) {
                $chunks[] = $current;
                $current = $part;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * @return list<string>
     */
    private function finer(string $text, int $depth): array
    {
        $parts = match ($depth) {
            0       => preg_split('/(?<=[.!?।])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY), // sentences (incl. Devanagari danda)
            1       => preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY),            // words
            default => mb_str_split($text, $this->size),
        };

        return $parts ?: mb_str_split($text, $this->size);
    }
}
