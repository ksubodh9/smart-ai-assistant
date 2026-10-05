<?php

namespace Subodh\SmartAiAssistant\Support;

use Subodh\SmartAiAssistant\Core\Contracts\Redactor;

/**
 * Masks common Indian personal identifiers with placeholders.
 *
 * Deliberately broad: any run of 9-18 digits is masked as [number], because
 * account numbers, Aadhaar numbers and card numbers look alike. Short codes
 * such as "Error 1001" are kept. Hosts can replace this class through the
 * 'redactor' config key.
 */
class DefaultRedactor implements Redactor
{
    /**
     * Applied in order; earlier patterns take precedence.
     */
    private const PATTERNS = [
        // Email addresses
        '/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.\p{L}{2,}/u'              => '[email]',
        // PAN: AAAAA9999A
        '/\b[A-Z]{5}[0-9]{4}[A-Z]\b/iu'                                   => '[pan]',
        // Aadhaar written in groups: 1234 5678 9012 / 1234-5678-9012
        '/(?<![\d-])\d{4}[ -]\d{4}[ -]\d{4}(?![\d-])/u'                   => '[aadhaar]',
        // Indian mobile numbers, optionally with +91 / 91 / 0 and one separator
        '/(?<![\d+])(?:(?:\+|00)?91[ -]?|0)?[6-9]\d{4}[ -]?\d{5}(?!\d)/u' => '[phone]',
        // Long digit runs: account, Aadhaar, card and similar numbers
        '/(?<!\d)\d{9,18}(?!\d)/u'                                        => '[number]',
    ];

    public function redact(string $text): string
    {
        foreach (self::PATTERNS as $pattern => $placeholder) {
            $text = preg_replace($pattern, $placeholder, $text);

            // Fail closed: never store text that could not be checked (e.g. invalid UTF-8)
            if ($text === null) {
                return '[unreadable]';
            }
        }

        return $text;
    }
}
