<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiResearchSynthesis extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'uuid',
        'research_run_id',
        'brief',
        'claim_refs',
        'source_refs',
        'contradictions',
        'open_questions',
        'overall_confidence',
        'evidence_refs',
        'synthesis_hash',
    ];

    protected function casts(): array
    {
        return [
            'claim_refs' => 'array',
            'source_refs' => 'array',
            'contradictions' => 'array',
            'open_questions' => 'array',
            'evidence_refs' => 'array',
            'overall_confidence' => 'decimal:4',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function researchRun(): BelongsTo
    {
        return $this->belongsTo(AiResearchRun::class, 'research_run_id');
    }
}
