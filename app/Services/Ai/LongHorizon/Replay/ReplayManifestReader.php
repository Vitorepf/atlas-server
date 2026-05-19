<?php

declare(strict_types=1);

namespace App\Services\Ai\LongHorizon\Replay;

use App\Models\AtlasLongHorizonCompactionReceipt;
use App\Models\AtlasLongHorizonContinuationPack;
use App\Models\AtlasLongHorizonReplayManifest;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\Mission\MissionCanonicalHash;
use InvalidArgumentException;

/**
 * TEOS-I2 · Replay Manifest Reader.
 *
 * Provider-independent reader: takes a persisted `AtlasLongHorizonReplayManifest`
 * (or its UUID) and returns a normalized bundle that ANY provider/agent
 * (Claude, Codex, Gemini, Atlas itself) can consume without touching raw chat.
 *
 * Bundle shape (`atlas.long_horizon.replay_reader_bundle.v1`):
 *   - schema_version
 *   - manifest_uuid
 *   - manifest_hash
 *   - manifest_status (replay_status)
 *   - scope { type, id }
 *   - context_pack_hash
 *   - integrity { context_pack_hash_matches }
 *   - provider_independent_summary
 *   - reader_instructions
 *   - safety_notes
 *   - required_refs, available_refs, missing_refs
 *   - event_refs, evidence_refs
 *   - recommended_resume_mode  (mapped from manifest_status)
 *   - next_action_hint (string)
 *   - claim_policy (anti-claim fences)
 *   - bundle_hash (deterministic over the bundle minus volatile fields)
 *
 * Hard rules:
 *   - NEVER invokes a provider, NEVER reads/writes chat transcripts.
 *   - NEVER copies raw text from the linked pack into the bundle —
 *     only what the pack/receipt already published canonically (already
 *     sanitized by the builder).
 *   - Returns a plain array, no Eloquent leakage.
 */
class ReplayManifestReader
{
    public function read(AtlasLongHorizonReplayManifest $manifest): array
    {
        $manifest->refresh();
        $continuationPack = $manifest->continuation_pack_id !== null
            ? AtlasLongHorizonContinuationPack::query()->find($manifest->continuation_pack_id)
            : null;
        $compactionReceipt = $manifest->compaction_receipt_id !== null
            ? AtlasLongHorizonCompactionReceipt::query()->find($manifest->compaction_receipt_id)
            : null;

        $integrity = [
            'context_pack_hash_matches' => $continuationPack !== null
                && (string) $continuationPack->context_pack_hash === (string) $manifest->context_pack_hash,
            'continuation_pack_present' => $continuationPack !== null,
            'compaction_receipt_present' => $compactionReceipt !== null
                || $manifest->compaction_receipt_id === null, // null means not linked, which is fine
            'continuation_pack_pack_hash' => $continuationPack?->pack_hash,
            'compaction_receipt_hash' => $compactionReceipt?->receipt_hash,
        ];

        $manifestStatus = (string) $manifest->replay_status;
        $resumeMode = $this->mapResumeMode($manifestStatus);
        $nextHint = $this->nextActionHint($manifestStatus, (array) ($manifest->missing_refs ?? []));

        $bundle = [
            'schema_version' => AtlasLongHorizonCanon::REPLAY_READER_SCHEMA_VERSION,
            'manifest_uuid' => (string) $manifest->uuid,
            'manifest_hash' => (string) $manifest->hash,
            'manifest_status' => $manifestStatus,
            'scope' => [
                'type' => (string) $manifest->scope_type,
                'id' => $manifest->scope_id !== null ? (string) $manifest->scope_id : null,
            ],
            'context_pack_hash' => $manifest->context_pack_hash,
            'integrity' => $integrity,
            'provider_independent_summary' => (string) $manifest->provider_independent_summary,
            'reader_instructions' => (string) $manifest->reader_instructions,
            'safety_notes' => array_values((array) ($manifest->safety_notes ?? [])),
            'required_refs' => array_values((array) ($manifest->required_refs ?? [])),
            'available_refs' => array_values((array) ($manifest->available_refs ?? [])),
            'missing_refs' => array_values((array) ($manifest->missing_refs ?? [])),
            'event_refs' => array_values((array) ($manifest->event_refs ?? [])),
            'evidence_refs' => array_values((array) ($manifest->evidence_refs ?? [])),
            'recommended_resume_mode' => $resumeMode,
            'next_action_hint' => $nextHint,
            'claim_policy' => $this->claimPolicy(),
        ];

        $bundle['bundle_hash'] = MissionCanonicalHash::sha256($bundle);

        return $bundle;
    }

    public function readByUuid(string $manifestUuid): array
    {
        $manifest = AtlasLongHorizonReplayManifest::query()
            ->where('uuid', $manifestUuid)
            ->first();
        if ($manifest === null) {
            throw new InvalidArgumentException("replay_manifest not found by uuid [{$manifestUuid}]");
        }

        return $this->read($manifest);
    }

    private function mapResumeMode(string $manifestStatus): string
    {
        return match ($manifestStatus) {
            AtlasLongHorizonCanon::REPLAY_STATUS_READY => AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE,
            AtlasLongHorizonCanon::REPLAY_STATUS_PARTIAL => AtlasLongHorizonCanon::SAFE_RESUME_READ_ONLY,
            AtlasLongHorizonCanon::REPLAY_STATUS_REQUIRES_RECOVERY => AtlasLongHorizonCanon::SAFE_RESUME_REPAIR,
            AtlasLongHorizonCanon::REPLAY_STATUS_BLOCKED => AtlasLongHorizonCanon::SAFE_RESUME_ASK_HUMAN,
            default => AtlasLongHorizonCanon::SAFE_RESUME_ASK_HUMAN,
        };
    }

    /**
     * @param  array<int,mixed>  $missingRefs
     */
    private function nextActionHint(string $manifestStatus, array $missingRefs): string
    {
        return match ($manifestStatus) {
            AtlasLongHorizonCanon::REPLAY_STATUS_READY => 'resume_using_provider_independent_summary_and_event_refs',
            AtlasLongHorizonCanon::REPLAY_STATUS_PARTIAL => $missingRefs === []
                ? 'hold_execute_until_pack_freshness_verified'
                : 'rehydrate_missing_refs_or_downgrade_to_read_only',
            AtlasLongHorizonCanon::REPLAY_STATUS_REQUIRES_RECOVERY => 'replay_compaction_recovery_queries_before_execute',
            AtlasLongHorizonCanon::REPLAY_STATUS_BLOCKED => 'do_not_execute_request_human_decision',
            default => 'do_not_execute_request_human_decision',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function claimPolicy(): array
    {
        return [
            'declares_provider_dependency' => false,
            'declares_external_benchmark' => false,
            'declares_external_superiority' => false,
            'invokes_provider' => false,
            'reads_raw_chat' => false,
            'mutates_persistent_state' => false,
            'scope' => 'atlas_long_horizon_replay_reader',
            'forbidden_uses' => [
                'paste_raw_chat_into_provider_prompt',
                'call_provider_to_complete_manifest',
                'declare_external_rivals_certified_via_replay',
                'execute_when_status_blocked_or_requires_recovery',
            ],
        ];
    }
}
