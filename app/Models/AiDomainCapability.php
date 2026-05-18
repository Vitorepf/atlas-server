<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiDomainCapability extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'uuid',
        'domain_manifest_id',
        'capability_id',
        'name',
        'description',
        'input_schema',
        'output_schema',
        'allowed_tools',
        'risk_level',
        'required_gates',
        'evidence_required',
        'maturity_level',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'input_schema' => 'array',
            'output_schema' => 'array',
            'allowed_tools' => 'array',
            'required_gates' => 'array',
            'evidence_required' => 'array',
            'maturity_level' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(AiDomainManifest::class, 'domain_manifest_id');
    }
}
