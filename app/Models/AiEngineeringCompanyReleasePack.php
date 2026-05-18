<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiEngineeringCompanyReleasePack extends Model
{
    use HasUuids;

    protected $fillable = ['engagement_record_id', 'schema_version', 'release_pack_id', 'status', 'summary', 'risk_register', 'evidence_refs', 'receipt', 'release_hash'];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'risk_register' => 'array',
            'evidence_refs' => 'array',
            'receipt' => 'array',
        ];
    }
}
