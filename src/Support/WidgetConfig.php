<?php

namespace Subodh\SmartAiAssistant\Support;

/**
 * The widget settings, with defaults for every key the host leaves out.
 *
 * Laravel merges the host config one level deep, so a host that sets
 * 'widget' => ['branding' => [...]] would otherwise lose every other widget
 * setting. Here each section is merged key by key; list values (selectors,
 * suggestions) are replaced as a whole.
 */
class WidgetConfig
{
    public const DEFAULTS = [
        'branding' => [
            'title'            => 'Support assistant',
            'icon'             => '🤖',
            'welcome_title'    => 'Hello!',
            'welcome_subtitle' => 'How may I assist you today?',
            'footer'           => null,
            'primary_color'    => '#667eea',
            'secondary_color'  => '#764ba2',
        ],
        'features' => [
            'page_scan'              => true,
            'attachments'            => true,
            'screenshot'             => true,
            'bootstrap_modal_compat' => false,
        ],
        'suggestions' => [],
        'page_scan' => [
            'ids'             => [],
            'selectors'       => ['.alert-danger', '.smart-error'],
            'soft_selectors'  => ['.text-danger'],
            'ignore_classes'  => ['invalid-feedback', 'help-block', 'placeholder'],
            'ignore_ids'      => [],
            'ignore_patterns' => ['^(loading\.*|please\s+wait|processing)$'],
        ],
    ];

    private array $settings;

    public function __construct(array $widget = [])
    {
        $settings = self::DEFAULTS;

        foreach (['branding', 'features', 'page_scan'] as $section) {
            $settings[$section] = array_replace(self::DEFAULTS[$section], (array) ($widget[$section] ?? []));
        }

        $settings['suggestions'] = array_values(array_filter(
            array_map('strval', (array) ($widget['suggestions'] ?? [])),
            fn ($text) => trim($text) !== ''
        ));

        $this->settings = $settings;
    }

    public static function fromConfig(): self
    {
        return new self((array) config('smart-ai-assistant.widget', []));
    }

    public function branding(string $key): mixed
    {
        return $this->settings['branding'][$key];
    }

    public function feature(string $key): bool
    {
        return (bool) $this->settings['features'][$key];
    }

    /**
     * @return list<string>
     */
    public function suggestions(): array
    {
        return $this->settings['suggestions'];
    }

    /**
     * What the widget scripts read (rendered as JSON into the page).
     */
    public function forScript(): array
    {
        return [
            'features'  => $this->settings['features'],
            'page_scan' => $this->settings['page_scan'],
        ];
    }

    /**
     * CSS custom properties for the brand colours. Values that are not hex
     * colours fall back to the defaults, so config cannot inject CSS.
     *
     * @return array<string, string>
     */
    public function cssVariables(): array
    {
        $primary = $this->hex('primary_color');
        $secondary = $this->hex('secondary_color');

        return [
            '--sa-primary'       => $primary,
            '--sa-secondary'     => $secondary,
            '--sa-primary-rgb'   => $this->rgb($primary),
            '--sa-secondary-rgb' => $this->rgb($secondary),
        ];
    }

    private function hex(string $key): string
    {
        $value = (string) $this->settings['branding'][$key];

        return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value) ? $value : self::DEFAULTS['branding'][$key];
    }

    private function rgb(string $hex): string
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return implode(', ', array_map('hexdec', str_split($hex, 2)));
    }
}
