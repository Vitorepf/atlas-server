<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasAverTestLedger extends Model
{
    use HasUuids;

    protected $fillable = [
        'execution_id',
        'command_ledger_id',
        'schema_version',
        'status',
        'test_command_hash',
        'exit_code',
        'test_impact',
        'evidence_refs',
        'test_hash',
    ];

    protected function casts(): array
    {
        return [
            'exit_code' => 'integer',
            'test_impact' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
