<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasEngineeringBlueprint extends Model
{
    use HasUuids;

    protected $fillable = [
        'task_id',
        'project_id',
        'project_step_id',
        'status',
        'version',
        'source',
        'contract_json',
        'blueprint_json',
        'content_hash',
        'frozen_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'contract_json' => 'array',
            'blueprint_json' => 'array',
            'frozen_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(AtlasTask::class, 'task_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(AtlasProject::class, 'project_id');
    }

    public function projectStep(): BelongsTo
    {
        return $this->belongsTo(AtlasProjectStep::class, 'project_step_id');
    }
}
