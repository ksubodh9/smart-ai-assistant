<?php

namespace Subodh\SmartAiAssistant\Support;

use Subodh\SmartAiAssistant\Models\ErrorDefinition;
use Illuminate\Support\Str;

/**
 * Helper class to find a matching error definition based on substring matching.
 *
 * It first attempts an efficient SQL LIKE query (case‑insensitive) and, if no
 * result is found, falls back to a PHP‑side contains check for safety.
 */
class ErrorMatcher
{
    /**
     * Find the first matching error definition for a given service and error text.
     *
     * @param string $service   Service name (e.g. "AEPS")
     * @param string $errorText Full error text captured from the page
     * @return ErrorDefinition|null
     */
    public function findMatchingDefinition(string $service, string $errorText)
    {
        $errorText = trim($errorText);
        if ($errorText === '') {
            return null;
        }

        // First try a case‑insensitive LIKE query where the key_text appears anywhere
        $definition = ErrorDefinition::where('service', $service)
            ->whereRaw('LOWER(?) LIKE CONCAT("%", LOWER(key_text), "%")', [$errorText])
            ->first();

        if ($definition) {
            return $definition;
        }

        // Fallback: fetch all definitions for the service and check with Str::contains
        $candidates = ErrorDefinition::where('service', $service)->get();
        $lowerError = Str::lower($errorText);
        foreach ($candidates as $candidate) {
            if (Str::contains($lowerError, Str::lower($candidate->key_text))) {
                return $candidate;
            }
        }

        return null;
    }
}
?>
