<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiTestResult extends Model
{
    use HasUuids;

    protected $table = 'ai_test_results';

    protected $fillable = [
        'schema_version',
        'uuid',
        'test_scope',
        'command',
        'status',
        'output_ref',
        'output_hash',
        'metadata',
        'mission_id',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
