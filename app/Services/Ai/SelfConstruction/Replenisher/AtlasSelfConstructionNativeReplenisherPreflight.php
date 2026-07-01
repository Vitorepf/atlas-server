<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Replenisher;

use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;

/**
 * Runs {@see AtlasTaskPacketQualityInspector} over every drafted packet and separates results into:
 *   - accepted   : self_sufficient packets ready for the queue
 *   - rejected   : packets with HARD blockers (forbidden self-target, missing acceptance/evidence)
 *   - repairable : packets where deficiencies have an obvious template-fix hint
 *
 * Emits exact blocking_deficiencies AND repair_hints for non-accepted packets. NEVER enqueues.
 *
 * INVARIANTS:
 *   - DETERMINISTIC envelope.
 *   - PURE.
 */
final class AtlasSelfConstructionNativeReplenisherPreflight
{
    public const SCHEMA = 'atlas.replenisher.preflight.v1';

    /** Deficiencies that ALWAYS reject (no auto-repair). */
    public const HARD_REJECT_DEFICIENCIES = [
        'forbidden_self_target_in_allowed_files',
        'simplicity_contract_violation',
        'isolation_violation',
    ];

    /** @param null|AtlasTaskPacketQualityInspector|object{inspect:callable} $inspector */
    public function __construct(private readonly ?object $inspector = null) {}

    /**
     * @param  list<array<string,mixed>>  $packetDrafts
     * @return array{schema:string, accepted:list<array<string,mixed>>, rejected:list<array<string,mixed>>, repairable:list<array<string,mixed>>}
     */
    public function preflight(array $packetDrafts): array
    {
        $inspector = $this->inspector ?? $this->makeInspector();
        $accepted = [];
        $rejected = [];
        $repairable = [];

        foreach ($packetDrafts as $packet) {
            if (! is_array($packet)) {
                continue;
            }
            $inspection = $inspector !== null ? $inspector->inspect($packet) : ['self_sufficient' => null, 'blocking_deficiencies' => []];
            $deficiencies = is_array($inspection['blocking_deficiencies'] ?? null) ? array_values(array_map('strval', $inspection['blocking_deficiencies'])) : [];

            if ($deficiencies === [] && ($inspection['self_sufficient'] ?? null) === true) {
                $accepted[] = ['packet' => $packet, 'inspection' => $inspection];

                continue;
            }

            $hard = array_intersect(self::HARD_REJECT_DEFICIENCIES, $deficiencies);
            if ($hard !== []) {
                $rejected[] = [
                    'packet' => $packet,
                    'blocking_deficiencies' => $deficiencies,
                    'rejection_reasons' => array_values($hard),
                ];

                continue;
            }

            // Repairable: known deficiencies with hint templates.
            $hints = [];
            if (in_array('missing_acceptance_criteria', $deficiencies, true)) {
                $hints[] = 'add_at_least_one_acceptance_criterion';
            }
            if (in_array('missing_required_evidence', $deficiencies, true)) {
                $hints[] = 'add_required_evidence_list';
            }
            if (in_array('bare_directory_in_allowed_files', $deficiencies, true)) {
                $hints[] = 'replace_bare_directory_with_concrete_file_paths';
            }
            if (in_array('scope_incoherent', $deficiencies, true)) {
                $hints[] = 'narrow_scope_in_to_match_allowed_files';
            }
            if ($hints === []) {
                // Unrecognized deficiencies have no known repair template — fail closed
                // (reject) rather than pretend a repair hint exists.
                $rejected[] = [
                    'packet' => $packet,
                    'blocking_deficiencies' => $deficiencies,
                    'rejection_reasons' => ['unknown_deficiency:'.implode(',', $deficiencies)],
                ];

                continue;
            }
            $repairable[] = [
                'packet' => $packet,
                'blocking_deficiencies' => $deficiencies,
                'repair_hints' => $hints,
            ];
        }

        usort($accepted, static fn (array $a, array $b): int => strcmp((string) ($a['packet']['frontier_id'] ?? ''), (string) ($b['packet']['frontier_id'] ?? '')));
        usort($rejected, static fn (array $a, array $b): int => strcmp((string) ($a['packet']['frontier_id'] ?? ''), (string) ($b['packet']['frontier_id'] ?? '')));
        usort($repairable, static fn (array $a, array $b): int => strcmp((string) ($a['packet']['frontier_id'] ?? ''), (string) ($b['packet']['frontier_id'] ?? '')));

        return [
            'schema' => self::SCHEMA,
            'accepted' => $accepted,
            'rejected' => $rejected,
            'repairable' => $repairable,
        ];
    }

    public const REASON_WAIT_OK = 'wait_ok';

    public const REASON_WORKER_STARVATION_RISK = 'worker_starvation_risk';

    public const REASON_MAESTRO_URGENCY_NOT_WAIT = 'maestro_urgency_not_wait';

    public const REASON_BUFFER_BELOW_TARGET = 'buffer_below_target';

    public const REASON_DUPLICATE_PRESSURE_TOO_HIGH = 'duplicate_pressure_too_high';

    public const REASON_EVIDENCE_NOT_READY = 'evidence_not_ready';

    /** duplicate_pressure at/above this fraction blocks an override regardless of drain risk. */
    private const DUPLICATE_PRESSURE_THRESHOLD = 0.7;

    /**
     * Maestro's "wait" advice must never silently suppress replenishment when the
     * claimable buffer is thin enough that active workers risk draining to
     * no_claimable_task. worker_floor_breach / replenish_soon signals OVERRIDE wait
     * (hard floor — unconditional). A buffer below the DESIRED target
     * (worker_buffer_target_per_worker), while still above the hard floor, also
     * overrides wait — but only when both buffer facts are actually supplied; this
     * is a softer, opt-in signal and never assumed from absent facts.
     *
     * @param  array{maestro_urgency?: string, worker_floor_breach?: bool, replenish_soon?: bool,
     *                claimable_per_active_worker?: float, worker_buffer_target_per_worker?: float}  $input
     * @return array{schema:string, allowed:bool, reason:string}
     */
    public function evaluateWaitOverride(array $input): array
    {
        $maestroUrgency = (string) ($input['maestro_urgency'] ?? 'wait');
        $workerFloorBreach = (bool) ($input['worker_floor_breach'] ?? false);
        $replenishSoon = (bool) ($input['replenish_soon'] ?? false);

        if ($maestroUrgency !== 'wait') {
            return ['schema' => self::SCHEMA, 'allowed' => true, 'reason' => self::REASON_MAESTRO_URGENCY_NOT_WAIT];
        }

        if ($workerFloorBreach || $replenishSoon) {
            return ['schema' => self::SCHEMA, 'allowed' => true, 'reason' => self::REASON_WORKER_STARVATION_RISK];
        }

        if (array_key_exists('claimable_per_active_worker', $input) && array_key_exists('worker_buffer_target_per_worker', $input)) {
            $claimablePerActiveWorker = (float) $input['claimable_per_active_worker'];
            $workerBufferTargetPerWorker = (float) $input['worker_buffer_target_per_worker'];
            if ($claimablePerActiveWorker < $workerBufferTargetPerWorker) {
                return ['schema' => self::SCHEMA, 'allowed' => true, 'reason' => self::REASON_BUFFER_BELOW_TARGET];
            }
        }

        return ['schema' => self::SCHEMA, 'allowed' => false, 'reason' => self::REASON_WAIT_OK];
    }

    /**
     * Richer preflight for a wait-override decision — same drain-risk signal as
     * {@see evaluateWaitOverride()}, but gated by packet-quality facts (duplicate pressure,
     * evidence readiness) so a real need is never inferred from an unproven or duplicate-heavy
     * queue. Quality gates take precedence: a genuine drain risk still gets vetoed when the
     * replenishment work behind it is duplicate-heavy or unproven.
     *
     * @param  array{maestro_urgency?: string, worker_floor_breach?: bool, replenish_soon?: bool,
     *                claimable_per_active_worker?: float, worker_buffer_target_per_worker?: float,
     *                claimable_depth?: int, duplicate_pressure?: float, evidence_ready?: bool}  $input
     * @return array{schema:string, wait_override:bool, queue_depth:int, worker_drain:bool, quality_gate:bool, duplicate_pressure:float, evidence_ready:bool, reasons:list<string>}
     */
    public function preflightWaitDecision(array $input): array
    {
        $queueDepth = (int) ($input['claimable_depth'] ?? 0);
        $workerDrain = (bool) ($input['worker_floor_breach'] ?? false) || (bool) ($input['replenish_soon'] ?? false);
        $duplicatePressure = max(0.0, min(1.0, (float) ($input['duplicate_pressure'] ?? 0.0)));
        $evidenceReady = (bool) ($input['evidence_ready'] ?? true);

        $reasons = [];
        if ($duplicatePressure >= self::DUPLICATE_PRESSURE_THRESHOLD) {
            $reasons[] = self::REASON_DUPLICATE_PRESSURE_TOO_HIGH;
        }
        if (! $evidenceReady) {
            $reasons[] = self::REASON_EVIDENCE_NOT_READY;
        }
        $qualityGate = $reasons === [];

        if (! $qualityGate) {
            return [
                'schema' => self::SCHEMA,
                'wait_override' => false,
                'queue_depth' => $queueDepth,
                'worker_drain' => $workerDrain,
                'quality_gate' => false,
                'duplicate_pressure' => $duplicatePressure,
                'evidence_ready' => $evidenceReady,
                'reasons' => $reasons,
            ];
        }

        $base = $this->evaluateWaitOverride($input);

        return [
            'schema' => self::SCHEMA,
            'wait_override' => $base['allowed'],
            'queue_depth' => $queueDepth,
            'worker_drain' => $workerDrain,
            'quality_gate' => true,
            'duplicate_pressure' => $duplicatePressure,
            'evidence_ready' => $evidenceReady,
            'reasons' => [$base['reason']],
        ];
    }

    private function makeInspector(): ?AtlasTaskPacketQualityInspector
    {
        try {
            return app(AtlasTaskPacketQualityInspector::class);
        } catch (\Throwable) {
            return null;
        }
    }
}
