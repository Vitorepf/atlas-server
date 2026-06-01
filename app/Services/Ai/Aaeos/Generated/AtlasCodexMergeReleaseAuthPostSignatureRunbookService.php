<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Codex Merge Post-Execution Action Persistence Writer Release Authorization
 * Post-Signature Runbook — pure, deterministic, READ-ONLY surface.
 *
 * This surface answers exactly one question (doc "Human Meaning"):
 *
 *     "What sequence should a future operator follow after external signature
 *      evidence exists?"
 *
 * It deliberately does NOT answer:
 *
 *     "Has the signature been accepted or has the writer been released?"
 *
 * The answer to that second question is, and stays, no. Signature acceptance,
 * signed-receipt templating and any writer-release execution belong to LATER
 * governed surfaces — not this runbook.
 *
 * Hard boundary (doc "Boundary") — every result this service emits keeps all
 * nine keys false, always:
 *   execution_allowed=false, writer_file_creation_allowed=false,
 *   ledger_write_allowed=false, dispatch_allowed=false, approval_granted=false,
 *   merge_allowed=false, signature_valid=false, receipt_signed=false,
 *   receipt_persisted=false.
 *
 * The runbook must not, and this code does not (doc "It must not"): create
 * writer files, write ledger events, persist receipts, accept or validate
 * signatures, record decisions, approve code, merge, or dispatch work. It only
 * sequences a read-only set of external evidence checks and then stops.
 *
 * Documented invariants this code ENFORCES (not just documents):
 *   - "must keep ...=false" boundary => boundary() returns all nine keys false
 *     and every public result embeds it verbatim; assertBoundaryHeld() proves no
 *     result ever flipped a key true, and the assertion is non-vacuous.
 *   - "Ordered Steps ... stop before signature acceptance, writer creation or
 *     ledger write" => orderedSteps() emits the documented step sequence in
 *     order, every step is_terminal=false EXCEPT the final stop step, and the
 *     final step is the explicit halt token. No step is ever "execute".
 *   - "Future Validator Checks: A later validator must prove: <8 checks>" =>
 *     futureValidatorChecks() lists the eight checks a LATER validator must
 *     prove, marks every one proven=false here (this runbook proves none of
 *     them), and keeps validator_present=false.
 *   - "What sequence ... after external signature evidence exists?" vs
 *     "Has the signature been accepted or has the writer been released?" =>
 *     releaseStatus() always answers signature_accepted=false /
 *     writer_released=false with the documented deferral reason, structurally,
 *     regardless of input.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-authorization-post-signature-runbook.md
 */
final class AtlasCodexMergeReleaseAuthPostSignatureRunbookService
{
    /** Stable evidence schema id this read-only surface emits. */
    public const SCHEMA = 'atlas.self_construction_codex_merge_post_execution_action_persistence_writer_release_authorization_post_signature_runbook.v1';

    /** Surface label (closed set). */
    public const SURFACE = 'writer_release_authorization_post_signature_runbook';

    /** Status when the runbook produced its read-only sequence and then halted. */
    public const STATUS_SEQUENCED_AND_HALTED = 'post_signature_runbook_sequenced_and_halted';

    /** The explicit terminal step token: the runbook stops here. */
    public const TERMINAL_STEP = 'stop_before_signature_acceptance_writer_creation_or_ledger_write';

    /** Documented reason release / acceptance is always answered "no". */
    public const RELEASE_DEFERRED_REASON = 'signature_acceptance_and_writer_release_remain_blocked_until_a_separate_signed_receipt_template_and_release_path_exist';

    /**
     * The nine boundary keys (doc "Boundary"): every result keeps all false.
     *
     * @var list<string>
     */
    public const BOUNDARY_KEYS = [
        'execution_allowed',
        'writer_file_creation_allowed',
        'ledger_write_allowed',
        'dispatch_allowed',
        'approval_granted',
        'merge_allowed',
        'signature_valid',
        'receipt_signed',
        'receipt_persisted',
    ];

    /**
     * The eight actions the runbook MUST NOT take (doc "It must not"). Held for
     * provenance and so the surface can publish exactly what it refuses to do.
     * Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const FORBIDDEN_ACTIONS = [
        'create_writer_files',
        'write_ledger_events',
        'persist_receipts',
        'accept_or_validate_signatures',
        'record_decisions',
        'approve_code',
        'merge',
        'dispatch_work',
    ];

    /**
     * The ordered steps the runbook MAY sequence (doc "Ordered Steps"). The
     * final entry is the mandated stop; everything before it is a read-only
     * external evidence check. Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const ORDERED_STEPS = [
        'collect_external_writer_release_signature_evidence',
        'verify_request_hash_against_signable_payload',
        'verify_receipt_hash_against_signed_payload',
        'verify_signable_payload_hash_against_signature_request',
        'verify_required_writer_release_authorization_evidence_exists',
        'verify_hot_scope_is_clean',
        'prepare_a_signed_receipt_template_candidate',
        self::TERMINAL_STEP,
    ];

    /**
     * The eight checks a LATER validator must prove (doc "Future Validator
     * Checks"). This runbook proves NONE of them — it only names them as the
     * future validator's obligations. Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const FUTURE_VALIDATOR_CHECKS = [
        'external_signature_value_is_present',
        'validator_identity_is_present',
        'validation_timestamp_is_present',
        'validated_receipt_hash_matches_source',
        'validated_signable_payload_hash_matches_source',
        'selected_decision_is_explicit',
        'hot_scope_is_still_clean',
        'writer_patch_still_matches_contract_hash',
    ];

    /**
     * The nine documented boundary keys, all forced false.
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
     * Primary surface: the read-only post-signature runbook.
     *
     * Emits the documented ordered step sequence (every prior step a read-only
     * check, the final step the mandated halt), the forbidden-action list, the
     * future validator obligations, and the always-false release status — all
     * inside the held boundary. Nothing is accepted, validated, signed,
     * persisted, approved, merged or dispatched, on any input.
     *
     * @param array<string,mixed> $input ignored for gating purposes — the
     *   runbook is structurally read-only; no input can advance it past the
     *   stop. Accepted only so callers may pass context for echo if needed.
     * @return array{
     *   surface:string, schema:string, status:string,
     *   ordered_steps:list<array{order:int,step:string,kind:string,is_terminal:bool}>,
     *   terminal_step:string, step_count:int,
     *   forbidden_actions:list<string>,
     *   future_validator_checks:list<array{check:string,proven:bool}>,
     *   validator_present:false,
     *   release_status:array{question:string,signature_accepted:false,writer_released:false,reason:string,boundary:array<string,false>},
     *   boundary:array<string,false>,
     *   signature_valid:false, receipt_signed:false, receipt_persisted:false,
     *   accepts_or_validates_signature:false, halts_before_acceptance:true
     * }
     */
    public function runbook(array $input = []): array
    {
        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'status' => self::STATUS_SEQUENCED_AND_HALTED,
            'ordered_steps' => $this->orderedSteps(),
            'terminal_step' => self::TERMINAL_STEP,
            'step_count' => count(self::ORDERED_STEPS),
            'forbidden_actions' => self::FORBIDDEN_ACTIONS,
            'future_validator_checks' => $this->futureValidatorChecks(),
            // This runbook is not the validator; no validator identity is bound.
            'validator_present' => false,
            'release_status' => $this->releaseStatus(),
            'boundary' => $this->boundary(),
            // Restated per the doc's hard non-acceptance / non-release guarantee.
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'accepts_or_validates_signature' => false,
            // The whole point of the surface: it stops before doing anything.
            'halts_before_acceptance' => true,
        ];
    }

    /**
     * The documented ordered steps, each annotated. Every step before the last
     * is a read-only check (is_terminal=false); the final step is the mandated
     * stop (is_terminal=true). No step is ever an execution.
     *
     * @return list<array{order:int,step:string,kind:string,is_terminal:bool}>
     */
    public function orderedSteps(): array
    {
        $steps = [];
        $last = count(self::ORDERED_STEPS) - 1;
        foreach (self::ORDERED_STEPS as $i => $step) {
            $isTerminal = ($i === $last);
            $steps[] = [
                'order' => $i + 1,
                'step' => $step,
                // Closed kind set: read-only evidence checks vs the halt.
                'kind' => $isTerminal ? 'halt' : 'read_only_check',
                'is_terminal' => $isTerminal,
            ];
        }

        return $steps;
    }

    /**
     * The eight future-validator obligations (doc "Future Validator Checks"),
     * each marked proven=false: this runbook proves none of them. A later
     * validator surface is responsible for actually proving each.
     *
     * @return list<array{check:string,proven:bool}>
     */
    public function futureValidatorChecks(): array
    {
        $checks = [];
        foreach (self::FUTURE_VALIDATOR_CHECKS as $check) {
            $checks[] = ['check' => $check, 'proven' => false];
        }

        return $checks;
    }

    /**
     * The question this surface explicitly does NOT answer with a yes.
     *
     * Doc "Human Meaning": it does not answer "Has the signature been accepted
     * or has the writer been released?" — that "remains blocked until a separate
     * signed receipt template and release path exist." Structural: regardless of
     * any input, acceptance and release are always false.
     *
     * @return array{
     *   question:string, signature_accepted:false, writer_released:false,
     *   reason:string, boundary:array<string,false>
     * }
     */
    public function releaseStatus(): array
    {
        return [
            'question' => 'has_the_signature_been_accepted_or_has_the_writer_been_released',
            'signature_accepted' => false,
            'writer_released' => false,
            'reason' => self::RELEASE_DEFERRED_REASON,
            'boundary' => $this->boundary(),
        ];
    }

    /**
     * Convenience composite entrypoint: produce the runbook and prove the
     * boundary held across the runbook result and its release-status block.
     *
     * @param array<string,mixed> $input forwarded to runbook()
     * @return array{
     *   schema:string,
     *   runbook:array<string,mixed>,
     *   boundary_held:bool, boundary_violations:list<string>,
     *   terminal_step_is_last:bool
     * }
     */
    public function evaluate(array $input = []): array
    {
        $runbook = $this->runbook($input);
        $release = $runbook['release_status'];

        $violations = $this->assertBoundaryHeld([$runbook, $release]);

        // Independent structural guarantee: the halt really is the last step.
        $steps = $runbook['ordered_steps'];
        $lastStep = $steps === [] ? null : $steps[count($steps) - 1];
        $terminalIsLast = is_array($lastStep)
            && ($lastStep['step'] ?? null) === self::TERMINAL_STEP
            && ($lastStep['is_terminal'] ?? false) === true;

        return [
            'schema' => self::SCHEMA,
            'runbook' => $runbook,
            'boundary_held' => $violations === [],
            'boundary_violations' => $violations,
            'terminal_step_is_last' => $terminalIsLast,
        ];
    }

    /**
     * Prove that no result ever flipped a boundary key to a truthy value.
     * Returns the list of "label.key" violations (empty = boundary intact).
     *
     * @param list<array<string,mixed>> $results
     * @return list<string>
     */
    public function assertBoundaryHeld(array $results): array
    {
        $violations = [];
        foreach ($results as $result) {
            $label = is_string($result['surface'] ?? null)
                ? $result['surface']
                : (is_string($result['question'] ?? null) ? $result['question'] : 'unknown');

            $boundary = is_array($result['boundary'] ?? null) ? $result['boundary'] : [];
            foreach (self::BOUNDARY_KEYS as $key) {
                // Missing key OR truthy value both count as a breach.
                if (! array_key_exists($key, $boundary) || $boundary[$key] !== false) {
                    $violations[] = $label.'.'.$key;
                }
            }

            // The runbook restates extra guarantees outside the boundary array;
            // any of them turning truthy is a breach too.
            foreach (['signature_valid', 'receipt_signed', 'receipt_persisted', 'accepts_or_validates_signature'] as $extra) {
                if (array_key_exists($extra, $result) && $result[$extra] !== false) {
                    $violations[] = $label.'.'.$extra;
                }
            }
        }

        return $violations;
    }
}
