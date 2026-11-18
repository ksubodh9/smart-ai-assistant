<?php

namespace Subodh\SmartAiAssistant\Notices;

use Subodh\SmartAiAssistant\Core\Contracts\StatusNoticeSource;
use Subodh\SmartAiAssistant\Core\Data\Notice;
use Subodh\SmartAiAssistant\Models\StatusNotice;

/**
 * Active notices from smart_ai_status_notices.
 */
class DatabaseStatusNoticeSource implements StatusNoticeSource
{
    public function active(): array
    {
        return StatusNotice::active()
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (StatusNotice $notice) => new Notice(
                $notice->id,
                $notice->service,
                array_filter((array) $notice->messages, fn ($text) => is_string($text) && trim($text) !== ''),
                $notice->ends_at,
            ))
            ->filter(fn (Notice $notice) => $notice->messages !== [])
            ->values()
            ->all();
    }
}
