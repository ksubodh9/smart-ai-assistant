<?php

namespace Subodh\SmartAiAssistant\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Subodh\SmartAiAssistant\Knowledge\KeywordKnowledgeSource;
use Subodh\SmartAiAssistant\Models\ErrorDefinition;
use Subodh\SmartAiAssistant\Tests\TestCase;

/**
 * Keyword entries: all words of the entry in the message, in any order, with
 * host spelling variants and synonyms.
 */
class KeywordKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['smart-ai-assistant.knowledge.synonyms' => [
            'refund' => ['refnd', 'wapas', 'reverse'],
            'money'  => ['paisa', 'pese', 'amount', 'rupees'],
            'not'    => ['nahi', 'nhi', 'nai', 'no'],
        ]]);
    }

    private function keywordEntry(string $keywords, string $answer, string $service = 'AEPS'): ErrorDefinition
    {
        return ErrorDefinition::create([
            'service'   => $service,
            'key_text'  => $keywords,
            'answer_en' => $answer,
            'meta'      => ['match' => KeywordKnowledgeSource::MATCH_TYPE],
        ]);
    }

    private function ask(string $text)
    {
        return $this->postJson('/smart-assistant/message', ['text' => $text]);
    }

    public function test_all_words_in_any_order_with_variants(): void
    {
        $this->keywordEntry('pan refund', 'PAN refund guidance.');

        $this->ask('Pan card apply kiya, pese refnd nahi huye')
            ->assertJson(['meta' => ['source' => 'kb', 'category' => 'PAN'], 'blocks' => [['text' => 'PAN refund guidance.']]]);
        $this->ask('refund for my PAN application')->assertJson(['meta' => ['source' => 'kb']]);
    }

    public function test_a_missing_word_means_no_match(): void
    {
        $this->keywordEntry('pan refund', 'PAN refund guidance.');

        $this->ask('pan card not generated')->assertJson(['meta' => ['source' => 'unknown']]);
    }

    public function test_words_match_whole_words_only(): void
    {
        $this->keywordEntry('id blocked', 'Unblock guidance.');

        $this->ask('paid but blocked')->assertJson(['meta' => ['source' => 'unknown']]);
        $this->ask('my id is blocked')->assertJson(['meta' => ['source' => 'kb']]);
    }

    public function test_small_spelling_mistakes_in_longer_words_still_match(): void
    {
        $this->keywordEntry('incomplete application', 'Incomplete application guidance.');

        $this->ask('my aplication shows incompleate')->assertJson(['meta' => ['source' => 'kb']]);
    }

    public function test_short_words_and_other_first_letters_need_exact_spelling(): void
    {
        $this->keywordEntry('pan', 'PAN guidance.');
        $this->keywordEntry('wallet', 'Wallet guidance.');

        $this->ask('pin not working')->assertJson(['meta' => ['source' => 'unknown']]);   // 3 letters: exact only
        $this->ask('tablet issue')->assertJson(['meta' => ['source' => 'unknown']]);      // different first letter
        $this->ask('wallett empty')->assertJson(['meta' => ['source' => 'kb']]);
    }

    public function test_typo_tolerance_can_be_switched_off(): void
    {
        config(['smart-ai-assistant.knowledge.typo_tolerance' => false]);
        $this->keywordEntry('incomplete', 'Incomplete guidance.');

        $this->ask('incompleate')->assertJson(['meta' => ['source' => 'unknown']]);
    }

    public function test_the_most_specific_entry_wins(): void
    {
        $this->keywordEntry('refund', 'General refund guidance.');
        $this->keywordEntry('pan refund not', 'PAN refund not received.');

        $this->ask('pan ka paisa wapas nahi aaya')->assertJson(['answer_en' => 'PAN refund not received.']);
        $this->ask('recharge refund')->assertJson(['answer_en' => 'General refund guidance.']);
    }

    public function test_entry_words_may_be_written_as_variants_too(): void
    {
        $this->keywordEntry('paisa wapas', 'Money back guidance.');

        $this->ask('amount refund kab milega')->assertJson(['meta' => ['source' => 'kb']]);
    }

    public function test_exact_text_entries_win_over_keyword_entries(): void
    {
        $this->keywordEntry('device found', 'Keyword answer.');
        ErrorDefinition::create(['service' => 'AEPS', 'key_text' => 'device not found', 'answer_en' => 'Exact answer.']);

        $this->ask('Device not found. Please attach your device')->assertJson(['answer_en' => 'Exact answer.']);
    }

    public function test_keyword_entries_are_not_matched_as_text(): void
    {
        // As text, "id" would match inside "paid"
        $this->keywordEntry('id', 'Should not match paid.');

        $this->ask('paid twice')->assertJson(['meta' => ['source' => 'unknown']]);
    }

    public function test_only_the_configured_service_is_searched(): void
    {
        $this->keywordEntry('pan refund', 'Other domain.', 'PAN');

        $this->ask('pan refund')->assertJson(['meta' => ['source' => 'unknown']]);
    }

    public function test_hindi_script_words_work(): void
    {
        $this->keywordEntry('पैसा वापस', 'हिंदी उत्तर।');

        $this->ask('मेरा पैसा अभी तक वापस नहीं आया')->assertJson(['meta' => ['source' => 'kb']]);
    }

    public function test_keyword_source_can_be_left_out(): void
    {
        config(['smart-ai-assistant.knowledge.sources' => [\Subodh\SmartAiAssistant\Knowledge\DatabaseKnowledgeSource::class]]);
        $this->keywordEntry('pan refund', 'PAN refund guidance.');

        $this->ask('pan refund')->assertJson(['meta' => ['source' => 'unknown']]);
    }

    public function test_seed_kb_imports_keyword_entries(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'kw') . '.csv';
        file_put_contents($file, "Keywords,Answer EN,Answer HI\npan refund,PAN refund guidance.,\nwallet,,\n");

        try {
            $this->artisan('smart-ai:seed-kb', ['file' => $file, '--keywords' => true])
                ->expectsOutputToContain('Skipped 1 keyword rows without an English answer')
                ->assertSuccessful();
        } finally {
            @unlink($file);
        }

        $entry = ErrorDefinition::sole();
        $this->assertSame(['match' => 'keywords'], $entry->meta);
        $this->ask('refund of pan fee')->assertJson(['meta' => ['source' => 'kb']]);
    }
}
