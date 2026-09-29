<?php

namespace Subodh\SmartAiAssistant\Core\Data;

/**
 * Who is talking to the assistant, as decided by the host application.
 *
 * Built server-side by a UserContextResolver, never from request payloads.
 * Keep personal data (phone, PAN, account numbers) out of it: anything that
 * needs such data asks the host through its own contract.
 */
final class UserContext
{
    /**
     * @param  string|null  $id  Host user identifier; null for guests
     * @param  array<string, mixed>  $attributes  Host-defined, non-sensitive facts (e.g. role)
     */
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $displayName = null,
        public readonly string $locale = 'en',
        public readonly array $attributes = [],
        public readonly ?string $tenantId = null,
    ) {
    }

    public static function guest(string $locale = 'en'): self
    {
        return new self(id: null, locale: $locale);
    }

    public function isAuthenticated(): bool
    {
        return $this->id !== null;
    }
}
