<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AtlasEngineeringTestCase extends Model
{
    use HasUuids;

    protected $fillable = [
        'task_id',
        'blueprint_id',
        'control_id',
        'case_code',
        'source',
        'type',
        'priority',
        'command',
        'expected_signal',
        'timeout_seconds',
        'required',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'timeout_seconds' => 'integer',
            'required' => 'boolean',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(AtlasTask::class, 'task_id');
    }

    public function control(): BelongsTo
    {
        return $this->belongsTo(AtlasEngineeringControl::class, 'control_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AtlasEngineeringTestRun::class, 'test_case_id');
    }
}
