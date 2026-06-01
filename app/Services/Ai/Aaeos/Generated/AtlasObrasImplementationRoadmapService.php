<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Obras - Implementation Roadmap — runtime.
 *
 * Turns the phased Obras roadmap doc into deterministic, pure decision logic.
 * The doc lays out SIX strictly ordered build phases (A..F), each mapping to a
 * patamar L0..L5, and asserts a "Principle" plus three load-bearing ordering
 * decisions from the frontmatter. This service enforces the doc's contract
 * instead of restating it:
 *
 *  - The phase ladder is strictly sequential and no-skip:
 *      A Foundation (L0)
 *      -> B Workspace Vivo (L1)
 *      -> C Enterprise Core (L2)
 *      -> D ObraOS (L3)
 *      -> E Foundry (L4)
 *      -> F Sovereign OS (L5).
 *    Advancement may only ever target the immediate next phase in this order.
 *
 *  - Decision "L0-L2 must be solid before ObraOS execution": Phase D (ObraOS)
 *    may not start until Phases A, B and C are all complete. The service treats
 *    this as a hard precondition, not advice.
 *
 *  - Decision "L5 must not be implemented before the system can produce and
 *    govern real assets": Phase F (Sovereign OS) may not start until the system
 *    can both PRODUCE real assets (Phase D ObraOS conducts an Obra to delivery,
 *    on top of Phase C Enterprise Core outputs) and GOVERN them as a portfolio
 *    (Phase E Foundry). The service refuses an F start while C, D or E is open.
 *
 *  - "MVP Warning" — the "may not" list is a hard validation gate. An MVP that
 *    uses a TCC-only model, stores the Obra only as Markdown, omits the next
 *    step, omits structure nodes, creates AI sessions without `obra_id`, or
 *    claims completion without an output or explicit closure is REJECTED. The
 *    "may" list is the allowed minimum scope (informational).
 *
 *  - "First Pilot Recommendation" — the recommended first serious pilot is the
 *    Atlas Self-Construction OS, qualified by five documented reasons (docs,
 *    APs, tests, gates and outputs already exist; complex enough to prove value;
 *    directly improves Atlas construction; avoids narrowing Obras to TCC). A
 *    pilot candidate is only "recommended" when every documented reason holds.
 *
 *  - frontmatter forbidden_changes ("Declarar runtime, maturidade ou prontidao
 *    sem evidencia verificavel e gates verdes"): a readiness / maturity / phase-
 *    promotion claim is refused unless verifiable evidence is cited AND the
 *    required gate is green.
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 *
 * @see docs/engineering-knowledge-base/obras/implementation-roadmap.md
 */
final class AtlasObrasImplementationRoadmapService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.obras_implementation_roadmap.v1';

    /**
     * The six build phases in their fixed, sequential order. `order` is the
     * canonical 1..6 rung index; advancement may only ever target order N+1.
     * `key` is the doc's phase letter (lower-cased name), `letter` preserves the
     * doc's "Phase A..F" label, `patamar` is the L0..L5 level the phase makes
     * real, `produces_assets` marks the phases that let the system produce real
     * assets, `governs_assets` marks the phase that governs assets as portfolio.
     *
     * @var list<array{order:int,key:string,letter:string,name:string,patamar:string,goal:string,produces_assets:bool,governs_assets:bool}>
     */
    public const PHASES = [
        ['order' => 1, 'key' => 'foundation', 'letter' => 'A', 'name' => 'Foundation', 'patamar' => 'L0', 'goal' => 'Make Obras exist as a first-class Atlas entity.', 'produces_assets' => false, 'governs_assets' => false],
        ['order' => 2, 'key' => 'workspace_vivo', 'letter' => 'B', 'name' => 'Workspace Vivo', 'patamar' => 'L1', 'goal' => 'Make Obra carry context.', 'produces_assets' => false, 'governs_assets' => false],
        ['order' => 3, 'key' => 'enterprise_core', 'letter' => 'C', 'name' => 'Enterprise Core', 'patamar' => 'L2', 'goal' => 'Make Obra reliable and auditable.', 'produces_assets' => true, 'governs_assets' => false],
        ['order' => 4, 'key' => 'obraos', 'letter' => 'D', 'name' => 'ObraOS', 'patamar' => 'L3', 'goal' => 'Make Atlas conduct an Obra to delivery.', 'produces_assets' => true, 'governs_assets' => false],
        ['order' => 5, 'key' => 'foundry', 'letter' => 'E', 'name' => 'Foundry', 'patamar' => 'L4', 'goal' => 'Make Obras become composed strategy.', 'produces_assets' => true, 'governs_assets' => true],
        ['order' => 6, 'key' => 'sovereign_os', 'letter' => 'F', 'name' => 'Sovereign OS', 'patamar' => 'L5', 'goal' => "Make Obras serve Vitor's complete autonomy.", 'produces_assets' => true, 'governs_assets' => true],
    ];

    /**
     * The phase that performs ObraOS execution. Decision: "L0-L2 must be solid
     * before ObraOS execution" -> every phase strictly before this order must be
     * complete before this phase may start.
     */
    public const OBRAOS_ORDER = 4; // Phase D.

    /**
     * The phase that builds the Sovereign OS. Decision: "L5 must not be
     * implemented before the system can produce and govern real assets" -> every
     * phase strictly before this order must be complete before this phase starts.
     */
    public const SOVEREIGN_ORDER = 6; // Phase F.

    /**
     * The MVP "may not" list (doc section "MVP Warning"). Each entry maps a
     * stable rule key to the boolean violation flag a caller supplies. If the
     * flag is true the rule is VIOLATED and the MVP is rejected. These are hard
     * gates, not advice.
     *
     * @var array<string,string>
     */
    public const MVP_FORBIDDEN = [
        'uses_tcc_only_model' => 'mvp_may_not_use_tcc_only_model',
        'stores_obra_only_as_markdown' => 'mvp_may_not_store_obra_only_as_markdown',
        'omits_next_step' => 'mvp_may_not_omit_next_step',
        'omits_structure_nodes' => 'mvp_may_not_omit_structure_nodes',
        'ai_sessions_without_obra_id' => 'mvp_may_not_create_ai_sessions_without_obra_id',
        'claims_completion_without_output_or_closure' => 'mvp_may_not_claim_completion_without_output_or_closure',
    ];

    /**
     * The MVP allowed minimum scope (doc section "MVP Warning" -> "may be small").
     *
     * @var list<string>
     */
    public const MVP_ALLOWED_SCOPE = [
        'create_obra',
        'define_objective',
        'define_type_domain_status',
        'define_next_step',
        'define_structure',
        'attach_notes_tasks_sources',
    ];

    /**
     * The data-model headroom the MVP must already leave (doc -> "the data model
     * must already leave room for").
     *
     * @var list<string>
     */
    public const REQUIRED_MODEL_HEADROOM = [
        'graph_relationships',
        'evidence_ledger',
        'decisions',
        'gates',
        'outputs',
        'assets',
        'portfolio',
        'autonomy_strategy',
    ];

    /**
     * The five documented reasons the first serious pilot should be the Atlas
     * Self-Construction OS (doc -> "First Pilot Recommendation" -> "Why").
     * Each maps a stable reason key to the boolean fact a caller supplies; every
     * reason must hold for a candidate to be "recommended".
     *
     * @var array<string,string>
     */
    public const PILOT_REASONS = [
        'has_docs_aps_tests_gates_outputs' => 'it already has docs, APs, tests, gates and outputs',
        'complex_enough_to_prove_value' => 'it is complex enough to prove value',
        'directly_improves_atlas_construction' => 'it directly improves Atlas construction',
        'avoids_narrowing_obras_to_tcc' => 'it avoids narrowing Obras to TCC',
    ];

    public const RECOMMENDED_PILOT = 'Atlas Self-Construction OS';

    /**
     * Resolve a phase by its dense order index (1..6).
     *
     * @return array{order:int,key:string,letter:string,name:string,patamar:string,goal:string,produces_assets:bool,governs_assets:bool}|null
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
     * Resolve a phase by its key OR its doc letter (A..F), case-insensitive.
     *
     * @return array{order:int,key:string,letter:string,name:string,patamar:string,goal:string,produces_assets:bool,governs_assets:bool}|null
     */
    private function phaseByKey(string $key): ?array
    {
        $needle = strtolower(trim($key));
        foreach (self::PHASES as $phase) {
            if ($phase['key'] === $needle || strtolower($phase['letter']) === $needle) {
                return $phase;
            }
        }

        return null;
    }

    /**
     * The ordered ladder as an evidence list: phase letter, name, the patamar it
     * makes real and its documented goal.
     *
     * @return list<array{order:int,letter:string,key:string,name:string,patamar:string,goal:string}>
     */
    public function phases(): array
    {
        $out = [];
        foreach (self::PHASES as $phase) {
            $out[] = [
                'order' => $phase['order'],
                'letter' => $phase['letter'],
                'key' => $phase['key'],
                'name' => $phase['name'],
                'patamar' => $phase['patamar'],
                'goal' => $phase['goal'],
            ];
        }

        return $out;
    }

    /**
     * Decide whether a proposed advancement from $fromPhase to $toPhase is
     * permitted by the doc's ordering contract.
     *
     * Rules enforced:
     *  - The target must be exactly one rung ahead (order+1); any larger jump is
     *    a forbidden skip; backwards / same is not an advance.
     *  - Every prior phase (orders < target) must be reported complete
     *    (sequential, layered build — "runtime must be built in layers").
     *  - Decision gate "L0-L2 must be solid before ObraOS execution": starting
     *    ObraOS (Phase D) requires A, B, C complete. This is implied by the
     *    prior-complete rule but is surfaced as its own explicit blocker.
     *  - Decision gate "L5 must not be implemented before the system can produce
     *    and govern real assets": starting Sovereign OS (Phase F) requires the
     *    asset-producing phases (C, D) and the asset-governing phase (E) complete.
     *
     * @param  list<string>  $completedPhases  phase keys/letters already complete
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
                'reason' => 'unknown_phase_outside_documented_ladder',
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

        // Sequential layered build: every prior phase must be complete.
        foreach (self::PHASES as $phase) {
            if ($phase['order'] < $to['order'] && ! in_array($phase['key'], $completed, true)) {
                $blockers[] = 'prior_phase_incomplete:' . $phase['key'];
            }
        }

        // Decision: L0-L2 must be solid before ObraOS execution.
        if ($to['order'] === self::OBRAOS_ORDER) {
            foreach (self::PHASES as $phase) {
                if ($phase['order'] < self::OBRAOS_ORDER && ! in_array($phase['key'], $completed, true)) {
                    $blockers[] = 'obraos_requires_l0_l2_solid:' . $phase['key'];
                }
            }
        }

        // Decision: L5 must not be implemented before the system can produce and
        // govern real assets.
        if ($to['order'] === self::SOVEREIGN_ORDER) {
            foreach (self::PHASES as $phase) {
                if ($phase['order'] >= self::SOVEREIGN_ORDER) {
                    continue;
                }
                if (($phase['produces_assets'] || $phase['governs_assets']) && ! in_array($phase['key'], $completed, true)) {
                    $blockers[] = 'sovereign_requires_real_assets_produced_and_governed:' . $phase['key'];
                }
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
     * Validate an MVP scope against the doc's "MVP Warning" gate. The "may not"
     * list is enforced as hard violations; the data-model headroom list is
     * enforced because "the data model must already leave room for" graph,
     * evidence, decisions, gates, outputs, assets, portfolio and autonomy.
     *
     * @param  array{
     *   uses_tcc_only_model?:bool,
     *   stores_obra_only_as_markdown?:bool,
     *   omits_next_step?:bool,
     *   omits_structure_nodes?:bool,
     *   ai_sessions_without_obra_id?:bool,
     *   claims_completion_without_output_or_closure?:bool,
     *   model_headroom?:array<string,bool>
     * }  $mvp
     * @return array{
     *   schema_version:string,
     *   valid:bool,
     *   violations:list<string>,
     *   missing_model_headroom:list<string>,
     *   allowed_scope:list<string>
     * }
     */
    public function validateMvp(array $mvp): array
    {
        $violations = [];
        foreach (self::MVP_FORBIDDEN as $flag => $violation) {
            if ((bool) ($mvp[$flag] ?? false)) {
                $violations[] = $violation;
            }
        }

        $headroom = is_array($mvp['model_headroom'] ?? null) ? $mvp['model_headroom'] : [];
        $missingHeadroom = [];
        foreach (self::REQUIRED_MODEL_HEADROOM as $room) {
            if (! (bool) ($headroom[$room] ?? false)) {
                $missingHeadroom[] = $room;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'valid' => $violations === [] && $missingHeadroom === [],
            'violations' => $violations,
            'missing_model_headroom' => $missingHeadroom,
            'allowed_scope' => self::MVP_ALLOWED_SCOPE,
        ];
    }

    /**
     * Decide whether a proposed pilot is "recommended" per the doc's "First Pilot
     * Recommendation". The recommended pilot is the Atlas Self-Construction OS,
     * and it qualifies only when every one of the documented reasons holds.
     *
     * @param  array{
     *   has_docs_aps_tests_gates_outputs?:bool,
     *   complex_enough_to_prove_value?:bool,
     *   directly_improves_atlas_construction?:bool,
     *   avoids_narrowing_obras_to_tcc?:bool
     * }  $facts
     * @return array{
     *   schema_version:string,
     *   recommended_pilot:string,
     *   recommended:bool,
     *   missing_reasons:list<string>,
     *   satisfied_reasons:list<string>
     * }
     */
    public function evaluatePilot(array $facts): array
    {
        $missing = [];
        $satisfied = [];
        foreach (self::PILOT_REASONS as $key => $_reason) {
            if ((bool) ($facts[$key] ?? false)) {
                $satisfied[] = $key;
            } else {
                $missing[] = $key;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'recommended_pilot' => self::RECOMMENDED_PILOT,
            'recommended' => $missing === [],
            'missing_reasons' => $missing,
            'satisfied_reasons' => $satisfied,
        ];
    }

    /**
     * Enforce frontmatter forbidden_changes: "Declarar runtime, maturidade ou
     * prontidao sem evidencia verificavel e gates verdes." A readiness / maturity
     * / phase-promotion claim is refused unless BOTH a verifiable evidence
     * reference is cited AND the required gate is green.
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
     *   recommended_pilot:string,
     *   skip_example:array<string,mixed>,
     *   obraos_blocked_example:array<string,mixed>,
     *   sovereign_blocked_example:array<string,mixed>,
     *   legal_advance_example:array<string,mixed>,
     *   mvp_rejected_example:array<string,mixed>,
     *   mvp_valid_example:array<string,mixed>,
     *   pilot_recommended_example:array<string,mixed>,
     *   claim_guard_example:array<string,mixed>
     * }
     */
    public function snapshot(): array
    {
        // A complete-headroom MVP that satisfies the data-model gate.
        $fullHeadroom = [];
        foreach (self::REQUIRED_MODEL_HEADROOM as $room) {
            $fullHeadroom[$room] = true;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'principle' => 'Do not shrink the vision. Do not implement L5 before L0-L2 are real.',
            'phases' => $this->phases(),
            'recommended_pilot' => self::RECOMMENDED_PILOT,
            // Worked example: Phase A -> Phase C skips Workspace Vivo (forbidden skip).
            'skip_example' => $this->evaluateAdvancement('foundation', 'enterprise_core'),
            // Worked example: ObraOS (D) attempted before Enterprise Core (C) is done.
            'obraos_blocked_example' => $this->evaluateAdvancement(
                'enterprise_core',
                'obraos',
                ['foundation', 'workspace_vivo'], // C missing.
            ),
            // Worked example: Sovereign OS (F) attempted before Foundry (E) is done.
            'sovereign_blocked_example' => $this->evaluateAdvancement(
                'foundry',
                'sovereign_os',
                ['foundation', 'workspace_vivo', 'enterprise_core', 'obraos'], // E missing.
            ),
            // Worked example: a clean single-step advance with all priors complete.
            'legal_advance_example' => $this->evaluateAdvancement(
                'enterprise_core',
                'obraos',
                ['foundation', 'workspace_vivo', 'enterprise_core'],
            ),
            // Worked example: a TCC-only, markdown-only MVP with no next step is rejected.
            'mvp_rejected_example' => $this->validateMvp([
                'uses_tcc_only_model' => true,
                'stores_obra_only_as_markdown' => true,
                'omits_next_step' => true,
                'model_headroom' => $fullHeadroom,
            ]),
            // Worked example: a compliant MVP with full model headroom is valid.
            'mvp_valid_example' => $this->validateMvp([
                'model_headroom' => $fullHeadroom,
            ]),
            // Worked example: the Self-Construction OS pilot, all reasons satisfied.
            'pilot_recommended_example' => $this->evaluatePilot([
                'has_docs_aps_tests_gates_outputs' => true,
                'complex_enough_to_prove_value' => true,
                'directly_improves_atlas_construction' => true,
                'avoids_narrowing_obras_to_tcc' => true,
            ]),
            // Worked example: a readiness claim with no evidence and a non-green gate is refused.
            'claim_guard_example' => $this->claimGuard(['evidence_ref' => '', 'gate_status' => 'unknown']),
        ];
    }
}
