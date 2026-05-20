<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AtlasIntelligenceFactoryDecision extends Model
{
    use HasUuids;

    protected $fillable = [
        'gap_id',
        'schema_version',
        'objective_hash',
        'decision',
        'status',
        'rationale',
        'selected_capability_id',
        'required_controls',
        'evidence_refs',
        'metadata',
        'decision_hash',
    ];

    protected function casts(): array
    {
        return [
            'rationale' => 'array',
            'required_controls' => 'array',
            'evidence_refs' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function gap(): BelongsTo
    {
        return $this->belongsTo(AtlasIntelligenceFactoryGap::class, 'gap_id');
    }

    public function capability(): BelongsTo
    {
        return $this->belongsTo(AtlasIntelligenceFactoryCapability::class, 'selected_capability_id');
    }

    public function simulations(): HasMany
    {
        return $this->hasMany(AtlasIntelligenceFactorySimulation::class, 'decision_id');
    }
}
