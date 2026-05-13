<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system.md
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md
 */
class AtlasProgrammingWorkItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'code',
        'intent_text',
        'intent_type',
        'scope_mode',
        'risk_level',
        'owner',
        'workspace',
        'status',
        'current_stage',
        'spec_hash',
        'plan_hash',
        'placement_json',
        'code_intelligence_json',
        'spec_json',
        'plan_json',
        'tasks_json',
        'evidence_refs_json',
        'gaps_json',
        'metadata_json',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'placement_json' => 'array',
            'code_intelligence_json' => 'array',
            'spec_json' => 'array',
            'plan_json' => 'array',
            'tasks_json' => 'array',
            'evidence_refs_json' => 'array',
            'gaps_json' => 'array',
            'metadata_json' => 'array',
            'closed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function gateRuns(): HasMany
    {
        return $this->hasMany(AtlasProgrammingGateRun::class, 'work_item_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(AtlasProgrammingReview::class, 'work_item_id');
    }

    public function latestGate(string $gateName): ?AtlasProgrammingGateRun
    {
        return $this->gateRuns()
            ->where('gate_name', $gateName)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    public function latestReview(): ?AtlasProgrammingReview
    {
        return $this->reviews()->orderByDesc('created_at')->first();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['closed', 'blocked']);
    }
}
