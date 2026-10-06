<?php

namespace Subodh\SmartAiAssistant\Core\Data;

/**
 * What the escalation channel did with a request.
 *
 * $message is shown to the user as plain text, so the channel words it.
 */
final class EscalationResult
{
    public const CREATED = 'created';
    public const REJECTED = 'rejected';
    public const THROTTLED = 'throttled';
    public const FAILED = 'failed';

    /**
     * @param  string|null  $reference  Host reference shown to the user, e.g. a ticket number
     * @param  string|null  $viewUrl  Host page where the user can follow up
     */
    public function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly ?string $reference = null,
        public readonly ?string $viewUrl = null,
    ) {
    }

    public static function created(string $message, ?string $reference = null, ?string $viewUrl = null): self
    {
        return new self(self::CREATED, $message, $reference, $viewUrl);
    }

    /** The host refused the request (not allowed, invalid for its rules). */
    public static function rejected(string $message): self
    {
        return new self(self::REJECTED, $message);
    }

    /** The host's own limit, e.g. one ticket per user every few minutes. */
    public static function throttled(string $message): self
    {
        return new self(self::THROTTLED, $message);
    }

    /** Something broke; the user may retry later. */
    public static function failed(string $message): self
    {
        return new self(self::FAILED, $message);
    }

    public function isCreated(): bool
    {
        return $this->status === self::CREATED;
    }
}
