<?php

namespace Subodh\SmartAiAssistant\Notices;

use DateTimeInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Subodh\SmartAiAssistant\Models\StatusNotice;

/**
 * Posts and ends notices in smart_ai_status_notices. Used by smart-ai:notice;
 * hosts can call it from their own admin screens.
 */
class StatusNoticeManager
{
    /**
     * @param  int|null  $defaultMinutes  How long a notice posted without an end lasts; null = until ended
     * @param  int  $maxLength  Longest text per language (characters)
     */
    public function __construct(
        private readonly ?int $defaultMinutes = 240,
        private readonly int $maxLength = 500,
    ) {
    }

    /**
     * @param  array<string, string>  $messages  Text per language code; at least one
     * @param  DateTimeInterface|false|null  $endsAt  null = the default duration; false = until ended
     *
     * @throws InvalidArgumentException When the service or texts are missing or too long
     */
    public function post(string $service, array $messages, DateTimeInterface|false|null $endsAt = null, ?DateTimeInterface $startsAt = null, ?string $postedBy = null): StatusNotice
    {
        $service = trim($service);

        if ($service === '' || mb_strlen($service) > 64) {
            throw new InvalidArgumentException('The service must be 1 to 64 characters.');
        }

        $clean = [];

        foreach ($messages as $locale => $text) {
            $text = trim((string) $text);

            if (!is_string($locale) || $locale === '' || $text === '') {
                continue;
            }

            if (mb_strlen($text) > $this->maxLength) {
                throw new InvalidArgumentException("The {$locale} text is longer than {$this->maxLength} characters.");
            }

            $clean[$locale] = $text;
        }

        if ($clean === []) {
            throw new InvalidArgumentException('A notice needs a text in at least one language.');
        }

        if ($endsAt === null && $this->defaultMinutes !== null) {
            $endsAt = now()->addMinutes($this->defaultMinutes);
        }

        return StatusNotice::create([
            'service'   => $service,
            'messages'  => $clean,
            'starts_at' => $startsAt,
            'ends_at'   => $endsAt ?: null,
            'posted_by' => $postedBy,
        ]);
    }

    /**
     * End active notices now: one by id, all of one service, or all (both null).
     *
     * @return int How many were ended
     */
    public function end(?string $service = null, int|string|null $id = null): int
    {
        return StatusNotice::active()
            ->when($id !== null, fn ($query) => $query->whereKey($id))
            ->when($service !== null, fn ($query) => $query->where('service', $service))
            ->update(['ends_at' => now()]);
    }

    /**
     * @return Collection<int, StatusNotice> Active notices, or also ended ones (latest 20), newest first
     */
    public function list(bool $withEnded = false): Collection
    {
        return StatusNotice::query()
            ->when(!$withEnded, fn ($query) => $query->active())
            ->orderByDesc('id')
            ->limit(20)
            ->get();
    }
}
