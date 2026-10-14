<?php

namespace Subodh\SmartAiAssistant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One passage of a document, the unit that retrieval searches.
 */
class DocumentChunk extends Model
{
    public $timestamps = false;

    protected $table = 'smart_ai_document_chunks';

    protected $fillable = [
        'document_id',
        'position',
        'heading',
        'content',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
