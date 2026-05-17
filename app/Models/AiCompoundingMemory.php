<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCompoundingMemory extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'learning_candidate_id',
        'memory_type',
        'scope',
        'flow_id',
        'status',
        'claim',
        'confidence',
        'evidence_refs',
        'revalidation_policy',
        'valid_until',
        'last_revalidated_at',
        'payload',
        'memory_hash',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'integer',
            'evidence_refs' => 'array',
            'valid_until' => 'immutable_datetime',
            'last_revalidated_at' => 'immutable_datetime',
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->where(function (Builder $query): void {
                $query->whereNull('valid_until')->orWhere('valid_until', '>', now());
            });
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(AiLearningCandidate::class, 'learning_candidate_id');
    }
}
