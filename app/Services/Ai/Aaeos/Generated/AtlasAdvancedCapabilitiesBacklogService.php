<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Advanced Capabilities Backlog — runtime.
 *
 * Turns the long-term power-backlog doc into deterministic, pure decision logic.
 * The doc's frontmatter is explicit that advanced capabilities "start in proposal
 * or shadow mode", that "critical behavior requires human review until evidence
 * proves safety", and that "tool synthesis must use sandbox, tests, security
 * gates and registry promotion". This service enforces exactly those governed
 * limits instead of inventing loose superpowers.
 *
 * Concrete contracts implemented (from the doc body):
 *
 *  - Backlog Families. The "Backlog Families" table lists eight families, each
 *    with a target. {@see families()} is that read model.
 *
 *  - Autonomy Ladder. The "Autonomy Ladder" table defines five levels in
 *    increasing power: shadow, proposal, assisted, governed, critical. The doc
 *    states verbatim that Critical is "never automatic without explicit policy
 *    and human approval", and the frontmatter adds "never implement high-autonomy
 *    capabilities directly from this backlog". {@see classifyLevel()} maps a level
 *    to its allowed behavior and enforces that any mutation requires at least the
 *    governed level (receipt + gates), and that critical can never run
 *    automatically. {@see ladder()} is the ordered read model.
 *
 *  - Tool Synthesis Minimum Gate. The "Tool Synthesis Minimum Gate" section says
 *    a synthesized tool needs all seven of: purpose and owner; sandbox execution;
 *    tests; security scan; registry entry; evidence event; rollback/delete path.
 *    {@see evaluateToolSynthesis()} promotes a synthesized tool ONLY when all
 *    seven requirements are present, and reports every missing one otherwise.
 *
 *  - Simulation Loop. The "Simulation Loop" section says simulation features
 *    "must close the loop with real outcomes" and that "simulation without
 *    calibration becomes fiction". {@see evaluateSimulation()} marks an
 *    uncalibrated simulation as fiction (not trustworthy) and only accepts a
 *    simulation that is closed against real outcomes.
 *
 * Stateless and DB-free: every method is a pure function of its arguments. The
 * service NEVER mutates production, calls a provider, touches money/privacy or
 * writes the database — it only classifies, gates and reports.
 *
 * @see docs/engineering-knowledge-base/evolution/advanced-capabilities-backlog.md
 */
final class AtlasAdvancedCapabilitiesBacklogService
{
    public const SCHEMA_VERSION = 'atlas.advanced_capabilities_backlog.v1';

    /**
     * The "Backlog Families" table verbatim: family -> target.
     *
     * @var array<string,string>
     */
    private const FAMILIES = [
        'zero_click_operations' => 'detect anomaly, draft fix, pass gates, ask approval',
        'tool_synthesis' => 'create missing tool in sandbox, test and promote',
        'dynamic_compute_market' => 'optimize provider/model/cost per task',
        'real_world_feedback_loop' => 'deploy, measure, learn and iterate',
        'cross_pollination' => 'transfer heuristics across domains',
        'continuous_multimodal_context' => 'use voice, screen, files and activity with privacy gates',
        'swarms_councils' => 'use multi-agent disagreement only when it improves outcome',
        'local_models' => 'use RAM/GPU/Neural Engine for privacy, latency and cost',
    ];

    /** Autonomy Ladder levels (closed set), ordered from least to most power. */
    public const LEVEL_SHADOW = 'shadow';
    public const LEVEL_PROPOSAL = 'proposal';
    public const LEVEL_ASSISTED = 'assisted';
    public const LEVEL_GOVERNED = 'governed';
    public const LEVEL_CRITICAL = 'critical';

    /**
     * The "Autonomy Ladder" table verbatim: level -> rank and allowed behavior.
     * Rank encodes the documented increasing-power order.
     *
     * @var array<string,array{rank:int,allowed:string}>
     */
    private const LADDER = [
        self::LEVEL_SHADOW => [
            'rank' => 0,
            'allowed' => 'observe and emit evidence only',
        ],
        self::LEVEL_PROPOSAL => [
            'rank' => 1,
            'allowed' => 'create plan for human review',
        ],
        self::LEVEL_ASSISTED => [
            'rank' => 2,
            'allowed' => 'execute reversible local steps',
        ],
        self::LEVEL_GOVERNED => [
            'rank' => 3,
            'allowed' => 'execute bounded tasks with receipt and gates',
        ],
        self::LEVEL_CRITICAL => [
            'rank' => 4,
            'allowed' => 'never automatic without explicit policy and human approval',
        ],
    ];

    /**
     * The minimum gate a synthesized tool must satisfy to be promoted, in the
     * documented order (the "Tool Synthesis Minimum Gate" list, 7 items).
     *
     * @var list<string>
     */
    public const TOOL_SYNTHESIS_REQUIREMENTS = [
        'purpose_and_owner',
        'sandbox_execution',
        'tests',
        'security_scan',
        'registry_entry',
        'evidence_event',
        'rollback_or_delete_path',
    ];

    /**
     * The eight backlog families with their targets (the "Backlog Families"
     * table), as an ordered read model.
     *
     * @return array{schema_version:string,count:int,families:list<array{id:string,target:string}>}
     */
    public function families(): array
    {
        $families = [];
        foreach (self::FAMILIES as $id => $target) {
            $families[] = ['id' => $id, 'target' => $target];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'count' => count($families),
            'families' => $families,
        ];
    }

    /**
     * The Autonomy Ladder as an ordered read model (least -> most power).
     *
     * @return array{schema_version:string,count:int,levels:list<array{level:string,rank:int,allowed:string}>}
     */
    public function ladder(): array
    {
        $levels = [];
        foreach (self::LADDER as $level => $row) {
            $levels[] = [
                'level' => $level,
                'rank' => $row['rank'],
                'allowed' => $row['allowed'],
            ];
        }

        usort($levels, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'count' => count($levels),
            'levels' => $levels,
        ];
    }

    /**
     * Classify an autonomy-ladder level into what it may do, and decide whether a
     * candidate action at that level may run automatically.
     *
     * Documented rules enforced:
     *  - Only shadow/proposal/assisted are reversible-or-observational and may run
     *    automatically; mutating (non-reversible / production) work requires at
     *    least the governed level (receipt + gates).
     *  - Critical is "never automatic without explicit policy and human approval":
     *    it can NEVER run automatically regardless of the mutation flag.
     *  - An unknown level is treated as critical (most restrictive) so a novel
     *    capability can never leak past the ladder by being unnamed.
     *
     * @return array{
     *   schema_version:string,
     *   level:string,
     *   known:bool,
     *   rank:int,
     *   allowed:string,
     *   mutating:bool,
     *   may_run_automatically:bool,
     *   requires_human_approval:bool,
     *   requires_receipt_and_gates:bool,
     *   reason:string
     * }
     */
    public function classifyLevel(string $level, bool $mutating = false): array
    {
        $key = strtolower(trim($level));
        $known = $key !== '' && array_key_exists($key, self::LADDER);
        $effective = $known ? $key : self::LEVEL_CRITICAL;
        $row = self::LADDER[$effective];

        $isCritical = $effective === self::LEVEL_CRITICAL;
        // Governed is the minimum rank allowed to perform mutating work.
        $hasGovernance = $row['rank'] >= self::LADDER[self::LEVEL_GOVERNED]['rank'];

        // Critical never runs automatically. A mutating action needs governance
        // (receipt + gates), and governed runs as a bounded task that itself
        // carries a receipt rather than firing fully unattended.
        $mayRunAutomatically = match (true) {
            $isCritical => false,
            $mutating => false,
            default => $row['rank'] < self::LADDER[self::LEVEL_GOVERNED]['rank'],
        };

        $reason = match (true) {
            ! $known => 'unknown_level_defaults_to_critical_human_approval',
            $isCritical => 'critical_never_automatic_requires_policy_and_human_approval',
            $mutating && ! $hasGovernance => 'mutating_action_requires_governed_receipt_and_gates',
            $mutating => 'governed_mutation_runs_as_bounded_task_with_receipt',
            default => 'reversible_or_observational_level_may_run',
        };

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'level' => $effective,
            'known' => $known,
            'rank' => $row['rank'],
            'allowed' => $row['allowed'],
            'mutating' => $mutating,
            'may_run_automatically' => $mayRunAutomatically,
            'requires_human_approval' => $isCritical || ($mutating && ! $hasGovernance),
            'requires_receipt_and_gates' => $mutating && $hasGovernance,
            'reason' => $reason,
        ];
    }

    /**
     * Evaluate the Tool Synthesis Minimum Gate. A synthesized tool may only be
     * promoted when ALL seven documented requirements are present; any missing
     * requirement blocks promotion and is reported.
     *
     * @param  array<string,bool>  $signals  requirement key -> satisfied
     * @return array{
     *   schema_version:string,
     *   promotable:bool,
     *   satisfied:list<string>,
     *   missing:list<string>,
     *   total_required:int,
     *   reason:string
     * }
     */
    public function evaluateToolSynthesis(array $signals): array
    {
        $satisfied = [];
        $missing = [];
        foreach (self::TOOL_SYNTHESIS_REQUIREMENTS as $requirement) {
            if (($signals[$requirement] ?? false) === true) {
                $satisfied[] = $requirement;
            } else {
                $missing[] = $requirement;
            }
        }

        $promotable = $missing === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'promotable' => $promotable,
            'satisfied' => $satisfied,
            'missing' => $missing,
            'total_required' => count(self::TOOL_SYNTHESIS_REQUIREMENTS),
            'reason' => $promotable
                ? 'all_seven_synthesis_requirements_satisfied_may_promote'
                : 'tool_synthesis_gate_requires_all_seven_requirements',
        ];
    }

    /**
     * Evaluate a simulation against the Simulation Loop rule: a simulation
     * "must close the loop with real outcomes" and "simulation without
     * calibration becomes fiction".
     *
     * A simulation is only trustworthy when it is BOTH closed against real
     * outcomes AND calibrated. An uncalibrated (or unclosed) simulation is
     * explicitly flagged as fiction and must not be trusted for decisions.
     *
     * @return array{
     *   schema_version:string,
     *   closed_with_real_outcomes:bool,
     *   calibrated:bool,
     *   is_fiction:bool,
     *   trustworthy:bool,
     *   reason:string
     * }
     */
    public function evaluateSimulation(bool $closedWithRealOutcomes, bool $calibrated): array
    {
        $isFiction = ! ($closedWithRealOutcomes && $calibrated);

        $reason = match (true) {
            ! $closedWithRealOutcomes && ! $calibrated => 'simulation_not_closed_and_uncalibrated_is_fiction',
            ! $closedWithRealOutcomes => 'simulation_must_close_loop_with_real_outcomes',
            ! $calibrated => 'simulation_without_calibration_becomes_fiction',
            default => 'simulation_closed_and_calibrated_is_trustworthy',
        };

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'closed_with_real_outcomes' => $closedWithRealOutcomes,
            'calibrated' => $calibrated,
            'is_fiction' => $isFiction,
            'trustworthy' => ! $isFiction,
            'reason' => $reason,
        ];
    }

    /**
     * Primary entry point: produce the full advanced-capabilities-backlog
     * governance snapshot used by the command and as a single source of the
     * doc's contract.
     *
     * @return array{
     *   schema_version:string,
     *   families:array<string,mixed>,
     *   ladder:array<string,mixed>,
     *   tool_synthesis_requirements:list<string>,
     *   critical_level_example:array<string,mixed>,
     *   tool_synthesis_example:array<string,mixed>,
     *   simulation_example:array<string,mixed>
     * }
     */
    public function snapshot(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'families' => $this->families(),
            'ladder' => $this->ladder(),
            'tool_synthesis_requirements' => self::TOOL_SYNTHESIS_REQUIREMENTS,
            // Worked example: critical never runs automatically.
            'critical_level_example' => $this->classifyLevel(self::LEVEL_CRITICAL, true),
            // Worked example: a half-filled synthesis gate is blocked.
            'tool_synthesis_example' => $this->evaluateToolSynthesis([
                'purpose_and_owner' => true,
                'sandbox_execution' => true,
                'tests' => true,
            ]),
            // Worked example: an uncalibrated simulation is fiction.
            'simulation_example' => $this->evaluateSimulation(true, false),
        ];
    }
}
