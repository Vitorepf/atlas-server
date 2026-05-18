<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiEngineeringControlPlaneEvent extends Model
{
    use HasUuids;

    protected $fillable = ['goal_record_id', 'schema_version', 'event_id', 'event_type', 'status', 'payload', 'evidence_refs', 'receipt', 'receipt_hash'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'evidence_refs' => 'array',
            'receipt' => 'array',
        ];
    }
}
