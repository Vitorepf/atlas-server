<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiDomainMaturityAssessment extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'uuid',
        'domain_manifest_id',
        'maturity_stage',
        'checked_requirements',
        'missing_requirements',
        'evidence_refs',
        'status',
        'assessed_at',
        'assessment_hash',
    ];

    protected function casts(): array
    {
        return [
            'maturity_stage' => 'integer',
            'checked_requirements' => 'array',
            'missing_requirements' => 'array',
            'evidence_refs' => 'array',
            'assessed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(AiDomainManifest::class, 'domain_manifest_id');
    }
}
