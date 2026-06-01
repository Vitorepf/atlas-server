<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Runtime for the Impeccable Live Mode Teardown doc.
 *
 * The doc dissects the external Live Mode iteration loop (pick element in
 * browser -> agent writes variants -> user accepts/discards -> source patch ->
 * journal/recovery) and pins the contract Atlas must respect to match/surpass
 * it. Its load-bearing, deterministic rules:
 *
 *   1. "Contratos" / "Fluxo": the event loop is an ORDERED pipeline
 *      (prepare -> serve -> inject browser UI -> wrap source -> agent writes
 *      variants -> browser detects -> user accept/discard -> accept cleanup ->
 *      recovery). A step that runs before its prerequisite is a contract
 *      violation. Accept can never precede the agent writing variants; recovery
 *      only exists after an accept.
 *   2. "Regras para IA" (7 hard rules): helper port is never the app URL; the
 *      agent poll must be long-timeout and re-poll after every event; the
 *      annotated screenshot must be read before planning; `--text` disambiguates
 *      repeated elements; never write a generated file; every variant div holds
 *      EXACTLY one top-level element; accept (carbonize) requires cleanup before
 *      complete.
 *   3. "Riscos" -> "Mitigacao Atlas": each named risk has a required mitigation
 *      and none may be waived (e.g. accepting without evidence is blocked by
 *      receipt + screenshot + diff).
 *   4. `forbidden_changes`: "Criar live source mutation no Atlas sem
 *      boundary/evidence" — a live source mutation without a boundary AND
 *      evidence is always refused. risk_level is high; requires_evidence true.
 *
 * This service enforces those rules in pure memory with NO DB, returning typed
 * arrays. It is a teardown-contract EVALUATOR (does the loop respect the doc's
 * rules?), distinct from AtlasFrontendLiveSourcePatchRuntimeService which is the
 * stateful file-mutating Atlas target runtime.
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-impeccable-live-mode.md
 */
final class AtlasProgrammingFrontendImpeccableLiveModeService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.programming_frontend_impeccable_live_mode.v1';

    public const MODE = 'live_mode_teardown_contract_evaluator';

    /** Doc frontmatter: risk_level high, requires_evidence true. */
    public const RISK_LEVEL = 'high';

    /** Doc `required_tests` / `quality_gates`. */
    public const REQUIRED_QUALITY_GATE = 'php artisan atlas:engineering:knowledge docs-health --json';

    /**
     * The ordered event-loop steps, from the doc's "Contratos" chain and the
     * 9-step "Fluxo". Index = required execution order. A step that fires before
     * any earlier step in this list has not run is a contract violation.
     *
     * @var array<int, string>
     */
    private const FLOW = [
        'config_and_start_server',   // 1. live.mjs verifica config, inicia server
        'serve_live_and_detect',     // 2. live-server.mjs serve /live.js, /detect.js, SSE, poll
        'inject_browser_ui',         // 3. live-browser.js cria picker, bar, variants UI
        'wrap_source_element',       // 4. live-wrap.mjs localiza elemento e insere wrapper
        'agent_writes_variants',     // 5. agente escreve variantes em bloco unico
        'browser_detects_variants',  // 6. browser detecta variants via DOM/HMR
        'user_accept_or_discard',    // 7. usuario aceita/descarta
        'accept_cleanup',            // 8. live-accept.mjs preserva variante, limpa scaffolding/CSS
        'recovery_by_journal',       // 9. live-session-store.mjs recovery por journal/snapshot
    ];

    /** The terminal mutating decisions in the loop. */
    public const STEP_AGENT_WRITES = 'agent_writes_variants';

    public const STEP_ACCEPT_CLEANUP = 'accept_cleanup';

    public const STEP_USER_DECISION = 'user_accept_or_discard';

    /**
     * The 7 "Regras para IA", each as a hard rule with the boolean evidence key
     * an evaluated loop must satisfy. Order matches the doc's numbering.
     *
     * @var array<int, array{id:string, rule:string, evidence_key:string}>
     */
    private const RULES = [
        ['id' => 'R1', 'rule' => 'helper_port_is_never_app_url', 'evidence_key' => 'helper_port_distinct_from_app_url'],
        ['id' => 'R2', 'rule' => 'poll_is_long_timeout_and_repolls_after_each_event', 'evidence_key' => 'poll_long_timeout_and_repolls'],
        ['id' => 'R3', 'rule' => 'annotated_screenshot_read_before_planning', 'evidence_key' => 'annotated_screenshot_read'],
        ['id' => 'R4', 'rule' => 'text_flag_disambiguates_repeated_elements', 'evidence_key' => 'text_disambiguation_when_repeated'],
        ['id' => 'R5', 'rule' => 'never_write_a_generated_file', 'evidence_key' => 'target_is_not_generated_file'],
        ['id' => 'R6', 'rule' => 'each_variant_div_has_exactly_one_top_level_element', 'evidence_key' => 'one_top_level_element_per_variant'],
        ['id' => 'R7', 'rule' => 'accept_carbonize_cleans_up_before_complete', 'evidence_key' => 'cleanup_done_before_complete'],
    ];

    /**
     * The "Riscos" -> "Mitigacao Atlas" matrix, verbatim mapping.
     *
     * @var array<int, array{risk:string, mitigation:string}>
     */
    private const RISK_MITIGATIONS = [
        ['risk' => 'wrong_source', 'mitigation' => 'code_intelligence_text_disambiguation_software_twin'],
        ['risk' => 'generated_file', 'mitigation' => 'acrui_is_generated_plus_deny_write'],
        ['risk' => 'hmr_does_not_update', 'mitigation' => 'recovery_replay_explicit_status'],
        ['risk' => 'invalid_css_or_jsx', 'mitigation' => 'framework_adapters_plus_tests'],
        ['risk' => 'accept_without_evidence', 'mitigation' => 'receipt_plus_screenshot_plus_diff'],
    ];

    /**
     * Return the ordered event-loop pipeline (Contratos + Fluxo).
     *
     * @return array<int, string>
     */
    public function flow(): array
    {
        return self::FLOW;
    }

    /**
     * Return the 7 "Regras para IA".
     *
     * @return array<int, array{id:string, rule:string, evidence_key:string}>
     */
    public function rules(): array
    {
        return self::RULES;
    }

    /**
     * Return the Riscos -> Mitigacao Atlas matrix.
     *
     * @return array<int, array{risk:string, mitigation:string}>
     */
    public function riskMitigations(): array
    {
        return self::RISK_MITIGATIONS;
    }

    /**
     * Evaluate an observed sequence of event-loop steps against the documented
     * ordered pipeline (doc rule 1: "Contratos" / "Fluxo").
     *
     * A step is a violation when it fires before one of its prerequisites (an
     * earlier step in FLOW) has occurred. This encodes the doc's hard ordering:
     * the agent can never accept before writing variants; recovery only exists
     * after an accept/cleanup; the browser only detects variants after the agent
     * wrote them. Unknown steps are reported and never count as satisfied.
     *
     * @param  array<int, string>  $observedSteps
     * @return array{
     *   ordered:bool,
     *   order_violations:array<int, array{step:string, missing_prerequisites:array<int, string>}>,
     *   unknown_steps:array<int, string>,
     *   executed_in_order:array<int, string>,
     *   conclusion:string
     * }
     */
    public function evaluateEventLoopOrder(array $observedSteps): array
    {
        $rank = array_flip(self::FLOW);
        $seen = [];
        $violations = [];
        $unknown = [];
        $executed = [];

        foreach ($observedSteps as $step) {
            if (! array_key_exists($step, $rank)) {
                $unknown[] = $step;

                continue;
            }

            // Every step strictly earlier in FLOW is a prerequisite.
            $missing = [];
            for ($i = 0; $i < $rank[$step]; $i++) {
                $prereq = self::FLOW[$i];
                if (! array_key_exists($prereq, $seen)) {
                    $missing[] = $prereq;
                }
            }

            if ($missing !== []) {
                $violations[] = ['step' => $step, 'missing_prerequisites' => $missing];
            } else {
                $executed[] = $step;
            }

            $seen[$step] = true;
        }

        $ordered = $violations === [] && $unknown === [];

        return [
            'ordered' => $ordered,
            'order_violations' => $violations,
            'unknown_steps' => $unknown,
            'executed_in_order' => $executed,
            'conclusion' => $ordered
                ? 'event_loop_respects_documented_order'
                : 'event_loop_out_of_order_contract_violated',
        ];
    }

    /**
     * Evaluate a live iteration against the 7 "Regras para IA" (doc rule 2). A
     * rule passes only when its boolean evidence key is strictly true; missing
     * or non-true evidence is a violation ("looks fine" is not evidence). The
     * loop is compliant only when every rule passes.
     *
     * @param  array<string, mixed>  $evidence
     * @return array{
     *   compliant:bool,
     *   passed_ids:array<int, string>,
     *   violated_ids:array<int, string>,
     *   violation_count:int,
     *   total_rules:int,
     *   conclusion:string
     * }
     */
    public function evaluateRules(array $evidence): array
    {
        $passed = [];
        $violated = [];

        foreach (self::RULES as $rule) {
            if (($evidence[$rule['evidence_key']] ?? null) === true) {
                $passed[] = $rule['id'];
            } else {
                $violated[] = $rule['id'];
            }
        }

        $compliant = $violated === [];

        return [
            'compliant' => $compliant,
            'passed_ids' => $passed,
            'violated_ids' => $violated,
            'violation_count' => count($violated),
            'total_rules' => count(self::RULES),
            'conclusion' => $compliant
                ? 'live_iteration_satisfies_all_regras_para_ia'
                : 'live_iteration_violates_regras_para_ia',
        ];
    }

    /**
     * Validate a single variant block against doc rule 6: "Cada variant div deve
     * conter exatamente um top-level element." Zero or multiple top-level
     * elements is invalid. This is a deterministic structural check used by the
     * accept gate.
     *
     * @return array{valid:bool, top_level_count:int, reason:string}
     */
    public function validateVariantTopLevel(int $topLevelElementCount): array
    {
        $valid = $topLevelElementCount === 1;

        return [
            'valid' => $valid,
            'top_level_count' => $topLevelElementCount,
            'reason' => match (true) {
                $topLevelElementCount < 1 => 'variant_has_no_top_level_element',
                $topLevelElementCount > 1 => 'variant_has_multiple_top_level_elements',
                default => 'variant_has_exactly_one_top_level_element',
            },
        ];
    }

    /**
     * The forbidden-change gate (doc `forbidden_changes`: "Criar live source
     * mutation no Atlas sem boundary/evidence"; risk "accept_without_evidence";
     * requires_evidence true; risk_level high).
     *
     * A live source mutation is AUTHORIZED only when ALL hold: an AVEOR/Software
     * Twin boundary is declared, evidence (receipt + screenshot + diff) is
     * present, the target is not a generated file (rule 5), and each variant has
     * exactly one top-level element (rule 6). Missing any one refuses the
     * mutation. This never authorizes runtime delivery — an accepted patch still
     * owes the visual quality gate and run certification downstream.
     *
     * @param  array<string, mixed>  $context
     * @return array{
     *   authorized:bool,
     *   missing_requirements:array<int, string>,
     *   required:array<int, string>,
     *   reason:string,
     *   asserts_delivery_done:bool,
     *   required_next_gates:array<int, string>
     * }
     */
    public function authorizeSourceMutation(array $context): array
    {
        $required = [
            'boundary_declared',          // AVEOR / Software Twin boundary
            'receipt_present',            // Dev/Forge decision receipt
            'screenshot_present',         // annotated screenshot evidence
            'diff_present',               // explicit diff evidence
            'target_is_not_generated',    // rule 5 / risk generated_file
            'variant_single_top_level',   // rule 6
        ];

        $missing = [];
        foreach ($required as $key) {
            if (($context[$key] ?? null) !== true) {
                $missing[] = $key;
            }
        }

        $authorized = $missing === [];

        return [
            'authorized' => $authorized,
            'missing_requirements' => $missing,
            'required' => $required,
            'reason' => $authorized
                ? 'live_source_mutation_has_boundary_and_evidence'
                : 'live_source_mutation_refused_boundary_or_evidence_missing',
            // Accepting a live patch is a decision, never a delivery completion.
            'asserts_delivery_done' => false,
            'required_next_gates' => $authorized
                ? ['visual_quality_gate', 'run_certification', 'aemor_outcome_memory']
                : ['supply_boundary_and_evidence_before_mutation'],
        ];
    }
}
