<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasRoutineEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'routine_id',
        'event_type',
        'source',
        'payload',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function routine(): BelongsTo
    {
        return $this->belongsTo(AtlasRoutine::class, 'routine_id');
    }
}
