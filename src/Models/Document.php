<?php

namespace Subodh\SmartAiAssistant\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One version of a host document. Uploading the same title again adds a new
 * version and archives the previous one.
 */
class Document extends Model
{
    public const ACTIVE = 'active';
    public const ARCHIVED = 'archived';

    // Who may get answers from the document
    public const AUDIENCE_PUBLIC = 'public'; // guests too
    public const AUDIENCE_USERS = 'users';   // logged-in users only
    public const AUDIENCES = [self::AUDIENCE_PUBLIC, self::AUDIENCE_USERS];

    protected $table = 'smart_ai_documents';

    protected $fillable = [
        'service',
        'title',
        'file_name',
        'mime',
        'size',
        'checksum',
        'audience',
        'version',
        'status',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'size' => 'integer',
        'version' => 'integer',
    ];

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class, 'document_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }
}
