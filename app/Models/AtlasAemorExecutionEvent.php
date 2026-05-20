<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasAemorExecutionEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'episode_id',
        'schema_version',
        'event_type',
        'stage',
        'status',
        'payload',
        'evidence_refs',
        'causation_event_id',
        'payload_hash',
        'event_hash',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'evidence_refs' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(AtlasAemorExecutionEpisode::class, 'episode_id');
    }
}
