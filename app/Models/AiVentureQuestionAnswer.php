<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiVentureQuestionAnswer extends Model
{
    use HasUuids;

    public const STATUS_ANSWERED = 'answered';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED_INTERNAL = 'blocked_internal';

    public const STATUS_BLOCKED_EXTERNAL = 'blocked_external';

    public const DATA_INTERNAL_CODE = 'internal_code';

    public const DATA_INTERNAL_METRIC = 'internal_metric';

    public const DATA_EXTERNAL_REQUIRED = 'external_required';

    protected $table = 'ai_venture_question_answers';

    protected $fillable = [
        'schema_version',
        'uuid',
        'run_id',
        'venture_id',
        'question_id',
        'dimension',
        'question_text',
        'status',
        'answer',
        'confidence',
        'evidence_kind',
        'evidence_refs',
        'data_kind',
        'external_source',
        'recommendation',
        'priority_score',
        'severity_if_blind',
        'answer_hash',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'evidence_refs' => 'array',
            'priority_score' => 'float',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
