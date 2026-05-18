<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiRealExecutionForgeHandoff extends Model
{
    use HasUuids;

    protected $fillable = ['goal_record_id', 'schema_version', 'handoff_id', 'status', 'handoff_packet', 'evidence_refs', 'receipt', 'handoff_hash'];

    protected function casts(): array
    {
        return [
            'handoff_packet' => 'array',
            'evidence_refs' => 'array',
            'receipt' => 'array',
        ];
    }
}
