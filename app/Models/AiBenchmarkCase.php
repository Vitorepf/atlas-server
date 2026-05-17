<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiBenchmarkCase extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'run_outcome_id',
        'case_id',
        'source',
        'prompt',
        'expected_flow',
        'required_evidence',
        'rivals',
        'status',
        'payload',
        'case_hash',
    ];

    protected function casts(): array
    {
        return [
            'required_evidence' => 'array',
            'rivals' => 'array',
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function outcome(): BelongsTo
    {
        return $this->belongsTo(AiRunOutcome::class, 'run_outcome_id');
    }
}
