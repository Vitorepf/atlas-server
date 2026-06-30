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
 *     status:           'proven'|'partially_proven'|'missing'|'duplicated'|'overgrown'
 *     evidence_count?:  int    (default 0)
 *     unresolved_count?: int   (default 0)
 *     critical?:        bool   (overridden to true for auto-critical areas)
 *
 * OUTPUT:
 *   { schema, area_readiness, overall_status, blocking_areas, next_closure_action }
 *
 *   area_readiness:     list<{ area, status, is_critical, next_closure_action }>
 *   overall_status:     'final_ready' | 'not_ready'
 *   blocking_areas:     list<string>   (areas preventing final_ready)
 *   next_closure_action: string        (highest-leverage global action)
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

    /** Closure action priority for the global next_closure_action selection. */
    private const ACTION_PRIORITY = [
        self::STATUS_MISSING          => 0,
        self::STATUS_DUPLICATED       => 1,
        self::STATUS_OVERGROWN        => 2,
        self::STATUS_PARTIALLY_PROVEN => 3,
        self::STATUS_PROVEN           => 4,
    ];

    /**
     * @param  array<string, array<string,mixed>>  $areaEvidence
     * @return array<string,mixed>
     */
    public function map(array $areaEvidence): array
    {
        $areaReadiness   = [];
        $blockingAreas   = [];
        $bestActionScore = PHP_INT_MAX;
        $bestAction      = 'none';

        // Sort areas deterministically.
        ksort($areaEvidence);

        foreach ($areaEvidence as $area => $evidence) {
            $area       = (string) $area;
            $status     = (string) ($evidence['status'] ?? self::STATUS_MISSING);
            $unresolved = max(0, (int) ($evidence['unresolved_count'] ?? 0));
            $isCritical = in_array($area, self::AUTO_CRITICAL_AREAS, true) || (bool) ($evidence['critical'] ?? false);

            $areaAction = $this->areaClosureAction($area, $status, $unresolved);

            $areaReadiness[] = [
                'area'               => $area,
                'status'             => $status,
                'is_critical'        => $isCritical,
                'next_closure_action' => $areaAction,
            ];

            // Determine if this area blocks final_ready.
            $blocks = $isCritical && ($status !== self::STATUS_PROVEN || $unresolved > 0);
            if ($blocks) {
                $blockingAreas[] = $area;
                $priority = self::ACTION_PRIORITY[$status] ?? 3;
                if ($priority < $bestActionScore) {
                    $bestActionScore = $priority;
                    $bestAction      = $areaAction;
                }
            }
        }

        $overallStatus = $blockingAreas === [] ? self::OVERALL_FINAL_READY : self::OVERALL_NOT_READY;

        sort($blockingAreas, SORT_STRING);

        return [
            'schema'              => self::SCHEMA,
            'area_readiness'      => $areaReadiness,
            'overall_status'      => $overallStatus,
            'blocking_areas'      => $blockingAreas,
            'next_closure_action' => $overallStatus === self::OVERALL_FINAL_READY ? 'none' : $bestAction,
        ];
    }

    private function areaClosureAction(string $area, string $status, int $unresolved): string
    {
        return match ($status) {
            self::STATUS_MISSING          => 'seed_evidence_for:'.$area,
            self::STATUS_PARTIALLY_PROVEN => 'complete_proof_for:'.$area,
            self::STATUS_DUPLICATED       => $unresolved > 0 ? 'resolve_duplicates_in:'.$area : 'none',
            self::STATUS_OVERGROWN        => $unresolved > 0 ? 'consolidate_overgrown:'.$area : 'none',
            default                       => 'none',
        };
    }
}
