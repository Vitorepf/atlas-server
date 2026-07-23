<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Self-Construction Runtime Implementation Roadmap — runtime.
 *
 * Turns the phased Self-Construction runtime roadmap doc into deterministic,
 * pure decision logic. The doc lays out NINE strictly sequential phases and one
 * universal "Phase Gate", and asserts a single load-bearing safety decision:
 * "Runtime begins read-only and advisory before autonomous patching." This
 * service enforces the doc's contract instead of restating it:
 *
 *  - The phase ladder is strictly sequential and no-skip:
 *      1 Documentation And Registry
 *      -> 2 Read-Only Gap Report
 *      -> 3 Meta-SDD Artifact Generator
 *      -> 4 Receipt-Scoped Task Planner
 *      -> 4.5 Traceability Guardrail
 *      -> 4.8 Promotion Gate
 *      -> 5 Low-Risk Agent Execution
 *      -> 6 Restricted Runtime Patches
 *      -> 7 Strategic Self-Construction.
 *    Advancement may only ever target the immediate next phase in this order.
 *
 *  - The "Phase Gate" section is the heart of the doc: "No phase may start until
 *    the previous phase has: tests; docs; evidence; architecture validation;
 *    explicit residual risk." This service treats those FIVE artifacts as the
 *    universal start gate; any missing artifact on the predecessor blocks the
 *    next phase from starting.
 *
 *  - Decision "Runtime begins read-only and advisory before autonomous
 *    patching": every phase up to and including 4.8 (Promotion Gate) is
 *    read-only / advisory and writes no code. Phase 5 is the first execution
 *    phase and is scoped to docs-only or test-only with "no high-risk runtime
 *    mutation". Autonomous code patching (Phase 6) and strategic self-selection
 *    (Phase 7) are explicitly later. The service exposes this boundary and
 *    refuses to call any phase before 6 a code-patching phase.
 *
 *  - frontmatter forbidden_changes ("Declarar runtime, maturidade ou prontidao
 *    sem evidencia verificavel e gates verdes"): a readiness / maturity claim is
 *    refused unless verifiable evidence is cited AND the required gate is green.
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 *
 * @see docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap.md
 */
final class AtlasRuntimeImplementationRoadmapService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.runtime_implementation_roadmap.v1';

    /**
     * The first phase that performs any execution at all. Decision in the doc:
     * "Runtime begins read-only and advisory before autonomous patching." Every
     * phase strictly before this order is read-only / advisory.
     */
    public const FIRST_EXECUTION_ORDER = 7; // Phase 5 (order 7 in the 9-rung ladder).

    /**
     * The first phase allowed to patch its own code (autonomous patching). The
     * doc places code patching at Phase 6, strictly after low-risk docs/test-only
     * execution. Nothing before this may be called a patching phase.
     */
    public const FIRST_PATCHING_ORDER = 8; // Phase 6 (order 8 in the 9-rung ladder).

    /**
     * The nine phases in their fixed, sequential order. `order` is the canonical
     * 1..9 rung index (it is dense even though phase labels include 4.5 / 4.8);
     * advancement may only ever target order N+1. `label` preserves the doc's
     * human phase number. `read_only` marks whether the phase writes no code.
     *
     * @var list<array{order:int,key:string,label:string,name:string,read_only:bool,patches_code:bool}>
     */
    public const PHASES = [
        ['order' => 1, 'key' => 'documentation_and_registry', 'label' => '1', 'name' => 'Documentation And Registry', 'read_only' => true, 'patches_code' => false],
        ['order' => 2, 'key' => 'read_only_gap_report', 'label' => '2', 'name' => 'Read-Only Gap Report', 'read_only' => true, 'patches_code' => false],
        ['order' => 3, 'key' => 'meta_sdd_artifact_generator', 'label' => '3', 'name' => 'Meta-SDD Artifact Generator', 'read_only' => true, 'patches_code' => false],
        ['order' => 4, 'key' => 'receipt_scoped_task_planner', 'label' => '4', 'name' => 'Receipt-Scoped Task Planner', 'read_only' => true, 'patches_code' => false],
        ['order' => 5, 'key' => 'traceability_guardrail', 'label' => '4.5', 'name' => 'Traceability Guardrail', 'read_only' => true, 'patches_code' => false],
        ['order' => 6, 'key' => 'promotion_gate', 'label' => '4.8', 'name' => 'Promotion Gate', 'read_only' => true, 'patches_code' => false],
        ['order' => 7, 'key' => 'low_risk_agent_execution', 'label' => '5', 'name' => 'Low-Risk Agent Execution', 'read_only' => false, 'patches_code' => false],
        ['order' => 8, 'key' => 'restricted_runtime_patches', 'label' => '6', 'name' => 'Restricted Runtime Patches', 'read_only' => false, 'patches_code' => true],
        ['order' => 9, 'key' => 'strategic_self_construction', 'label' => '7', 'name' => 'Strategic Self-Construction', 'read_only' => false, 'patches_code' => true],
    ];

    /**
     * The universal Phase Gate (doc section "Phase Gate"): the FIVE artifacts a
     * phase must have produced before the NEXT phase may start. Keyed by the
     * boolean fact the caller provides; value is the documented blocker emitted
     * when it is false.
     *
     * @var array<string,string>
     */
    public const PHASE_GATE = [
        'tests' => 'previous_phase_missing_tests',
        'docs' => 'previous_phase_missing_docs',
        'evidence' => 'previous_phase_missing_evidence',
        'architecture_validation' => 'previous_phase_missing_architecture_validation',
        'explicit_residual_risk' => 'previous_phase_missing_explicit_residual_risk',
    ];

    /**
     * Resolve a phase by its dense order index (1..9).
     *
     * @return array{order:int,key:string,label:string,name:string,read_only:bool,patches_code:bool}|null
     */
    private function phaseByOrder(int $order): ?array
    {
        foreach (self::PHASES as $phase) {
            if ($phase['order'] === $order) {
                return $phase;
            }
        }

        return null;
    }

    /**
     * Resolve a phase by its key.
     *
     * @return array{order:int,key:string,label:string,name:string,read_only:bool,patches_code:bool}|null
     */
    private function phaseByKey(string $key): ?array
    {
        $needle = strtolower(trim($key));
        foreach (self::PHASES as $phase) {
            if ($phase['key'] === $needle) {
                return $phase;
            }
        }

        return null;
    }

    /**
     * Evaluate the doc's Phase Gate for a single phase: may the phase that
     * immediately FOLLOWS $completedPhaseKey start, given the artifacts that
     * phase produced?
     *
     * "No phase may start until the previous phase has: tests; docs; evidence;
     * architecture validation; explicit residual risk." Every missing artifact
     * is its own documented blocker. The terminal phase (order 9) has no
     * successor, so there is nothing to start.
     *
     * @param  array{
     *   tests?:bool,
     *   docs?:bool,
     *   evidence?:bool,
     *   architecture_validation?:bool,
     *   explicit_residual_risk?:bool
     * }  $previousPhaseArtifacts
     * @return array{
     *   schema_version:string,
     *   previous_phase:?string,
     *   next_phase:?string,
     *   may_start_next:bool,
     *   blockers:list<string>
     * }
     */
    public function evaluatePhaseStart(string $completedPhaseKey, array $previousPhaseArtifacts): array
    {
        $previous = $this->phaseByKey($completedPhaseKey);
        if ($previous === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'previous_phase' => strtolower(trim($completedPhaseKey)),
                'next_phase' => null,
                'may_start_next' => false,
                'blockers' => ['unknown_phase_outside_documented_ladder'],
            ];
        }

        $next = $this->phaseByOrder($previous['order'] + 1);
        if ($next === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'previous_phase' => $previous['key'],
                'next_phase' => null,
                'may_start_next' => false,
                'blockers' => ['terminal_phase_has_no_successor'],
            ];
        }

        $blockers = [];
        foreach (self::PHASE_GATE as $artifact => $blocker) {
            if (! (bool) ($previousPhaseArtifacts[$artifact] ?? false)) {
                $blockers[] = $blocker;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'previous_phase' => $previous['key'],
            'next_phase' => $next['key'],
            'may_start_next' => $blockers === [],
            'blockers' => $blockers,
        ];
    }

    /**
     * Decide whether a proposed advancement from $fromPhaseKey to $toPhaseKey is
     * permitted by the sequential no-skip rule AND the Phase Gate.
     *
     * Rules enforced:
     *  - The target must be exactly one rung ahead (order+1); any larger jump is
     *    a forbidden skip; backwards / same is not an advance.
     *  - Every prior phase (orders < target) must be reported complete.
     *  - The Phase Gate must pass on the source phase: the source must have
     *    produced tests + docs + evidence + architecture validation + explicit
     *    residual risk before the next phase may start.
     *
     * @param  list<string>  $completedPhaseKeys  phase keys already complete
     * @param  array{
     *   tests?:bool,
     *   docs?:bool,
     *   evidence?:bool,
     *   architecture_validation?:bool,
     *   explicit_residual_risk?:bool
     * }  $sourcePhaseArtifacts  artifacts the source phase produced
     * @return array{
     *   schema_version:string,
     *   from_phase:?string,
     *   to_phase:?string,
     *   verdict:string,
     *   allowed:bool,
     *   reason:?string,
     *   gate_blockers:list<string>
     * }
     */
    public function evaluateAdvancement(string $fromPhaseKey, string $toPhaseKey, array $completedPhaseKeys = [], array $sourcePhaseArtifacts = []): array
    {
        $from = $this->phaseByKey($fromPhaseKey);
        $to = $this->phaseByKey($toPhaseKey);

        if ($from === null || $to === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'from_phase' => $from['key'] ?? null,
                'to_phase' => $to['key'] ?? null,
                'verdict' => 'rejected',
                'allowed' => false,
                'reason' => 'unknown_phase_outside_documented_ladder',
                'gate_blockers' => [],
            ];
        }

        if ($to['order'] <= $from['order']) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'from_phase' => $from['key'],
                'to_phase' => $to['key'],
                'verdict' => 'rejected',
                'allowed' => false,
                'reason' => 'not_a_forward_advancement',
                'gate_blockers' => [],
            ];
        }

        if ($to['order'] !== $from['order'] + 1) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'from_phase' => $from['key'],
                'to_phase' => $to['key'],
                'verdict' => 'rejected',
                'allowed' => false,
                'reason' => 'forbidden_phase_skip',
                'gate_blockers' => [],
            ];
        }

        // Every prior phase must be complete before this advance (sequential gate).
        $completed = array_map(static fn (string $k): string => strtolower(trim($k)), $completedPhaseKeys);
        foreach (self::PHASES as $phase) {
            if ($phase['order'] < $to['order'] && ! in_array($phase['key'], $completed, true)) {
                return [
                    'schema_version' => self::SCHEMA_VERSION,
                    'from_phase' => $from['key'],
                    'to_phase' => $to['key'],
                    'verdict' => 'blocked',
                    'allowed' => false,
                    'reason' => 'prior_phase_incomplete:' . $phase['key'],
                    'gate_blockers' => [],
                ];
            }
        }

        // Phase Gate on the source: no next phase starts until the previous phase
        // has tests + docs + evidence + architecture validation + residual risk.
        $gate = $this->evaluatePhaseStart($from['key'], $sourcePhaseArtifacts);
        if (! $gate['may_start_next']) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'from_phase' => $from['key'],
                'to_phase' => $to['key'],
                'verdict' => 'blocked',
                'allowed' => false,
                'reason' => 'phase_gate_not_satisfied',
                'gate_blockers' => $gate['blockers'],
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'from_phase' => $from['key'],
            'to_phase' => $to['key'],
            'verdict' => 'allowed',
            'allowed' => true,
            'reason' => null,
            'gate_blockers' => [],
        ];
    }

    /**
     * Classify a single phase against the "read-only before autonomous patching"
     * decision: is the phase read-only/advisory, does it execute at all, and may
     * it patch its own code? Enforces the doc's ordering boundary so callers
     * cannot mislabel an early phase as an execution or patching phase.
     *
     * @return array{
     *   schema_version:string,
     *   phase:?string,
     *   phase_label:?string,
     *   order:?int,
     *   read_only_advisory:bool,
     *   executes:bool,
     *   may_patch_code:bool,
     *   reason:?string
     * }
     */
    public function classifyExecution(string $phaseKey): array
    {
        $phase = $this->phaseByKey($phaseKey);
        if ($phase === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'phase' => null,
                'phase_label' => null,
                'order' => null,
                'read_only_advisory' => false,
                'executes' => false,
                'may_patch_code' => false,
                'reason' => 'unknown_phase_outside_documented_ladder',
            ];
        }

        $executes = $phase['order'] >= self::FIRST_EXECUTION_ORDER;
        $mayPatch = $phase['order'] >= self::FIRST_PATCHING_ORDER;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'phase' => $phase['key'],
            'phase_label' => $phase['label'],
            'order' => $phase['order'],
            'read_only_advisory' => ! $executes,
            'executes' => $executes,
            'may_patch_code' => $mayPatch,
            'reason' => $executes
                ? ($mayPatch ? 'patching_phase_autonomous_writes_in_scope' : 'execution_phase_docs_or_test_only_no_high_risk_mutation')
                : 'read_only_advisory_before_autonomous_patching',
        ];
    }

    /**
     * Enforce frontmatter forbidden_changes: "Declarar runtime, maturidade ou
     * prontidao sem evidencia verificavel e gates verdes." A readiness / maturity
     * claim is refused unless BOTH a verifiable evidence reference is cited AND
     * the required gate is green.
     *
     * @param  array{evidence_ref?:string|null,gate_status?:string|null}  $facts
     * @return array{
     *   schema_version:string,
     *   claim_allowed:bool,
     *   blockers:list<string>
     * }
     */
    public function claimGuard(array $facts): array
    {
        $blockers = [];

        $evidenceRef = trim((string) ($facts['evidence_ref'] ?? ''));
        if ($evidenceRef === '') {
            $blockers[] = 'no_readiness_claim_without_verifiable_evidence';
        }

        // required_tests / quality_gates: "php artisan atlas:engineering:knowledge
        // docs-health --json" must report ok. Only an explicit green status clears.
        $gateStatus = strtolower(trim((string) ($facts['gate_status'] ?? '')));
        if (! in_array($gateStatus, ['ok', 'green', 'passed'], true)) {
            $blockers[] = 'no_readiness_claim_without_green_gates';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'claim_allowed' => $blockers === [],
            'blockers' => $blockers,
        ];
    }

    /**
     * The read-only maturity posture asserted by the doc: runtime begins
     * read-only/advisory; autonomous self-programming is not enabled; the
     * read-only boundary holds through the Promotion Gate (Phase 4.8 / order 6).
     *
     * @return array{
     *   schema_version:string,
     *   runtime_begins_read_only_advisory:bool,
     *   autonomous_patching_enabled:bool,
     *   read_only_boundary_through_order:int,
     *   first_execution_phase:string,
     *   first_patching_phase:string,
     *   phases:list<array{order:int,key:string,label:string,name:string,read_only:bool,patches_code:bool}>
     * }
     */
    public function maturity(): array
    {
        $firstExecution = $this->phaseByOrder(self::FIRST_EXECUTION_ORDER);
        $firstPatching = $this->phaseByOrder(self::FIRST_PATCHING_ORDER);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'runtime_begins_read_only_advisory' => true,
            'autonomous_patching_enabled' => false,
            'read_only_boundary_through_order' => self::FIRST_EXECUTION_ORDER - 1,
            'first_execution_phase' => (string) ($firstExecution['key'] ?? ''),
            'first_patching_phase' => (string) ($firstPatching['key'] ?? ''),
            'phases' => self::PHASES,
        ];
    }

    /**
     * Primary entry point: a single governance snapshot of the roadmap contract,
     * with worked examples that demonstrate each enforced rule.
     *
     * @return array{
     *   schema_version:string,
     *   maturity:array<string,mixed>,
     *   phase_gate:array<string,string>,
     *   skip_example:array<string,mixed>,
     *   gate_blocked_example:array<string,mixed>,
     *   legal_advance_example:array<string,mixed>,
     *   read_only_phase_example:array<string,mixed>,
     *   patching_phase_example:array<string,mixed>,
     *   claim_guard_example:array<string,mixed>
     * }
     */
    public function snapshot(): array
    {
        // A complete-artifacts set that satisfies the Phase Gate.
        $fullArtifacts = [
            'tests' => true,
            'docs' => true,
            'evidence' => true,
            'architecture_validation' => true,
            'explicit_residual_risk' => true,
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'maturity' => $this->maturity(),
            'phase_gate' => self::PHASE_GATE,
            // Worked example: Phase 1 -> Phase 3 skips Phase 2 (forbidden skip).
            'skip_example' => $this->evaluateAdvancement('documentation_and_registry', 'meta_sdd_artifact_generator'),
            // Worked example: Read-Only Gap Report -> Meta-SDD, prior phases done,
            // but the source phase is missing its evidence + residual risk: blocked.
            'gate_blocked_example' => $this->evaluateAdvancement(
                'read_only_gap_report',
                'meta_sdd_artifact_generator',
                ['documentation_and_registry', 'read_only_gap_report'],
                ['tests' => true, 'docs' => true, 'evidence' => false, 'architecture_validation' => true, 'explicit_residual_risk' => false],
            ),
            // Worked example: same advance with a full Phase Gate is allowed.
            'legal_advance_example' => $this->evaluateAdvancement(
                'read_only_gap_report',
                'meta_sdd_artifact_generator',
                ['documentation_and_registry', 'read_only_gap_report'],
                $fullArtifacts,
            ),
            // Worked example: the Promotion Gate (4.8) is still read-only/advisory.
            'read_only_phase_example' => $this->classifyExecution('promotion_gate'),
            // Worked example: Restricted Runtime Patches (6) is the first patching phase.
            'patching_phase_example' => $this->classifyExecution('restricted_runtime_patches'),
            // Worked example: a readiness claim with no evidence and a non-green gate is refused.
            'claim_guard_example' => $this->claimGuard(['evidence_ref' => '', 'gate_status' => 'unknown']),
        ];
    }
}
