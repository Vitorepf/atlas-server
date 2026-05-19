<?php

namespace App\Models;

use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\LongHorizon\Replay\LongHorizonReplayManifestBuilder;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for `atlas.long_horizon.replay_manifest.v1` rows.
 *
 * Persistence-only — orchestration lives in
 * {@see LongHorizonReplayManifestBuilder}.
 * The model stores the manifest payload and exposes a deterministic
 * `canonicalHash()` so a re-emitted manifest with identical content yields
 * the same `hash`.
 */
class AtlasLongHorizonReplayManifest extends Model
{
    use HasUuids;

    protected $table = 'atlas_long_horizon_replay_manifests';

    protected $fillable = [
        'schema_version',
        'uuid',
        'scope_type',
        'scope_id',
        'continuation_pack_id',
        'compaction_receipt_id',
        'required_refs',
        'available_refs',
        'missing_refs',
        'event_refs',
        'evidence_refs',
        'context_pack_hash',
        'reader_instructions',
        'provider_independent_summary',
        'safety_notes',
        'replay_status',
        'hash',
    ];

    protected $attributes = [
        'schema_version' => AtlasLongHorizonCanon::REPLAY_MANIFEST_SCHEMA_VERSION,
        'replay_status' => AtlasLongHorizonCanon::REPLAY_STATUS_READY,
    ];

    protected function casts(): array
    {
        return [
            'required_refs' => 'array',
            'available_refs' => 'array',
            'missing_refs' => 'array',
            'event_refs' => 'array',
            'evidence_refs' => 'array',
            'safety_notes' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Deterministic hash over canonical payload. Excludes id/hash/timestamps.
     *
     * @param  array<string,mixed>  $payload
     */
    public static function canonicalHash(array $payload): string
    {
        $payload['schema_version'] = AtlasLongHorizonCanon::REPLAY_MANIFEST_SCHEMA_VERSION;
        unset($payload['id'], $payload['hash'], $payload['created_at'], $payload['updated_at']);

        return MissionCanonicalHash::sha256($payload);
    }
}
