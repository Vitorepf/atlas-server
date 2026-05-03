<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasEngineeringProjectBlueprint extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id',
        'status',
        'version',
        'source',
        'created_by',
        'blueprint_json',
        'validation_json',
        'human_exception_json',
        'content_hash',
        'prepared_at',
        'frozen_at',
        'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'blueprint_json' => 'array',
            'validation_json' => 'array',
            'human_exception_json' => 'array',
            'prepared_at' => 'immutable_datetime',
            'frozen_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(AtlasProject::class, 'project_id');
    }
}
