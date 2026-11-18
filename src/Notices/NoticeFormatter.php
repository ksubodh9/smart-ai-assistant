<?php

namespace Subodh\SmartAiAssistant\Notices;

use Subodh\SmartAiAssistant\Core\Data\Notice;
use Subodh\SmartAiAssistant\Support\Locales;
use Subodh\SmartAiAssistant\Support\ResponseCatalog;

/**
 * Turns notices into reply texts per language, through a ResponseCatalog key
 * with :service and :message placeholders.
 */
class NoticeFormatter
{
    public function __construct(
        private readonly ResponseCatalog $responses,
        private readonly Locales $locales,
    ) {
    }

    /**
     * @param  list<Notice>  $notices
     * @return array<string, string> Text per language code, one paragraph per notice
     */
    public function answers(array $notices, string $key): array
    {
        $templates = $this->responses->answers($key);
        $default = $this->locales->default();
        $codes = [];

        foreach ($notices as $notice) {
            $codes = array_merge($codes, array_keys($notice->messages));
        }

        $answers = [];

        foreach (array_unique($codes) as $code) {
            $template = $templates[$code] ?? $templates[$default] ?? reset($templates) ?: ':message';
            $paragraphs = [];

            foreach ($notices as $notice) {
                $message = $notice->messages[$code] ?? $notice->messages[$default] ?? array_values($notice->messages)[0] ?? '';
                $paragraphs[] = strtr($template, [':service' => $notice->service, ':message' => $message]);
            }

            $answers[$code] = implode("\n\n", $paragraphs);
        }

        return $answers;
    }
}
