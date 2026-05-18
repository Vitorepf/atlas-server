<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiResearchRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'uuid',
        'mission_id',
        'work_order_id',
        'question',
        'hypothesis',
        'source_plan',
        'status',
        'source_diversity',
        'overall_confidence',
        'certification_status',
        'missing_requirements',
        'certification_hash',
        'evidence_pack_hash',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'source_plan' => 'array',
            'missing_requirements' => 'array',
            'source_diversity' => 'decimal:4',
            'overall_confidence' => 'decimal:4',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function sources(): HasMany
    {
        return $this->hasMany(AiResearchSource::class, 'research_run_id');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(AiResearchClaim::class, 'research_run_id');
    }

    public function syntheses(): HasMany
    {
        return $this->hasMany(AiResearchSynthesis::class, 'research_run_id');
    }

    public function latestSynthesis(): HasOne
    {
        return $this->hasOne(AiResearchSynthesis::class, 'research_run_id')->latestOfMany();
    }
}
