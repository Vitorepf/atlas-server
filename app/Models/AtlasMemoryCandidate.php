<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasMemoryCandidate extends Model
{
    use HasUuids;

    public const STATUSES = [
        'candidate',
        'admitted',
        'rejected',
    ];

    protected $fillable = [
        'source_type',
        'source_id',
        'source_path',
        'memory_type',
        'scope_type',
        'scope_id',
        'title',
        'summary',
        'body',
        'candidate_payload',
        'quality_report',
        'missing_checks',
        'status',
        'memory_entry_id',
        'admitted_at',
        'rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'candidate_payload' => 'array',
            'quality_report' => 'array',
            'missing_checks' => 'array',
            'admitted_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function memoryEntry(): BelongsTo
    {
        return $this->belongsTo(AtlasMemoryEntry::class, 'memory_entry_id');
    }
}
