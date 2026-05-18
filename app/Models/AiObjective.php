<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiObjective extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'uuid',
        'mission_id',
        'title',
        'description',
        'objective_type',
        'priority',
        'success_criteria',
        'constraints',
        'assumptions',
        'status',
        'evidence_refs',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'success_criteria' => 'array',
            'constraints' => 'array',
            'assumptions' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(AiMission::class, 'mission_id');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(AiWorkOrder::class, 'objective_id');
    }
}
