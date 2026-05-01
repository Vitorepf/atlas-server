<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiOutcomeLink extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'trace_id',
        'thread_id',
        'session_id',
        'outcome_type',
        'target_type',
        'target_id',
        'value_score',
        'confidence',
        'source',
        'occurred_at',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'value_score' => 'integer',
            'confidence' => 'float',
            'occurred_at' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function trace(): BelongsTo
    {
        return $this->belongsTo(AiTrace::class, 'trace_id');
    }
}
