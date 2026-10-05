<?php

namespace Subodh\SmartAiAssistant\Core\Data;

/**
 * A user message after validation and length limits, before interpretation.
 */
final class IncomingMessage
{
    public const SOURCE_TYPED = 'typed';
    public const SOURCE_PAGE_ERROR = 'page_error';
    public const SOURCE_SUGGESTION = 'suggestion';

    /**
     * @param  string|null  $pageUrl  Path only; query strings are stripped before this point
     */
    public function __construct(
        public readonly string $text,
        public readonly string $source = self::SOURCE_PAGE_ERROR,
        public readonly ?string $pageUrl = null,
    ) {
    }
}
