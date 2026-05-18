<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiToolPlan extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'uuid',
        'mission_id',
        'work_order_id',
        'domain_id',
        'objective',
        'tools_considered',
        'tools_selected',
        'selection_reason',
        'rejected_tools',
        'safety_notes',
        'status',
        'receipt_hash',
    ];

    protected function casts(): array
    {
        return [
            'tools_considered' => 'array',
            'tools_selected' => 'array',
            'selection_reason' => 'array',
            'rejected_tools' => 'array',
            'safety_notes' => 'array',
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
