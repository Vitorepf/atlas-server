<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Codex Merge Post-Execution Contract — pure, deterministic, READ-ONLY surfaces
 * for the preflight that sits AFTER a future executor has produced a persisted
 * execution receipt, plus the action template that describes (but never
 * authorizes) a future merge.
 *
 * This contract exposes exactly two surfaces and NOTHING that can authorize a
 * merge:
 *
 *   1. post-execution preflight  — declares the required inputs, required checks
 *                                  and blocking conditions a future merge surface
 *                                  must satisfy; defines a future merge action
 *                                  shape; authorizes nothing.
 *   2. action template           — describes a future merge action; defaults to
 *                                  `do_not_merge`; lists required inputs, the
 *                                  validations a future flow must pass, and the
 *                                  future action steps; forbids approval, merge,
 *                                  dispatch and final receipt persistence.
 *
 * Hard boundary (doc "Boundary") — every result this service emits keeps all SIX
 * keys false, always:
 *   execution_allowed=false, execution_receipt_persisted=false,
 *   approval_granted=false, merge_allowed=false, ledger_write_allowed=false,
 *   dispatch_allowed=false.
 *
 * The surfaces must not, and this code does not: accept execution receipt
 * evidence, persist execution receipts, approve code, merge, or dispatch work.
 *
 * Documented invariants this code ENFORCES (not merely documents):
 *   - "Boundary ...=false" => boundary() returns all six keys false and every
 *     public result embeds it verbatim; assertBoundaryHeld() proves no result
 *     ever flipped a key true.
 *   - "Required Inputs" (7) / "Required Checks" (12) / "Blocking Conditions" (10)
 *     => the preflight emits exactly those documented lists, in documented order.
 *   - "Future Merge Action must consume the persisted execution receipt only /
 *     revalidate diff / revalidate gate hashes / revalidate no hot-scope drift /
 *     require human confirmation hash / emit a final merge receipt" => the
 *     preflight exposes exactly those six obligations.
 *   - "[Action Template] must default to do_not_merge" => the template's default
 *     decision is always `do_not_merge`.
 *   - "[Action Template] may become ready only after post-execution preflight is
 *     ready" => actionTemplate() returns status not_ready (and lists NO required
 *     inputs, NO validations, NO action steps) unless the preflight is proven
 *     ready. Fail-closed: absent an exact boolean true, the preflight is treated
 *     as not ready.
 *   - "[Action Template] must require <6 inputs> / must validate <7 conditions> /
 *     may define <6 future action steps>" => when ready, the template emits
 *     exactly those documented lists, in documented order.
 *   - "It must still forbid approval, merge, dispatch and final receipt
 *     persistence" => the template restates those four guarantees false and the
 *     boundary holds.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-contract.md
 */
final class AtlasCodexMergePostExecutionContractService
{
    /** Stable evidence schema id this read-only surface family emits. */
    public const SCHEMA = 'atlas.self_construction_codex_merge_post_execution_contract.v1';

    /** Surface labels (closed set). */
    public const SURFACE_PREFLIGHT = 'post_execution_preflight';
    public const SURFACE_ACTION_TEMPLATE = 'post_execution_action_template';

    /** Default (and only safe) decision for the action template. */
    public const DEFAULT_DECISION = 'do_not_merge';

    /** Action-template statuses. */
    public const STATUS_TEMPLATE_NOT_READY = 'blocked_before_post_execution_preflight';
    public const STATUS_TEMPLATE_READY = 'action_template_ready';

    /**
     * The six boundary keys (doc "Boundary"): every result keeps all false.
     *
     * @var list<string>
     */
    public const BOUNDARY_KEYS = [
        'execution_allowed',
        'execution_receipt_persisted',
        'approval_granted',
        'merge_allowed',
        'ledger_write_allowed',
        'dispatch_allowed',
    ];

    /**
     * Inputs a future merge surface must provide (doc "Required Inputs").
     * Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const REQUIRED_INPUTS = [
        'persisted_execution_receipt_id',
        'persisted_execution_receipt_hash',
        'append_only_execution_receipt_event_hash',
        'post_execution_diff_hash',
        'post_execution_gate_report_hash',
        'human_post_execution_confirmation_hash',
        'merge_candidate_hash',
    ];

    /**
     * Checks the future merge surface must prove (doc "Required Checks").
     * Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const REQUIRED_CHECKS = [
        'execution_receipt_template_is_ready',
        'persisted_execution_receipt_exists',
        'persisted_execution_receipt_hash_is_verified',
        'append_only_execution_receipt_event_exists',
        'execution_receipt_sources_match_templates',
        'post_execution_diff_matches_receipt',
        'post_execution_gates_passed',
        'hot_scope_is_clean_after_execution',
        'docs_health_is_clean_after_execution',
        'architecture_validation_is_clean_after_execution',
        'focused_tests_are_clean_after_execution',
        'human_post_execution_confirmation_exists',
    ];

    /**
     * Conditions the future merge surface must block on (doc "Blocking
     * Conditions"). Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const BLOCKING_CONDITIONS = [
        'missing_persisted_execution_receipt',
        'missing_append_only_execution_receipt_event',
        'execution_receipt_source_hash_mismatch',
        'post_execution_diff_mismatch',
        'post_execution_gate_failure',
        'hot_scope_failure_after_execution',
        'docs_health_failure_after_execution',
        'architecture_validation_failure_after_execution',
        'focused_tests_failure_after_execution',
        'missing_human_post_execution_confirmation',
    ];

    /**
     * Obligations a future merge action must satisfy (doc "Future Merge Action").
     * Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const FUTURE_MERGE_ACTION_OBLIGATIONS = [
        'consume_the_persisted_execution_receipt_only',
        'revalidate_post_execution_diff',
        'revalidate_gate_hashes',
        'revalidate_no_hot_scope_drift',
        'require_human_confirmation_hash',
        'emit_a_final_merge_receipt',
    ];

    /**
     * Inputs the action template must REQUIRE (doc "Action Template" → "It must
     * require"). Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const TEMPLATE_REQUIRED_INPUTS = [
        'post_execution_preflight_hash',
        'persisted_execution_receipt_hash',
        'post_execution_gate_report_hash',
        'merge_candidate_hash',
        'human_post_execution_confirmation_hash',
        'merge_operator_identity',
    ];

    /**
     * Conditions the action template must VALIDATE (doc "Action Template" → "It
     * must validate"). Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const TEMPLATE_VALIDATIONS = [
        'post_execution_preflight_is_ready',
        'persisted_execution_receipt_hash_matches_preflight',
        'merge_candidate_hash_matches_post_execution_diff',
        'post_execution_gate_report_hash_matches_preflight',
        'human_post_execution_confirmation_hash_is_present',
        'no_hot_scope_drift_since_preflight',
        'no_unreviewed_diff_since_preflight',
    ];

    /**
     * Future action steps the action template MAY define (doc "Action Template" →
     * "It may define future action steps"). The final step emits only an UNSIGNED
     * receipt — it never persists, approves, merges or dispatches. Order is
     * load-bearing.
     *
     * @var list<string>
     */
    public const TEMPLATE_ACTION_STEPS = [
        'read_persisted_execution_receipt',
        'read_post_execution_gate_report',
        'read_merge_candidate_diff',
        'verify_merge_candidate_hashes',
        'request_final_merge_confirmation',
        'emit_unsigned_final_merge_action_receipt',
    ];

    /** The terminal action-template step (emits unsigned receipt only). */
    public const TEMPLATE_TERMINAL_STEP = 'emit_unsigned_final_merge_action_receipt';

    /**
     * The six documented boundary keys, all forced false.
     *
     * @return array<string,false>
     */
    public function boundary(): array
    {
        $out = [];
        foreach (self::BOUNDARY_KEYS as $key) {
            $out[$key] = false;
        }

        return $out;
    }

    /**
     * Surface 1 — the read-only POST-EXECUTION PREFLIGHT.
     *
     * Declares the inputs, checks and blocking conditions a future merge surface
     * must satisfy once a persisted execution receipt exists, and the shape of the
     * future merge action. It accepts no execution receipt evidence, persists
     * nothing, approves nothing, merges nothing and dispatches nothing. The six
     * boundary keys stay false unconditionally.
     *
     * @return array{
     *   surface:string, schema:string,
     *   required_inputs:list<string>, required_checks:list<string>,
     *   blocking_conditions:list<string>,
     *   future_merge_action_obligations:list<string>,
     *   future_merge_action_consumes:string,
     *   boundary:array<string,false>,
     *   execution_allowed:false, execution_receipt_persisted:false,
     *   approval_granted:false, merge_allowed:false,
     *   ledger_write_allowed:false, dispatch_allowed:false
     * }
     */
    public function postExecutionPreflight(): array
    {
        return [
            'surface' => self::SURFACE_PREFLIGHT,
            'schema' => self::SCHEMA,
            'required_inputs' => self::REQUIRED_INPUTS,
            'required_checks' => self::REQUIRED_CHECKS,
            'blocking_conditions' => self::BLOCKING_CONDITIONS,
            'future_merge_action_obligations' => self::FUTURE_MERGE_ACTION_OBLIGATIONS,
            // Doc "Future Merge Action": "consume the persisted execution receipt
            // only" — never live evidence, never re-execution.
            'future_merge_action_consumes' => 'persisted_execution_receipt_only',
            'boundary' => $this->boundary(),
            // Restated per the doc's hard non-authorizing guarantee.
            'execution_allowed' => false,
            'execution_receipt_persisted' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
        ];
    }

    /**
     * Surface 2 — the read-only ACTION TEMPLATE.
     *
     * Describes a future merge action WITHOUT authorizing it. It may become ready
     * ONLY after the post-execution preflight is ready; otherwise it lists no
     * required inputs, no validations and no action steps. The default decision is
     * always `do_not_merge`. It forbids approval, merge, dispatch and final
     * receipt persistence; the six boundary keys stay false unconditionally.
     *
     * @param array<string,mixed> $input recognised key:
     *   post_execution_preflight_ready (bool) — the ONLY positive signal that the
     *   upstream preflight is ready. Absent / non-true => fail-closed to not ready.
     * @return array{
     *   surface:string, schema:string,
     *   upstream_preflight_ready:bool, status:string, template_ready:bool,
     *   default_decision:string,
     *   required_inputs:list<string>, validations:list<string>,
     *   action_steps:list<string>, terminal_step:string,
     *   forbids:array{approval:false, merge:false, dispatch:false,
     *     final_receipt_persistence:false},
     *   boundary:array<string,false>,
     *   execution_allowed:false, approval_granted:false, merge_allowed:false,
     *   dispatch_allowed:false
     * }
     */
    public function actionTemplate(array $input = []): array
    {
        $preflightReady = $this->signal($input, 'post_execution_preflight_ready', false);

        $status = $preflightReady ? self::STATUS_TEMPLATE_READY : self::STATUS_TEMPLATE_NOT_READY;

        // Fail-closed: until the preflight is proven ready, the template lists
        // NOTHING — there is no future action to template yet.
        $requiredInputs = $preflightReady ? self::TEMPLATE_REQUIRED_INPUTS : [];
        $validations = $preflightReady ? self::TEMPLATE_VALIDATIONS : [];
        $actionSteps = $preflightReady ? self::TEMPLATE_ACTION_STEPS : [];

        return [
            'surface' => self::SURFACE_ACTION_TEMPLATE,
            'schema' => self::SCHEMA,
            'upstream_preflight_ready' => $preflightReady,
            'status' => $status,
            'template_ready' => $preflightReady,
            // Doc: "It must default to do_not_merge." Independent of readiness.
            'default_decision' => self::DEFAULT_DECISION,
            'required_inputs' => $requiredInputs,
            'validations' => $validations,
            'action_steps' => $actionSteps,
            'terminal_step' => self::TEMPLATE_TERMINAL_STEP,
            // Doc: "It must still forbid approval, merge, dispatch and final
            // receipt persistence."
            'forbids' => [
                'approval' => false,
                'merge' => false,
                'dispatch' => false,
                'final_receipt_persistence' => false,
            ],
            'boundary' => $this->boundary(),
            'execution_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'dispatch_allowed' => false,
        ];
    }

    /**
     * Composite entrypoint: evaluate both surfaces under a single input and prove
     * the boundary held across every result.
     *
     * The progressive gate is respected: with safe (empty) defaults the action
     * template is not ready and lists nothing.
     *
     * @param array<string,mixed> $input forwarded to the action template
     * @return array{
     *   schema:string,
     *   post_execution_preflight:array<string,mixed>,
     *   action_template:array<string,mixed>,
     *   boundary_held:bool, boundary_violations:list<string>
     * }
     */
    public function evaluate(array $input = []): array
    {
        $preflight = $this->postExecutionPreflight();
        $template = $this->actionTemplate($input);

        $violations = $this->assertBoundaryHeld([$preflight, $template]);

        return [
            'schema' => self::SCHEMA,
            'post_execution_preflight' => $preflight,
            'action_template' => $template,
            'boundary_held' => $violations === [],
            'boundary_violations' => $violations,
        ];
    }

    /**
     * Prove that no result ever flipped a boundary key to a truthy value.
     * Returns the list of "surface.key" violations (empty = boundary intact).
     *
     * @param list<array<string,mixed>> $results
     * @return list<string>
     */
    public function assertBoundaryHeld(array $results): array
    {
        $violations = [];
        foreach ($results as $result) {
            $label = is_string($result['surface'] ?? null) ? $result['surface'] : 'unknown';

            $boundary = is_array($result['boundary'] ?? null) ? $result['boundary'] : [];
            foreach (self::BOUNDARY_KEYS as $key) {
                // Missing key OR truthy value both count as a breach.
                if (! array_key_exists($key, $boundary) || $boundary[$key] !== false) {
                    $violations[] = $label.'.'.$key;
                }
            }

            // Any restated top-level guarantee turning truthy is a breach too.
            foreach ([
                'execution_allowed',
                'execution_receipt_persisted',
                'approval_granted',
                'merge_allowed',
                'ledger_write_allowed',
                'dispatch_allowed',
            ] as $extra) {
                if (array_key_exists($extra, $result) && $result[$extra] !== false) {
                    $violations[] = $label.'.'.$extra;
                }
            }

            // The action template's nested "forbids" block must also stay false.
            if (is_array($result['forbids'] ?? null)) {
                foreach ($result['forbids'] as $forbidKey => $forbidValue) {
                    if ($forbidValue !== false) {
                        $violations[] = $label.'.forbids.'.(is_string($forbidKey) ? $forbidKey : 'unknown');
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * Read a boolean signal. Only an exact boolean true clears it; anything else
     * (missing key, null, truthy-string, 1) falls back to the fail-closed default.
     * This keeps the upstream gate impossible to trip accidentally.
     *
     * @param array<string,mixed> $input
     */
    private function signal(array $input, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $input)) {
            return $default;
        }

        return $input[$key] === true;
    }
}
