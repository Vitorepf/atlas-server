<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @see docs/engineering-knowledge-base/spec-operating-system/data-model-and-services.md (atlas_operations)
 */
class AtlasOperation extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'user_id', 'project_id', 'work_item_id',
        'raw_input', 'interpreted_intent', 'domain', 'status',
        'risk_level', 'confidence_class', 'routing_metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'routing_metadata_json' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(AtlasProgrammingWorkItem::class, 'work_item_id');
    }

    public function specs(): HasMany
    {
        return $this->hasMany(AtlasSpec::class, 'operation_id');
    }

    public function decisionReceipts(): HasMany
    {
        return $this->hasMany(AtlasDecisionReceipt::class, 'operation_id');
    }
}
