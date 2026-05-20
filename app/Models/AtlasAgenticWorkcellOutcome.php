<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasAgenticWorkcellOutcome extends Model
{
    use HasUuids;

    protected $fillable = [
        'workcell_id',
        'schema_version',
        'status',
        'quality_score',
        'coordination_roi_score',
        'signals',
        'learning_candidates',
        'evidence_refs',
        'outcome_hash',
    ];

    protected function casts(): array
    {
        return [
            'quality_score' => 'float',
            'coordination_roi_score' => 'float',
            'signals' => 'array',
            'learning_candidates' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function workcell(): BelongsTo
    {
        return $this->belongsTo(AtlasAgenticWorkcell::class, 'workcell_id');
    }
}
