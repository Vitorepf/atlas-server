<?php

declare(strict_types=1);

namespace App\Services\Ai\RealExecution;

use App\Services\Ai\Reality\AtlasRealityGraphIngestionService;
use App\Services\Ai\Reality\AtlasUnifiedRealityGraphTemporalService;
use Throwable;

/**
 * S2.F3 — THE CLOSED MISSION LOOP, as a product surface.
 *
 * This is the single entry point the CLI (and any future surface) calls to run a
 * mission from natural language end to end. It is the thin product seam ON TOP of
 * the already-built loop machinery — it does NOT re-implement any of it:
 *
 *   request
 *     → [brain] AURG provider-bound context threaded into the code-gen prompt
 *     → [hands] AtlasLiveCodeDeliveryService (certified) → real branch
 *     → [brain] outcome recorded back INTO the AURG so the NEXT run sees it
 *     → product envelope (mission_id, branch, brain/evidence flags, review cmds)
 *
 * The {@see MissionDeliveryOrchestrator} already owns BOTH bridges (brain context
 * in / outcome out), both flag-gated and fail-open. This service's job is the
 * product contract:
 *   - mint the stable mission id ONCE and thread it down so the returned
 *     mission_id is the SAME id the brain recorded its mission node under (the loop
 *     is only "closed" if the product surface can name what the brain stored);
 *   - expose --no-brain as a per-run bypass of the brain-context bridge WITHOUT
 *     mutating global config (a single run never leaks its choice to other runs);
 *   - flatten the orchestrator's nested result into a stable, machine-first
 *     envelope: {mission_id, request, delivered, branch, brain_context_used,
 *     evidence_recorded, review_commands, main_untouched, never_merged}.
 *
 * HARD CONTRACTS (inherited from the loop, re-stated at the product boundary):
 *   - COST: this service spends ONLY because the orchestrator's delivery step
 *     spends — in tests a fake delivery stands in (zero tokens), exactly as the
 *     orchestrator tests do. This class adds no provider calls of its own.
 *   - NEVER-MERGE: branch-only is enforced by the materializer downstream; the
 *     envelope reports the BRANCH ref and never_merged=true, never a merge.
 *   - PRIVACY: the brain context the orchestrator threads in is provider_bound
 *     (sensitive/secret excluded by construction); the recorded outcome carries
 *     ids/hashes/labels/branch only. This service never relaxes either.
 *   - FAIL-OPEN: brain-context and outcome-recording failures degrade the run but
 *     NEVER break a delivery — the orchestrator already guarantees this; the
 *     envelope simply reports brain_context_used / evidence_recorded honestly.
 *
 * S2.F4 — THE LOOP COMPOUNDS + TEMPORAL (cognition accrues from execution):
 *   - the orchestrator's outcome write-back is what makes the loop COMPOUND — a
 *     recorded mission node + its cite-or-omit references to shared modules/memories
 *     becomes part of the brain so the NEXT mission's provider-bound query reaches it
 *     (proven cost-free in {@see \Tests\Feature\Ai\RealExecution\AtlasMissionLoopCompoundsTest});
 *   - this surface additionally places each delivered mission accrual into the AURG
 *     4D temporal chain: AFTER the outcome is recorded it ticks a REAL graph-state
 *     snapshot ({@see AtlasRealityGraphIngestionService::recordTemporalSnapshot()},
 *     the F4-of-Salto-1 primitive) so the mission's compounding is timestamped in the
 *     append-only chain. FAIL-OPEN + flag-gated (atlas.mission.record_temporal_enabled,
 *     default ON): a temporal-tick failure NEVER changes the delivery result, and the
 *     tick is skipped entirely for a blocked (non-delivered) run.
 */
class AtlasMissionService
{
    public const SCHEMA = 'atlas.ai.mission.v1';

    public function __construct(
        private readonly MissionDeliveryOrchestrator $orchestrator,
        // S2.F4 — TEMPORAL: the ingestion service owns the real graph-state snapshot
        // primitive; the temporal service owns the append-only 4D chain it ticks into.
        // Both nullable so the legacy `new AtlasMissionService($orchestrator)` (and the
        // F3 tests) keep working with no temporal wiring — the tick is simply skipped.
        private readonly ?AtlasRealityGraphIngestionService $ingestion = null,
        private readonly ?AtlasUnifiedRealityGraphTemporalService $temporal = null,
    ) {}

    /**
     * Run a mission from natural language: brain context → governed delivery →
     * branch → outcome recorded back into the brain. Returns the product envelope.
     *
     * @param  array<string,mixed>  $opts  {id?, provider?, target_file?, measure_cmd?,
     *                                     repo_dir?, memory_refs?:list<string>, no_brain?:bool}
     * @return array<string,mixed> {schema_version, mission_id, request, delivered,
     *                             branch, brain_context_used, evidence_recorded, temporal_recorded,
     *                             temporal_snapshot_hash, review_commands, main_untouched, never_merged,
     *                             stage?, reason?}
     */
    public function run(string $request, array $opts = []): array
    {
        $request = trim($request);

        // Mint the stable mission id ONCE so the returned mission_id == the id the
        // brain records its mission node under (deterministic from the request when
        // the caller does not pin one — the same scheme the orchestrator uses).
        $missionId = $this->missionId($request, $opts);

        // Shape the orchestrator options. We thread the minted id down so STAGE 4's
        // branch (atlas/materialize/<id>) and STAGE 5's recorded mission node share
        // the SAME id as this envelope's mission_id.
        $options = $opts;
        $options['id'] = $missionId;

        // --no-brain: bypass the brain-context bridge for THIS run only, without
        // mutating global config (other runs / the live default are untouched). The
        // orchestrator reads the flag via config(); a per-run override scoped to the
        // delivery call keeps the choice local. The OUTCOME write-back is left to its
        // own flag — bypassing the read-in context does not silence the loop's
        // compounding write-back (the run still feeds the brain).
        $noBrain = (bool) ($opts['no_brain'] ?? false);
        unset($options['no_brain']);

        $result = $noBrain
            ? $this->withBrainContextDisabled(fn (): array => $this->orchestrator->deliver($request, $options))
            : $this->orchestrator->deliver($request, $options);

        // S2.F4 — TEMPORAL: after the outcome is recorded back into the brain, place
        // this mission's accrual into the AURG 4D chain (a real graph-state tick).
        // Fail-open: a tick failure NEVER touches the delivery result. Skipped for a
        // blocked run (nothing accrued ⇒ nothing to timestamp).
        $temporal = $this->recordTemporalTick($missionId, $result);

        return $this->envelope($missionId, $request, $result, $temporal);
    }

    /**
     * S2.F4 temporal tick: record the post-mission graph state into the append-only
     * AURG-4D chain so the compounding accrual is timestamped (a non-null
     * snapshot_hash derived from the REAL fused store, the same primitive the daily
     * full sync uses). Honest-skip / FAIL-OPEN by construction:
     *   - skipped when the run did not deliver (no accrual to timestamp);
     *   - skipped when the flag is off or the temporal wiring is absent;
     *   - any thrown error degrades to a non-recorded marker, NEVER propagates — a
     *     temporal outage must never break a delivery the orchestrator already made.
     *
     * @param  array<string,mixed>  $result  the orchestrator deliver() result
     * @return array<string,mixed> {recorded(bool), reason?, tick_id?, snapshot_hash?, ...}
     */
    private function recordTemporalTick(string $missionId, array $result): array
    {
        if (! (bool) ($result['delivered'] ?? false)) {
            return ['recorded' => false, 'reason' => 'not_delivered'];
        }
        if (! (bool) config('atlas.mission.record_temporal_enabled', true)) {
            return ['recorded' => false, 'reason' => 'disabled'];
        }
        if (! $this->ingestion instanceof AtlasRealityGraphIngestionService
            || ! $this->temporal instanceof AtlasUnifiedRealityGraphTemporalService) {
            return ['recorded' => false, 'reason' => 'temporal_unavailable'];
        }

        try {
            return $this->ingestion->recordTemporalSnapshot(
                $this->temporal,
                'atlas',
                'mission accrual ('.$missionId.')',
            );
        } catch (Throwable) {
            // Honest degrade — a temporal outage never breaks a delivery.
            return ['recorded' => false, 'reason' => 'tick_failed'];
        }
    }

    /**
     * The stable mission id: an explicit caller id wins; otherwise it is derived
     * deterministically from the request (the SAME scheme the orchestrator falls
     * back to), so re-running the same request targets the same branch + upserts the
     * same mission node (idempotent end to end).
     *
     * @param  array<string,mixed>  $opts
     */
    private function missionId(string $request, array $opts): string
    {
        $explicit = isset($opts['id']) && is_string($opts['id']) ? trim($opts['id']) : '';
        if ($explicit !== '') {
            return $explicit;
        }

        return 'mission-'.substr(hash('sha256', $request), 0, 10);
    }

    /**
     * Run a callback with the brain-context flag forced OFF, restoring the prior
     * config value afterward. Scoped: only this run's delivery sees the override; the
     * global config (and therefore every other run + the live default) is untouched.
     *
     * @template T
     *
     * @param  callable():T  $run
     * @return T
     */
    private function withBrainContextDisabled(callable $run): mixed
    {
        $key = 'atlas.mission.brain_context_enabled';
        $prior = config($key);
        config()->set($key, false);
        try {
            return $run();
        } finally {
            config()->set($key, $prior);
        }
    }

    /**
     * Flatten the orchestrator result into the stable product envelope. Honest
     * across both the delivered and the blocked paths — a blocked delivery has no
     * branch and never recorded an outcome.
     *
     * @param  array<string,mixed>  $result  the {@see MissionDeliveryOrchestrator::deliver()} result
     * @param  array<string,mixed>  $temporal  the {@see self::recordTemporalTick()} result
     * @return array<string,mixed>
     */
    private function envelope(string $missionId, string $request, array $result, array $temporal): array
    {
        $brain = (array) ($result['brain'] ?? []);
        $delivered = (bool) ($result['delivered'] ?? false);

        $envelope = [
            'schema_version' => self::SCHEMA,
            'mission_id' => $missionId,
            'request' => $request,
            'delivered' => $delivered,
            'branch' => $result['branch'] ?? null,
            // Did the brain feed the prompt this run? (false when off / down / blocked)
            'brain_context_used' => (bool) ($brain['context_used'] ?? false),
            // Did the run feed the brain back? (false when the write-back is off /
            // down, or when the delivery never produced a branch to record).
            'evidence_recorded' => (bool) ($brain['outcome_recorded'] ?? false),
            // S2.F4 — was this mission's accrual placed into the AURG 4D chain?
            // (false when off / unwired / blocked / the tick failed — honest degrade).
            'temporal_recorded' => (bool) ($temporal['recorded'] ?? false),
            'temporal_snapshot_hash' => isset($temporal['snapshot_hash']) && is_string($temporal['snapshot_hash'])
                ? $temporal['snapshot_hash']
                : null,
            'review_commands' => array_values((array) ($result['review_commands'] ?? [])),
            'main_untouched' => (bool) ($result['main_untouched'] ?? true),
            'never_merged' => (bool) ($result['never_merged'] ?? true),
        ];

        // S3.F3 — expose the touched files (with content) so an IN-PROCESS consumer
        // (the self-construction loop's OUT-OF-PROCESS relevance gate) can verify the
        // delivery hit the signal's file AND concern. The gate reads delivery.files and
        // accepts {path, content} entries. This is a same-process hand-off of the
        // operator's own generated code (about to be reviewed on the branch) — it never
        // rides a provider prompt and never enters the brain write-back (which uses the
        // path-only shape upstream). Only present on a delivered run.
        $contentFiles = (array) (($result['delivery']['content_files'] ?? null)
            ?? ($result['delivery']['files'] ?? []));
        if ($delivered && $contentFiles !== []) {
            $envelope['delivery'] = ['files' => array_values($contentFiles)];
        }

        // Surface the blocked stage/reason so the CLI can tell the operator WHY a
        // run did not deliver (e.g. delivery_not_certified, no_files_from_delivery).
        if (! $delivered) {
            $envelope['stage'] = (string) ($result['stage'] ?? 'unknown');
            $envelope['reason'] = (string) ($result['reason'] ?? 'not_delivered');
        }

        return $envelope;
    }
}
