<?php

namespace Subodh\SmartAiAssistant\Answering;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Daily caps on answer-generation (LLM) calls, overall and per user, so a
 * busy day or one user cannot run up the bill. Counted in the cache per
 * calendar day; a cap of 0 or null means no cap.
 */
class AnswerCallLimiter
{
    public function __construct(
        private readonly Cache $cache,
        private readonly ?int $dailyLimit = null,
        private readonly ?int $perUserDailyLimit = null,
    ) {
    }

    /**
     * Counts one call if it is within the caps.
     *
     * @param  string|null  $userId  Null for guests, who only count towards the overall cap
     */
    public function attempt(?string $userId): bool
    {
        $day = now()->format('Y-m-d');
        $keys = array_filter([
            "smart-ai:answer-calls:{$day}" => $this->dailyLimit,
            "smart-ai:answer-calls:{$day}:" . sha1((string) $userId) => $userId !== null ? $this->perUserDailyLimit : null,
        ]);

        foreach ($keys as $key => $limit) {
            if ((int) $this->cache->get($key, 0) >= $limit) {
                return false;
            }
        }

        foreach (array_keys($keys) as $key) {
            $this->cache->add($key, 0, now()->addDays(2));
            $this->cache->increment($key);
        }

        return true;
    }
}
