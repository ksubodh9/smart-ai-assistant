<?php

namespace Subodh\SmartAiAssistant\Documents;

use Subodh\SmartAiAssistant\Models\Document;

/**
 * What DocumentIngester::ingest() did with one file.
 */
final class IngestResult
{
    public const CREATED = 'created';                    // first version of the title
    public const NEW_VERSION = 'new_version';            // replaced the active version
    public const UNCHANGED = 'unchanged';                // same file as the active version
    public const AUDIENCE_CHANGED = 'audience_changed';  // same file, only the audience changed

    public function __construct(
        public readonly string $status,
        public readonly Document $document,
        public readonly int $chunks,
        public readonly ?int $previousVersion = null,
    ) {
    }
}
