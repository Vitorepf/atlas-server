<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Codex Merge Authorizing Action Contract — pure, deterministic, READ-ONLY
 * surfaces for a FUTURE governed merge authorizing action.
 *
 * The doc governs four read-only templates that come *before* any real
 * authorizing action exists:
 *
 *   1. authorizing-action template      (--codex-review-merge-authorizing-action-template)
 *   2. final merge receipt draft        (--codex-review-merge-final-receipt-draft)
 *   3. final merge signature request    (--codex-review-merge-final-signature-request)
 *   4. final post-signature runbook     (--codex-review-merge-final-post-signature-runbook)
 *
 * Each surface MAY describe future inputs, validations, receipt fields or an
 * ordered checklist, but NONE of them may accept evidence, validate a
 * signature, record a decision, sign a receipt, approve code, merge or dispatch.
 *
 * Documented invariants this code ENFORCES (not merely documents):
 *
 *   - "Read-Only Boundary" / each surface's "It must keep ...=false": every
 *     surface embeds the documented boundary flags and they are ALL false on
 *     every input. assertBoundaryHeld() proves no surface ever flipped one true,
 *     and the check is non-vacuous (a missing flag also counts as a breach).
 *
 *   - "The default decision is always `request_changes`": defaultDecision() is
 *     the constant DEFAULT_DECISION === 'request_changes', echoed by every
 *     surface that exposes a decision.
 *
 *   - "Required Future Validations: selected decision is exactly `merge`" plus
 *     "Any missing or mismatched item must produce `request_changes` or
 *     `abort`, never implicit approval": validateFutureAuthorization() requires
 *     the selected decision to be exactly REQUIRED_DECISION === 'merge' AND
 *     every documented validation to pass; any missing/false item yields
 *     decision 'request_changes' (or 'abort' on a present-but-non-merge,
 *     non-request_changes decision), and approval is NEVER granted from this
 *     read-only surface (approval_granted stays false unconditionally).
 *
 *   - "Required Future Inputs" (17 items, default decision request_changes):
 *     requiredFutureInputs() enumerates exactly those inputs.
 *
 *   - "Required Future Validations" (12 checks): requiredFutureValidations()
 *     enumerates exactly those checks; each is proven=false here (this surface
 *     proves none).
 *
 *   - "Future Receipt Fields" (13 fields): futureReceiptFields() enumerates
 *     exactly those fields and the receipt stays unsigned/non-authorizing.
 *
 *   - "Execution Boundary" / "The correct chain is ...": executionChain()
 *     returns the documented 5-stage chain in order, ending in the controlled
 *     merge action that is a SEPARATE executor — this surface is never the
 *     executor (apply_patches_allowed=false, merge_allowed=false).
 *
 *   - "Final Post-Signature Runbook ... It must sequence, but not execute":
 *     postSignatureRunbook() emits the ordered sequence ending in a non-execute
 *     stop, every step is_terminal=false except the last, and the boundary
 *     stays held.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-authorizing-action-contract.md
 */
final class AtlasCodexMergeAuthorizingActionContractRtService
{
    /** Stable evidence schema id this read-only surface emits. */
    public const SCHEMA = 'atlas.self_construction_codex_merge_authorizing_action_contract.v1';

    /** Doc: "The default decision is always `request_changes`." */
    public const DEFAULT_DECISION = 'request_changes';

    /** Doc: "selected decision is exactly `merge`". */
    public const REQUIRED_DECISION = 'merge';

    /**
     * Doc "Read-Only Boundary": the nine gate flags the authorizing-action
     * template must keep false. Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const TEMPLATE_BOUNDARY_KEYS = [
        'authorization_ready',
        'signature_present',
        'signature_valid',
        'decision_recorded',
        'approval_granted',
        'merge_allowed',
        'execution_allowed',
        'ledger_write_allowed',
        'dispatch_allowed',
    ];

    /**
     * Doc "Final Merge Receipt Draft" / "It must keep": ten flags, all false.
     * Same nine as the template plus receipt_signed up front.
     *
     * @var list<string>
     */
    public const RECEIPT_DRAFT_BOUNDARY_KEYS = [
        'receipt_signed',
        'authorization_ready',
        'signature_present',
        'signature_valid',
        'decision_recorded',
        'approval_granted',
        'merge_allowed',
        'execution_allowed',
        'ledger_write_allowed',
        'dispatch_allowed',
    ];

    /**
     * Doc "Final Merge Signature Request" / "It must keep": ten flags. The
     * request DEMANDS a future signature (signature_required=true) but still has
     * no signature and grants nothing. We keep signature_required separate from
     * the all-false boundary because it is documented as true.
     *
     * @var list<string>
     */
    public const SIGNATURE_REQUEST_BOUNDARY_KEYS = [
        'signature_present',
        'signature_valid',
        'receipt_signed',
        'decision_recorded',
        'approval_granted',
        'merge_allowed',
        'execution_allowed',
        'ledger_write_allowed',
        'dispatch_allowed',
    ];

    /**
     * Doc "Final Post-Signature Runbook": "It must keep signature_valid=false,
     * receipt_signed=false, decision_recorded=false, approval_granted=false and
     * merge_allowed=false." Five flags.
     *
     * @var list<string>
     */
    public const RUNBOOK_BOUNDARY_KEYS = [
        'signature_valid',
        'receipt_signed',
        'decision_recorded',
        'approval_granted',
        'merge_allowed',
    ];

    /**
     * Doc "Read-Only Boundary" / "It must not": the eight actions the template
     * must never take. Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const TEMPLATE_FORBIDDEN_ACTIONS = [
        'accept_authorization_evidence',
        'validate_a_signature',
        'infer_approval_from_completed_packets',
        'record_a_decision',
        'approve_code',
        'apply_a_patch',
        'merge',
        'dispatch_work',
    ];

    /**
     * Doc "Required Future Inputs": the 17 inputs the future authorizing action
     * must require. Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const REQUIRED_FUTURE_INPUTS = [
        'external_authorization_signature_value',
        'validator_identity',
        'validation_timestamp',
        'validated_authorization_signable_payload_hash',
        'validated_authorization_receipt_hash',
        'selected_decision',
        'decision_rationale',
        'fresh_merge_preflight_hash',
        'fresh_test_output_hash',
        'fresh_docs_health_output_hash',
        'fresh_architecture_validation_output_hash',
        'fresh_diff_check_output_hash',
        'scope_integrity_statement',
        'hot_scope_exclusion_statement',
        'completed_packet_evidence_integrity_statement',
        'rollback_plan',
        'human_final_merge_confirmation',
    ];

    /**
     * Doc "Required Future Validations": the 12 validations the future
     * authorizing action must perform. Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const REQUIRED_FUTURE_VALIDATIONS = [
        'selected_decision_is_exactly_merge',
        'signature_matches_source_authorization_signable_payload_hash',
        'authorization_receipt_hash_matches_source_chain',
        'fresh_merge_preflight_hash_is_bound',
        'tests_pass',
        'docs_health_passes',
        'architecture_validation_passes',
        'diff_check_passes',
        'scope_excludes_hot_voice_kernel_files',
        'completed_packet_evidence_matches_completed_packet_hashes',
        'rollback_plan_is_present',
        'human_final_merge_confirmation_is_explicit',
    ];

    /**
     * Doc "Future Receipt Fields": the 13 fields the append-only receipt must
     * contain. Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const FUTURE_RECEIPT_FIELDS = [
        'authorizing_action_id',
        'source_final_authorization_preflight_hash',
        'selected_decision',
        'decision_rationale',
        'validated_signature_hash',
        'validated_authorization_receipt_hash',
        'fresh_gate_hashes',
        'scope_integrity_result',
        'evidence_integrity_result',
        'rollback_plan_hash',
        'human_confirmation_hash',
        'authorizer_identity',
        'authorization_timestamp',
    ];

    /**
     * Doc "Execution Boundary" / "The correct chain is": the five ordered
     * stages. The final stage is a SEPARATE controlled merge action — never this
     * surface. Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const EXECUTION_CHAIN = [
        'authorizing_action',
        'append_only_final_merge_receipt',
        'separate_merge_executor',
        'last_minute_diff_and_scope_checks',
        'controlled_merge_action',
    ];

    /**
     * Doc "Final Post-Signature Runbook" / "It must sequence, but not execute":
     * the six steps the runbook sequences, ending in the mandated non-execute
     * stop. Order preserved exactly as documented (the doc lists six sequence
     * items; the final prepared surface is followed by the explicit stop).
     *
     * @var list<string>
     */
    public const RUNBOOK_STEPS = [
        'external_final_signature_verification',
        'final_signable_payload_and_receipt_hash_matching',
        'selected_decision_check',
        'fresh_gate_hash_verification',
        'scope_and_packet_evidence_checks',
        'preparation_of_a_separate_signed_final_receipt_surface',
        self::RUNBOOK_TERMINAL_STEP,
    ];

    /** The runbook's explicit terminal step: it never signs the receipt. */
    public const RUNBOOK_TERMINAL_STEP = 'stop_before_signing_the_receipt_a_separate_surface_signs';

    /** Doc: default decision the template always reports. */
    public function defaultDecision(): string
    {
        return self::DEFAULT_DECISION;
    }

    /**
     * Build a boundary map (all keys false) from a key list.
     *
     * @param list<string> $keys
     * @return array<string,false>
     */
    private function boundaryFrom(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = false;
        }

        return $out;
    }

    /**
     * Surface 1 — the read-only authorizing-action template.
     *
     * Lists the future inputs, future validations, future receipt fields and the
     * execution chain a real authorizing action would carry, while keeping every
     * gate false and forbidding every action. Nothing here is accepted,
     * validated, recorded, approved, merged or dispatched on any input.
     *
     * @param array<string,mixed> $input ignored for gating; read-only template
     * @return array{
     *   surface:'authorizing_action_template', schema:string,
     *   default_decision:'request_changes', required_decision:'merge',
     *   required_future_inputs:list<string>,
     *   required_future_validations:list<array{check:string,proven:bool}>,
     *   future_receipt_fields:list<string>,
     *   execution_chain:list<string>,
     *   forbidden_actions:list<string>,
     *   boundary:array<string,false>
     * }
     */
    public function authorizingActionTemplate(array $input = []): array
    {
        return [
            'surface' => 'authorizing_action_template',
            'schema' => self::SCHEMA,
            'default_decision' => self::DEFAULT_DECISION,
            'required_decision' => self::REQUIRED_DECISION,
            'required_future_inputs' => self::REQUIRED_FUTURE_INPUTS,
            'required_future_validations' => $this->requiredFutureValidations(),
            'future_receipt_fields' => self::FUTURE_RECEIPT_FIELDS,
            'execution_chain' => self::EXECUTION_CHAIN,
            'forbidden_actions' => self::TEMPLATE_FORBIDDEN_ACTIONS,
            'boundary' => $this->boundaryFrom(self::TEMPLATE_BOUNDARY_KEYS),
        ];
    }

    /**
     * The 17 documented required future inputs.
     *
     * @return list<string>
     */
    public function requiredFutureInputs(): array
    {
        return self::REQUIRED_FUTURE_INPUTS;
    }

    /**
     * The 12 documented required future validations, each marked proven=false:
     * this read-only surface proves none of them.
     *
     * @return list<array{check:string,proven:bool}>
     */
    public function requiredFutureValidations(): array
    {
        $checks = [];
        foreach (self::REQUIRED_FUTURE_VALIDATIONS as $check) {
            $checks[] = ['check' => $check, 'proven' => false];
        }

        return $checks;
    }

    /**
     * The 13 documented future receipt fields.
     *
     * @return list<string>
     */
    public function futureReceiptFields(): array
    {
        return self::FUTURE_RECEIPT_FIELDS;
    }

    /**
     * Doc "Execution Boundary": the ordered chain, each stage annotated. Only
     * the final stage is the controlled merge action and it is a SEPARATE
     * executor — this surface (the authorizing action) is stage 1 and never
     * applies patches or merges.
     *
     * @return list<array{order:int,stage:string,is_executor:bool,is_this_surface:bool}>
     */
    public function executionChain(): array
    {
        $chain = [];
        $last = count(self::EXECUTION_CHAIN) - 1;
        foreach (self::EXECUTION_CHAIN as $i => $stage) {
            $chain[] = [
                'order' => $i + 1,
                'stage' => $stage,
                // Only the final controlled merge action actually executes.
                'is_executor' => ($i === $last),
                // This surface models stage 1 only; it never becomes the executor.
                'is_this_surface' => ($i === 0),
            ];
        }

        return $chain;
    }

    /**
     * Surface 2 — the unsigned final merge receipt DRAFT shell.
     *
     * Binds the documented draft fields and keeps all ten boundary flags false.
     * The draft is never signed and authorizes nothing.
     *
     * @return array{
     *   surface:'final_merge_receipt_draft', schema:string,
     *   draft_bindings:list<string>, default_decision:'request_changes',
     *   forbidden_actions:list<string>, boundary:array<string,false>
     * }
     */
    public function finalReceiptDraft(): array
    {
        return [
            'surface' => 'final_merge_receipt_draft',
            'schema' => self::SCHEMA,
            // Doc "The receipt draft must bind:".
            'draft_bindings' => [
                'authorizing_action_template_hash',
                'final_authorization_preflight_hash',
                'authorization_signature_request_hash',
                'authorization_signable_payload_hash',
                'allowed_decisions',
                'default_decision',
                'future_authorization_fields',
                'requirements_before_signing',
                'future_executor_contract',
            ],
            'default_decision' => self::DEFAULT_DECISION,
            // Doc "The draft must not:".
            'forbidden_actions' => [
                'accept_a_signature',
                'validate_a_signature',
                'record_a_merge_decision',
                'persist_an_authorization_receipt',
                'approve_code',
                'execute_merge',
                'dispatch_an_executor',
            ],
            'boundary' => $this->boundaryFrom(self::RECEIPT_DRAFT_BOUNDARY_KEYS),
        ];
    }

    /**
     * Surface 3 — the final merge signature REQUEST (a signable payload, not a
     * signature). signature_required=true is the only true flag; everything that
     * would constitute an actual signature, decision or merge stays false.
     *
     * @return array{
     *   surface:'final_merge_signature_request', schema:string,
     *   signature_required:true, signable_payload:list<string>,
     *   forbidden_actions:list<string>, boundary:array<string,false>
     * }
     */
    public function finalSignatureRequest(): array
    {
        return [
            'surface' => 'final_merge_signature_request',
            'schema' => self::SCHEMA,
            // Doc "It must keep: signature_required=true; ...". The request
            // demands a future signature but is not itself a signature.
            'signature_required' => true,
            // Doc "The signable payload must include:".
            'signable_payload' => [
                'signature_request_id',
                'final_receipt_id_and_hash',
                'authorizing_action_template_hash',
                'final_authorization_preflight_hash',
                'authorization_signature_request_hash',
                'authorization_signable_payload_hash',
                'requested_signature_type',
                'allowed_decisions',
                'default_decision',
                'drafted_authorization_fields',
                'requirements_before_the_final_receipt_can_be_signed',
                'future_executor_contract',
                'explicit_forbidden_actions_after_the_request',
            ],
            // Doc "It must not accept signature evidence, validate a signature,
            // sign a receipt, record approval, merge or dispatch work."
            'forbidden_actions' => [
                'accept_signature_evidence',
                'validate_a_signature',
                'sign_a_receipt',
                'record_approval',
                'merge',
                'dispatch_work',
            ],
            'boundary' => $this->boundaryFrom(self::SIGNATURE_REQUEST_BOUNDARY_KEYS),
        ];
    }

    /**
     * Surface 4 — the final post-signature RUNBOOK. Sequences (does not execute)
     * the external-evidence checks, ending in the mandated non-execute stop.
     * Every step before the last is a read-only check; the boundary holds.
     *
     * @return array{
     *   surface:'final_post_signature_runbook', schema:string,
     *   required_evidence:list<string>,
     *   ordered_steps:list<array{order:int,step:string,kind:string,is_terminal:bool}>,
     *   terminal_step:string, boundary:array<string,false>,
     *   signs_receipt:false
     * }
     */
    public function postSignatureRunbook(): array
    {
        return [
            'surface' => 'final_post_signature_runbook',
            'schema' => self::SCHEMA,
            // Doc "The runbook must require:".
            'required_evidence' => [
                'external_final_merge_receipt_signature_value',
                'validator_identity_and_validation_timestamp',
                'validated_final_signable_payload_hash',
                'validated_final_receipt_hash',
                'selected_decision_and_rationale',
                'fresh_merge_preflight_test_docs_health_architecture_and_diff_check_hashes',
                'scope_integrity_and_hot_scope_exclusion_statements',
                'packet_evidence_integrity_statement',
                'rollback_plan_hash',
                'human_confirmation_hash',
            ],
            'ordered_steps' => $this->runbookOrderedSteps(),
            'terminal_step' => self::RUNBOOK_TERMINAL_STEP,
            'boundary' => $this->boundaryFrom(self::RUNBOOK_BOUNDARY_KEYS),
            // Doc "The runbook must never sign the receipt."
            'signs_receipt' => false,
        ];
    }

    /**
     * The documented runbook steps, each annotated. Every step before the last
     * is a read-only check (is_terminal=false); the final step is the mandated
     * stop (is_terminal=true). No step is ever an execution.
     *
     * @return list<array{order:int,step:string,kind:string,is_terminal:bool}>
     */
    public function runbookOrderedSteps(): array
    {
        $steps = [];
        $last = count(self::RUNBOOK_STEPS) - 1;
        foreach (self::RUNBOOK_STEPS as $i => $step) {
            $isTerminal = ($i === $last);
            $steps[] = [
                'order' => $i + 1,
                'step' => $step,
                'kind' => $isTerminal ? 'halt' : 'read_only_check',
                'is_terminal' => $isTerminal,
            ];
        }

        return $steps;
    }

    /**
     * Read-only evaluation of a HYPOTHETICAL future authorization decision.
     *
     * This does NOT authorize anything — approval_granted stays false no matter
     * what. It only computes, deterministically, what the future authorizing
     * action's decision WOULD be under the documented rules:
     *
     *   - Doc: default decision is `request_changes`.
     *   - Doc: selected decision must be exactly `merge` AND all 12 validations
     *     must pass for merge to be even eligible.
     *   - Doc: "Any missing or mismatched item must produce `request_changes` or
     *     `abort`, never implicit approval."
     *
     * Decision rules (deterministic):
     *   - selected_decision absent / not in allowed set, OR any validation
     *     missing/false  => 'request_changes' (the safe default).
     *   - selected_decision present but neither 'merge' nor 'request_changes'
     *     (e.g. a malformed/unknown decision)  => 'abort'.
     *   - selected_decision === 'merge' AND every documented validation true
     *     => 'merge' (eligible) — but still NON-authorizing here.
     *
     * @param array<string,mixed> $input may contain 'selected_decision' (string)
     *   and 'validations' (array<string,bool> keyed by the documented checks).
     * @return array{
     *   surface:'authorization_decision_preview', schema:string,
     *   selected_decision:?string, default_decision:'request_changes',
     *   required_decision:'merge', allowed_decisions:list<string>,
     *   validations:array<string,bool>, failed_validations:list<string>,
     *   all_validations_pass:bool, decision:string, merge_eligible:bool,
     *   approval_granted:false, authorizes:false,
     *   boundary:array<string,false>
     * }
     */
    public function previewAuthorizationDecision(array $input = []): array
    {
        $allowed = [self::REQUIRED_DECISION, self::DEFAULT_DECISION, 'abort'];

        $selected = isset($input['selected_decision']) && is_string($input['selected_decision'])
            ? $input['selected_decision']
            : null;

        // Normalise the validation map to exactly the documented checks.
        $given = isset($input['validations']) && is_array($input['validations'])
            ? $input['validations']
            : [];
        $validations = [];
        $failed = [];
        foreach (self::REQUIRED_FUTURE_VALIDATIONS as $check) {
            $passed = array_key_exists($check, $given) && $given[$check] === true;
            $validations[$check] = $passed;
            if (! $passed) {
                $failed[] = $check;
            }
        }
        $allPass = $failed === [];

        // Deterministic decision per the doc.
        if ($selected === self::REQUIRED_DECISION && $allPass) {
            // Everything aligns: merge is ELIGIBLE (still non-authorizing here).
            $decision = self::REQUIRED_DECISION;
        } elseif ($selected !== null
            && $selected !== self::REQUIRED_DECISION
            && $selected !== self::DEFAULT_DECISION) {
            // A present but malformed/unknown decision must not silently pass.
            $decision = 'abort';
        } else {
            // Missing decision, or merge requested with any failing validation,
            // or explicitly request_changes => the safe default.
            $decision = self::DEFAULT_DECISION;
        }

        $mergeEligible = ($decision === self::REQUIRED_DECISION);

        return [
            'surface' => 'authorization_decision_preview',
            'schema' => self::SCHEMA,
            'selected_decision' => $selected,
            'default_decision' => self::DEFAULT_DECISION,
            'required_decision' => self::REQUIRED_DECISION,
            'allowed_decisions' => $allowed,
            'validations' => $validations,
            'failed_validations' => $failed,
            'all_validations_pass' => $allPass,
            'decision' => $decision,
            // "Eligible" is the strongest this read-only surface ever says.
            'merge_eligible' => $mergeEligible,
            // Hard invariant: this surface NEVER grants approval or authorizes.
            'approval_granted' => false,
            'authorizes' => false,
            'boundary' => $this->boundaryFrom(self::TEMPLATE_BOUNDARY_KEYS),
        ];
    }

    /**
     * Composite entrypoint: emit all four read-only surfaces plus a decision
     * preview, and prove the documented boundary held across every surface that
     * exposes one.
     *
     * @param array<string,mixed> $input forwarded to previewAuthorizationDecision()
     * @return array{
     *   schema:string,
     *   authorizing_action_template:array<string,mixed>,
     *   final_receipt_draft:array<string,mixed>,
     *   final_signature_request:array<string,mixed>,
     *   final_post_signature_runbook:array<string,mixed>,
     *   decision_preview:array<string,mixed>,
     *   default_decision:'request_changes',
     *   boundary_held:bool, boundary_violations:list<string>,
     *   runbook_terminal_is_last:bool, executor_is_separate:bool
     * }
     */
    public function evaluate(array $input = []): array
    {
        $template = $this->authorizingActionTemplate($input);
        $draft = $this->finalReceiptDraft();
        $sigRequest = $this->finalSignatureRequest();
        $runbook = $this->postSignatureRunbook();
        $preview = $this->previewAuthorizationDecision($input);

        $violations = $this->assertBoundaryHeld([
            ['keys' => self::TEMPLATE_BOUNDARY_KEYS, 'result' => $template],
            ['keys' => self::RECEIPT_DRAFT_BOUNDARY_KEYS, 'result' => $draft],
            ['keys' => self::SIGNATURE_REQUEST_BOUNDARY_KEYS, 'result' => $sigRequest],
            ['keys' => self::RUNBOOK_BOUNDARY_KEYS, 'result' => $runbook],
            ['keys' => self::TEMPLATE_BOUNDARY_KEYS, 'result' => $preview],
        ]);

        // Independent structural guarantee: the runbook halt is the last step.
        $steps = $runbook['ordered_steps'];
        $lastStep = $steps === [] ? null : $steps[count($steps) - 1];
        $runbookTerminalIsLast = is_array($lastStep)
            && ($lastStep['step'] ?? null) === self::RUNBOOK_TERMINAL_STEP
            && ($lastStep['is_terminal'] ?? false) === true;

        // Independent structural guarantee: the executor is a SEPARATE stage,
        // never this surface (stage 1).
        $chain = $this->executionChain();
        $executorStages = array_values(array_filter(
            $chain,
            static fn (array $s): bool => ($s['is_executor'] ?? false) === true,
        ));
        $executorIsSeparate = count($executorStages) === 1
            && ($executorStages[0]['is_this_surface'] ?? true) === false;

        return [
            'schema' => self::SCHEMA,
            'authorizing_action_template' => $template,
            'final_receipt_draft' => $draft,
            'final_signature_request' => $sigRequest,
            'final_post_signature_runbook' => $runbook,
            'decision_preview' => $preview,
            'default_decision' => self::DEFAULT_DECISION,
            'boundary_held' => $violations === [],
            'boundary_violations' => $violations,
            'runbook_terminal_is_last' => $runbookTerminalIsLast,
            'executor_is_separate' => $executorIsSeparate,
        ];
    }

    /**
     * Prove that, for each surface, none of its DOCUMENTED boundary keys was
     * ever flipped to a truthy value. Each entry carries the documented key list
     * ('keys') that surface must keep false and the surface result ('result').
     * Returns "surface.key" violations (empty list = boundary intact).
     *
     * A missing documented key counts as a breach too, so the check is
     * non-vacuous.
     *
     * @param list<array{keys:list<string>,result:array<string,mixed>}> $surfaces
     * @return list<string>
     */
    public function assertBoundaryHeld(array $surfaces): array
    {
        $violations = [];

        foreach ($surfaces as $entry) {
            $keys = is_array($entry['keys'] ?? null) ? $entry['keys'] : [];
            $result = is_array($entry['result'] ?? null) ? $entry['result'] : [];

            $label = is_string($result['surface'] ?? null) ? $result['surface'] : 'unknown';
            $boundary = is_array($result['boundary'] ?? null) ? $result['boundary'] : [];

            foreach ($keys as $key) {
                if (! array_key_exists($key, $boundary) || $boundary[$key] !== false) {
                    $violations[] = $label . '.' . $key;
                }
            }

            // Cross-cutting hard invariants restated outside the boundary array:
            // no surface may ever advertise approval, authorization or signing.
            foreach (['approval_granted', 'authorizes', 'signs_receipt', 'signature_valid'] as $extra) {
                if (array_key_exists($extra, $result) && $result[$extra] !== false) {
                    $violations[] = $label . '.' . $extra;
                }
            }
        }

        return $violations;
    }
}
