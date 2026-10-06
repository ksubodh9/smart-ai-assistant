<?php

namespace Subodh\SmartAiAssistant\Console\Commands;

use Illuminate\Console\Command;
use Subodh\SmartAiAssistant\Models\Conversation;
use Subodh\SmartAiAssistant\Models\Message;

class PruneConversations extends Command
{
    protected $signature = 'smart-ai:prune
        {--days= : Delete conversations idle for more than this many days; defaults to config conversations.retention_days}';

    protected $description = 'Delete stored assistant conversations (and their messages) older than the retention period';

    public function handle()
    {
        $days = $this->option('days') ?? config('smart-ai-assistant.conversations.retention_days');

        if ($days === null || $days === '') {
            $this->info('No retention period set (conversations.retention_days); nothing deleted.');
            return 0;
        }

        if (!ctype_digit((string) $days) || (int) $days < 1) {
            $this->error('The retention period must be a whole number of days, at least 1.');
            return 1;
        }

        $expired = Conversation::where('updated_at', '<', now()->subDays((int) $days));

        // Messages first: the foreign key cascade is not enforced on every database
        $messages = Message::whereIn('conversation_id', (clone $expired)->select('id'))->delete();
        $conversations = $expired->delete();

        $this->info("Deleted {$conversations} conversation(s) and {$messages} message(s) idle for more than {$days} day(s).");

        return 0;
    }
}
