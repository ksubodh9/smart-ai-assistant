<?php

namespace Subodh\SmartAiAssistant\Core\Contracts;

use Subodh\SmartAiAssistant\Core\Data\Notice;

/**
 * Known issues that are active right now. The default reads the notices
 * posted with smart-ai:notice (or Notices\StatusNoticeManager); a host can
 * provide its own, e.g. from an operator status feed.
 */
interface StatusNoticeSource
{
    /**
     * @return list<Notice> Newest first
     */
    public function active(): array;
}
