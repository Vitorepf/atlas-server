<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiLocalAgentIngestionCandidate extends Model
{
    use HasUuids;

    protected $table = 'ai_local_agent_ingestion_candidates';

    protected $fillable = [
        'uuid',
        'run_id',
        'run_uuid',
        'source_id',
        'source_uuid',
        'schema_version',
        'source_class',
        'status',
        'memory_eligible',
        'context_eligible',
        'embedding_allowed',
        'promotion_target',
        'evidence_refs',
        'payload',
        'candidate_hash',
    ];

    protected function casts(): array
    {
        return [
            'memory_eligible' => 'boolean',
            'context_eligible' => 'boolean',
            'embedding_allowed' => 'boolean',
            'evidence_refs' => 'array',
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiLocalAgentIngestionRun::class, 'run_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(AiLocalAgentIngestionSource::class, 'source_id');
    }
}
