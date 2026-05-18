<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiDomainHandoff extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'uuid',
        'mission_id',
        'work_order_id',
        'source_domain_id',
        'target_domain_id',
        'reason',
        'context_pack',
        'evidence_refs',
        'expected_output',
        'blockers',
        'status',
        'receipt_hash',
    ];

    protected function casts(): array
    {
        return [
            'context_pack' => 'array',
            'evidence_refs' => 'array',
            'expected_output' => 'array',
            'blockers' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
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
