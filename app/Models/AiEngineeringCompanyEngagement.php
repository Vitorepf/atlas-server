<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiEngineeringCompanyEngagement extends Model
{
    use HasUuids;

    protected $fillable = ['schema_version', 'engagement_id', 'goal', 'status', 'target_runtime', 'roles', 'evidence_refs', 'receipt', 'receipt_hash'];

    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'evidence_refs' => 'array',
            'receipt' => 'array',
        ];
    }
}
