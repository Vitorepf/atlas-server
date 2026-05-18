<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMarketingExperiment extends Model
{
    use HasUuids;

    protected $table = 'ai_marketing_experiments';

    protected $fillable = [
        'schema_version',
        'uuid',
        'marketing_run_id',
        'name',
        'hypothesis',
        'primary_metric',
        'success_criterion',
        'decision_rule',
        'variants',
        'guardrails',
        'status',
        'experiment_hash',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'variants' => 'array',
            'guardrails' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function marketingRun(): BelongsTo
    {
        return $this->belongsTo(AiMarketingRun::class, 'marketing_run_id');
    }
}
