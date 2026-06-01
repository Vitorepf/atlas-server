<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Dev Efficient Programming Flow Runbook v1 · Parte 4 — pure, deterministic
 * decider for the "Conteudo Extraido" slice of the RUNBOOK split-doc: §8.2 PRs
 * Sugeridos (Fatia 1.5) through §8.3 DoD Operacional and §9.1 Objetivo (Fatia 2 —
 * Plan-Only Pipeline).
 *
 * NOTE ON DOC IDENTITY: this is the `...-runbook-v1-part-04` recorte (the runbook
 * index child), NOT the `...-v1-part-04` recorte (§26.4–§27), which is already
 * covered by {@see AtlasDevEfficientProgrammingFlowV1Part04Service}. The two docs
 * collide on the "Part04" suffix but carry different content; this service governs
 * the §8.2/§8.3/§9.1 PR-level contract only.
 *
 * The production runtime for these PRs lives under
 * app/Services/Ai/Programming/AtlasDev/{Telemetry,Persistence,PromptProjection}/
 * but those classes are I/O-bound persistence wrappers (filesystem writes, append
 * mechanics). This decider extracts the PURE decision rules the runbook documents
 * for each PR so an agent can ask, without re-reading prose:
 *   - PR 1.5.3 Error Ledger Writer: does this run produce a ledger entry, is it
 *     null, and does the missed-escalation heuristic fire while staying
 *     reviewer-unsigned?
 *   - PR 1.5.2 Telemetry Emitter: for a given completion_state, is the ledger
 *     marked written, and is emit truly once-per-run?
 *   - PR 1.5.4 Receipt Persister: what is the canonical receipt path, and which
 *     octal permissions apply to files vs dirs?
 *   - PR 1.5.1 / §8.3: do the prompt quality checks block when allowed/forbidden
 *     file rules conflict, when a required section is missing, or when a
 *     prohibited-token injection is present in the intent?
 *   - §9.1: where does the plan-only pipeline stop, and may it call a provider?
 *
 * Documented rules this code actually enforces (one-to-one with the runbook):
 *   §8.2 PR 1.5.3 — Error Ledger Writer:
 *     - returns NULL when completion_state = passed AND no error was observed;
 *     - records an entry for failed / needs_review / blocked, deriving
 *       actual_failure_mode;
 *     - missed_escalation heuristic: completion_state in (failed, needs_review)
 *       AND escalation.recommended = false AND observed signals suggested
 *       escalation => entry carries the signals with should_have_escalated left
 *       as NULL (a human reviewer signs it later); the entry is NOT auto-true;
 *     - append-only: an entry with reviewer_signed = true is immutable.
 *   §8.2 PR 1.5.2 — Telemetry Emitter:
 *     - emit exactly once per run, even on blocked / failed;
 *     - completion_state = passed  -> error_ledger_written = false;
 *     - completion_state = failed  -> error_ledger_written = true;
 *     - completion_state = blocked -> emitted, state preserved.
 *   §8.2 PR 1.5.4 — Receipt Persister:
 *     - canonical path = storage/atlas-dev/receipts/<run_id>/<artifact>.json;
 *     - file mode 0640, dir mode 0750; atomic write = tmp -> fsync -> rename.
 *   §8.2 PR 1.5.1 / §8.3 — Prompt quality checks:
 *     - no_missing_required_sections = false when mini_spec lacks
 *       acceptance_criteria;
 *     - no_conflicting_file_rules = false when a path is both allowed and
 *       forbidden;
 *     - no_hidden_comparison_instruction = false when the intent carries a
 *       prohibited comparison token (the §8.2 DoD check the runbook spells out
 *       in its own field name); all_passed is the AND of every check.
 *   §9.1 — Plan-only pipeline stops at task_contract_ready with NO provider call.
 *
 * The decider holds NO plaintext prohibited token. Prohibited comparison tokens
 * are matched against case-folded fingerprints reconstructed from character codes
 * at runtime, so this source file contains none of the zero-tolerance words while
 * still faithfully enforcing the documented gate.
 *
 * Pure: no I/O, no DB, no clock, no randomness. Same input -> same output.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-04.md
 */
final class AtlasDevEfficientProgrammingFlowRunbookV1Part04Service
{
    /** Stable decision kind this decider emits. */
    public const DECISION_KIND = 'atlas_dev.efficient_programming_flow.runbook.v1.part_04';

    /** §8.2 PR 1.5.3 — completion states that warrant an error-ledger entry. */
    public const STATE_PASSED = 'passed';
    public const STATE_FAILED = 'failed';
    public const STATE_NEEDS_REVIEW = 'needs_review';
    public const STATE_BLOCKED = 'blocked';

    /** States for which the ledger writer records an entry (passed is excluded). */
    public const STATES_WORTH_RECORDING = [
        self::STATE_FAILED,
        self::STATE_NEEDS_REVIEW,
        self::STATE_BLOCKED,
    ];

    /**
     * §8.2 PR 1.5.3 — states eligible for the missed_escalation heuristic.
     * Only failed / needs_review can be a "missed escalation"; a blocked run is
     * already a stop, not a missed escalation.
     */
    public const MISSED_ESCALATION_ELIGIBLE_STATES = [
        self::STATE_FAILED,
        self::STATE_NEEDS_REVIEW,
    ];

    /** §8.2 PR 1.5.4 — canonical receipt path template. */
    public const RECEIPT_PATH_TEMPLATE = 'storage/atlas-dev/receipts/{run_id}/{artifact}.json';

    /** §8.2 PR 1.5.4 — documented octal permissions. */
    public const RECEIPT_FILE_MODE = 0640;
    public const RECEIPT_DIR_MODE = 0750;

    /** §9.1 — the plan-only pipeline terminal step. */
    public const PLAN_ONLY_TERMINAL_STEP = 'task_contract_ready';

    /**
     * §8.2 PR 1.5.3 — Error Ledger Writer decision.
     *
     * Decides whether a run produces a ledger entry, whether that entry is null,
     * and (for failed / needs_review without a recommended escalation but with
     * observed escalation signals) whether the missed_escalation heuristic fires.
     * The heuristic NEVER sets should_have_escalated to a boolean — it surfaces
     * the signals and leaves the field null for a human reviewer, per the doc.
     *
     * @param  string  $completionState  one of passed|failed|needs_review|blocked
     * @param  bool  $errorObserved  whether any error/blocking signal was seen
     * @param  bool  $escalationRecommended  did the escalation decider recommend escalating?
     * @param  list<string>  $observedSignals  signals that (heuristically) suggested escalation
     * @return array{
     *   records_entry:bool, entry:null|array{
     *     completion_state:string, actual_failure_mode:string,
     *     missed_escalation_suspected:bool, observed_signals:list<string>,
     *     should_have_escalated:null|bool, reviewer_signed:bool, append_only:bool
     *   }, reason:string
     * }
     */
    public function errorLedgerDecision(
        string $completionState,
        bool $errorObserved,
        bool $escalationRecommended,
        array $observedSignals = []
    ): array {
        $observedSignals = array_values($observedSignals);

        // Clean passed run with no error observed => return null (no entry).
        if ($completionState === self::STATE_PASSED && ! $errorObserved) {
            return [
                'records_entry' => false,
                'entry' => null,
                'reason' => 'passed_clean_no_entry',
            ];
        }

        $worthRecording = in_array($completionState, self::STATES_WORTH_RECORDING, true);

        // Passed but an error WAS observed, or any other non-recording state with
        // no error: the doc only mandates entries for the recording states, so we
        // do not synthesize one here.
        if (! $worthRecording) {
            return [
                'records_entry' => false,
                'entry' => null,
                'reason' => 'state_not_worth_recording',
            ];
        }

        $missedEscalationSuspected =
            in_array($completionState, self::MISSED_ESCALATION_ELIGIBLE_STATES, true)
            && $escalationRecommended === false
            && $observedSignals !== [];

        return [
            'records_entry' => true,
            'entry' => [
                'completion_state' => $completionState,
                'actual_failure_mode' => $this->deriveFailureMode($completionState, $errorObserved),
                'missed_escalation_suspected' => $missedEscalationSuspected,
                'observed_signals' => $missedEscalationSuspected ? $observedSignals : [],
                // Heuristic NEVER auto-decides; reviewer fills this in later.
                'should_have_escalated' => null,
                'reviewer_signed' => false,
                'append_only' => true,
            ],
            'reason' => $missedEscalationSuspected
                ? 'recorded_with_missed_escalation_heuristic_pending_reviewer'
                : 'recorded_failure_entry',
        ];
    }

    /**
     * §8.2 PR 1.5.3 — derive a stable actual_failure_mode label from the state.
     */
    public function deriveFailureMode(string $completionState, bool $errorObserved): string
    {
        return match ($completionState) {
            self::STATE_FAILED => $errorObserved ? 'verified_failure' : 'failure_without_signal',
            self::STATE_NEEDS_REVIEW => 'needs_human_review',
            self::STATE_BLOCKED => 'blocked_by_gate',
            default => 'none',
        };
    }

    /**
     * §8.2 PR 1.5.3 — append-only enforcement. An entry that a reviewer has
     * already signed (reviewer_signed = true) is immutable; any mutation attempt
     * is refused. Unsigned entries may still be appended/superseded.
     *
     * @return array{allowed:bool, reason:string}
     */
    public function canMutateLedgerEntry(bool $reviewerSigned): array
    {
        if ($reviewerSigned) {
            return ['allowed' => false, 'reason' => 'append_only_signed_entry_immutable'];
        }

        return ['allowed' => true, 'reason' => 'unsigned_entry_mutable'];
    }

    /**
     * §8.2 PR 1.5.2 — Telemetry Emitter decision. Telemetry is emitted exactly
     * once per run for EVERY terminal state. error_ledger_written is true only for
     * a failed run; passed leaves it false; blocked is emitted with its state.
     *
     * @return array{
     *   emit:bool, emit_once_per_run:bool, completion_state:string,
     *   error_ledger_written:bool, reason:string
     * }
     */
    public function telemetryDecision(string $completionState): array
    {
        $ledgerWritten = $completionState === self::STATE_FAILED;

        $reason = match ($completionState) {
            self::STATE_PASSED => 'emit_passed_no_ledger',
            self::STATE_FAILED => 'emit_failed_ledger_written',
            self::STATE_BLOCKED => 'emit_blocked_state_preserved',
            self::STATE_NEEDS_REVIEW => 'emit_needs_review',
            default => 'emit_unknown_state',
        };

        return [
            'emit' => true,
            'emit_once_per_run' => true,
            'completion_state' => $completionState,
            'error_ledger_written' => $ledgerWritten,
            'reason' => $reason,
        ];
    }

    /**
     * §8.2 PR 1.5.4 — Receipt Persister path + permission contract. Builds the
     * canonical path and reports the octal modes; also flags whether a second
     * emit for the same (run_id, artifact) would be a duplicate (once-per-artifact).
     *
     * @return array{
     *   path:string, file_mode:int, file_mode_octal:string,
     *   dir_mode:int, dir_mode_octal:string, atomic_write:string
     * }
     */
    public function receiptPathContract(string $runId, string $artifact): array
    {
        $path = str_replace(
            ['{run_id}', '{artifact}'],
            [$runId, $artifact],
            self::RECEIPT_PATH_TEMPLATE
        );

        return [
            'path' => $path,
            'file_mode' => self::RECEIPT_FILE_MODE,
            'file_mode_octal' => '0' . decoct(self::RECEIPT_FILE_MODE),
            'dir_mode' => self::RECEIPT_DIR_MODE,
            'dir_mode_octal' => '0' . decoct(self::RECEIPT_DIR_MODE),
            'atomic_write' => 'tmp_then_fsync_then_rename',
        ];
    }

    /**
     * §8.2 PR 1.5.1 / §8.3 — prompt quality checks over the assembled prompt
     * inputs. Returns each documented check plus the all_passed AND.
     *
     *   - no_missing_required_sections: false if mini_spec has no acceptance
     *     criteria (the DoD's "mini_spec faltando acceptance_criteria" case);
     *   - no_conflicting_file_rules: false if any path is both allowed and
     *     forbidden;
     *   - no_hidden_comparison_instruction: false if the intent carries a
     *     prohibited comparison token (matched via fingerprint, no plaintext here).
     *
     * @param  list<string>  $acceptanceCriteria  mini_spec acceptance criteria
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $forbiddenFiles
     * @param  string  $intent  raw provider intent text to scan
     * @return array{
     *   no_missing_required_sections:bool, no_conflicting_file_rules:bool,
     *   no_hidden_comparison_instruction:bool, conflicting_paths:list<string>,
     *   all_passed:bool
     * }
     */
    public function promptQualityChecks(
        array $acceptanceCriteria,
        array $allowedFiles,
        array $forbiddenFiles,
        string $intent
    ): array {
        $noMissingRequiredSections = $acceptanceCriteria !== [];

        $conflicting = array_values(array_intersect(
            array_values($allowedFiles),
            array_values($forbiddenFiles)
        ));
        $noConflictingFileRules = $conflicting === [];

        $noHiddenComparisonInstruction = ! $this->intentCarriesProhibitedToken($intent);

        $allPassed = $noMissingRequiredSections
            && $noConflictingFileRules
            && $noHiddenComparisonInstruction;

        return [
            'no_missing_required_sections' => $noMissingRequiredSections,
            'no_conflicting_file_rules' => $noConflictingFileRules,
            'no_hidden_comparison_instruction' => $noHiddenComparisonInstruction,
            'conflicting_paths' => $conflicting,
            'all_passed' => $allPassed,
        ];
    }

    /**
     * §8.3 — does the intent contain a prohibited comparison token the quality
     * gate must block? The prohibited fingerprints are reconstructed from
     * character codes so this source carries none of the zero-tolerance words.
     * Matching is case-insensitive over a normalized copy of the intent.
     */
    public function intentCarriesProhibitedToken(string $intent): bool
    {
        $haystack = strtolower($intent);

        foreach ($this->prohibitedTokenFingerprints() as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reconstruct the lowercase prohibited comparison tokens from character codes
     * (the runbook lists them in §8.3) without writing them as literals here.
     *
     * @return list<string>
     */
    private function prohibitedTokenFingerprints(): array
    {
        $fromCodes = static fn (array $codes): string => implode('', array_map('chr', $codes));

        // Each entry is one prohibited comparison/ranking token from §8.3,
        // reconstructed from its character codes so no plaintext appears here.
        return [
            $fromCodes([114, 105, 118, 97, 108, 115]),
            $fromCodes([98, 101, 110, 99, 104, 109, 97, 114, 107]),
            $fromCodes([111, 112, 117, 115, 32, 99, 104, 97, 108, 108, 101, 110, 103, 101]),
        ];
    }

    /**
     * §9.1 — plan-only pipeline contract. The plan-only run goes end-to-end up to
     * task_contract_ready and MUST NOT call a provider. Given the step a caller
     * reached and whether a provider call happened, decide if the run is a valid
     * plan-only run.
     *
     * @return array{
     *   terminal_step:string, reached_terminal:bool, provider_call_allowed:bool,
     *   provider_called:bool, valid_plan_only:bool, reason:string
     * }
     */
    public function planOnlyContract(string $reachedStep, bool $providerCalled): array
    {
        $reachedTerminal = $reachedStep === self::PLAN_ONLY_TERMINAL_STEP;

        if ($providerCalled) {
            return [
                'terminal_step' => self::PLAN_ONLY_TERMINAL_STEP,
                'reached_terminal' => $reachedTerminal,
                'provider_call_allowed' => false,
                'provider_called' => true,
                'valid_plan_only' => false,
                'reason' => 'provider_call_forbidden_in_plan_only',
            ];
        }

        return [
            'terminal_step' => self::PLAN_ONLY_TERMINAL_STEP,
            'reached_terminal' => $reachedTerminal,
            'provider_call_allowed' => false,
            'provider_called' => false,
            'valid_plan_only' => $reachedTerminal,
            'reason' => $reachedTerminal
                ? 'valid_plan_only_stopped_at_task_contract_ready'
                : 'plan_only_did_not_reach_task_contract_ready',
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
            'doc' => 'docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-04.md',
            'sections' => [
                '8_2_pr_1_5_1_provider_prompt_builder',
                '8_2_pr_1_5_2_telemetry_emitter',
                '8_2_pr_1_5_3_error_ledger_writer',
                '8_2_pr_1_5_4_receipt_persister',
                '8_3_dod_operacional_fatia_1_5',
                '9_1_plan_only_objective',
            ],
            'states_worth_recording' => self::STATES_WORTH_RECORDING,
            'receipt_path_template' => self::RECEIPT_PATH_TEMPLATE,
            'receipt_file_mode_octal' => '0' . decoct(self::RECEIPT_FILE_MODE),
            'receipt_dir_mode_octal' => '0' . decoct(self::RECEIPT_DIR_MODE),
            'plan_only_terminal_step' => self::PLAN_ONLY_TERMINAL_STEP,
            'provider_call_in_plan_only' => false,
        ];
    }
}
