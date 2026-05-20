<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AtlasAemorExecutionEpisode extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'status',
        'scope_type',
        'scope_id',
        'workspace',
        'surface_id',
        'domain',
        'flow_id',
        'provider',
        'trace_id',
        'mission_id',
        'work_order_id',
        'obra_id',
        'apcr_pack_id',
        'persistent_context_hash',
        'objective_hash',
        'objective',
        'workspace_baseline',
        'risk_prediction',
        'evidence_refs',
        'metadata',
        'episode_hash',
        'opened_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'workspace_baseline' => 'array',
            'risk_prediction' => 'array',
            'evidence_refs' => 'array',
            'metadata' => 'array',
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(AtlasAemorExecutionEvent::class, 'episode_id');
    }

    public function outcome(): HasOne
    {
        return $this->hasOne(AtlasAemorOutcome::class, 'episode_id');
    }
}
