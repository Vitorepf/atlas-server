<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure, deterministic router. Converts per-area domain-dominance gap facts (maturity, freshness, risk,
 * owner clarity, evidence coverage, blocked dependencies) into the next concrete action the brain should
 * take for that area. NEVER executes the action — only emits {area_id, action, reason, required_evidence}.
 *
 * Input per area:
 *   { area_id, maturity:float (0..1), stale:bool, risk_level:'high'|'medium'|'low',
 *     owner_clear:bool, evidence_coverage:float (0..1), blocked_dependencies?:list<string>,
 *     sprawl?:bool, overlapping_ownership?:bool }
 *
 * ACTIONS (priority-ordered; first match wins per area):
 *   maestro_unblock_plan   — blocked_dependencies !== [] (blocking signal beats everything else)
 *   refresh_context        — stale AND risk_level==='high' (stale+risky beats task creation)
 *   simplify_or_consolidate — sprawl OR overlapping_ownership
 *   create_task_chain      — low maturity AND owner_clear AND evidence gap
 *   run_research_grounding — evidence gap AND NOT owner_clear (unclear owner needs research before tasking)
 *   retire_stale_work      — stale AND NOT owner_clear AND risk_level==='low' (no risk, no owner, stale)
 *   no_action              — none of the above signals present
 *
 * Output is ordered by urgency (action priority above), area_id ASC within the same action.
 *
 * Pure: no I/O, no provider calls, no queue writes.
 */
final class AtlasExternalBrainKnowledgeDominanceGapRouter
{
    public const SCHEMA = 'atlas.self_construction.external_brain.knowledge_dominance_gap_router.v1';

    public const ACTION_MAESTRO_UNBLOCK = 'maestro_unblock_plan';

    public const ACTION_REFRESH_CONTEXT = 'refresh_context';

    public const ACTION_SIMPLIFY_OR_CONSOLIDATE = 'simplify_or_consolidate';

    public const ACTION_CREATE_TASK_CHAIN = 'create_task_chain';

    public const ACTION_RUN_RESEARCH_GROUNDING = 'run_research_grounding';

    public const ACTION_RETIRE_STALE_WORK = 'retire_stale_work';

    public const ACTION_NO_ACTION = 'no_action';

    private const MATURITY_LOW_THRESHOLD = 0.40;

    private const EVIDENCE_GAP_THRESHOLD = 0.50;

    /** Lower value = more urgent → appears first in output. */
    private const URGENCY_ORDER = [
        self::ACTION_MAESTRO_UNBLOCK => 1,
        self::ACTION_REFRESH_CONTEXT => 2,
        self::ACTION_SIMPLIFY_OR_CONSOLIDATE => 3,
        self::ACTION_CREATE_TASK_CHAIN => 4,
        self::ACTION_RUN_RESEARCH_GROUNDING => 5,
        self::ACTION_RETIRE_STALE_WORK => 6,
        self::ACTION_NO_ACTION => 7,
    ];

    private const REQUIRED_EVIDENCE = [
        self::ACTION_MAESTRO_UNBLOCK => ['dependency_resolution_plan', 'blocked_dependency_names'],
        self::ACTION_REFRESH_CONTEXT => ['context_pack_refresh_receipt'],
        self::ACTION_SIMPLIFY_OR_CONSOLIDATE => ['consolidation_plan', 'ownership_map'],
        self::ACTION_CREATE_TASK_CHAIN => ['task_chain_draft', 'acceptance_criteria'],
        self::ACTION_RUN_RESEARCH_GROUNDING => ['research_grounding_receipt'],
        self::ACTION_RETIRE_STALE_WORK => ['retirement_rationale'],
        self::ACTION_NO_ACTION => [],
    ];

    /** Confidence in the routing decision itself — deterministic blocking signals score highest. */
    private const CONFIDENCE_BY_ACTION = [
        self::ACTION_MAESTRO_UNBLOCK => 0.95,
        self::ACTION_REFRESH_CONTEXT => 0.90,
        self::ACTION_SIMPLIFY_OR_CONSOLIDATE => 0.85,
        self::ACTION_CREATE_TASK_CHAIN => 0.75,
        self::ACTION_RUN_RESEARCH_GROUNDING => 0.70,
        self::ACTION_RETIRE_STALE_WORK => 0.65,
        self::ACTION_NO_ACTION => 0.50,
    ];

    /**
     * @param  list<array<string,mixed>>  $areas
     * @return array{schema:string, routes:list<array{area_id:string, action:string, reason:string, required_evidence:list<string>}>}
     */
    public function route(array $areas): array
    {
        $routes = [];

        foreach ($areas as $area) {
            if (! is_array($area)) {
                continue;
            }
            $id = (string) ($area['area_id'] ?? '');
            if ($id === '') {
                continue;
            }

            $maturity = (float) ($area['maturity'] ?? 0.0);
            $stale = (bool) ($area['stale'] ?? false);
            $riskLevel = (string) ($area['risk_level'] ?? 'low');
            $ownerClear = (bool) ($area['owner_clear'] ?? false);
            $evidenceCoverage = (float) ($area['evidence_coverage'] ?? 1.0);
            $blockedDeps = array_values(array_map('strval', (array) ($area['blocked_dependencies'] ?? [])));
            $sprawl = (bool) ($area['sprawl'] ?? false);
            $overlappingOwnership = (bool) ($area['overlapping_ownership'] ?? false);
            $evidenceGap = $evidenceCoverage < self::EVIDENCE_GAP_THRESHOLD;

            [$action, $reason] = match (true) {
                $blockedDeps !== [] => [
                    self::ACTION_MAESTRO_UNBLOCK,
                    sprintf('blocked_dependencies present: %s', implode(',', $blockedDeps)),
                ],
                $stale && $riskLevel === 'high' => [
                    self::ACTION_REFRESH_CONTEXT,
                    'stale area with high risk_level — refresh context before any task creation',
                ],
                $sprawl || $overlappingOwnership => [
                    self::ACTION_SIMPLIFY_OR_CONSOLIDATE,
                    $sprawl && $overlappingOwnership
                        ? 'sprawl and overlapping_ownership both present'
                        : ($sprawl ? 'sprawl present' : 'overlapping_ownership present'),
                ],
                $maturity < self::MATURITY_LOW_THRESHOLD && $ownerClear && $evidenceGap => [
                    self::ACTION_CREATE_TASK_CHAIN,
                    sprintf('low maturity=%.2f with clear owner and evidence_coverage=%.2f gap', $maturity, $evidenceCoverage),
                ],
                $evidenceGap && ! $ownerClear => [
                    self::ACTION_RUN_RESEARCH_GROUNDING,
                    sprintf('evidence_coverage=%.2f gap with unclear owner — ground via research before tasking', $evidenceCoverage),
                ],
                $stale && ! $ownerClear && $riskLevel === 'low' => [
                    self::ACTION_RETIRE_STALE_WORK,
                    'stale, low risk, no clear owner — not worth active maintenance',
                ],
                default => [self::ACTION_NO_ACTION, 'no actionable gap signal present'],
            };

            // refusal_reason: explains why creating a task chain was refused for this area, when the
            // signals that would normally route to create_task_chain were preempted by an unsafe
            // condition (blocked deps, stale+high-risk context, or evidence gap).
            $wouldBeUnsafeForTaskChain = $blockedDeps !== [] || ($stale && $riskLevel === 'high') || $evidenceGap;
            $refusalReason = ($action !== self::ACTION_CREATE_TASK_CHAIN && $wouldBeUnsafeForTaskChain)
                ? 'insufficient_evidence_or_stale_high_risk_context_blocks_task_chain_creation'
                : null;

            $routes[] = [
                'area_id' => $id,
                'action' => $action,
                'reason' => $reason,
                'required_evidence' => self::REQUIRED_EVIDENCE[$action],
                'priority' => self::URGENCY_ORDER[$action] ?? 99,
                'confidence' => self::CONFIDENCE_BY_ACTION[$action] ?? 0.50,
                'refusal_reason' => $refusalReason,
            ];
        }

        usort($routes, static function (array $a, array $b): int {
            $ua = self::URGENCY_ORDER[$a['action']] ?? 99;
            $ub = self::URGENCY_ORDER[$b['action']] ?? 99;

            return $ua <=> $ub ?: strcmp($a['area_id'], $b['area_id']);
        });

        return [
            'schema' => self::SCHEMA,
            'routes' => $routes,
        ];
    }
}
