<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiMarketingRun extends Model
{
    use HasUuids;

    protected $table = 'ai_marketing_runs';

    protected $fillable = [
        'schema_version',
        'uuid',
        'mission_id',
        'work_order_id',
        'product',
        'objective',
        'status',
        'certification_status',
        'missing_requirements',
        'certification_hash',
        'evidence_pack_hash',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'missing_requirements' => 'array',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(AiMarketingArtifact::class, 'marketing_run_id');
    }

    public function experiments(): HasMany
    {
        return $this->hasMany(AiMarketingExperiment::class, 'marketing_run_id');
    }

    public function approvalGates(): HasMany
    {
        return $this->hasMany(AiMarketingApprovalGate::class, 'marketing_run_id');
    }
}
