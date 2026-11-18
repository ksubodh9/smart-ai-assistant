<?php

namespace Subodh\SmartAiAssistant\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Subodh\SmartAiAssistant\Models\StatusNotice;
use Subodh\SmartAiAssistant\Notices\StatusNoticeManager;

class ManageNotices extends Command
{
    protected $signature = 'smart-ai:notice
        {action=list : list, post (announce a known issue) or end}
        {service? : Category tag from understanding.categories, e.g. PAYMENTS}
        {message? : post: the notice in the default language}
        {--text=* : post: the notice in another language, as code:text (repeatable)}
        {--minutes= : post: minutes until it ends; 0 = until ended by hand (default: status_notices.default_minutes)}
        {--id= : end: one notice by id}
        {--all : list: include ended notices; end: every active notice}';

    protected $description = 'Announce a known issue with a service (shown in the widget), list notices, or end them';

    public function handle(StatusNoticeManager $notices)
    {
        return match ($this->argument('action')) {
            'list'  => $this->list($notices),
            'post'  => $this->post($notices),
            'end'   => $this->end($notices),
            default => $this->invalidAction(),
        };
    }

    private function list(StatusNoticeManager $notices): int
    {
        $rows = $notices->list((bool) $this->option('all'));

        if ($rows->isEmpty()) {
            $this->info($this->option('all') ? 'No notices.' : 'No active notices.');
            return 0;
        }

        $this->table(
            ['Id', 'Service', 'Text', 'Languages', 'Starts', 'Ends', 'Active'],
            $rows->map(function (StatusNotice $n) {
                $messages = (array) $n->messages;

                return [
                    $n->id,
                    $n->service,
                    mb_strimwidth((string) (reset($messages) ?: ''), 0, 60, '…'),
                    implode(', ', array_keys($messages)),
                    $n->starts_at?->toDateTimeString() ?? '-',
                    $n->ends_at?->toDateTimeString() ?? 'until ended',
                    $n->isActive() ? 'yes' : 'no',
                ];
            })->all()
        );

        return 0;
    }

    private function post(StatusNoticeManager $notices): int
    {
        $service = trim((string) $this->argument('service'));
        $messages = [config('smart-ai-assistant.locales.default', 'en') => (string) $this->argument('message')];

        foreach ((array) $this->option('text') as $text) {
            [$code, $value] = array_pad(explode(':', (string) $text, 2), 2, '');

            if (trim($code) === '' || trim($value) === '') {
                $this->error("Write --text as code:text, e.g. --text=\"hi:...\" (got \"{$text}\").");
                return 1;
            }

            $messages[trim($code)] = $value;
        }

        $minutes = $this->option('minutes');

        if ($minutes !== null && (!ctype_digit((string) $minutes))) {
            $this->error('--minutes must be a whole number (0 = until ended by hand).');
            return 1;
        }

        $endsAt = match (true) {
            $minutes === null       => null,
            (int) $minutes === 0    => false,
            default                 => now()->addMinutes((int) $minutes),
        };

        try {
            $notice = $notices->post($service, $messages, $endsAt, null, 'console');
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return 1;
        }

        $categories = array_map('mb_strtolower', array_keys((array) config('smart-ai-assistant.understanding.categories', [])));

        if (!in_array(mb_strtolower($notice->service), $categories, true)) {
            $this->warn("{$notice->service} is not a category in understanding.categories: the notice shows when the widget opens, but no message will be matched to it.");
        }

        $until = $notice->ends_at ? 'until ' . $notice->ends_at->toDateTimeString() : 'until ended';
        $this->info("Posted notice #{$notice->id} for {$notice->service}, {$until}. End it with: smart-ai:notice end {$notice->service}");

        return 0;
    }

    private function end(StatusNoticeManager $notices): int
    {
        $service = $this->argument('service');
        $id = $this->option('id');

        if ($service === null && $id === null && !$this->option('all')) {
            $this->error('Name a service, --id, or --all.');
            return 1;
        }

        $ended = $notices->end($service, $id);

        if ($ended === 0) {
            $this->error('No active notice matched.');
            return 1;
        }

        $this->info("Ended {$ended} notice(s).");

        return 0;
    }

    private function invalidAction(): int
    {
        $this->error('Unknown action; use list, post or end.');

        return 1;
    }
}
