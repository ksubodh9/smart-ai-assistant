<?php

namespace Subodh\SmartAiAssistant\Core\Data;

/**
 * What a DataTool found, as display-safe label/value pairs.
 *
 * Shown to the user as a key_value block and stored with the conversation,
 * so values must already be masked.
 */
final class ToolResult
{
    public const FOUND = 'found';
    public const NOT_FOUND = 'not_found';

    /**
     * @param  list<array{label: string, value: string}>  $items
     * @param  string|null  $note  One plain sentence shown under the items
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $title = null,
        public readonly array $items = [],
        public readonly ?string $note = null,
    ) {
    }

    /**
     * @param  array<string, string|int|float|null>  $values  Label => value, in display order; null values are left out
     */
    public static function found(string $title, array $values, ?string $note = null): self
    {
        $items = [];

        foreach ($values as $label => $value) {
            if ($value !== null && $value !== '') {
                $items[] = ['label' => (string) $label, 'value' => (string) $value];
            }
        }

        return new self(self::FOUND, $title, $items, $note);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND);
    }

    public function isFound(): bool
    {
        return $this->status === self::FOUND;
    }
}
