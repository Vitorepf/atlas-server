<?php

declare(strict_types=1);

namespace App\Services\Ai\RealExecution;

use App\Services\Ai\Reality\AtlasRealityGraphIngestionService;
use Throwable;

/**
 * S2.F2 — EXECUTION feeds the BRAIN (hands → cognition, the compounding close).
 *
 * The dedicated, single-responsibility collaborator that closes the mission loop:
 * after a governed delivery materializes a branch, this recorder writes the OUTCOME
 * back INTO the AURG fused store so the NEXT mission's brain query sees the prior
 * one (compounding for execution). It is the named seam the orchestrator depends on
 * for "the hands feed the brain" — distinct from {@see AtlasRealityGraphIngestionService}
 * (the brain's store + the 5 read-model syncs), which owns the upsert primitives.
 *
 * SINGLE SOURCE OF TRUTH: this recorder does NOT duplicate the upsert logic or the
 * confidence ladder. It SHAPES a {@see MissionDeliveryOrchestrator} delivery result
 * into the deterministic outcome payload and DELEGATES the actual node/edge upsert to
 * {@see AtlasRealityGraphIngestionService::recordMissionOutcome()} — which already
 * holds the cite-or-omit linkers (mission→module 1.0/0.7, mission→memory 1.0), the
 * 'mission' source-kind prune scope, and the idempotent store boundary. One recording
 * path; this class is the contract + the fail-open boundary in front of it.
 *
 * HARD CONTRACTS (mirrored from the delegate, enforced at this boundary too):
 *   - PRIVACY: only ids/hashes/labels/branch-ref/paths cross into the brain. The
 *     request label is redacted downstream; the measure contributes a payload-free
 *     pass/fail signal only — never the test output, never source code, never diffs.
 *   - NEVER-MERGE: the recorded ref is the BRANCH (atlas/materialize/<id>), never a
 *     merge — the materializer already enforces branch-only upstream.
 *   - IDEMPOTENT: re-recording the same outcome upserts (no dup nodes/edges) — the
 *     delegate keys nodes on the deterministic id and edges on (from,to,kind).
 *   - FAIL-OPEN: a disabled flag, an absent store, or any thrown error returns a
 *     non-recorded marker and NEVER propagates — a brain outage degrades the loop
 *     but never breaks a delivery (the caller is also wrapped, defence in depth).
 *   - FLAG-GATED: gated by atlas.mission.record_outcome_enabled (default ON; it only
 *     ever writes mission-source nodes/edges, never touches main).
 */
class AtlasMissionOutcomeRecorder
{
    public function __construct(
        private readonly ?AtlasRealityGraphIngestionService $ingestion = null,
    ) {}

    /**
     * Record a delivered-mission outcome from an EXPLICIT, already-shaped payload.
     *
     * FAIL-OPEN at this boundary: returns a non-recorded marker (never throws) when
     * the write-back flag is off, the ingestion store is unavailable, or the delegate
     * throws. Otherwise delegates the upsert + cite-or-omit linking to the ingestion
     * service (single source of truth) and returns its result verbatim.
     *
     * @param  array<string,mixed>  $outcome  {id, request, branch, delivered(bool),
     *     provider?, receipt?, files?:list<string>, measure?:array{status?,ok?},
     *     memory_refs?:list<string>}
     * @return array<string,mixed> {recorded(bool), reason?, mission_node?, evidence_node?, edges?}
     */
    public function record(array $outcome): array
    {
        if (! (bool) config('atlas.mission.record_outcome_enabled', true)) {
            return ['recorded' => false, 'reason' => 'disabled'];
        }
        if (! $this->ingestion instanceof AtlasRealityGraphIngestionService) {
            return ['recorded' => false, 'reason' => 'ingestion_unavailable'];
        }

        try {
            return $this->ingestion->recordMissionOutcome($outcome);
        } catch (Throwable) {
            // Honest degrade — a brain outage never breaks a delivery.
            return ['recorded' => false, 'reason' => 'record_failed'];
        }
    }

    /**
     * Record straight from a {@see MissionDeliveryOrchestrator}-shaped delivery
     * result, normalizing it into the payload-free outcome the brain stores. This is
     * the convenience seam for the orchestrator: it maps the materializer's measure
     * to a pass/fail boolean ONLY (no output_tail), keeps the branch ref (never a
     * merge), and threads ids/hashes/labels/paths only.
     *
     * @param  array<string,mixed>  $delivery  the orchestrator deliver() result (or an
     *     equivalent map carrying id/request/branch/delivered/delivery/materialization)
     * @param  list<string>  $memoryRefs  cited memory ids (cite-or-omit downstream)
     * @return array<string,mixed> {recorded(bool), reason?, mission_node?, evidence_node?, edges?}
     */
    public function recordFromDelivery(array $delivery, array $memoryRefs = []): array
    {
        return $this->record($this->outcomeFromDelivery($delivery, $memoryRefs));
    }

    /**
     * Deterministically project an orchestrator delivery result onto the brain
     * outcome payload — payload-free by construction (only ids/hashes/labels/paths/
     * branch-ref + a pass/fail boolean ever cross). Pure: no IO, no store access.
     *
     * @param  array<string,mixed>  $delivery
     * @param  list<string>  $memoryRefs
     * @return array<string,mixed>
     */
    public function outcomeFromDelivery(array $delivery, array $memoryRefs = []): array
    {
        $deliveryMeta = (array) ($delivery['delivery'] ?? []);
        $materialization = (array) ($delivery['materialization'] ?? []);
        $measure = (array) ($materialization['measure'] ?? ($delivery['measure'] ?? []));

        $provider = $delivery['provider'] ?? ($deliveryMeta['provider'] ?? null);
        $branch = $delivery['branch'] ?? ($materialization['branch'] ?? null);
        $files = $delivery['files'] ?? ($deliveryMeta['files'] ?? []);

        return [
            'id' => (string) ($delivery['id'] ?? ''),
            'request' => (string) ($delivery['request'] ?? ''),
            'branch' => is_string($branch) ? $branch : '',
            'delivered' => (bool) ($delivery['delivered'] ?? false),
            'provider' => is_string($provider) ? $provider : null,
            'receipt' => is_string($delivery['receipt'] ?? null) ? (string) $delivery['receipt'] : null,
            'files' => array_values(array_filter((array) $files, 'is_string')),
            // Payload-free: only the pass/fail boolean rides the brain — never the
            // measure's output_tail. Absent measure ⇒ no key (the delegate derives a
            // delivered/blocked status honestly from `delivered`).
            'measure' => array_key_exists('passed', $measure)
                ? ['ok' => (bool) $measure['passed']]
                : (array_key_exists('ok', $measure) ? ['ok' => (bool) $measure['ok']] : []),
            'memory_refs' => array_values(array_filter($memoryRefs, 'is_string')),
        ];
    }
}
