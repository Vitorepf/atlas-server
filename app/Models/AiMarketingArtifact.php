<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMarketingArtifact extends Model
{
    use HasUuids;

    protected $table = 'ai_marketing_artifacts';

    protected $fillable = [
        'schema_version',
        'uuid',
        'marketing_run_id',
        'artifact_type',
        'title',
        'payload',
        'status',
        'artifact_hash',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
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
