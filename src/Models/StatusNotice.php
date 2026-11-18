<?php

namespace Subodh\SmartAiAssistant\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A known issue with one service, shown to users while it is active.
 */
class StatusNotice extends Model
{
    protected $table = 'smart_ai_status_notices';

    protected $fillable = [
        'service',
        'messages',
        'starts_at',
        'ends_at',
        'posted_by',
    ];

    protected $casts = [
        'messages'  => 'array',
        'starts_at' => 'datetime',
        'ends_at'   => 'datetime',
    ];

    /**
     * Same rule as scopeActive, for a loaded row.
     */
    public function isActive(): bool
    {
        $now = now();

        return ($this->starts_at === null || $this->starts_at->lte($now))
            && ($this->ends_at === null || $this->ends_at->gt($now));
    }

    /**
     * Started (or no start time) and not yet ended.
     */
    public function scopeActive(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now));
    }
}
