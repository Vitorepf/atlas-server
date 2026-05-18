<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiEngineeringCompanyCycle extends Model
{
    use HasUuids;

    protected $fillable = ['engagement_record_id', 'schema_version', 'cycle_id', 'cycle_index', 'status', 'plan', 'evidence_refs', 'receipt', 'cycle_hash'];

    protected function casts(): array
    {
        return [
            'plan' => 'array',
            'evidence_refs' => 'array',
            'receipt' => 'array',
        ];
    }
}
