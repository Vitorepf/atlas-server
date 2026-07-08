<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Runtime for the Obras "Metrics, Risks and Excellence" specification.
 *
 * Turns the doc's three "Quality Claim Rules" gates, the Excellence Criteria,
 * the Main Risks register and the per-level metric catalog into deterministic,
 * pure decision logic. The load-bearing contract is the three claim gates:
 * each "Atlas may not claim X unless [conditions]" is enforced as a hard gate
 * that only returns can_claim=true when EVERY required condition is satisfied,
 * and otherwise reports exactly which conditions are missing.
 *
 * Pure: no database, no IO, no clock. Same input -> same output.
 *
 * @see docs/engineering-knowledge-base/obras/metrics-risks-and-excellence.md
 */
final class AtlasObrasMetricsRisksAndExcellenceService
{
    private const SCHEMA_VERSION = 'atlas.obras.metrics_risks_and_excellence.v1';

    /**
     * "Atlas may not claim an Obra is complete unless:" (doc -> Quality Claim Rules).
     * Each entry maps the documented condition to the boolean state key callers supply.
     *
     * @var array<string, string>
     */
    private const COMPLETE_CONDITIONS = [
        'output_exists' => 'output exists',
        'current_version_identified' => 'current version is identified',
        'required_gates_ran' => 'required gates ran',
        'critical_failures_resolved_or_accepted' => 'critical failures are resolved or accepted',
        'key_decisions_recorded' => 'key decisions are recorded',
        'evidence_exists' => 'evidence exists',
        'next_step_or_closure_explicit' => 'next step or closure is explicit',
        'learning_captured' => 'learning is captured',
    ];

    /**
     * "Atlas may not claim Foundry maturity unless:" (doc -> Quality Claim Rules).
     *
     * @var array<string, string>
     */
    private const FOUNDRY_CONDITIONS = [
        'output_became_asset' => 'output became an asset',
        'asset_type_classified' => 'asset type is classified',
        'dependency_or_portfolio_relation_exists' => 'dependency/portfolio relation exists',
        'opportunity_cost_considered' => 'opportunity cost was considered',
        'continue_pause_kill_scale_decision_exists' => 'continue/pause/kill/scale decision exists',
    ];

    /**
     * "Atlas may not claim Sovereign maturity unless:" (doc -> Quality Claim Rules).
     *
     * @var array<string, string>
     */
    private const SOVEREIGN_CONDITIONS = [
        'autonomy_impact_explicit' => 'autonomy impact is explicit',
        'capital_impact_explicit' => 'capital impact is explicit',
        'health_relationship_integrity_constraints_passed' => 'health/relationship/integrity constraints passed',
        'success_metrics_exist' => 'success metrics exist',
        'reversal_or_review_plan_exists' => 'reversal or review plan exists',
    ];

    /**
     * Excellence Criteria — Obras reaches state of the art when it combines (doc).
     *
     * @var list<string>
     */
    private const EXCELLENCE_CRITERIA = [
        'persistent_context_per_obra',
        'agentic_execution',
        'sources_and_evidence',
        'traceable_decisions',
        'quality_gates',
        'repair_loops',
        'versioning',
        'real_outputs',
        'strategic_portfolio',
        'operator_autonomy_protection',
    ];

    /**
     * Main Risks register with mitigations (doc -> Main Risks).
     *
     * @var list<array{id: string, name: string, risk: string, mitigations: list<string>}>
     */
    private const RISKS = [
        [
            'id' => 'risk_1_pretty_folder',
            'name' => 'Pretty Folder',
            'risk' => 'Obras becomes only a collection of files.',
            'mitigations' => [
                'every Obra requires objective, structure, next step, gates and output',
                'Markdown may be projection/export, not the runtime brain',
            ],
        ],
        [
            'id' => 'risk_2_renamed_chat',
            'name' => 'Renamed Chat',
            'risk' => 'User talks with AI, but nothing becomes an artifact.',
            'mitigations' => [
                'every AI session must link to Obra, node, task, decision or output',
                'every significant AI result must become note, task, draft, decision, gate finding, output or evidence',
            ],
        ],
        [
            'id' => 'risk_3_automation_without_governance',
            'name' => 'Automation Without Governance',
            'risk' => 'AI makes sensitive decisions without control.',
            'mitigations' => [
                'human checkpoints',
                'permissions',
                'logs',
                'approval flow',
                'provider and data policy',
            ],
        ],
        [
            'id' => 'risk_4_fantasy_strategy',
            'name' => 'Fantasy Strategy',
            'risk' => 'Foundry or Sovereign OS becomes grand speech without execution.',
            'mitigations' => [
                'metrics',
                'evidence',
                'opportunity cost',
                'periodic review',
                'kill/pause/scale decisions',
            ],
        ],
        [
            'id' => 'risk_5_too_much_complexity_too_early',
            'name' => 'Too Much Complexity Too Early',
            'risk' => 'Atlas tries to build L5 before L0-L2 are real.',
            'mitigations' => [
                'implement in layers',
                'preserve final ontology',
                'start with L0/L1 and the Atlas Self-Construction OS pilot',
            ],
        ],
    ];

    /**
     * Metrics by maturity level (doc -> Metrics By Level).
     *
     * @var array<string, list<string>>
     */
    private const LEVEL_METRICS = [
        'L0' => [
            'obras_created',
            'active_obras',
            'archived_obras',
            'obras_without_next_step',
            'obras_missing_objective_type_status',
        ],
        'L1' => [
            'notes_per_obra',
            'tasks_per_obra',
            'sources_per_obra',
            'sections_complete',
            'last_activity',
            'obra_summary_freshness',
            'tasks_linked_to_structure',
        ],
        'L2' => [
            'gates_approved',
            'gates_failed',
            'decisions_registered',
            'versions_published',
            'evidence_events',
            'feedbacks_resolved',
            'outputs_traceable_to_versions',
        ],
        'L3' => [
            'time_to_delivery',
            'number_of_repair_loops',
            'gate_approval_rate',
            'outputs_generated',
            'human_interventions',
            'critical_failures_detected',
            'unsupported_claims_repaired',
        ],
        'L4' => [
            'obras_became_assets',
            'obras_paused_correctly',
            'obras_killed_correctly',
            'dependencies_resolved',
            'estimated_strategic_return',
            'dispersion_reduced',
            'spin_offs_generated',
        ],
        'L5' => [
            'autonomy_increased',
            'capital_created',
            'health_preserved',
            'relationships_preserved',
            'reputation_created',
            'technical_capacity_increased',
            'revenue_or_opportunity_generated',
            'operator_energy_protected',
        ],
    ];

    /**
     * Evaluate every Obras claim gate at once for a given Obra state plus the
     * excellence score and full risk/metric catalog. This is the command's
     * primary entrypoint.
     *
     * @param array<string, bool> $state
     * @return array<string, mixed>
     */
    public function evaluate(array $state = []): array
    {
        $complete = $this->evaluateCompleteClaim($state);
        $foundry = $this->evaluateFoundryClaim($state);
        $sovereign = $this->evaluateSovereignClaim($state);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'claims' => [
                'complete' => $complete,
                'foundry' => $foundry,
                'sovereign' => $sovereign,
            ],
            'highest_claimable_maturity' => $this->highestClaimableMaturity($complete, $foundry, $sovereign),
            'excellence' => $this->excellence($state),
            'risks' => $this->risks(),
            'level_metrics' => self::LEVEL_METRICS,
        ];
    }

    /**
     * Gate: may Atlas claim the Obra is COMPLETE?
     *
     * @param array<string, bool> $state
     * @return array{claim: string, can_claim: bool, satisfied: list<string>, missing: list<string>, total_conditions: int, satisfied_count: int}
     */
    public function evaluateCompleteClaim(array $state): array
    {
        return $this->gate('complete', self::COMPLETE_CONDITIONS, $state);
    }

    /**
     * Gate: may Atlas claim FOUNDRY maturity?
     *
     * Foundry is also escalation-gated: a delivery only reaches Foundry once it
     * is genuinely complete (Final Principle: "If the delivery does not become
     * an asset, it has not reached Foundry"). So a Foundry claim additionally
     * requires the complete claim to hold; this is surfaced as the
     * `predecessor_satisfied` flag and folded into can_claim.
     *
     * @param array<string, bool> $state
     * @return array{claim: string, can_claim: bool, satisfied: list<string>, missing: list<string>, total_conditions: int, satisfied_count: int, predecessor_satisfied: bool}
     */
    public function evaluateFoundryClaim(array $state): array
    {
        $gate = $this->gate('foundry', self::FOUNDRY_CONDITIONS, $state);
        $predecessor = $this->allSatisfied(self::COMPLETE_CONDITIONS, $state);

        $gate['predecessor_satisfied'] = $predecessor;
        $gate['can_claim'] = $gate['can_claim'] && $predecessor;

        return $gate;
    }

    /**
     * Gate: may Atlas claim SOVEREIGN maturity?
     *
     * Sovereign sits above Foundry (Final Principle: "If the asset does not
     * increase autonomy, it has not reached Sovereign OS"). A Sovereign claim
     * therefore additionally requires both the complete and Foundry condition
     * sets to hold.
     *
     * @param array<string, bool> $state
     * @return array{claim: string, can_claim: bool, satisfied: list<string>, missing: list<string>, total_conditions: int, satisfied_count: int, predecessor_satisfied: bool}
     */
    public function evaluateSovereignClaim(array $state): array
    {
        $gate = $this->gate('sovereign', self::SOVEREIGN_CONDITIONS, $state);
        $predecessor = $this->allSatisfied(self::COMPLETE_CONDITIONS, $state)
            && $this->allSatisfied(self::FOUNDRY_CONDITIONS, $state);

        $gate['predecessor_satisfied'] = $predecessor;
        $gate['can_claim'] = $gate['can_claim'] && $predecessor;

        return $gate;
    }

    /**
     * Excellence Criteria scoring: Obras reaches state of the art only when it
     * combines ALL ten criteria. state_of_the_art is true iff every criterion
     * is present.
     *
     * @param array<string, bool> $state
     * @return array{criteria: list<string>, present: list<string>, missing: list<string>, total: int, present_count: int, score_out_of_10: int, state_of_the_art: bool}
     */
    public function excellence(array $state): array
    {
        $present = [];
        $missing = [];
        foreach (self::EXCELLENCE_CRITERIA as $criterion) {
            if (($state[$criterion] ?? false) === true) {
                $present[] = $criterion;
            } else {
                $missing[] = $criterion;
            }
        }

        $total = count(self::EXCELLENCE_CRITERIA);
        $presentCount = count($present);

        return [
            'criteria' => self::EXCELLENCE_CRITERIA,
            'present' => $present,
            'missing' => $missing,
            'total' => $total,
            'present_count' => $presentCount,
            'score_out_of_10' => (int) round($presentCount / $total * 10),
            'state_of_the_art' => $missing === [],
        ];
    }

    /**
     * Full risk register (5 documented risks + mitigations).
     *
     * @return list<array{id: string, name: string, risk: string, mitigations: list<string>}>
     */
    public function risks(): array
    {
        return self::RISKS;
    }

    /**
     * Metric catalog for a single level, or all levels when null.
     *
     * @return array<string, list<string>>|list<string>
     */
    public function levelMetrics(?string $level = null): array
    {
        if ($level === null) {
            return self::LEVEL_METRICS;
        }

        $key = strtoupper(trim($level));

        return self::LEVEL_METRICS[$key] ?? [];
    }

    /**
     * Generic claim gate: returns can_claim=true only when EVERY documented
     * condition for that claim is satisfied in $state, otherwise lists the
     * missing conditions. A missing/absent key is treated as not satisfied.
     *
     * @param array<string, string> $conditions
     * @param array<string, bool> $state
     * @return array{claim: string, can_claim: bool, satisfied: list<string>, missing: list<string>, total_conditions: int, satisfied_count: int}
     */
    private function gate(string $claim, array $conditions, array $state): array
    {
        $satisfied = [];
        $missing = [];
        foreach ($conditions as $key => $label) {
            if (($state[$key] ?? false) === true) {
                $satisfied[] = $key;
            } else {
                $missing[] = $key;
            }
        }

        return [
            'claim' => $claim,
            'can_claim' => $missing === [],
            'satisfied' => $satisfied,
            'missing' => $missing,
            'total_conditions' => count($conditions),
            'satisfied_count' => count($satisfied),
        ];
    }

    /**
     * @param array<string, string> $conditions
     * @param array<string, bool> $state
     */
    private function allSatisfied(array $conditions, array $state): bool
    {
        foreach (array_keys($conditions) as $key) {
            if (($state[$key] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve the highest maturity Atlas is allowed to claim given the three
     * gate results. Returns 'none' when not even complete can be claimed.
     *
     * @param array{can_claim: bool} $complete
     * @param array{can_claim: bool} $foundry
     * @param array{can_claim: bool} $sovereign
     */
    private function highestClaimableMaturity(array $complete, array $foundry, array $sovereign): string
    {
        if ($sovereign['can_claim'] === true) {
            return 'sovereign';
        }
        if ($foundry['can_claim'] === true) {
            return 'foundry';
        }
        if ($complete['can_claim'] === true) {
            return 'complete';
        }

        return 'none';
    }
}
