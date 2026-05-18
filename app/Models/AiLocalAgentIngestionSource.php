<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiLocalAgentIngestionSource extends Model
{
    use HasUuids;

    protected $table = 'ai_local_agent_ingestion_sources';

    protected $fillable = [
        'uuid',
        'run_id',
        'run_uuid',
        'schema_version',
        'alias',
        'relative_path',
        'extension',
        'size_bytes',
        'mtime',
        'content_hash',
        'redacted_snippet',
        'source_class',
        'classification_signals',
        'quality_score',
        'freshness_score',
        'secret_finding_count',
        'secret_finding_counts',
        'status',
        'skip_reason',
        'source_hash',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'quality_score' => 'integer',
            'freshness_score' => 'integer',
            'secret_finding_count' => 'integer',
            'classification_signals' => 'array',
            'secret_finding_counts' => 'array',
            'mtime' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiLocalAgentIngestionRun::class, 'run_id');
    }
}
