<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas SDD Implementation Roadmap — runtime.
 *
 * Turns the phased Spec-Driven-Development roadmap doc into deterministic, pure
 * decision logic. The doc lays out FOUR strictly ordered build phases, each with
 * its own documented deliverables, plus a "First Safe Block" that fixes the only
 * read-only preview pipeline Atlas may run before any of the executing phases
 * exist. This service enforces the doc's contract instead of restating it:
 *
 *  - The phase ladder is strictly sequential and no-skip:
 *      1 Foundation
 *      -> 2 Auto-Spec
 *      -> 3 Controlled Execution
 *      -> 4 State Of Art.
 *    Advancement may only ever target the immediate next phase; a skip, a
 *    backwards move or a same-phase move is rejected, and every prior phase must
 *    be complete before the target may start.
 *
 *  - Phase <-> deliverable ownership: every documented deliverable (Spec
 *    Compiler, Plan Compiler, Spec Graph, ...) belongs to exactly one phase. A
 *    deliverable may not be built until its owning phase's predecessors are all
 *    complete — e.g. "Plan Compiler" (Phase 3) requires Phases 1 and 2 done.
 *
 *  - "First Safe Block" — the read-only SDD preview is the fixed ordered stage
 *    sequence "raw intent -> context summary -> draft spec -> assumptions ->
 *    questions -> no code". The load-bearing invariant is the terminal "no code":
 *    the preview must not write code or grant runtime any new autonomy. A trace
 *    that runs the stages out of order, omits a stage, or emits code / claims
 *    execution authority is REJECTED. The doc's own justification — "this reduces
 *    future risk without giving runtime new autonomy" — is the gate.
 *
 *  - frontmatter forbidden_changes ("Declarar runtime, maturidade ou prontidao
 *    sem evidencia verificavel e gates verdes"): a runtime / maturity / readiness
 *    / phase-promotion claim is refused unless verifiable evidence is cited AND
 *    the required gate ("php artisan atlas:engineering:knowledge docs-health
 *    --json") reports ok/green.
 *
 * Stateless and DB-free: every method is a pure function of its arguments. The
 * service never mutates code, calls a provider, runs a gate or touches the DB.
 *
 * @see docs/engineering-knowledge-base/spec-operating-system/implementation-roadmap.md
 */
final class AtlasSddImplementationRoadmapService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.sdd_implementation_roadmap.v1';

    /**
     * The four build phases in their fixed, sequential order. `order` is the
     * canonical 1..4 rung index (advancement may only ever target order N+1).
     * `key` is the stable phase key, `name` preserves the doc's heading,
     * `deliverables` is the exact list the doc assigns to the phase, and
     * `executes` marks phases that grant the runtime executing authority (Phase 1
     * is read-only/foundation; Phase 2 produces specs; Phases 3 and 4 execute).
     *
     * @var list<array{order:int,key:string,name:string,deliverables:list<string>,executes:bool}>
     */
    public const PHASES = [
        [
            'order' => 1,
            'key' => 'foundation',
            'name' => 'Foundation',
            'deliverables' => [
                'confirm_canonical_source_of_truth',
                'map_programming_harness_to_sdd_docs',
                'read_only_sdd_packet_schema_tests',
                'context_discovery_preview',
            ],
            'executes' => false,
        ],
        [
            'order' => 2,
            'key' => 'auto_spec',
            'name' => 'Auto-Spec',
            'deliverables' => [
                'spec_compiler',
                'spec_critic',
                'assumption_ledger',
                'clarification_gate',
                'spec_registry_read_model',
            ],
            'executes' => false,
        ],
        [
            'order' => 3,
            'key' => 'controlled_execution',
            'name' => 'Controlled Execution',
            'deliverables' => [
                'plan_compiler',
                'task_compiler',
                'decision_receipt_extension',
                'allowed_files_enforcement',
                'quality_gate_runner',
                'evidence_traceability',
            ],
            'executes' => true,
        ],
        [
            'order' => 4,
            'key' => 'state_of_art',
            'name' => 'State Of Art',
            'deliverables' => [
                'spec_graph',
                'spec_drift_detector',
                'learning_proposals',
                'versioned_context_packages',
                'low_risk_auto_patch_pr_flow',
            ],
            'executes' => true,
        ],
    ];

    /**
     * "First Safe Block" — the fixed, ordered read-only preview stages. A valid
     * preview trace is exactly this sequence, in this order, ending in "no_code".
     *
     * @var list<string>
     */
    public const SAFE_BLOCK_STAGES = [
        'raw_intent',
        'context_summary',
        'draft_spec',
        'assumptions',
        'questions',
        'no_code',
    ];

    /** The terminal stage of the First Safe Block — the "no code" invariant. */
    public const SAFE_BLOCK_TERMINAL = 'no_code';

    /**
     * The ordered ladder as an evidence list: order, key, name and the count of
     * documented deliverables each phase owns.
     *
     * @return list<array{order:int,key:string,name:string,executes:bool,deliverable_count:int}>
     */
    public function phases(): array
    {
        $out = [];
        foreach (self::PHASES as $phase) {
            $out[] = [
                'order' => $phase['order'],
                'key' => $phase['key'],
                'name' => $phase['name'],
                'executes' => $phase['executes'],
                'deliverable_count' => count($phase['deliverables']),
            ];
        }

        return $out;
    }

    /**
     * Resolve a phase by its key OR its 1-based order (as a numeric string),
     * case-insensitive.
     *
     * @return array{order:int,key:string,name:string,deliverables:list<string>,executes:bool}|null
     */
    private function phaseByKey(string $key): ?array
    {
        $needle = strtolower(trim($key));
        foreach (self::PHASES as $phase) {
            if ($phase['key'] === $needle || (string) $phase['order'] === $needle) {
                return $phase;
            }
        }

        return null;
    }

    /**
     * Locate the owning phase of a documented deliverable.
     *
     * @return array{order:int,key:string,name:string,deliverables:list<string>,executes:bool}|null
     */
    private function phaseOfDeliverable(string $deliverable): ?array
    {
        $needle = strtolower(trim($deliverable));
        foreach (self::PHASES as $phase) {
            if (in_array($needle, $phase['deliverables'], true)) {
                return $phase;
            }
        }

        return null;
    }

    /**
     * Decide whether a proposed advancement from $fromPhase to $toPhase is
     * permitted by the doc's ordering contract.
     *
     * Rules enforced:
     *  - The target must be exactly one rung ahead (order+1); any larger jump is a
     *    forbidden skip; backwards / same is not an advance.
     *  - Every prior phase (orders < target) must be reported complete (the doc is
     *    a strictly phased path: Foundation, then Auto-Spec, then Controlled
     *    Execution, then State Of Art).
     *
     * @param  list<string>  $completedPhases  phase keys (or order strings) already complete
     * @return array{
     *   schema_version:string,
     *   from_phase:?string,
     *   to_phase:?string,
     *   verdict:string,
     *   allowed:bool,
     *   reason:?string,
     *   blockers:list<string>
     * }
     */
    public function evaluateAdvancement(string $fromPhase, string $toPhase, array $completedPhases = []): array
    {
        $from = $this->phaseByKey($fromPhase);
        $to = $this->phaseByKey($toPhase);

        if ($from === null || $to === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'from_phase' => $from['key'] ?? null,
                'to_phase' => $to['key'] ?? null,
                'verdict' => 'rejected',
                'allowed' => false,
                'reason' => 'unknown_phase_outside_documented_roadmap',
                'blockers' => [],
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
                'blockers' => [],
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
                'blockers' => [],
            ];
        }

        $completed = array_values(array_filter(array_map(
            fn (string $k): ?string => $this->phaseByKey($k)['key'] ?? null,
            $completedPhases,
        )));

        $blockers = [];
        foreach (self::PHASES as $phase) {
            if ($phase['order'] < $to['order'] && ! in_array($phase['key'], $completed, true)) {
                $blockers[] = 'prior_phase_incomplete:' . $phase['key'];
            }
        }

        $allowed = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'from_phase' => $from['key'],
            'to_phase' => $to['key'],
            'verdict' => $allowed ? 'allowed' : 'blocked',
            'allowed' => $allowed,
            'reason' => $allowed ? null : $blockers[0],
            'blockers' => $blockers,
        ];
    }

    /**
     * Decide whether a named deliverable may be built now, given the phases
     * already complete. A deliverable's owning phase's predecessors must all be
     * complete (you cannot build "plan_compiler" — Phase 3 — until Phases 1 and 2
     * are done). The owning phase itself need not be complete (it is the phase the
     * deliverable belongs to / is being built in).
     *
     * @param  list<string>  $completedPhases  phase keys (or order strings) already complete
     * @return array{
     *   schema_version:string,
     *   deliverable:string,
     *   owning_phase:?string,
     *   owning_phase_order:?int,
     *   buildable:bool,
     *   reason:?string,
     *   missing_prerequisite_phases:list<string>
     * }
     */
    public function evaluateDeliverable(string $deliverable, array $completedPhases = []): array
    {
        $phase = $this->phaseOfDeliverable($deliverable);
        if ($phase === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'deliverable' => strtolower(trim($deliverable)),
                'owning_phase' => null,
                'owning_phase_order' => null,
                'buildable' => false,
                'reason' => 'unknown_deliverable_outside_documented_roadmap',
                'missing_prerequisite_phases' => [],
            ];
        }

        $completed = array_values(array_filter(array_map(
            fn (string $k): ?string => $this->phaseByKey($k)['key'] ?? null,
            $completedPhases,
        )));

        $missing = [];
        foreach (self::PHASES as $candidate) {
            if ($candidate['order'] < $phase['order'] && ! in_array($candidate['key'], $completed, true)) {
                $missing[] = $candidate['key'];
            }
        }

        $buildable = $missing === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'deliverable' => strtolower(trim($deliverable)),
            'owning_phase' => $phase['key'],
            'owning_phase_order' => $phase['order'],
            'buildable' => $buildable,
            'reason' => $buildable ? null : 'prerequisite_phase_incomplete',
            'missing_prerequisite_phases' => $missing,
        ];
    }

    /**
     * Validate a proposed "First Safe Block" preview run against the doc's fixed
     * read-only pipeline. The doc states the preview is exactly:
     *
     *   raw intent -> context summary -> draft spec -> assumptions -> questions -> no code
     *
     * and that it must reduce future risk "without giving runtime new autonomy".
     * So a valid preview:
     *   - runs exactly the documented stages, in the documented order;
     *   - ends in the terminal "no_code" stage;
     *   - emits no code and claims no execution authority.
     *
     * @param  list<string>  $observedStages  the stages the preview actually ran, in order
     * @param  array{emits_code?:bool,claims_execution_authority?:bool}  $effects
     * @return array{
     *   schema_version:string,
     *   verdict:string,
     *   valid:bool,
     *   stage_order_ok:bool,
     *   terminal_is_no_code:bool,
     *   emits_code:bool,
     *   grants_runtime_autonomy:bool,
     *   violations:list<string>,
     *   expected_stages:list<string>
     * }
     */
    public function validateSafeBlock(array $observedStages, array $effects = []): array
    {
        $stages = [];
        foreach ($observedStages as $stage) {
            if (is_string($stage) && trim($stage) !== '') {
                $stages[] = strtolower(trim($stage));
            }
        }

        $violations = [];

        $stageOrderOk = $stages === self::SAFE_BLOCK_STAGES;
        if (! $stageOrderOk) {
            $violations[] = 'safe_block_stage_sequence_violation';
        }

        $terminalIsNoCode = $stages !== [] && end($stages) === self::SAFE_BLOCK_TERMINAL;
        if (! $terminalIsNoCode) {
            $violations[] = 'safe_block_must_end_in_no_code';
        }

        $emitsCode = ($effects['emits_code'] ?? false) === true;
        if ($emitsCode) {
            $violations[] = 'safe_block_must_not_emit_code';
        }

        $claimsAuthority = ($effects['claims_execution_authority'] ?? false) === true;
        if ($claimsAuthority) {
            $violations[] = 'safe_block_must_not_grant_runtime_autonomy';
        }

        $valid = $violations === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'verdict' => $valid ? 'allowed' : 'blocked',
            'valid' => $valid,
            'stage_order_ok' => $stageOrderOk,
            'terminal_is_no_code' => $terminalIsNoCode,
            'emits_code' => $emitsCode,
            'grants_runtime_autonomy' => $claimsAuthority,
            'violations' => $violations,
            'expected_stages' => self::SAFE_BLOCK_STAGES,
        ];
    }

    /**
     * Enforce frontmatter forbidden_changes: "Declarar runtime, maturidade ou
     * prontidao sem evidencia verificavel e gates verdes." A runtime / maturity /
     * readiness / phase-promotion claim is refused unless BOTH a verifiable
     * evidence reference is cited AND the required gate is green.
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
     * Primary entry point: a single governance snapshot of the roadmap contract,
     * with worked examples that demonstrate each enforced rule.
     *
     * @return array{
     *   schema_version:string,
     *   principle:string,
     *   phases:list<array<string,mixed>>,
     *   safe_block_stages:list<string>,
     *   skip_example:array<string,mixed>,
     *   prior_incomplete_example:array<string,mixed>,
     *   legal_advance_example:array<string,mixed>,
     *   deliverable_blocked_example:array<string,mixed>,
     *   deliverable_buildable_example:array<string,mixed>,
     *   safe_block_valid_example:array<string,mixed>,
     *   safe_block_rejected_example:array<string,mixed>,
     *   claim_guard_example:array<string,mixed>
     * }
     */
    public function snapshot(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'principle' => 'Build SDD in phases; the only pre-execution preview is read-only and ends in no code.',
            'phases' => $this->phases(),
            'safe_block_stages' => self::SAFE_BLOCK_STAGES,
            // Worked example: Foundation -> Controlled Execution skips Auto-Spec.
            'skip_example' => $this->evaluateAdvancement('foundation', 'controlled_execution'),
            // Worked example: Auto-Spec -> Controlled Execution with Foundation not done.
            'prior_incomplete_example' => $this->evaluateAdvancement(
                'auto_spec',
                'controlled_execution',
                ['auto_spec'], // Foundation missing.
            ),
            // Worked example: a clean single-step advance with all priors complete.
            'legal_advance_example' => $this->evaluateAdvancement(
                'auto_spec',
                'controlled_execution',
                ['foundation', 'auto_spec'],
            ),
            // Worked example: Plan Compiler (Phase 3) before Phase 2 is done.
            'deliverable_blocked_example' => $this->evaluateDeliverable(
                'plan_compiler',
                ['foundation'], // Auto-Spec missing.
            ),
            // Worked example: Spec Compiler (Phase 2) with Foundation complete.
            'deliverable_buildable_example' => $this->evaluateDeliverable(
                'spec_compiler',
                ['foundation'],
            ),
            // Worked example: the documented read-only preview, in order, no code.
            'safe_block_valid_example' => $this->validateSafeBlock(self::SAFE_BLOCK_STAGES),
            // Worked example: a preview that emits code violates the "no code" invariant.
            'safe_block_rejected_example' => $this->validateSafeBlock(
                self::SAFE_BLOCK_STAGES,
                ['emits_code' => true],
            ),
            // Worked example: a readiness claim with no evidence and a non-green gate is refused.
            'claim_guard_example' => $this->claimGuard(['evidence_ref' => '', 'gate_status' => 'unknown']),
        ];
    }
}
