<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasAverCommandLedger extends Model
{
    use HasUuids;

    protected $fillable = [
        'execution_id',
        'schema_version',
        'status',
        'command_hash',
        'cwd_hash',
        'exit_code',
        'duration_ms',
        'stdout_excerpt',
        'stderr_excerpt',
        'stdout_hash',
        'stderr_hash',
        'safety_gate',
        'evidence_refs',
        'ledger_hash',
    ];

    protected function casts(): array
    {
        return [
            'exit_code' => 'integer',
            'duration_ms' => 'integer',
            'safety_gate' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
