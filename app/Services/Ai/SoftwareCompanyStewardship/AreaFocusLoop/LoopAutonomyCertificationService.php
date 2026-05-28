<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * AP-806 — Loop Autonomy Certification.
 *
 * Read-only. Answers ONE operational question and turns the answer into the
 * next unblock: "How autonomous is the stewardship loop right now, and what is
 * the exact next dependency to eliminate so it can run a week alone?"
 *
 * This is NOT just a passive read model. It classifies every stage of the loop
 * cycle, scores autonomy, ORDERS the blockers by impact, and emits the next
 * executable slice + the most-autonomous-command-available-now + the 24h/7d/30d
 * gap. Certifying autonomy is the map; eliminating per-cycle dependency is the
 * mission — this report is the ruler that generates that mission.
 *
 * It composes AP-805 (TenCycleReadinessGovernorService) — it never duplicates
 * its probes, never runs the loop, never invokes a provider, never merges.
 *
 * Honesty rules (operator does not accept false autonomy claims):
 *   - Forge real execution is plan-only/fixture today → it is reported as
 *     not_implemented, never dressed as ready.
 *   - A stage is `autonomous` only when the loop already does it with no human
 *     and no missing runtime; everything else is named precisely.
 *   - Auto-merging cross-system work to main is `unsafe`, never `autonomous`.
 */
final class LoopAutonomyCertificationService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.loop_autonomy_certification.v1';

    /** Target operating modes the certification can assess. */
    public const MODE_FACTORY_SCOPED = 'factory_scoped_self_improvement';

    public const MODE_AAEOS_DEV_LANE = 'aaeos_dev_integration_lane';

    public const MODE_AAEOS_FORGE = 'aaeos_forge_full';

    /** Autonomy classification of a single cycle stage. */
    public const STATE_AUTONOMOUS = 'autonomous';

    public const STATE_POLICY_PRE_AUTHORIZED = 'policy_pre_authorized';

    public const STATE_REQUIRES_OPERATOR_ONCE = 'requires_operator_once';

    public const STATE_REQUIRES_OPERATOR_PER_CYCLE = 'requires_operator_per_cycle';

    public const STATE_REQUIRES_CLAUDE_MANUAL = 'requires_claude_manual';

    public const STATE_NOT_IMPLEMENTED = 'not_implemented';

    public const STATE_UNSAFE = 'unsafe';

    /**
     * How much each state counts toward the autonomy score. policy_pre_authorized
     * and requires_operator_once are one-time setup costs (autonomous thereafter),
     * so they score high-but-not-full; per-cycle human / manual / missing / unsafe
     * score zero because each blocks "turn it on and walk away".
     */
    private const STATE_WEIGHT = [
        self::STATE_AUTONOMOUS => 1.0,
        self::STATE_POLICY_PRE_AUTHORIZED => 0.85,
        self::STATE_REQUIRES_OPERATOR_ONCE => 0.6,
        self::STATE_REQUIRES_OPERATOR_PER_CYCLE => 0.0,
        self::STATE_REQUIRES_CLAUDE_MANUAL => 0.0,
        self::STATE_NOT_IMPLEMENTED => 0.0,
        self::STATE_UNSAFE => 0.0,
    ];

    /**
     * The full loop cycle, in order. `weight` = impact on the autonomy goal.
     * `phase` groups the stage. Stage keys mirror the operator's cycle map.
     *
     * @var list<array{key:string,title:string,phase:string,weight:int}>
     */
    private const STAGES = [
        ['key' => 'finding_discovery', 'title' => 'Finding discovery (deep scan)', 'phase' => 'sense', 'weight' => 3],
        ['key' => 'priority', 'title' => 'Priority ranking', 'phase' => 'sense', 'weight' => 2],
        ['key' => 'scope_admission', 'title' => 'Scope admission (what is eligible to execute)', 'phase' => 'sense', 'weight' => 3],
        ['key' => 'slice_planner', 'title' => 'Slice planner / decomposition', 'phase' => 'plan', 'weight' => 3],
        ['key' => 'spec_architecture', 'title' => 'Spec / architecture', 'phase' => 'plan', 'weight' => 2],
        ['key' => 'route_dev_vs_forge', 'title' => 'Route Atlas Dev vs Forge', 'phase' => 'plan', 'weight' => 2],
        ['key' => 'forge_intake_obra', 'title' => 'Forge intake / Obra authority', 'phase' => 'authorize', 'weight' => 2],
        ['key' => 'provider_topology_decide', 'title' => 'Provider topology / Atlas Decide', 'phase' => 'authorize', 'weight' => 2],
        ['key' => 'budget', 'title' => 'Budget envelope', 'phase' => 'authorize', 'weight' => 2],
        ['key' => 'sandbox', 'title' => 'Isolated sandbox', 'phase' => 'execute', 'weight' => 2],
        ['key' => 'execution', 'title' => 'Execution (real provider patch)', 'phase' => 'execute', 'weight' => 3],
        ['key' => 'judge_repair', 'title' => 'Judge / repair', 'phase' => 'execute', 'weight' => 3],
        ['key' => 'evidence', 'title' => 'Evidence pack', 'phase' => 'prove', 'weight' => 2],
        ['key' => 'inbox_product_mode', 'title' => 'Inbox / Product Mode', 'phase' => 'prove', 'weight' => 1],
        ['key' => 'merge_target', 'title' => 'Merge target', 'phase' => 'land', 'weight' => 3],
        ['key' => 'learning_compounding', 'title' => 'Learning / compounding', 'phase' => 'land', 'weight' => 2],
        ['key' => 'continuation_recovery', 'title' => 'Continuation / recovery', 'phase' => 'land', 'weight' => 2],
    ];

    public function __construct(
        private readonly TenCycleReadinessGovernorService $readiness,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function certify(array $input = []): array
    {
        $area = trim((string) ($input['area'] ?? 'agentic_engineering_os')) ?: 'agentic_engineering_os';
        $focus = trim((string) ($input['focus'] ?? 'dev_forge')) ?: 'dev_forge';
        $targetMode = $this->normalizeMode((string) ($input['target_mode'] ?? self::MODE_AAEOS_DEV_LANE));

        // Compose AP-805 (never duplicate its probes). Tests may inject a payload.
        $readiness = is_array($input['readiness'] ?? null)
            ? $input['readiness']
            : $this->readiness->assess($input + ['area' => $area, 'focus' => $focus]);

        $signals = $this->gatherSignals($readiness);

        // Classify every cycle stage for each known mode.
        $modes = [];
        foreach ([self::MODE_FACTORY_SCOPED, self::MODE_AAEOS_DEV_LANE, self::MODE_AAEOS_FORGE] as $mode) {
            $modes[$mode] = $this->assessMode($mode, $signals);
        }

        $primary = $modes[$targetMode];
        $blockers = $this->orderedBlockers($primary['stages'], $signals);
        $perCycleDeps = $this->perCycleDependencies($modes);

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-806',
            'area' => $area,
            'focus' => $focus,
            'target_mode' => $targetMode,
            'certified_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'composed_readiness_status' => (string) ($readiness['status'] ?? 'unknown'),
            'autonomy_score' => $primary['autonomy_score'],
            'autonomy_band' => $this->band($primary['autonomy_score']),
            'verdict' => $this->verdict($primary, $blockers),
            'stages' => $primary['stages'],
            'blockers_by_impact' => $blockers,
            'per_cycle_dependencies_to_eliminate' => $perCycleDeps,
            'next_executable_slice' => $this->nextSlice($blockers, $signals),
            'most_autonomous_command_now' => $this->mostAutonomousCommandNow($area, $focus, $modes),
            'horizon_gaps' => $this->horizonGaps($modes, $signals),
            'mode_autonomy_scores' => array_map(
                static fn (array $m): array => ['autonomy_score' => $m['autonomy_score'], 'verdict_summary' => $m['blocking_stage_keys']],
                $modes,
            ),
            'claim_policy' => [
                'read_only' => true,
                'runs_loop' => false,
                'runs_provider' => false,
                'runs_merge' => false,
                'forge_real_execution_is_not_implemented_today' => true,
                'false_autonomy_never_claimed' => true,
            ],
        ];

        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->withoutVolatile($payload));

        return $payload;
    }

    /**
     * Pull the few real signals that drive classification out of the AP-805
     * payload. Documented runtime facts (Forge plan-only/fixture, no integration
     * lane envelope) are constants of the current runtime, cited with evidence.
     *
     * @param  array<string,mixed>  $readiness
     * @return array<string,mixed>
     */
    private function gatherSignals(array $readiness): array
    {
        $gates = is_array($readiness['gates'] ?? null) ? $readiness['gates'] : [];
        $gateOk = static function (string $key) use ($gates): bool {
            return (bool) (($gates[$key]['ok'] ?? false));
        };

        $providerState = is_array($readiness['provider_state'] ?? null) ? $readiness['provider_state'] : [];
        $binaries = array_values(array_filter((array) ($providerState['available_binaries'] ?? []), 'is_string'));

        return [
            'readiness_status' => (string) ($readiness['status'] ?? 'unknown'),
            'slice_planner_available' => $gateOk('finding_slice_planner_available'),
            'duplicate_guard_available' => $gateOk('duplicate_finding_guard_available'),
            'multi_agent_available' => $gateOk('multi_agent_lane_contracts_available'),
            'judge_repair_available' => $gateOk('judge_repair_available'),
            'provider_routing_ok' => $gateOk('provider_routing_available_or_honest_degraded'),
            'provider_timeout_ok' => $gateOk('provider_timeout_minimum_ok'),
            'merge_truth_guard_present' => $gateOk('merge_truth_guard_present'),
            'kill_switch_available' => $gateOk('kill_switch_available'),
            'no_stale_lock' => $gateOk('no_stale_lock'),
            'product_mode_memory_safe' => $gateOk('product_mode_projection_memory_safe'),
            'provider_binaries' => $binaries,
            'real_provider_available' => $binaries !== [],

            // Documented runtime facts (current code state, cited with evidence).
            'forge_real_execution_implemented' => false, // live-execute = fixture; owner dispatch = plan-only
            'integration_lane_autonomy_envelope_implemented' => false, // no standing pre-authorized cross-system lane mode
            'cross_system_scope_admission_implemented' => false, // factory_max rejects cross-system without forge authority
            'factory_scoped_finding_quality_real' => false, // deep scan emits strategic roadmap + vague seeds, not concrete factory tasks
            'learning_compounding_wired_into_loop' => false, // learning packets not fed back into next-cycle selection
            'seven_day_stability_proven' => false,
            'twentyfour_hour_stability_proven' => true, // AP-790 runner + lockStatus orphan reclaim (this session)
        ];
    }

    /**
     * @param  array<string,mixed>  $signals
     * @return array{mode:string,autonomy_score:float,stages:array<string,array<string,mixed>>,blocking_stage_keys:list<string>}
     */
    private function assessMode(string $mode, array $signals): array
    {
        $stages = [];
        $weightedSum = 0.0;
        $weightTotal = 0;
        $blocking = [];

        foreach (self::STAGES as $stage) {
            $key = $stage['key'];
            [$state, $detail, $evidence, $remediation, $relevant] = $this->classifyStage($key, $mode, $signals);

            $stages[$key] = [
                'title' => $stage['title'],
                'phase' => $stage['phase'],
                'state' => $state,
                'relevant_to_mode' => $relevant,
                'detail' => $detail,
                'evidence' => $evidence,
                'remediation' => $remediation,
                'impact_weight' => $stage['weight'],
            ];

            if (! $relevant) {
                continue; // a bypassed stage neither helps nor hurts this mode's score
            }

            $weightedSum += self::STATE_WEIGHT[$state] * $stage['weight'];
            $weightTotal += $stage['weight'];
            if (self::STATE_WEIGHT[$state] <= 0.0) {
                $blocking[] = $key;
            }
        }

        $score = $weightTotal > 0 ? round($weightedSum / $weightTotal, 3) : 0.0;

        return [
            'mode' => $mode,
            'autonomy_score' => $score,
            'stages' => $stages,
            'blocking_stage_keys' => $blocking,
        ];
    }

    /**
     * The honest classification engine. Returns
     * [state, detail, evidence, remediation, relevant_to_mode].
     *
     * @param  array<string,mixed>  $signals
     * @return array{0:string,1:string,2:string,3:string,4:bool}
     */
    private function classifyStage(string $key, string $mode, array $signals): array
    {
        $isForgeMode = $mode === self::MODE_AAEOS_FORGE;
        $isCrossSystem = $mode !== self::MODE_FACTORY_SCOPED;

        return match ($key) {
            'finding_discovery' => [
                self::STATE_AUTONOMOUS,
                'Deep scan runs with no human; emits findings every cycle.',
                'AreaFocusDeepFindingEngineService::scan()',
                '',
                true,
            ],
            'priority' => [
                self::STATE_AUTONOMOUS,
                'Priority engine ranks candidates autonomously.',
                'StewardshipPriorityEngineService::rank()',
                '',
                true,
            ],
            'scope_admission' => $this->classifyScopeAdmission($mode, $isCrossSystem, $signals),
            'slice_planner' => $signals['slice_planner_available']
                ? [self::STATE_AUTONOMOUS, 'Slice planner present; decomposes or blocks honestly.', 'FindingSlicePlannerService (AP-796)', $isCrossSystem ? 'For cross-system: decompose a strategic finding into a factory-scoped first slice.' : '', true]
                : [self::STATE_NOT_IMPLEMENTED, 'Slice planner reported unavailable by AP-805.', 'AP-805 gate finding_slice_planner_available', 'Restore the slice planner gate.', true],
            'spec_architecture' => [
                self::STATE_AUTONOMOUS,
                'Atlas Dev senior loop is spec-driven (intent + plan-only routing) per cycle.',
                'SeniorEngineerLoopExecutor::planOnly()',
                'Deepen SDD/architecture quality for large cross-system slices.',
                ! $isForgeMode,
            ],
            'route_dev_vs_forge' => [
                self::STATE_AUTONOMOUS,
                'Owner derivation routes atlas_dev/forge autonomously.',
                'Ap786OwnerFlowExecutor owner derivation',
                $isForgeMode ? 'Forge route leads to not_implemented execution (see execution).' : '',
                true,
            ],
            'forge_intake_obra' => [
                self::STATE_NOT_IMPLEMENTED,
                $isForgeMode
                    ? 'Forge real execution is plan-only/fixture; a real governed Obra would still hit a fixture executor.'
                    : 'Bypassed: this mode uses the Atlas Dev executable path, not Forge.',
                'AtlasForgeLiveExecutionService (fixture patch); Ap786OwnerFlowExecutor STATUS_FORGE_PLANNED',
                'Build real Forge provider-execution runtime (separate large AP) before relying on Forge.',
                $isForgeMode,
            ],
            'provider_topology_decide' => $signals['provider_routing_ok']
                ? [self::STATE_AUTONOMOUS, 'Per-lane provider routing available; binaries: '.implode(',', $signals['provider_binaries']).'.', 'AP-804 per-lane routing', $isForgeMode ? 'Forge needs a live Atlas Decide receipt (operator/Obra).' : '', true]
                : [self::STATE_REQUIRES_OPERATOR_ONCE, 'Provider routing degraded; configure providers.', 'AP-805 gate provider_routing_available_or_honest_degraded', 'Configure provider binaries/topology once.', true],
            'budget' => [
                self::STATE_POLICY_PRE_AUTHORIZED,
                'Budgets are run-level (cycles/runtime/merges/blocked-in-row); set once in the standing envelope.',
                'Reliable24hLoopRunnerService budgets',
                'Move budgets into a one-time weekly autonomy envelope.',
                true,
            ],
            'sandbox' => [
                self::STATE_AUTONOMOUS,
                'Branch/worktree sandbox materializes and cleans up autonomously.',
                'AreaFocusBranchSandboxMaterializerService (AP-756)',
                '',
                true,
            ],
            'execution' => $this->classifyExecution($mode, $isForgeMode, $signals),
            'judge_repair' => $signals['judge_repair_available']
                ? [self::STATE_AUTONOMOUS, 'Integration judge + repair planner run autonomously; judge now coherent.', 'MultiAgentIntegrationJudgeService + RepairPlanner (AP-798/799/803)', '', true]
                : [self::STATE_NOT_IMPLEMENTED, 'Judge/repair reported unavailable by AP-805.', 'AP-805 gate judge_repair_available', 'Restore judge/repair gate.', true],
            'evidence' => [
                self::STATE_AUTONOMOUS,
                'Evidence pack produced per cycle autonomously.',
                'Stewardship runtime result bridge (AP-765)',
                '',
                true,
            ],
            'inbox_product_mode' => $signals['product_mode_memory_safe']
                ? [self::STATE_AUTONOMOUS, 'Inbox + Product Mode events emitted autonomously.', 'AP-765 result bridge + Product Mode', '', true]
                : [self::STATE_REQUIRES_OPERATOR_ONCE, 'Product Mode projection flagged memory-unsafe.', 'AP-805 gate product_mode_projection_memory_safe', 'Resolve product-mode memory safety once.', true],
            'merge_target' => $this->classifyMergeTarget($mode, $isCrossSystem, $signals),
            'learning_compounding' => $signals['learning_compounding_wired_into_loop']
                ? [self::STATE_AUTONOMOUS, 'Learning packets fed back into next-cycle selection.', 'Learning loop wired into selection', '', true]
                : [self::STATE_NOT_IMPLEMENTED, 'Learning exists in the scanner but is NOT fed back into next-cycle selection/backlog; no compounding across cycles.', 'AreaFocusDeepFindingEngineService only; no loop feedback', 'Wire LearningPacket/NextCycleRecommendation into the next cycle selection so the loop compounds.', true],
            'continuation_recovery' => $signals['twentyfour_hour_stability_proven']
                ? [self::STATE_AUTONOMOUS, '24h continuation proven (locks/budget/crash-recovery + orphaned-lock reclaim). 7d/30d not yet proven.', 'Reliable24hLoopRunnerService (AP-790) + lockStatus orphan reclaim', $signals['seven_day_stability_proven'] ? '' : 'Prove 7d/30d stability (lease renewal, pollution control, backlog depth).', true]
                : [self::STATE_NOT_IMPLEMENTED, '24h continuation not proven.', 'AP-790', 'Prove 24h stability.', true],
            default => [self::STATE_NOT_IMPLEMENTED, 'Unknown stage.', '', '', true],
        };
    }

    /**
     * @param  array<string,mixed>  $signals
     * @return array{0:string,1:string,2:string,3:string,4:bool}
     */
    private function classifyScopeAdmission(string $mode, bool $isCrossSystem, array $signals): array
    {
        if (! $isCrossSystem) {
            return [
                self::STATE_AUTONOMOUS,
                'Factory-scoped admission accepts low-risk loop-runtime work autonomously.',
                'AutonomousEvolutionSessionService::candidateRejectionReason (factoryScopedAutonomousPatchCandidate)',
                '',
                true,
            ];
        }

        return $signals['cross_system_scope_admission_implemented']
            ? [self::STATE_POLICY_PRE_AUTHORIZED, 'Cross-system admission governed by the standing envelope.', 'envelope', '', true]
            : [
                self::STATE_NOT_IMPLEMENTED,
                'factory_max rejects ALL cross-system findings without forge authority; there is no admission profile that accepts cross-system Atlas Dev work routed to an integration lane.',
                'AutonomousEvolutionSessionService::candidateRejectionReason:2571-2589',
                'Add a pre-authorized envelope admission profile that accepts cross-system atlas_dev findings when merge target is the integration lane.',
                true,
            ];
    }

    /**
     * @param  array<string,mixed>  $signals
     * @return array{0:string,1:string,2:string,3:string,4:bool}
     */
    private function classifyExecution(string $mode, bool $isForgeMode, array $signals): array
    {
        if ($isForgeMode) {
            return [
                self::STATE_NOT_IMPLEMENTED,
                'Forge runtime is plan-only (owner_flow_forge_planned) or fixture (live-execute); it does NOT generate real code.',
                'Ap786OwnerFlowExecutor::STATUS_FORGE_PLANNED; AtlasForgeLiveExecutionService fixture patch',
                'Build the real Forge provider-execution runtime (large, separate AP).',
                true,
            ];
        }

        return $signals['real_provider_available']
            ? [
                self::STATE_AUTONOMOUS,
                'Atlas Dev senior loop invokes a real provider and produces executable scoped patches (proven by prior autonomous merges).',
                'SeniorEngineerLoopExecutor via real provider ('.implode(',', $signals['provider_binaries']).')',
                '',
                true,
            ]
            : [
                self::STATE_REQUIRES_OPERATOR_ONCE,
                'No real provider binary available; configure one.',
                'AP-805 provider_state',
                'Install/configure a provider binary once.',
                true,
            ];
    }

    /**
     * @param  array<string,mixed>  $signals
     * @return array{0:string,1:string,2:string,3:string,4:bool}
     */
    private function classifyMergeTarget(string $mode, bool $isCrossSystem, array $signals): array
    {
        if (! $isCrossSystem) {
            return [
                self::STATE_AUTONOMOUS,
                'Factory-scoped ff-only auto-merge to main is allowed and proven (lowest blast radius).',
                'StewardshipBranchMergeGovernor (factory-scoped boundary)',
                '',
                true,
            ];
        }

        return $signals['integration_lane_autonomy_envelope_implemented']
            ? [self::STATE_POLICY_PRE_AUTHORIZED, 'Cross-system work auto-merges to the governed integration lane; operator promotes to main at week end.', 'integration lane (AP-782/783) under envelope', '', true]
            : [
                self::STATE_NOT_IMPLEMENTED,
                'No autonomous mode merges cross-system work to a governed integration lane. Auto-merging cross-system to main directly is UNSAFE; per-cycle human review is anti-autonomy.',
                'integration-lane (AP-782/783) not wired as the loop autonomous merge target',
                'Wire the integration lane (AP-782/783) as the cross-system merge target under the standing envelope; operator reviews+promotes weekly.',
                true,
            ];
    }

    /**
     * Order the blocking stages of the primary mode by impact (weight) so the
     * report tells the operator exactly what to eliminate first.
     *
     * @param  array<string,array<string,mixed>>  $stages
     * @param  array<string,mixed>  $signals
     * @return list<array<string,mixed>>
     */
    private function orderedBlockers(array $stages, array $signals): array
    {
        $blockers = [];
        foreach ($stages as $key => $stage) {
            if (($stage['relevant_to_mode'] ?? false) !== true) {
                continue;
            }
            if ((self::STATE_WEIGHT[$stage['state']] ?? 0.0) > 0.0) {
                continue;
            }
            $blockers[] = [
                'stage' => $key,
                'title' => $stage['title'],
                'state' => $stage['state'],
                'impact_weight' => $stage['impact_weight'] ?? 0,
                'detail' => $stage['detail'],
                'evidence' => $stage['evidence'],
                'remediation' => $stage['remediation'],
            ];
        }

        usort($blockers, static fn (array $a, array $b): int => ($b['impact_weight'] <=> $a['impact_weight']) ?: ($a['stage'] <=> $b['stage']));

        return $blockers;
    }

    /**
     * Per-cycle dependencies are the autonomy killers: anything that needs a
     * human or Claude EVERY cycle (vs. a one-time setup). Aggregated across modes.
     *
     * @param  array<string,array<string,mixed>>  $modes
     * @return list<array<string,mixed>>
     */
    private function perCycleDependencies(array $modes): array
    {
        $perCycle = [self::STATE_REQUIRES_OPERATOR_PER_CYCLE, self::STATE_REQUIRES_CLAUDE_MANUAL];
        $deps = [];
        foreach ($modes as $mode => $assessment) {
            foreach ($assessment['stages'] as $key => $stage) {
                if (($stage['relevant_to_mode'] ?? false) === true && in_array($stage['state'], $perCycle, true)) {
                    $deps[] = ['mode' => $mode, 'stage' => $key, 'state' => $stage['state'], 'detail' => $stage['detail']];
                }
            }
        }

        return $deps;
    }

    /**
     * @param  list<array<string,mixed>>  $blockers
     * @param  array<string,mixed>  $signals
     * @return array<string,mixed>
     */
    private function nextSlice(array $blockers, array $signals): array
    {
        if ($blockers === []) {
            return [
                'title' => 'No autonomy blocker in the target mode; prove stability at the next horizon.',
                'scope_files' => [],
                'acceptance' => 'Run the most-autonomous command for the target horizon and certify continuation.',
            ];
        }

        $top = $blockers[0];

        // The first concrete unblock toward the operator's goal: the pre-authorized
        // integration-lane envelope for Atlas Dev cross-system work.
        if (in_array($top['stage'], ['merge_target', 'scope_admission'], true)) {
            return [
                'title' => 'Build the pre-authorized weekly autonomy envelope: admit cross-system Atlas Dev AAEOS work and route its merge to a governed integration lane (never blind main), with a one-time standing policy (area, duration, budget, allowed providers, risk ceiling, forbidden actions, merge target = integration lane, quality criteria).',
                'first_small_slice' => 'Introduce a StewardshipAutonomyEnvelope value object + a new scope-admission profile (e.g. aaeos_dev_integration_lane) inside AreaFocusLoop/ that (a) admits cross-system atlas_dev findings ONLY when the envelope sets merge target = integration lane, and (b) forces the merge target to the integration lane (AP-782/783), never main. Pure admission+routing change; no provider, no new Forge runtime.',
                'scope_files' => [
                    'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
                    'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionServiceTest.php',
                ],
                'acceptance' => 'Under the envelope profile, a cross-system atlas_dev finding is ADMITTED (not rejected) AND its merge target resolves to the integration lane, never main; factory_max behavior is unchanged when no envelope is set; cross-system auto-merge to main remains forbidden.',
                'depends_on_blocker' => $top['stage'],
            ];
        }

        return [
            'title' => 'Eliminate the highest-impact blocker: '.$top['title'],
            'remediation' => $top['remediation'],
            'scope_hint' => $top['evidence'],
            'acceptance' => 'The stage classifies as autonomous or policy_pre_authorized after the change, proven by tests.',
            'depends_on_blocker' => $top['stage'],
        ];
    }

    /**
     * The most autonomous command that is SAFE to run RIGHT NOW (honest about
     * what it will and will not do).
     *
     * @param  array<string,array<string,mixed>>  $modes
     * @return array<string,mixed>
     */
    private function mostAutonomousCommandNow(string $area, string $focus, array $modes): array
    {
        $factory = $modes[self::MODE_FACTORY_SCOPED];
        $devLane = $modes[self::MODE_AAEOS_DEV_LANE];

        // The AAEOS dev-lane mode is the goal, but until its envelope exists the
        // only fully-safe autonomous mode is factory-scoped self-improvement.
        $devLaneReady = $devLane['blocking_stage_keys'] === [];

        if ($devLaneReady) {
            return [
                'mode' => self::MODE_AAEOS_DEV_LANE,
                'command' => sprintf(
                    'php artisan atlas:software-company-stewardship:reliable-24h-loop --area=%s --focus=%s --scope-profile=aaeos_dev_integration_lane --repo-root=$(pwd) --execute --multi-agent-workcell --max-cycles=12 --record --json',
                    $area,
                    $focus,
                ),
                'caveat' => 'Merges to the integration lane; operator reviews+promotes weekly.',
            ];
        }

        return [
            'mode' => self::MODE_FACTORY_SCOPED,
            'command' => sprintf(
                'php artisan atlas:software-company-stewardship:reliable-24h-loop --area=%s --focus=%s --scope-profile=factory_max --repo-root=$(pwd) --execute --auto-merge --allow-code-auto-merge --multi-agent-workcell --cleanup-worktrees --continue-on-blocked --max-cycles=12 --max-merges=10 --record --json',
                $area,
                $focus,
            ),
            'caveat' => 'HONEST LIMIT: this only safely auto-merges factory-scoped loop self-improvement. With the current finding engine it will mostly produce honest blocks/quarantine (vague seeds), NOT a stream of high-value AAEOS merges. The AAEOS dev-lane mode is blocked until the integration-lane envelope is built — do not expect AAEOS implementation from this command yet.',
            'factory_scoped_autonomy_score' => $factory['autonomy_score'],
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $modes
     * @param  array<string,mixed>  $signals
     * @return array<string,mixed>
     */
    private function horizonGaps(array $modes, array $signals): array
    {
        return [
            'h24' => [
                'factory_scoped' => $signals['twentyfour_hour_stability_proven'] ? 'ready (loop self-improvement; quality limited by finding engine)' : 'blocked',
                'aaeos_dev_lane' => $modes[self::MODE_AAEOS_DEV_LANE]['blocking_stage_keys'] === [] ? 'ready' : 'blocked: '.implode(',', $modes[self::MODE_AAEOS_DEV_LANE]['blocking_stage_keys']),
            ],
            'd7' => [
                'missing' => array_values(array_filter([
                    $signals['integration_lane_autonomy_envelope_implemented'] ? null : 'integration_lane_autonomy_envelope',
                    $signals['cross_system_scope_admission_implemented'] ? null : 'cross_system_scope_admission',
                    $signals['seven_day_stability_proven'] ? null : 'seven_day_stability_proof (lease renewal, branch/worktree pollution control at scale, backlog depth)',
                ])),
            ],
            'd30' => [
                'missing' => array_values(array_filter([
                    $signals['learning_compounding_wired_into_loop'] ? null : 'learning_compounding_feedback_into_selection',
                    'self_bootstrap_next_leap_when_docs_exhausted',
                    'multi_area_parallel_loops',
                    $signals['forge_real_execution_implemented'] ? null : 'real_forge_execution_runtime_for_heaviest_work',
                ])),
            ],
        ];
    }

    /**
     * @param  array{autonomy_score:float,blocking_stage_keys:list<string>}  $primary
     * @param  list<array<string,mixed>>  $blockers
     */
    private function verdict(array $primary, array $blockers): string
    {
        if ($blockers === []) {
            return 'autonomous_in_target_mode';
        }
        $top = $blockers[0];

        return sprintf(
            'NOT autonomous in target mode (score %.2f); top blocker: %s (%s). %d blocking stage(s).',
            $primary['autonomy_score'],
            $top['stage'],
            $top['state'],
            count($blockers),
        );
    }

    private function band(float $score): string
    {
        return match (true) {
            $score >= 0.9 => 'high',
            $score >= 0.6 => 'medium',
            default => 'low',
        };
    }

    private function normalizeMode(string $mode): string
    {
        $mode = trim($mode);

        return in_array($mode, [self::MODE_FACTORY_SCOPED, self::MODE_AAEOS_DEV_LANE, self::MODE_AAEOS_FORGE], true)
            ? $mode
            : self::MODE_AAEOS_DEV_LANE;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function withoutVolatile(array $payload): array
    {
        unset($payload['certified_at'], $payload['report_hash']);

        return $payload;
    }
}
