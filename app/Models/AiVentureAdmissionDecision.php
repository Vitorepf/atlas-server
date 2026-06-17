<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Anti-gaming ledger for the company success engine: records every venture/idea
 * assessed for the cohort and whether it was admitted, rejected or parked. The
 * admission_rate computed from this table guards the >=70% success target from
 * being gamed by cowardly under-admission.
 */
class AiVentureAdmissionDecision extends Model
{
    use HasUuids;

    protected $table = 'ai_venture_admission_decisions';

    public const DECISION_ADMITTED = 'admitted';

    public const DECISION_REJECTED = 'rejected';

    public const DECISION_PARKED = 'parked';

    public const ORIGIN_CREATED = 'created';

    public const ORIGIN_MANAGED = 'managed';

    protected $fillable = [
        'schema_version',
        'uuid',
        'venture_id',
        'idea_id',
        'decision',
        'origin',
        'reason',
        'assessment_run_id',
        'score',
        'success_milestone',
        'decided_by',
        'decided_at',
        'decision_hash',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'success_milestone' => 'array',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
