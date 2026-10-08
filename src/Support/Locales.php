<?php

namespace Subodh\SmartAiAssistant\Support;

/**
 * The reply languages a host offers (config 'locales'), how a message's
 * language is recognised, and which text of a translation map to show.
 *
 * Languages are codes the host chooses (e.g. 'en', 'hi', 'hi-Latn'). Each may set:
 *   - label:    shown in the widget's language menu
 *   - script:   Unicode script name (e.g. 'Devanagari'); a message written
 *               mostly in that script is in this language
 *   - patterns: regexes for words that mark the language in Latin letters
 *               (e.g. romanised Hindi); one match is enough
 *   - fallback: the language whose text is shown when a text has no
 *               translation in this one (default: the default language)
 * A language with neither script nor patterns is the catch-all for longer
 * messages that match no other language.
 */
class Locales
{
    /** Messages shorter than this ("ok", "hello") are too short to tell the language */
    private const MIN_WORDS_FOR_CATCH_ALL = 3;

    /** @var array<string, array<string, mixed>> */
    private array $available;

    private string $default;

    public function __construct(array $available = [], ?string $default = null)
    {
        $this->available = [];

        foreach ($available as $code => $settings) {
            if (is_string($code) && $code !== '') {
                $this->available[$code] = (array) $settings;
            }
        }

        if ($this->available === []) {
            $this->available = ['en' => ['label' => 'English']];
        }

        $this->default = $default !== null && isset($this->available[$default])
            ? $default
            : array_key_first($this->available);
    }

    public static function fromConfig(): self
    {
        $config = (array) config('smart-ai-assistant.locales', []);

        return new self((array) ($config['available'] ?? []), $config['default'] ?? null);
    }

    public function default(): string
    {
        return $this->default;
    }

    public function has(?string $code): bool
    {
        return $code !== null && isset($this->available[$code]);
    }

    /**
     * The languages the widget offers (rendered into its config block).
     *
     * @return list<array{code: string, label: string}>
     */
    public function forScript(): array
    {
        $languages = [];

        foreach ($this->available as $code => $settings) {
            $languages[] = ['code' => $code, 'label' => (string) ($settings['label'] ?? $code)];
        }

        return $languages;
    }

    /**
     * The language a message is written in, or null when it cannot be told
     * (too short, or no configured rule matches).
     */
    public function detect(string $text): ?string
    {
        $letters = preg_match_all('/\p{L}/u', $text);

        if (!$letters) {
            return null;
        }

        foreach ($this->available as $code => $settings) {
            $script = (string) ($settings['script'] ?? '');

            // Letters of the script (not vowel signs, which are marks) against all letters.
            // Script names are letters and underscores only, so config cannot alter the regex.
            if (preg_match('/^[A-Za-z_]+$/', $script)
                && (int) @preg_match_all('/(?=\p{L})\p{' . $script . '}/u', $text) * 2 > $letters) {
                return $code;
            }
        }

        foreach ($this->available as $code => $settings) {
            foreach ((array) ($settings['patterns'] ?? []) as $pattern) {
                // A broken host pattern must not break the assistant; @ hides the warning
                if (@preg_match((string) $pattern, $text) === 1) {
                    return $code;
                }
            }
        }

        if (preg_match_all('/\p{L}+/u', $text) >= self::MIN_WORDS_FOR_CATCH_ALL) {
            foreach ($this->available as $code => $settings) {
                if (empty($settings['script']) && empty($settings['patterns'])) {
                    return $code;
                }
            }
        }

        return null;
    }

    /**
     * The reply language: the user's choice in the widget, else the language
     * the message is written in, else the one used earlier in the
     * conversation, else the host's language for the user, else the default.
     *
     * @param  string|null  $requested  From the widget: a language code, or 'auto'/null
     */
    public function choose(?string $requested, ?string $detected, ?string $previous, ?string $userLocale): string
    {
        foreach ([$requested, $detected, $previous, $userLocale] as $candidate) {
            if ($this->has($candidate)) {
                return $candidate;
            }
        }

        return $this->default;
    }

    /**
     * The text to show for a language: its own translation, else its
     * fallback languages, else the default language, else any text.
     *
     * @param  array<string, string|null>  $texts  Language code => text
     * @return array{locale: string, text: string}|null  null when there is no text at all
     */
    public function pick(array $texts, string $locale): ?array
    {
        foreach ($this->chain($locale) as $code) {
            if (isset($texts[$code]) && trim((string) $texts[$code]) !== '') {
                return ['locale' => $code, 'text' => (string) $texts[$code]];
            }
        }

        foreach ($texts as $code => $text) {
            if (is_string($code) && trim((string) $text) !== '') {
                return ['locale' => $code, 'text' => (string) $text];
            }
        }

        return null;
    }

    /**
     * @return list<string> The language, its fallbacks in order, then the default
     */
    private function chain(string $locale): array
    {
        $chain = [];

        while ($locale !== '' && !in_array($locale, $chain, true)) {
            $chain[] = $locale;
            $locale = (string) ($this->available[$locale]['fallback'] ?? $this->default);
        }

        if (!in_array($this->default, $chain, true)) {
            $chain[] = $this->default;
        }

        return $chain;
    }
}
