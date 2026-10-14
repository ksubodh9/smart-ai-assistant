<?php

namespace Subodh\SmartAiAssistant\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Subodh\SmartAiAssistant\Documents\TextChunker;

class TextChunkerTest extends TestCase
{
    public function test_empty_or_blank_text_gives_no_chunks(): void
    {
        $this->assertSame([], (new TextChunker())->chunk(''));
        $this->assertSame([], (new TextChunker())->chunk("\n\n  \t\n# Only a heading\n"));
    }

    public function test_short_paragraphs_of_one_section_share_a_chunk(): void
    {
        $chunks = (new TextChunker())->chunk("First paragraph.\n\nSecond paragraph.");

        $this->assertSame([['heading' => null, 'content' => "First paragraph.\n\nSecond paragraph."]], $chunks);
    }

    public function test_chunks_never_cross_a_heading_and_carry_the_heading_path(): void
    {
        $text = "Intro text.\n\n# Refunds\nRefund overview.\n\n## Timelines\nRefunds take 5 days.\n\n## Charges\nNo charge.\n\n# Login\nUse your phone number.";

        $chunks = (new TextChunker())->chunk($text);

        $this->assertSame([
            ['heading' => null, 'content' => 'Intro text.'],
            ['heading' => 'Refunds', 'content' => 'Refund overview.'],
            ['heading' => 'Refunds > Timelines', 'content' => 'Refunds take 5 days.'],
            ['heading' => 'Refunds > Charges', 'content' => 'No charge.'],
            ['heading' => 'Login', 'content' => 'Use your phone number.'],
        ], $chunks);
    }

    public function test_paragraphs_are_packed_up_to_the_size_without_splitting_them(): void
    {
        $paragraph = str_repeat('word ', 39) . 'end.'; // 199 characters

        $chunks = (new TextChunker(450))->chunk(implode("\n\n", array_fill(0, 5, $paragraph)));

        $this->assertCount(3, $chunks);
        $this->assertSame($paragraph . "\n\n" . $paragraph, $chunks[0]['content']);
        $this->assertSame($paragraph, $chunks[2]['content']);
    }

    public function test_long_paragraphs_are_split_at_sentences_then_words(): void
    {
        $sentence = str_repeat('a', 150) . '.';
        $chunks = (new TextChunker(200))->chunk(implode(' ', array_fill(0, 3, $sentence)) . ' ' . str_repeat('verylongword ', 40));

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(200, mb_strlen($chunk['content']));
        }

        $this->assertSame($sentence, $chunks[0]['content']);
        $this->assertStringStartsWith('verylongword', $chunks[3]['content']);
    }

    public function test_a_word_longer_than_the_size_is_cut(): void
    {
        $chunks = (new TextChunker(200))->chunk(str_repeat('x', 450));

        $this->assertSame([200, 200, 50], array_map(fn ($c) => mb_strlen($c['content']), $chunks));
    }

    public function test_devanagari_sentences_split_at_the_danda_and_sizes_count_characters(): void
    {
        $sentence = str_repeat('क', 120) . '।';
        $chunks = (new TextChunker(200))->chunk("{$sentence} {$sentence}");

        $this->assertSame([$sentence, $sentence], array_column($chunks, 'content'));
    }

    public function test_hash_lines_inside_code_fences_are_not_headings(): void
    {
        $chunks = (new TextChunker())->chunk("# Setup\n```\n# not a heading\nrun\n```");

        $this->assertSame('Setup', $chunks[0]['heading']);
        $this->assertStringContainsString('# not a heading', $chunks[0]['content']);
    }

    public function test_whitespace_control_characters_and_invalid_utf8_are_cleaned(): void
    {
        $chunks = (new TextChunker())->chunk("Tabs\t\tand\u{00A0}spaces\r\nnext line\x07\xFF");

        $this->assertSame("Tabs and spaces\nnext line", $chunks[0]['content']);
    }
}
