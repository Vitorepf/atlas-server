<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiVentureAssessmentRun extends Model
{
    use HasUuids;

    protected $table = 'ai_venture_assessment_runs';

    protected $fillable = [
        'schema_version',
        'uuid',
        'venture_id',
        'comprehension_run_id',
        'stage',
        'status',
        'questions_total',
        'answered_count',
        'partial_count',
        'blocked_internal_count',
        'blocked_external_count',
        'data_readiness_pct',
        'focus',
        'data_gaps',
        'summary',
        'started_at',
        'completed_at',
        'run_hash',
    ];

    protected function casts(): array
    {
        return [
            'questions_total' => 'integer',
            'answered_count' => 'integer',
            'partial_count' => 'integer',
            'blocked_internal_count' => 'integer',
            'blocked_external_count' => 'integer',
            'data_readiness_pct' => 'float',
            'focus' => 'array',
            'data_gaps' => 'array',
            'summary' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function answers(): HasMany
    {
        return $this->hasMany(AiVentureQuestionAnswer::class, 'run_id');
    }
}
