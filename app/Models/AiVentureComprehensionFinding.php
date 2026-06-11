<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiVentureComprehensionFinding extends Model
{
    use HasUuids;

    public const CAPABILITY_BUSINESS_RULE = 'business_rule';

    public const CAPABILITY_PROBLEM = 'problem';

    public const CAPABILITY_IMPROVEMENT = 'improvement';

    public const CAPABILITY_AUDIENCE_USAGE = 'audience_usage';

    public const EVIDENCE_OBSERVED = 'observed';

    public const EVIDENCE_INFERRED = 'inferred';

    protected $table = 'ai_venture_comprehension_findings';

    protected $fillable = [
        'schema_version',
        'uuid',
        'run_id',
        'venture_id',
        'capability',
        'kind',
        'category',
        'title',
        'detail',
        'severity',
        'impact_score',
        'effort_score',
        'leverage_score',
        'confidence',
        'evidence_kind',
        'evidence_path',
        'evidence_line',
        'evidence_snippet',
        'evidence_refs',
        'recommendation',
        'payload',
        'source',
        'finding_hash',
    ];

    protected function casts(): array
    {
        return [
            'impact_score' => 'float',
            'effort_score' => 'float',
            'leverage_score' => 'float',
            'confidence' => 'float',
            'evidence_line' => 'integer',
            'evidence_refs' => 'array',
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
