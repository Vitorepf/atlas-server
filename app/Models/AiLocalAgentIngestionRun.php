<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiLocalAgentIngestionRun extends Model
{
    use HasUuids;

    protected $table = 'ai_local_agent_ingestion_runs';

    protected $fillable = [
        'uuid',
        'schema_version',
        'status',
        'dry_run',
        'actor_alias',
        'root_aliases',
        'root_alias_fingerprint',
        'started_at',
        'ended_at',
        'config_snapshot',
        'summary',
        'receipt_hash',
    ];

    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'root_aliases' => 'array',
            'config_snapshot' => 'array',
            'summary' => 'array',
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function sources(): HasMany
    {
        return $this->hasMany(AiLocalAgentIngestionSource::class, 'run_id');
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(AiLocalAgentIngestionCandidate::class, 'run_id');
    }
}
