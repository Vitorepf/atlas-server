<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

use App\Services\Ai\Aaeos\Support\AtlasAaeosValueNormalizer;

/**
 * Atlas Dev Flow Map And Product Options v1 · Parte 1 — pure, deterministic
 * decider for the SETTLED rules of this recorte of the flow/product map doc.
 *
 * This recorte is a cartography-readable slice. Its "Regras para IA" forbid
 * turning a hipótese / diário / opção futura / comparação into runtime, and
 * forbid mixing patamar / versão / fonte / risco / regra / prova. This decider
 * therefore encodes ONLY the parts the doc itself states as decisões / regras /
 * contratos, and deliberately ignores the "Hipoteses geradas", "Riscos" and
 * "Backlog candidato" sections (those stay non-runtime):
 *
 *   - "Regra De Ouro" → the measurement arena (the final arena named in the doc)
 *     must NOT change until SIX preconditions are all met: clear execution
 *     contract, reproducible local results, selected battery tasks, quality/
 *     cost/time metrics, an escalation policy and a comparison criterion. Until
 *     then any attempt to touch it is blocked with `golden_rule_not_yet`.
 *   - "Ordem De Batalha" → a fixed 7-step ordering whose final step (prepare for
 *     the arena) is gated by completing steps 1..6 first.
 *   - Context 2 "completion deve retornar estado honesto" → the closed honest
 *     completion state machine: passed | needs_review | failed | escalate_forge,
 *     with the invariant that `passed` only exists WITH evidence (otherwise it
 *     downgrades to needs_review) and `needs_review` is never a full success.
 *   - Context 2 "intake precisa classificar risco e decidir se precisa spec leve
 *     ou spec forte" → risk-based spec routing (light spec vs strong spec), with
 *     the consolidated "spec antes do codigo, proporcional ao risco" rule.
 *   - "Diario De Contexto Da Sessao" → the `Formato obrigatorio` schema: the
 *     closed set of mandatory fields every new context entry must carry.
 *
 * It executes nothing, promotes no hypothesis/backlog item to a contract, and
 * touches no database. It only lets a caller ASK, deterministically:
 *   - may the measurement arena be touched yet, given which preconditions hold?
 *   - what is the honest completion state for a (claimed_state, has_evidence) shape?
 *   - which spec strength does an intake risk require (light vs strong)?
 *   - is a context-diary entry complete under the mandatory-field schema?
 *
 * @see docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-01.md
 */
final class AtlasDevFlowMapProductOptionsV1Part01Service
{
    /** Stable decision kind this decider emits. */
    public const DECISION_KIND = 'atlas_dev.flow_map_and_product_options.v1.part_01';

    /** The owner index this recorte is a canonical child of. */
    public const OWNER_DOC = 'atlas-dev-flow-map-and-product-options-v1';

    /**
     * "Regra De Ouro": the closed set of SIX preconditions that must all be true
     * before the measurement arena (the final arena named in the doc) may change.
     * Stable machine token => the asserted precondition in plain words.
     *
     * @var array<string,string>
     */
    public const GOLDEN_RULE_PRECONDITIONS = [
        'execution_contract' => 'atlas_dev_has_a_clear_execution_contract',
        'reproducible_local_results' => 'local_results_are_reproducible',
        'selected_battery_tasks' => 'the_local_task_battery_is_selected',
        'quality_cost_time_metrics' => 'quality_cost_and_time_metrics_exist',
        'escalation_policy' => 'an_escalation_policy_to_the_heavy_path_exists',
        'comparison_criterion' => 'a_comparison_criterion_against_a_pure_strong_model_exists',
    ];

    /**
     * "Ordem De Batalha": the fixed 7-step ordering. Step 7 (prepare for the
     * arena) is the only step that is gated — it must not start until 1..6 ran.
     *
     * @var list<string>
     */
    public const ORDER_OF_BATTLE = [
        'understand_current_atlas_dev_flows',
        'receive_user_contexts_and_record_value',
        'extract_product_and_architecture_principles',
        'turn_principles_into_testable_hypotheses',
        'turn_hypotheses_into_small_atlas_dev_changes',
        'measure_locally_against_representative_tasks',
        'only_then_prepare_for_the_arena',
    ];

    /**
     * Context 2: the closed, honest completion state machine. These are the ONLY
     * states `completion` may return. Order is meaningful for severity but the
     * set is what is contractual.
     *
     * @var list<string>
     */
    public const COMPLETION_STATES = ['passed', 'needs_review', 'failed', 'escalate_forge'];

    /**
     * "Diario De Contexto Da Sessao" → the `Formato obrigatorio` mandatory fields
     * every new context entry must carry. Closed set; an entry missing any is
     * incomplete.
     *
     * @var list<string>
     */
    public const CONTEXT_ENTRY_FIELDS = [
        'fonte',
        'texto_recebido',
        'valor_estrategico',
        'muda_na_tese',
        'fluxos_afetados',
        'hipoteses',
        'experimentos',
        'decisoes',
        'riscos',
        'backlog_candidato',
    ];

    /**
     * "Regra De Ouro" gate: may the measurement arena be touched yet? It may ONLY
     * change once every one of the six preconditions holds. Any missing
     * precondition blocks with `golden_rule_not_yet` and the exact gaps are
     * reported. Unknown precondition keys are ignored (the gate only asserts the
     * documented six, it does not invent new ones).
     *
     * @param  array<string,mixed>  $preconditions  precondition token => bool-ish
     * @return array{
     *   may_change_arena:bool, missing:list<string>, satisfied:list<string>, reason:string
     * }
     */
    public function goldenRuleGate(array $preconditions): array
    {
        $missing = [];
        $satisfied = [];

        foreach (array_keys(self::GOLDEN_RULE_PRECONDITIONS) as $key) {
            if (($preconditions[$key] ?? false) === true) {
                $satisfied[] = $key;
            } else {
                $missing[] = $key;
            }
        }

        $mayChange = $missing === [];

        return [
            'may_change_arena' => $mayChange,
            'missing' => $missing,
            'satisfied' => $satisfied,
            'reason' => $mayChange
                ? 'all_six_golden_rule_preconditions_met'
                : 'golden_rule_not_yet',
        ];
    }

    /**
     * "Ordem De Batalha" gate for the final step: preparing for the arena (step 7)
     * must not start until steps 1..6 are all done. Given the set of completed
     * step tokens, decide whether step 7 may start and which earlier steps remain.
     *
     * @param  list<string>  $completedSteps
     * @return array{
     *   may_prepare_arena:bool, remaining_prerequisites:list<string>, reason:string
     * }
     */
    public function orderOfBattleGate(array $completedSteps): array
    {
        $done = array_values(array_unique(array_map(
            static fn ($s): string => is_string($s) ? strtolower(trim($s)) : '',
            $completedSteps
        )));

        $prerequisites = array_slice(self::ORDER_OF_BATTLE, 0, 6);
        $remaining = array_values(array_diff($prerequisites, $done));
        $may = $remaining === [];

        return [
            'may_prepare_arena' => $may,
            'remaining_prerequisites' => $remaining,
            'reason' => $may
                ? 'steps_one_through_six_complete'
                : 'arena_prep_blocked_until_prior_steps_done',
        ];
    }

    /**
     * Context 2 honest-completion rule. The completion state must be one of the
     * four documented states. The load-bearing invariant: `passed` ONLY exists
     * with evidence — a claimed `passed` without evidence is downgraded to
     * `needs_review` (never silently accepted). An unknown claimed state is
     * coerced to `failed` (the safe honest floor). `needs_review` is explicitly
     * not a full success.
     *
     * @param  string  $claimedState
     * @return array{
     *   state:string, is_success:bool, downgraded:bool, reason:string
     * }
     */
    public function honestCompletion(string $claimedState, bool $hasEvidence): array
    {
        $claim = strtolower(trim($claimedState));

        if (! in_array($claim, self::COMPLETION_STATES, true)) {
            return [
                'state' => 'failed',
                'is_success' => false,
                'downgraded' => true,
                'reason' => 'unknown_state_coerced_to_failed',
            ];
        }

        // `passed` only exists with evidence; otherwise downgrade to needs_review.
        if ($claim === 'passed' && ! $hasEvidence) {
            return [
                'state' => 'needs_review',
                'is_success' => false,
                'downgraded' => true,
                'reason' => 'passed_requires_evidence_downgraded_to_needs_review',
            ];
        }

        $isSuccess = $claim === 'passed'; // needs_review / failed / escalate_forge are not full successes

        return [
            'state' => $claim,
            'is_success' => $isSuccess,
            'downgraded' => false,
            'reason' => $isSuccess
                ? 'passed_with_evidence'
                : 'not_a_full_success_state',
        ];
    }

    /**
     * Context 2 intake rule: classify risk and decide whether the run needs a
     * light spec or a strong spec — "spec antes do codigo, proporcional ao risco".
     * Documented mapping: low risk (R0..R2) → light spec; higher risk (R3) →
     * strong spec; R4/R5 → strong spec AND the work is plan-only (it never becomes
     * a daily fast-path patch, consistent with the rest of the flow map). Every
     * write still needs a spec — the strength is what scales with risk.
     *
     * @return array{
     *   risk_level:string, spec_strength:string, spec_required:bool,
     *   plan_only:bool, reason:string
     * }
     */
    public function specRoutingFor(string $riskLevel): array
    {
        $risk = $this->normalizeRisk($riskLevel);

        if (in_array($risk, ['R4', 'R5'], true)) {
            return [
                'risk_level' => $risk,
                'spec_strength' => 'strong',
                'spec_required' => true,
                'plan_only' => true,
                'reason' => 'high_risk_needs_strong_spec_and_is_plan_only',
            ];
        }

        if ($risk === 'R3') {
            return [
                'risk_level' => $risk,
                'spec_strength' => 'strong',
                'spec_required' => true,
                'plan_only' => false,
                'reason' => 'elevated_risk_needs_strong_spec',
            ];
        }

        return [
            'risk_level' => $risk,
            'spec_strength' => 'light',
            'spec_required' => true,
            'plan_only' => false,
            'reason' => 'low_risk_needs_light_spec_proportional_to_risk',
        ];
    }

    /**
     * "Diario De Contexto Da Sessao" schema check: is a context-diary entry
     * complete under the mandatory-field set? A field counts as present only if
     * its key exists AND its value is a non-empty string (a context entry must be
     * operational material, not an empty placeholder).
     *
     * @param  array<string,mixed>  $entry
     * @return array{complete:bool, missing_fields:list<string>, reason:string}
     */
    public function contextEntryComplete(array $entry): array
    {
        $missing = [];

        foreach (self::CONTEXT_ENTRY_FIELDS as $field) {
            $value = $entry[$field] ?? null;
            if (! is_string($value) || trim($value) === '') {
                $missing[] = $field;
            }
        }

        $complete = $missing === [];

        return [
            'complete' => $complete,
            'missing_fields' => $missing,
            'reason' => $complete
                ? 'all_mandatory_context_fields_present'
                : 'context_entry_missing_mandatory_fields',
        ];
    }

    /**
     * Stable manifest of the slice this decider governs (for the command/probe).
     *
     * @return array<string,mixed>
     */
    public function manifest(): array
    {
        return [
            'decision_kind' => self::DECISION_KIND,
            'doc' => 'docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-01.md',
            'owner_doc' => self::OWNER_DOC,
            'golden_rule_precondition_keys' => array_keys(self::GOLDEN_RULE_PRECONDITIONS),
            'order_of_battle' => self::ORDER_OF_BATTLE,
            'completion_states' => self::COMPLETION_STATES,
            'context_entry_fields' => self::CONTEXT_ENTRY_FIELDS,
        ];
    }

    /**
     * Normalize a risk token to upper-case R0..R5 (anything else → 'R0').
     */
    private function normalizeRisk(mixed $risk): string
    {
        return AtlasAaeosValueNormalizer::riskCodeR0ToR5($risk, 'R0');
    }
}
