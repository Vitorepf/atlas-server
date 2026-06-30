<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Summarizes the external brain's final-state readiness by capability area.
 *
 * Separates areas into: proven | partially_proven | missing | duplicated | overgrown.
 * Refuses overall final_ready when any critical area lacks direct evidence or has unresolved issues.
 *
 * CRITICAL AREAS (auto-critical regardless of input flag):
 *   originator, task_fabric, maestro, learning, anti_goodhart, runtime, consolidation
 *
 * INPUT: map<area_name, evidence_record>
 *   evidence_record:
 *     status:                 'proven'|'partially_proven'|'missing'|'duplicated'|'overgrown'
 *     evidence_count?:        int    (default 0)
 *     unresolved_count?:      int    (default 0)
 *     critical?:              bool   (overridden to true for auto-critical areas)
 *     has_runnable_proof?:    bool   (fresh runnable proof exists; default false)
 *     knowledge_sync_current?: bool  (knowledge-base sync is current; default false)
 *     operator_independence?: bool   (operator-independence evidence present; default false)
 *     poison_blocker_open?:   bool   (any open poison/give_back blocker; default false)
 *
 * OUTPUT:
 *   { schema, area_readiness, overall_status, blocking_areas,
 *     final_readiness_percent, missing_evidence_by_area, next_closure_action }
 *
 *   area_readiness:           list<{ area, status, is_critical, next_closure_action, missing_evidence_signals }>
 *   overall_status:           'final_ready' | 'not_ready'
 *   blocking_areas:           list<string>   (critical areas preventing final_ready)
 *   final_readiness_percent:  float          (0-100; critical areas fully ready / total critical)
 *   missing_evidence_by_area: map<area, list<signal>>  (critical areas only, evidence gaps)
 *   next_closure_action:      string         (highest-leverage global action)
 *
 * 95%-HONESTY GATE:
 *   A critical area is FULLY READY only when:
 *     1. status === 'proven'
 *     2. unresolved_count === 0
 *     3. has_runnable_proof === true
 *     4. knowledge_sync_current === true
 *     5. operator_independence === true
 *     6. poison_blocker_open === false (no open blocker)
 *   static 'proven' status alone is not enough — all six conditions must hold.
 *   final_ready requires: no blocking areas AND final_readiness_percent >= 95.
 *
 * PURE / DETERMINISTIC. No I/O.
 */
final class AtlasExternalBrainFinalReadinessMap
{
    public const SCHEMA = 'atlas.external_brain.final_readiness_map.v1';

    public const STATUS_PROVEN           = 'proven';
    public const STATUS_PARTIALLY_PROVEN = 'partially_proven';
    public const STATUS_MISSING          = 'missing';
    public const STATUS_DUPLICATED       = 'duplicated';
    public const STATUS_OVERGROWN        = 'overgrown';

    public const OVERALL_FINAL_READY = 'final_ready';
    public const OVERALL_NOT_READY   = 'not_ready';

    /** @var list<string> */
    private const AUTO_CRITICAL_AREAS = [
        'originator', 'task_fabric', 'maestro', 'learning',
        'anti_goodhart', 'runtime', 'consolidation',
    ];

    /** Evidence signals that every critical proven area must supply. */
    private const EVIDENCE_SIGNALS = [
        'has_runnable_proof',
        'knowledge_sync_current',
        'operator_independence',
    ];

    /** Minimum percent of critical areas that must be fully ready for final_ready. */
    private const READINESS_THRESHOLD_PCT = 95.0;

    /** Closure action priority for the global next_closure_action selection (lower = more urgent). */
    private const ACTION_PRIORITY = [
        self::STATUS_MISSING          => 0,
        self::STATUS_DUPLICATED       => 1,
        self::STATUS_OVERGROWN        => 2,
        self::STATUS_PARTIALLY_PROVEN => 3,
        self::STATUS_PROVEN           => 4,
    ];

    private const PRIORITY_MISSING_EVIDENCE = 2;

    /**
     * @param  array<string, array<string,mixed>>  $areaEvidence
     * @return array<string,mixed>
     */
    public function map(array $areaEvidence): array
    {
        $areaReadiness        = [];
        $blockingAreas        = [];
        $missingEvidenceByArea = [];
        $bestActionScore      = PHP_INT_MAX;
        $bestAction           = 'none';
        $criticalCount        = 0;
        $criticalReadyCount   = 0;

        // Sort areas deterministically.
        ksort($areaEvidence);

        foreach ($areaEvidence as $area => $evidence) {
            $area       = (string) $area;
            $status     = (string) ($evidence['status'] ?? self::STATUS_MISSING);
            $unresolved = max(0, (int) ($evidence['unresolved_count'] ?? 0));
            $isCritical = in_array($area, self::AUTO_CRITICAL_AREAS, true) || (bool) ($evidence['critical'] ?? false);

            // Audit evidence signals (critical gates beyond static status).
            $missingSignals = [];
            foreach (self::EVIDENCE_SIGNALS as $signal) {
                if (! (bool) ($evidence[$signal] ?? false)) {
                    $missingSignals[] = $signal;
                }
            }
            if ((bool) ($evidence['poison_blocker_open'] ?? false)) {
                $missingSignals[] = 'poison_or_give_back_blocker_open';
            }

            $fullyReady = $status === self::STATUS_PROVEN && $unresolved === 0 && $missingSignals === [];
            $areaAction = $this->areaClosureAction($area, $status, $unresolved, $missingSignals);

            $areaReadiness[] = [
                'area'                    => $area,
                'status'                  => $status,
                'is_critical'             => $isCritical,
                'next_closure_action'     => $areaAction,
                'missing_evidence_signals' => $missingSignals,
            ];

            if ($isCritical) {
                $criticalCount++;
                if ($fullyReady) {
                    $criticalReadyCount++;
                } else {
                    // Collect missing signals (or structural gap) for reporting.
                    $gaps = $missingSignals;
                    if ($status !== self::STATUS_PROVEN) {
                        $gaps[] = 'status_not_proven';
                    }
                    if ($unresolved > 0) {
                        $gaps[] = 'has_unresolved_items';
                    }
                    $missingEvidenceByArea[$area] = $gaps;
                }
            }

            // Block final_ready when critical area is not fully ready.
            if ($isCritical && ! $fullyReady) {
                $blockingAreas[] = $area;
                // Priority: missing evidence on a proven area shares overgrown priority.
                $priority = ($status === self::STATUS_PROVEN && $missingSignals !== [])
                    ? self::PRIORITY_MISSING_EVIDENCE
                    : (self::ACTION_PRIORITY[$status] ?? 3);
                if ($priority < $bestActionScore) {
                    $bestActionScore = $priority;
                    $bestAction      = $areaAction;
                }
            }
        }

        $finalReadinessPct = $criticalCount > 0
            ? round($criticalReadyCount / $criticalCount * 100.0, 2)
            : 100.0;

        $overallStatus = $blockingAreas === [] && $finalReadinessPct >= self::READINESS_THRESHOLD_PCT
            ? self::OVERALL_FINAL_READY
            : self::OVERALL_NOT_READY;

        sort($blockingAreas, SORT_STRING);
        ksort($missingEvidenceByArea);

        return [
            'schema'                   => self::SCHEMA,
            'area_readiness'           => $areaReadiness,
            'overall_status'           => $overallStatus,
            'blocking_areas'           => $blockingAreas,
            'final_readiness_percent'  => $finalReadinessPct,
            'missing_evidence_by_area' => $missingEvidenceByArea,
            'next_closure_action'      => $overallStatus === self::OVERALL_FINAL_READY ? 'none' : $bestAction,
        ];
    }

    /** @param list<string> $missingSignals */
    private function areaClosureAction(string $area, string $status, int $unresolved, array $missingSignals): string
    {
        // Proven status but evidence signals are missing → specific evidence-gap action.
        if ($status === self::STATUS_PROVEN && $unresolved === 0 && $missingSignals !== []) {
            return 'add_evidence_for:'.$area;
        }

        return match ($status) {
            self::STATUS_MISSING          => 'seed_evidence_for:'.$area,
            self::STATUS_PARTIALLY_PROVEN => 'complete_proof_for:'.$area,
            self::STATUS_DUPLICATED       => $unresolved > 0 ? 'resolve_duplicates_in:'.$area : 'none',
            self::STATUS_OVERGROWN        => $unresolved > 0 ? 'consolidate_overgrown:'.$area : 'none',
            default                       => 'none',
        };
    }
}
