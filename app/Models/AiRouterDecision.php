<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRouterDecision extends Model
{
    use HasUuids;

    protected $fillable = [
        'trace_id',
        'mode',
        'selected_provider',
        'fallback_provider',
        'signals',
        'reason',
        'was_overridden',
    ];

    protected function casts(): array
    {
        return [
            'trace_id' => 'string',
            'signals' => 'array',
            'was_overridden' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function trace(): BelongsTo
    {
        return $this->belongsTo(AiTrace::class, 'trace_id');
    }
}
