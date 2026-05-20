<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasIntelligenceFactorySimulation extends Model
{
    use HasUuids;

    protected $fillable = [
        'decision_id',
        'capability_id',
        'schema_version',
        'status',
        'mode',
        'scenario',
        'predicted_actions',
        'risks',
        'required_evidence',
        'result',
        'evidence_refs',
        'simulation_hash',
    ];

    protected function casts(): array
    {
        return [
            'predicted_actions' => 'array',
            'risks' => 'array',
            'required_evidence' => 'array',
            'result' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(AtlasIntelligenceFactoryDecision::class, 'decision_id');
    }
}
