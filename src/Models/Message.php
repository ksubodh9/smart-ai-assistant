<?php

namespace Subodh\SmartAiAssistant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $table = 'smart_ai_messages';

    protected $fillable = [
        'conversation_id',
        'sender_type',
        'message',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    /**
     * The conversation this message belongs to.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }
}
