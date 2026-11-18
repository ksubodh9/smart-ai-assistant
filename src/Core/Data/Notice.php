<?php

namespace Subodh\SmartAiAssistant\Core\Data;

use DateTimeInterface;

/**
 * An active known issue with one service (see Core\Contracts\StatusNoticeSource).
 */
final class Notice
{
    /**
     * @param  string  $service  The category tag it applies to, e.g. 'PAYMENTS'
     *         (matched case-insensitively against StructuredProblem::$domains)
     * @param  array<string, string>  $messages  Text per language code
     */
    public function __construct(
        public readonly int|string $id,
        public readonly string $service,
        public readonly array $messages,
        public readonly ?DateTimeInterface $endsAt = null,
    ) {
    }
}
