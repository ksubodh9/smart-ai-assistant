<?php

namespace Subodh\SmartAiAssistant\Models;

use Illuminate\Database\Eloquent\Model;

class ErrorDefinition extends Model
{
    protected $table = 'smart_ai_error_definitions';

    protected $fillable = [
        'service',
        'key_text',
        'answer_en',
        'answer_hi',
        'meta',
        'created_by_ai',
    ];

    protected $casts = [
        'meta' => 'array',
        'created_by_ai' => 'boolean',
    ];
}
