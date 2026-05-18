<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiWorkOrder extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'uuid',
        'mission_id',
        'objective_id',
        'domain_runtime',
        'flow_profile',
        'title',
        'instructions',
        'expected_artifacts',
        'expected_tests',
        'risk_notes',
        'rollback_plan',
        'status',
        'evidence_refs',
        'receipt_hash',
    ];

    protected function casts(): array
    {
        return [
            'expected_artifacts' => 'array',
            'expected_tests' => 'array',
            'risk_notes' => 'array',
            'rollback_plan' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(AiMission::class, 'mission_id');
    }

    public function objective(): BelongsTo
    {
        return $this->belongsTo(AiObjective::class, 'objective_id');
    }
}
