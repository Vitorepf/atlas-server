<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiDomainRuntimeRecord extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'uuid',
        'mission_id',
        'work_order_id',
        'domain_manifest_id',
        'domain_id',
        'runtime_status',
        'selected_capabilities',
        'execution_plan',
        'evidence_refs',
        'blockers',
        'receipt_hash',
    ];

    protected function casts(): array
    {
        return [
            'selected_capabilities' => 'array',
            'execution_plan' => 'array',
            'evidence_refs' => 'array',
            'blockers' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(AiDomainManifest::class, 'domain_manifest_id');
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(AiMission::class, 'mission_id');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(AiWorkOrder::class, 'work_order_id');
    }
}
