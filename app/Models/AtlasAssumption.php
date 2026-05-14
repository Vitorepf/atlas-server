<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasAssumption extends Model
{
    use HasUuids;

    protected $fillable = [
        'spec_id', 'text', 'confidence_class', 'blocking',
        'evidence_json', 'clarification_questions_json',
        'resolved_status', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'blocking' => 'boolean',
            'evidence_json' => 'array',
            'clarification_questions_json' => 'array',
            'resolved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function spec(): BelongsTo
    {
        return $this->belongsTo(AtlasSpec::class, 'spec_id');
    }

    public function scopeBlocking(Builder $query): Builder
    {
        return $query->where('blocking', true)->whereNull('resolved_at');
    }
}
