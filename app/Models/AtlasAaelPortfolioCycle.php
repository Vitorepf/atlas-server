<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AtlasAaelPortfolioCycle extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'status',
        'surface_id',
        'workspace_hash',
        'portfolio_snapshot',
        'selection_policy',
        'autonomy_budget',
        'strategic_alignment_gate',
        'anti_drift_doctrine_gate',
        'selected_opportunities',
        'deferred_opportunities',
        'operator_queue',
        'evidence_refs',
        'cycle_hash',
    ];

    protected function casts(): array
    {
        return [
            'portfolio_snapshot' => 'array',
            'selection_policy' => 'array',
            'autonomy_budget' => 'array',
            'strategic_alignment_gate' => 'array',
            'anti_drift_doctrine_gate' => 'array',
            'selected_opportunities' => 'array',
            'deferred_opportunities' => 'array',
            'operator_queue' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function experiments(): HasMany
    {
        return $this->hasMany(AtlasAaelEvolutionExperiment::class, 'cycle_id');
    }
}
