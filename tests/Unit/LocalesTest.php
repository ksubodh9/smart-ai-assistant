<?php

namespace Subodh\SmartAiAssistant\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Subodh\SmartAiAssistant\Support\Locales;

class LocalesTest extends TestCase
{
    private function maddoxPay(): Locales
    {
        $config = require __DIR__ . '/../Fixtures/maddoxpay-config.php';

        return new Locales($config['locales']['available'], $config['locales']['default']);
    }

    public static function messages(): array
    {
        return [
            'Devanagari'                 => ['पैसा कट गया लेकिन ट्रांजैक्शन फेल', 'hi'],
            'mostly Devanagari'          => ['AEPS में पैसा कट गया', 'hi'],
            'Hinglish'                   => ['mATM par device kaise register karen', 'hi-Latn'],
            'Hinglish, one marker word'  => ['aeps nahi chal raha', 'hi-Latn'],
            'English'                    => ['How to use AEPS?', 'en'],
            'English, three words'       => ['capture timeout again', 'en'],
            'too short to tell'          => ['hello', null],
            'two words'                  => ['thank you', null],
            'no letters'                 => ['12345 !!', null],
            'marker inside a word only'  => ['checking kiosk balance', 'en'],
        ];
    }

    #[DataProvider('messages')]
    public function test_it_detects_the_language_of_a_message(string $text, ?string $expected): void
    {
        $this->assertSame($expected, $this->maddoxPay()->detect($text));
    }

    public function test_package_default_is_english_only(): void
    {
        $locales = new Locales();

        $this->assertSame('en', $locales->default());
        $this->assertSame([['code' => 'en', 'label' => 'English']], $locales->forScript());
        $this->assertSame('en', $locales->detect('my card was declined'));
        // Without a Devanagari language configured, Hindi text is not told apart
        $this->assertSame('en', $locales->detect('पैसा कट गया'));
    }

    public function test_broken_patterns_and_unsafe_script_names_are_ignored(): void
    {
        $locales = new Locales([
            'en' => ['label' => 'English'],
            'xx' => ['script' => 'Latin}|.', 'patterns' => ['/(unclosed/']],
        ]);

        $this->assertSame('en', $locales->detect('this is english text'));
    }

    public function test_the_user_choice_wins_then_the_message_then_the_conversation(): void
    {
        $locales = $this->maddoxPay();

        $this->assertSame('en', $locales->choose('en', 'hi', 'hi-Latn', 'hi'));
        $this->assertSame('hi', $locales->choose('auto', 'hi', 'hi-Latn', 'en'));
        $this->assertSame('hi-Latn', $locales->choose(null, null, 'hi-Latn', 'en'));
        $this->assertSame('hi', $locales->choose(null, null, null, 'hi'));
        // Unknown codes (an old or newer widget, a host language that was removed) are skipped
        $this->assertSame('hi', $locales->choose('fr', null, 'de', 'hi'));
        $this->assertSame('en', $locales->choose(null, null, null, null));
    }

    public function test_a_missing_translation_falls_back_along_the_chain(): void
    {
        $locales = $this->maddoxPay();
        $texts = ['en' => 'Clean the scanner.', 'hi' => 'स्कैनर साफ करें।'];

        $this->assertSame(['locale' => 'hi', 'text' => 'स्कैनर साफ करें।'], $locales->pick($texts, 'hi-Latn'));
        $this->assertSame(['locale' => 'en', 'text' => 'Clean the scanner.'], $locales->pick(['en' => 'Clean the scanner.', 'hi' => ''], 'hi-Latn'));
        $this->assertSame(['locale' => 'fr', 'text' => 'Nettoyez.'], $locales->pick(['fr' => 'Nettoyez.'], 'hi'));
        $this->assertNull($locales->pick(['en' => null, 'hi' => ' '], 'en'));
    }

    public function test_circular_fallbacks_end_at_the_default(): void
    {
        $locales = new Locales([
            'en' => ['label' => 'English'],
            'a'  => ['fallback' => 'b'],
            'b'  => ['fallback' => 'a'],
        ]);

        $this->assertSame(['locale' => 'en', 'text' => 'English text'], $locales->pick(['en' => 'English text'], 'a'));
    }
}
