<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiSourceRef extends Model
{
    use HasUuids;

    protected $table = 'ai_source_refs';

    protected $fillable = [
        'schema_version',
        'uuid',
        'source_type',
        'source_ref',
        'source_hash',
        'source_quality',
        'metadata',
        'mission_id',
    ];

    protected function casts(): array
    {
        return [
            'source_quality' => 'float',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
