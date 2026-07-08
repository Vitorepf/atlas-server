<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Dev Efficient Programming Flow v1 · Parte 2 — pure, deterministic
 * decider for the normative slice covering sections 9–18 of the flow doc.
 *
 * This service does NOT execute anything. It encodes the documented contract as
 * a closed set of typed decisions so an agent (or any caller) can ask:
 *   - is this fast-path state transition legal? (§9 State Machine)
 *   - which initial mode does this task_kind get? (§10 Classificacao De Tarefa)
 *   - what is the operating envelope for this risk level? (§11 Risk Levels R0–R5)
 *   - which gates are non-negotiable for a write, and can adaptive remove one?
 *     (§14 Invariantes De Cert / §17 Gates Adaptativos)
 *   - what is the context char-budget for this mode, and how does overflow
 *     resolve? (§15.2 Context Budget — unit is chars, locked 2026-05-16)
 *   - what does scope guard return for a given diff? (§18 Scope Guard)
 *
 * Documented rules this code actually enforces (one-to-one with the doc):
 *   - §9: the only legal `next` states are those drawn in the documented
 *     transition graph; terminal states have no successor; gate states are a
 *     closed set {passed,failed,needs_review,skipped,waived}; final states are
 *     a closed set {completed,completed_with_risk,needs_human_review,
 *     failed_closed,promotion_preview_created,blocked}.
 *   - §10: question->read_only, patch->fast_path, repair->fast_path_repair,
 *     review->read_only (review can also fast-path), frontend->fast_path_visual,
 *     risky->plan_only_forge_preview.
 *   - §11: per-R-level max-repairs and max-state-without-Forge:
 *     R0=0 repair / passed|needs_review; R1=1 / passed; R2=1 / passed|failed;
 *     R3=2 / passed|needs_review; R4=0 patch-by-default / escalate_forge|
 *     needs_human_review; R5 Forge-only. R0–R3 are native; R5 is Forge-only;
 *     R4 may stay in Dev only as plan/review/debug or a small reversible
 *     operator-confirmed patch. No R-level may bypass mini-spec, task contract,
 *     scope guard, verification and receipt.
 *   - §14/§17: the seven non-negotiable write gates (mini_spec_before_code_gate,
 *     light_task_contract_gate, scope_guard_light, verification_gate,
 *     receipt_gate, completion_state_gate, forge_escalation_gate). Adaptive can
 *     ADD or HARDEN a gate but may NEVER remove a non-negotiable gate for a
 *     task with write.
 *   - §15.2: char budget by mode (read_only 4k–6k, small_bug 8k–12k,
 *     patch_review_debug 12k–20k, frontend_visual 16k–24k, forge_preview
 *     20k–32k, full_forge = out of fast path). Overflow policy: preserve
 *     core+code_intelligence, cut lower-priority refs, mark truncated=true,
 *     record missing_sources, escalate if a required source does not fit.
 *   - §18: forbidden file touched => blocked (completion blocked); unforeseen
 *     but defensible file => needs_review; > expected_max (default 6 files) OR
 *     3+ layers => escalate_forge; otherwise within_scope. Pre-existing user
 *     changes are flagged, not silently absorbed; scope expansion needs a new
 *     receipt, not a "touched a bit more" tag.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-02.md
 */
final class AtlasDevEfficientProgrammingFlowV1Part02Service
{
    /** Stable decision kind this decider emits. */
    public const DECISION_KIND = 'atlas_dev.efficient_programming_flow.v1.part_02';

    /** §9 — final (terminal) states. Closed set. */
    public const FINAL_STATES = [
        'completed',
        'completed_with_risk',
        'needs_human_review',
        'failed_closed',
        'promotion_preview_created',
        'blocked',
    ];

    /** §9 — gate states. Closed set. */
    public const GATE_STATES = ['passed', 'failed', 'needs_review', 'skipped', 'waived'];

    /** §14/§17 — non-negotiable gates for any write. Adaptive cannot remove these. */
    public const NON_NEGOTIABLE_WRITE_GATES = [
        'mini_spec_before_code_gate',
        'light_task_contract_gate',
        'scope_guard_light',
        'verification_gate',
        'receipt_gate',
        'completion_state_gate',
        'forge_escalation_gate',
    ];

    /** §15.2 — context budget unit is chars; locked 2026-05-16. */
    public const BUDGET_UNIT = 'chars';
    public const BUDGET_LOCKED_AT = '2026-05-16';

    /**
     * §9 — documented state-machine transition graph (source state => allowed next states).
     * Terminal states intentionally map to an empty list.
     *
     * @return array<string, list<string>>
     */
    public function transitionGraph(): array
    {
        return [
            'idle' => ['intake_received'],
            'intake_received' => ['preflight_ready', 'blocked_no_workspace', 'blocked_permissions'],
            'preflight_ready' => ['classified'],
            'blocked_no_workspace' => ['blocked'],
            'blocked_permissions' => ['blocked'],
            'classified' => ['context_budgeted'],
            'context_budgeted' => ['context_selected'],
            'context_selected' => ['code_discovery_ready'],
            'code_discovery_ready' => ['compact_sdd_ready', 'structural_spec_required'],
            'structural_spec_required' => ['compact_sdd_ready'],
            'compact_sdd_ready' => ['mini_spec_ready'],
            'mini_spec_ready' => ['task_contract_ready'],
            'task_contract_ready' => ['prompt_projected'],
            'prompt_projected' => ['route_decided'],
            'route_decided' => ['read_only_answering', 'fast_path_planning', 'forge_promotion_preview'],
            'read_only_answering' => ['provider_selected'],
            'fast_path_planning' => ['provider_selected'],
            'forge_promotion_preview' => ['promotion_preview_created', 'awaiting_human_decision'],
            'provider_selected' => ['executing'],
            'executing' => ['patch_projected', 'no_patch_needed'],
            'patch_projected' => ['scope_guarding'],
            'no_patch_needed' => ['verifying'],
            'scope_guarding' => ['verifying'],
            'verifying' => ['passed', 'needs_review', 'failed'],
            'passed' => ['completed'],
            'needs_review' => ['completed_with_risk', 'repair_planned', 'forge_promotion_preview'],
            'failed' => ['repair_planned', 'forge_promotion_preview', 'failed_closed'],
            'repair_planned' => ['repair_executing'],
            'repair_executing' => ['verifying'],
            'escalate_forge' => ['promotion_preview_created', 'awaiting_human_decision'],
            'awaiting_human_decision' => ['telemetry_emitted'],
            'promotion_preview_created' => ['telemetry_emitted'],
            'telemetry_emitted' => [],
            // terminal / final states (no successor)
            'completed' => [],
            'completed_with_risk' => [],
            'needs_human_review' => [],
            'failed_closed' => [],
            'blocked' => [],
        ];
    }

    /**
     * §9 — list legal next states for a source state.
     *
     * @return list<string>
     */
    public function nextStates(string $state): array
    {
        return $this->transitionGraph()[$state] ?? [];
    }

    public function isFinalState(string $state): bool
    {
        return in_array($state, self::FINAL_STATES, true);
    }

    public function isGateState(string $state): bool
    {
        return in_array($state, self::GATE_STATES, true);
    }

    /**
     * §9 — is `from -> to` a legal transition? Unknown source or a transition
     * not drawn in the documented graph is illegal.
     *
     * @return array{from:string,to:string,legal:bool,reason:string,allowed_next:list<string>}
     */
    public function evaluateTransition(string $from, string $to): array
    {
        $known = array_key_exists($from, $this->transitionGraph());
        $allowed = $this->nextStates($from);

        if (! $known) {
            $reason = 'unknown_source_state';
            $legal = false;
        } elseif ($this->isFinalState($from)) {
            $reason = 'source_is_terminal_final_state';
            $legal = false;
        } elseif (in_array($to, $allowed, true)) {
            $reason = 'transition_in_documented_graph';
            $legal = true;
        } else {
            $reason = 'transition_not_in_documented_graph';
            $legal = false;
        }

        return [
            'from' => $from,
            'to' => $to,
            'legal' => $legal,
            'reason' => $reason,
            'allowed_next' => $allowed,
        ];
    }

    /**
     * §10 — initial mode by task_kind.
     *
     * @return array{task_kind:string,initial_mode:string,write_allowed:bool,note:string}
     */
    public function classifyInitialMode(string $taskKind): array
    {
        $map = [
            'question' => ['read_only', false, 'explicar fluxo, localizar arquivo, ler diff'],
            'patch' => ['fast_path', true, 'bug pequeno, ajuste local'],
            'repair' => ['fast_path_repair', true, 'teste/gate falhando'],
            'review' => ['read_only', false, 'revisar diff/codigo; pode ir para fast path'],
            'frontend' => ['fast_path_visual', true, 'tela, screenshot, UI pontual; visual gate'],
            'risky' => ['plan_only_forge_preview', false, 'auth, billing, migration, prod, security'],
        ];

        [$mode, $write, $note] = $map[$taskKind] ?? ['read_only', false, 'unknown task_kind defaults to safe read_only'];

        return [
            'task_kind' => array_key_exists($taskKind, $map) ? $taskKind : 'unknown',
            'initial_mode' => $mode,
            'write_allowed' => $write,
            'note' => $note,
        ];
    }

    /**
     * §11 — operating envelope per risk level.
     *
     * @return array{
     *   risk_level:string, known:bool, max_repairs:int, max_state_without_forge:list<string>,
     *   native_to_dev:bool, forge_only:bool, default_patch_in_dev:bool, rules:list<string>
     * }
     */
    public function riskEnvelope(string $riskLevel): array
    {
        $table = [
            'R0' => [0, ['passed', 'needs_review'], true, false, true],
            'R1' => [1, ['passed'], true, false, true],
            'R2' => [1, ['passed', 'failed'], true, false, true],
            'R3' => [2, ['passed', 'needs_review'], true, false, true],
            // R4: 0 patch by default in Dev; max stop without Forge is escalate/needs_human_review.
            'R4' => [0, ['escalate_forge', 'needs_human_review'], false, false, false],
            // R5: Forge-only.
            'R5' => [0, [], false, true, false],
        ];

        $known = array_key_exists($riskLevel, $table);
        [$maxRepairs, $maxState, $native, $forgeOnly, $defaultPatch] = $table[$riskLevel] ?? [0, [], false, false, false];

        $rules = [
            'nenhum R-level burla mini-spec, task contract, scope guard, verification e receipt',
        ];
        if ($riskLevel === 'R4') {
            $rules[] = 'R4 sem Obra pode ficar em Dev como plan/review/debug ou patch pequeno reversivel confirmado pelo operador';
        }
        if ($riskLevel === 'R5') {
            $rules[] = 'R5 e Forge-only';
        }
        if (in_array($riskLevel, ['R0', 'R1', 'R2', 'R3'], true)) {
            $rules[] = 'territorio nativo do Atlas Dev';
        }

        return [
            'risk_level' => $known ? $riskLevel : 'unknown',
            'known' => $known,
            'max_repairs' => $maxRepairs,
            'max_state_without_forge' => $maxState,
            'native_to_dev' => $native,
            'forge_only' => $forgeOnly,
            'default_patch_in_dev' => $defaultPatch,
            'rules' => $rules,
        ];
    }

    /**
     * §11 — given a risk level and the repair attempt about to be tried (1-based),
     * decide whether another repair is allowed or the run must escalate/close.
     *
     * @return array{risk_level:string,attempt:int,max_repairs:int,allowed:bool,decision:string}
     */
    public function repairDecision(string $riskLevel, int $attempt): array
    {
        $env = $this->riskEnvelope($riskLevel);
        $max = $env['max_repairs'];
        $allowed = $attempt >= 1 && $attempt <= $max;

        if ($env['forge_only']) {
            $decision = 'forge_only_no_dev_repair';
        } elseif ($allowed) {
            $decision = 'repair_allowed';
        } else {
            $decision = 'repair_cap_reached_escalate_or_close';
        }

        return [
            'risk_level' => $env['risk_level'],
            'attempt' => $attempt,
            'max_repairs' => $max,
            'allowed' => $allowed,
            'decision' => $decision,
        ];
    }

    /**
     * §14/§17 — non-negotiable gates for a write, plus whether adaptive may
     * remove a given gate. Adaptive may add/harden but NEVER remove a
     * non-negotiable gate for a task that writes.
     *
     * @return array{write:bool,gates:list<string>,adaptive_may_add:bool,adaptive_may_remove_non_negotiable:bool}
     */
    public function writeGateRequirements(bool $write): array
    {
        return [
            'write' => $write,
            'gates' => $write ? self::NON_NEGOTIABLE_WRITE_GATES : [],
            'adaptive_may_add' => true,
            'adaptive_may_remove_non_negotiable' => false,
        ];
    }

    /**
     * §17 — can adaptive remove this specific gate for a task with write?
     * Never, if it is one of the non-negotiable gates.
     */
    public function canAdaptiveRemoveGate(string $gate, bool $write): bool
    {
        if ($write && in_array($gate, self::NON_NEGOTIABLE_WRITE_GATES, true)) {
            return false;
        }

        // A read-only task or a non-listed gate is not protected by this invariant.
        return ! in_array($gate, self::NON_NEGOTIABLE_WRITE_GATES, true) || ! $write;
    }

    /**
     * §15.2 — context char-budget per mode.
     *
     * @return array{mode:string,known:bool,in_fast_path:bool,min_chars:int,max_chars:int,unit:string}
     */
    public function contextBudget(string $mode): array
    {
        $table = [
            'read_only' => [4000, 6000, true],
            'small_bug' => [8000, 12000, true],
            'patch_review_debug' => [12000, 20000, true],
            'frontend_visual' => [16000, 24000, true],
            'forge_preview' => [20000, 32000, true],
            'full_forge' => [0, 0, false],
        ];

        $known = array_key_exists($mode, $table);
        [$min, $max, $inFast] = $table[$mode] ?? [0, 0, false];

        return [
            'mode' => $known ? $mode : 'unknown',
            'known' => $known,
            'in_fast_path' => $inFast,
            'min_chars' => $min,
            'max_chars' => $max,
            'unit' => self::BUDGET_UNIT,
        ];
    }

    /**
     * §15.2 — apply the overflow policy. If used chars exceed the mode's max
     * budget: preserve core+code_intelligence, cut lower-priority refs,
     * mark truncated=true, record missing_sources, escalate if a REQUIRED
     * source could not fit.
     *
     * @param  string  $mode
     * @param  int  $usedChars       chars the candidate context would consume
     * @param  list<string>  $requiredSources  sources that must be present (e.g. ['core','code_intelligence'])
     * @param  list<string>  $droppedRequired  required sources that did not fit after trimming
     * @return array{
     *   mode:string, budget_max:int, used_chars:int, within_budget:bool,
     *   truncated:bool, preserved:list<string>, missing_sources:list<string>,
     *   escalate:bool, reason:string, unit:string
     * }
     */
    public function applyBudgetOverflow(string $mode, int $usedChars, array $requiredSources = [], array $droppedRequired = []): array
    {
        $budget = $this->contextBudget($mode);
        $max = $budget['max_chars'];

        // full_forge / unknown is out of fast path: it cannot satisfy a fast-path budget.
        if (! $budget['in_fast_path']) {
            return [
                'mode' => $budget['mode'],
                'budget_max' => $max,
                'used_chars' => $usedChars,
                'within_budget' => false,
                'truncated' => false,
                'preserved' => [],
                'missing_sources' => $droppedRequired,
                'escalate' => true,
                'reason' => 'mode_out_of_fast_path',
                'unit' => self::BUDGET_UNIT,
            ];
        }

        $withinBudget = $usedChars <= $max;
        // Always-preserved tiers from the doc.
        $preserved = ['core', 'code_intelligence'];
        $missing = array_values(array_unique($droppedRequired));

        if ($withinBudget && $missing === []) {
            return [
                'mode' => $budget['mode'],
                'budget_max' => $max,
                'used_chars' => $usedChars,
                'within_budget' => true,
                'truncated' => false,
                'preserved' => $preserved,
                'missing_sources' => [],
                'escalate' => false,
                'reason' => 'fits_budget',
                'unit' => self::BUDGET_UNIT,
            ];
        }

        // Overflow OR a dropped source: we truncated. Escalate only if a REQUIRED
        // source was dropped (i.e. an obligatory source could not fit).
        $requiredDropped = array_values(array_intersect($missing, $requiredSources));
        $escalate = $requiredDropped !== [];

        return [
            'mode' => $budget['mode'],
            'budget_max' => $max,
            'used_chars' => $usedChars,
            'within_budget' => false,
            'truncated' => true,
            'preserved' => $preserved,
            'missing_sources' => $missing,
            'escalate' => $escalate,
            'reason' => $escalate ? 'required_source_did_not_fit_escalate' : 'truncated_lower_priority_refs',
            'unit' => self::BUDGET_UNIT,
        ];
    }

    /**
     * §18 — scope guard verdict for a diff against the contract.
     *
     * @param  list<string>  $touchedForbidden     touched files that are in forbidden_files
     * @param  list<string>  $touchedUnforeseen    touched files not in allowed set but defensible
     * @param  int  $touchedCount                  total files the diff touches
     * @param  int  $layersTouched                 distinct architecture layers touched
     * @param  int|null  $expectedMaxFiles         from LightTaskContract; default 6 (the "5-6" ceiling)
     * @param  list<string>  $preexistingUserChanges  user changes present before the run
     * @return array{
     *   verdict:string, blocks_completion:bool, escalate:bool, reason:string,
     *   touched_count:int, expected_max_files:int, layers_touched:int,
     *   preexisting_flagged:list<string>
     * }
     */
    public function scopeGuardVerdict(
        array $touchedForbidden,
        array $touchedUnforeseen,
        int $touchedCount,
        int $layersTouched,
        ?int $expectedMaxFiles = null,
        array $preexistingUserChanges = []
    ): array {
        $max = $expectedMaxFiles ?? 6;
        $preexisting = array_values(array_unique($preexistingUserChanges));

        // Highest-severity rule first: a forbidden file blocks completion.
        if ($touchedForbidden !== []) {
            return [
                'verdict' => 'blocked',
                'blocks_completion' => true,
                'escalate' => false,
                'reason' => 'forbidden_file_touched',
                'touched_count' => $touchedCount,
                'expected_max_files' => $max,
                'layers_touched' => $layersTouched,
                'preexisting_flagged' => $preexisting,
            ];
        }

        // Over the file ceiling OR 3+ layers tends to escalate_forge.
        if ($touchedCount > $max || $layersTouched >= 3) {
            return [
                'verdict' => 'escalate_forge',
                'blocks_completion' => true,
                'escalate' => true,
                'reason' => $touchedCount > $max ? 'over_expected_max_files' : 'three_plus_layers_touched',
                'touched_count' => $touchedCount,
                'expected_max_files' => $max,
                'layers_touched' => $layersTouched,
                'preexisting_flagged' => $preexisting,
            ];
        }

        // Touched an unforeseen-but-defensible file => needs_review.
        if ($touchedUnforeseen !== []) {
            return [
                'verdict' => 'needs_review',
                'blocks_completion' => false,
                'escalate' => false,
                'reason' => 'unforeseen_but_defensible_file',
                'touched_count' => $touchedCount,
                'expected_max_files' => $max,
                'layers_touched' => $layersTouched,
                'preexisting_flagged' => $preexisting,
            ];
        }

        return [
            'verdict' => 'within_scope',
            'blocks_completion' => false,
            'escalate' => false,
            'reason' => 'diff_within_contract',
            'touched_count' => $touchedCount,
            'expected_max_files' => $max,
            'layers_touched' => $layersTouched,
            'preexisting_flagged' => $preexisting,
        ];
    }

    /**
     * Stable manifest of the slice this decider governs (for the command/probe).
     *
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        return [
            'decision_kind' => self::DECISION_KIND,
            'doc' => 'docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-02.md',
            'sections' => [
                '9_state_machine',
                '10_task_classification',
                '11_risk_levels_r0_r5',
                '14_cert_invariants',
                '15_2_context_budget_chars',
                '17_adaptive_gates',
                '18_scope_guard',
            ],
            'final_states' => self::FINAL_STATES,
            'gate_states' => self::GATE_STATES,
            'non_negotiable_write_gates' => self::NON_NEGOTIABLE_WRITE_GATES,
            'budget_unit' => self::BUDGET_UNIT,
            'budget_locked_at' => self::BUDGET_LOCKED_AT,
        ];
    }
}
