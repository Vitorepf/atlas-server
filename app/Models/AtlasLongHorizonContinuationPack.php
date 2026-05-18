<?php

namespace App\Models;

use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for `atlas.long_horizon.continuation_pack.v2` rows.
 *
 * The model is intentionally a thin persistence wrapper — no orchestration,
 * no provider call, no compaction logic. The runtime authoring path
 * (ProgrammingResumeService, ForgeLongHorizonStateService, future
 * LongHorizonRecoveryPlannerService) composes the payload; this model just
 * stores it deterministically with a stable pack_hash.
 */
class AtlasLongHorizonContinuationPack extends Model
{
    use HasUuids;

    protected $table = 'atlas_long_horizon_continuation_packs';

    protected $fillable = [
        'schema_version',
        'uuid',
        'scope_type',
        'scope_id',
        'objective',
        'current_phase',
        'state_summary',
        'decisions',
        'superseded_decisions',
        'open_tasks',
        'completed_tasks',
        'blockers',
        'risks',
        'evidence_refs',
        'context_manifest',
        'context_pack_hash',
        'summary_hash',
        'source_receipts',
        'stale_after',
        'safe_resume_mode',
        'next_safe_action',
        'human_decisions_required',
        'confidence',
        'pack_hash',
    ];

    protected $attributes = [
        'schema_version' => AtlasLongHorizonCanon::CONTINUATION_PACK_SCHEMA_VERSION,
        'safe_resume_mode' => AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE,
    ];

    protected function casts(): array
    {
        return [
            'decisions' => 'array',
            'superseded_decisions' => 'array',
            'open_tasks' => 'array',
            'completed_tasks' => 'array',
            'blockers' => 'array',
            'risks' => 'array',
            'evidence_refs' => 'array',
            'context_manifest' => 'array',
            'source_receipts' => 'array',
            'human_decisions_required' => 'array',
            'stale_after' => 'immutable_datetime',
            'confidence' => 'float',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Compute a deterministic pack_hash from the canonical payload. The hash
     * intentionally excludes the volatile `id`, `pack_hash` and timestamps so
     * the same content yields the same hash on replay or re-emission.
     *
     * @param  array<string,mixed>  $payload
     */
    public static function canonicalPackHash(array $payload): string
    {
        $payload['schema_version'] = AtlasLongHorizonCanon::CONTINUATION_PACK_SCHEMA_VERSION;
        unset($payload['id'], $payload['pack_hash'], $payload['created_at'], $payload['updated_at']);

        return MissionCanonicalHash::sha256($payload);
    }
}
