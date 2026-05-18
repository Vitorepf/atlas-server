<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiRealExecutionDeliveryPack extends Model
{
    use HasUuids;

    protected $fillable = ['goal_record_id', 'schema_version', 'delivery_pack_id', 'status', 'summary', 'changed_files', 'test_evidence', 'risk_register', 'evidence_refs', 'receipt', 'delivery_hash'];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'changed_files' => 'array',
            'test_evidence' => 'array',
            'risk_register' => 'array',
            'evidence_refs' => 'array',
            'receipt' => 'array',
        ];
    }
}
