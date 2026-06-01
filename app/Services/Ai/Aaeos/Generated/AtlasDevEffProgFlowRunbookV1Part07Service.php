<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Dev Efficient Programming Flow Runbook v1 · Parte 7 — pure, deterministic
 * decider for the "Conteudo Extraido" slice (§10.4 Marco 3 One-Call no Atlas AI
 * Desktop through §12.1 Objetivo: Surface Parity + EscalationDecision).
 *
 * This service executes nothing and touches no I/O. The richer runtime already
 * lives elsewhere:
 *   - the Run gate + confirmation token in
 *     App\Services\Ai\Programming\AtlasDev\Security\ConfirmationTokenService;
 *   - the repair loop in App\Services\Ai\Programming\AtlasDev\Repair
 *     (FailureCapsuleBuilder, FailureSignatureHasher) and
 *     App\Services\Ai\Kernel\Repair (AtlasRepairOrchestrator);
 *   - the surface adapter in
 *     App\Services\Ai\Surface\Adapters\AtlasDesktopAiSurfaceAdapter.
 * Here we encode the doc-as-law contract for part-07 as a closed set of typed
 * decisions so an agent (or any caller) can ask, without re-reading prose:
 *   - §10.4 Run gate: given operator_confirmed + a task_contract_hash match +
 *     a single-use confirmation_token state, does the backend ACCEPT or REJECT
 *     the Run, and with which HTTP status (400/422/403)?
 *   - §10.4 Confirmation token: is the token redeemable given its binding to
 *     (run_id, task_contract_hash), its 5-minute TTL, single-use invalidation,
 *     and the fail-closed ATLAS_DEV_KEY_MISSING rule when APP_KEY < 32 bytes?
 *     And is it even issuable (only on routing_decision = atlas_dev_fast_path)?
 *   - §10.4 Streaming: what is the locked snapshot-replay-then-close event
 *     order, and which REST fields are the resume / source-of-truth contract?
 *   - §11 Repair loop: given a normalized error, what is the deterministic
 *     failure_signature, and given the attempt index / same-signature / diff-
 *     growth / scope signals, is the loop decision continue | stop | escalate?
 *   - §12.1 Surface boundary: which inputs the desktop adapter MAY receive and
 *     which it MUST NOT (no final prompt, no ProviderPromptProjection, no
 *     surface-custom prompt).
 *
 * Documented rules this code actually enforces (one-to-one with the doc):
 *   §10.4 "Sem operator_confirmed=true, task_contract_hash valido e
 *     confirmation_token single-use, o backend rejeita a execucao."
 *     -> all three conditions are required (logical AND); any miss rejects.
 *   §10.4 status mapping covered by the feature test: 400 without
 *     operator_confirmed, 422 with an invalid task_contract_hash, 403 with a
 *     token absent/invalid/expired/reused/contract-mismatch.
 *   §10.4 confirmation token: TTL 5min (atlas_dev.confirmation_token.ttl_seconds
 *     = 300), bound to (run_id, task_contract_hash) so a token issued for one
 *     plan never redeems another, invalidated on the first atomic consume, and
 *     issued only when routing_decision = atlas_dev_fast_path; APP_KEY base64
 *     with >= 32 bytes is a precondition, else Plan/Run fail closed with
 *     ATLAS_DEV_KEY_MISSING.
 *   §10.4 streaming "snapshot-replay-then-close": deterministic order is a
 *     phase per already-persisted artifact, then the final receipt (if any),
 *     then a stream_closed marker, then the stream closes; the persisted
 *     receipt (DB + filesystem) is the source of truth and REST GET
 *     /runs/{run_id} is the resume contract.
 *   §11.2 PR 4.1 failure_signature = sha256(gate + "::" + normalize(error));
 *     decision retry|stop|escalate (retry while attempt_index < max_attempts
 *     and not the same signature; escalate on same signature OR diff growing OR
 *     new scope; stop when max_attempts reached).
 *   §11.2 PR 4.2 RepairOrchestrator decision continue|stop|escalate (green ->
 *     continue with no new capsule; same signature twice -> escalate; exceed
 *     max_attempts -> stop; diff growing -> escalate), honoring
 *     repair_policy.abort_on_same_signature_twice.
 *   §12.1 the desktop adapter receives raw intent + workspace + UX selections
 *     and never receives the final prompt, never builds ProviderPromptProjection
 *     on the frontend, and never permits a surface-custom prompt.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-07.md
 */
final class AtlasDevEffProgFlowRunbookV1Part07Service
{
    /** Stable decision kind this decider emits. */
    public const DECISION_KIND = 'atlas_dev.efficient_programming_flow.runbook.v1.part_07';

    // §10.4 — Run gate verdicts and the documented HTTP status codes.
    public const RUN_ACCEPT = 'accept';

    public const RUN_REJECT = 'reject';

    public const HTTP_OK = 200;

    /** Missing operator_confirmed=true (bad request shape). */
    public const HTTP_MISSING_CONFIRMATION = 400;

    /** task_contract_hash invalid / does not match the plan. */
    public const HTTP_INVALID_CONTRACT_HASH = 422;

    /** Confirmation token absent/invalid/expired/reused/contract-mismatch. */
    public const HTTP_TOKEN_REJECTED = 403;

    /** Fail-closed when APP_KEY base64 has < 32 bytes. */
    public const HTTP_KEY_MISSING = 500;

    public const ERROR_KEY_MISSING = 'ATLAS_DEV_KEY_MISSING';

    // §10.4 — confirmation token states.
    public const TOKEN_VALID = 'valid';

    public const TOKEN_ABSENT = 'absent';

    public const TOKEN_INVALID = 'invalid';

    public const TOKEN_EXPIRED = 'expired';

    public const TOKEN_REUSED = 'reused';

    public const TOKEN_CONTRACT_MISMATCH = 'contract_mismatch';

    /** §10.4 — TTL of a confirmation token, in seconds (5 minutes). */
    public const CONFIRMATION_TOKEN_TTL_SECONDS = 300;

    /** §10.4 — APP_KEY must decode to at least this many bytes. */
    public const MIN_APP_KEY_BYTES = 32;

    /** §10.4 — a token is issuable only for this routing decision. */
    public const ISSUE_ON_ROUTING_DECISION = 'atlas_dev_fast_path';

    // §11.2 — repair-loop step decisions (mirror FailureCapsule + orchestrator).
    public const REPAIR_RETRY = 'retry';

    public const REPAIR_STOP = 'stop';

    public const REPAIR_ESCALATE = 'escalate';

    /** §11.2 PR 4.2 — orchestrator emits `continue` for a green repair. */
    public const ORCHESTRATOR_CONTINUE = 'continue';

    /**
     * §10.4 — the locked snapshot-replay-then-close SSE event order. A `phase:`
     * per already-persisted artifact, the final `receipt:` (if any), then the
     * `stream_closed:` marker, then the backend closes the stream.
     */
    private const STREAM_EVENT_ORDER = [
        'phase:executing',
        'phase:scope_guarding',
        'phase:verifying',
        'test_started',
        'test_finished',
        'phase:complete',
        'receipt',
        'stream_closed',
    ];

    /**
     * §10.4 — REST fields that `GET /runs/{run_id}` exposes as the resume
     * contract / source of truth when SSE is lost or the Desktop reloads.
     */
    private const REST_RESUME_FIELDS = [
        'completion_state',
        'has_receipt',
        'routing',
        'persisted_artifact_refs',
        'workspace_label',
        'workspace_hash',
    ];

    /**
     * §10.4 — Run gate. The backend rejects the execution unless ALL THREE hold:
     * operator_confirmed=true, the task_contract_hash matches the plan, and a
     * single-use confirmation token is currently valid. The verdict carries the
     * documented HTTP status so the gate is auditable without the controller.
     *
     * Precedence of the failure status mirrors the feature-test contract:
     *   key missing (500) > missing confirmation (400) > bad hash (422) >
     *   token rejected (403).
     *
     * @return array{
     *   verdict:string, http_status:int, accepted:bool, error:string|null,
     *   failed_condition:string|null, reason:string
     * }
     */
    public function evaluateRunGate(
        bool $operatorConfirmed,
        bool $taskContractHashMatches,
        string $tokenState,
        bool $appKeyPresent = true,
    ): array {
        // Fail closed first: without a usable APP_KEY the token layer cannot
        // verify anything, so Plan/Run fail closed regardless of the rest.
        if (! $appKeyPresent) {
            return $this->runResult(
                self::RUN_REJECT,
                self::HTTP_KEY_MISSING,
                self::ERROR_KEY_MISSING,
                'app_key',
                'app_key_below_32_bytes_fail_closed',
            );
        }

        if (! $operatorConfirmed) {
            return $this->runResult(
                self::RUN_REJECT,
                self::HTTP_MISSING_CONFIRMATION,
                'missing_operator_confirmed',
                'operator_confirmed',
                'operator_confirmed_must_be_true',
            );
        }

        if (! $taskContractHashMatches) {
            return $this->runResult(
                self::RUN_REJECT,
                self::HTTP_INVALID_CONTRACT_HASH,
                'invalid_task_contract_hash',
                'task_contract_hash',
                'task_contract_hash_must_match_plan',
            );
        }

        if ($tokenState !== self::TOKEN_VALID) {
            return $this->runResult(
                self::RUN_REJECT,
                self::HTTP_TOKEN_REJECTED,
                'confirmation_token_rejected',
                'confirmation_token',
                'token_'.$tokenState,
            );
        }

        return $this->runResult(
            self::RUN_ACCEPT,
            self::HTTP_OK,
            null,
            null,
            'all_run_conditions_satisfied',
        );
    }

    /**
     * §10.4 — the canonical confirmation-token contract (DB + HMAC; no
     * filesystem store after F-05).
     *
     * @return array<string, mixed>
     */
    public function confirmationTokenContract(): array
    {
        return [
            'implementation' => 'db_plus_hmac',
            'filesystem_store' => false,
            'legacy_store_removed' => 'ConfirmationTokenStore',
            'table' => 'atlas_dev_confirmation_tokens',
            'columns' => [
                'run_id',
                'task_contract_hash',
                'surface_id',
                'token_hash',
                'issued_at',
                'used_at',
                'expires_at',
            ],
            'token_hash_algorithm' => 'hmac_sha256',
            'hmac_key_source' => 'app_key_base64',
            'ttl_seconds' => self::CONFIRMATION_TOKEN_TTL_SECONDS,
            'bound_to' => ['run_id', 'task_contract_hash'],
            'single_use' => true,
            'invalidated_on' => 'first_atomic_consume',
            'race_protected_by' => 'db_unique_constraint',
            'issued_on_routing_decision' => self::ISSUE_ON_ROUTING_DECISION,
            'min_app_key_bytes' => self::MIN_APP_KEY_BYTES,
            'fail_closed_error' => self::ERROR_KEY_MISSING,
            'required_migrations' => [
                'atlas_dev_confirmation_tokens',
                'atlas_dev_run_index',
            ],
        ];
    }

    /**
     * §10.4 — decide whether a confirmation token is redeemable for a given
     * (run_id, task_contract_hash) at a given moment. A token bound to a
     * different plan never redeems (contract mismatch -> 403); an expired token
     * (issued_at + TTL <= now) is rejected; an already-consumed token is reused
     * (-> 403); a token issued for a non-fast-path plan is not issuable at all.
     *
     * @return array{
     *   redeemable:bool, state:string, http_status:int, ttl_seconds:int,
     *   age_seconds:int, reason:string
     * }
     */
    public function decideConfirmationToken(
        bool $exists,
        bool $runIdMatches,
        bool $taskContractHashMatches,
        bool $alreadyConsumed,
        int $ageSeconds,
        string $issuedForRoutingDecision = self::ISSUE_ON_ROUTING_DECISION,
    ): array {
        if ($issuedForRoutingDecision !== self::ISSUE_ON_ROUTING_DECISION) {
            return $this->tokenResult(false, self::TOKEN_INVALID, $ageSeconds, 'not_issuable_outside_fast_path');
        }
        if (! $exists) {
            return $this->tokenResult(false, self::TOKEN_ABSENT, $ageSeconds, 'token_absent');
        }
        if (! $runIdMatches || ! $taskContractHashMatches) {
            return $this->tokenResult(false, self::TOKEN_CONTRACT_MISMATCH, $ageSeconds, 'token_bound_to_other_plan');
        }
        if ($alreadyConsumed) {
            return $this->tokenResult(false, self::TOKEN_REUSED, $ageSeconds, 'token_already_consumed');
        }
        if ($ageSeconds >= self::CONFIRMATION_TOKEN_TTL_SECONDS) {
            return $this->tokenResult(false, self::TOKEN_EXPIRED, $ageSeconds, 'token_past_ttl');
        }

        return $this->tokenResult(true, self::TOKEN_VALID, $ageSeconds, 'token_valid_and_bound');
    }

    /**
     * §10.4 — the locked streaming plan: deterministic snapshot-replay-then-close
     * event order plus the REST resume contract. `stream_closed` is the canonical
     * end-of-stream marker; the persisted receipt is the source of truth.
     *
     * @return array{
     *   policy:string, keepalive:bool, source_of_truth:string,
     *   event_order:list<string>, terminal_marker:string,
     *   rest_resume_endpoint:string, rest_resume_fields:list<string>,
     *   live_async:string
     * }
     */
    public function streamingPlan(): array
    {
        return [
            'policy' => 'snapshot_replay_then_close',
            'keepalive' => false,
            'source_of_truth' => 'persisted_receipt',
            'event_order' => self::STREAM_EVENT_ORDER,
            'terminal_marker' => 'stream_closed',
            'rest_resume_endpoint' => 'GET /ai/interactions/atlas-dev/runs/{run_id}',
            'rest_resume_fields' => self::REST_RESUME_FIELDS,
            'live_async' => 'future_evolution',
        ];
    }

    /**
     * §11.2 PR 4.1 — the deterministic failure signature
     * `sha256(gate + "::" + normalize(error))`. Mirrors the runtime DTO
     * {@see \App\Services\Ai\Programming\AtlasDev\Schemas\FailureCapsule::signatureOf()}
     * byte-for-byte: normalize = collapse whitespace runs, trim, lowercase.
     */
    public function failureSignatureOf(string $gate, string $primaryError): string
    {
        $normalized = strtolower(trim((string) preg_replace('/\s+/', ' ', $primaryError)));

        return 'sha256:'.hash('sha256', $gate.'::'.$normalized);
    }

    /**
     * §11.2 PR 4.1 / PR 4.2 — the repair-loop step decision. Combines the
     * capsule decision (retry|stop|escalate) with the orchestrator's `continue`
     * verdict for a green attempt:
     *   - green attempt           -> continue (newCapsule = null);
     *   - same signature twice     -> escalate (when abort_on_same_signature_twice);
     *   - diff growing OR new scope -> escalate;
     *   - max_attempts reached      -> stop;
     *   - otherwise                 -> retry.
     *
     * Escalation signals always win over the budget check, exactly as the
     * runtime builder folds them. `attemptIndex` is the just-evaluated attempt;
     * the loop allows a retry only while attemptIndex + 1 <= maxAttempts.
     *
     * @return array{
     *   decision:string, signals:list<string>, attempt_index:int,
     *   max_attempts:int, retries_remaining:int, new_capsule:bool, reason:string
     * }
     */
    public function decideRepairLoopStep(
        bool $passed,
        int $attemptIndex,
        int $maxAttempts,
        bool $sameSignatureRepeated,
        bool $diffGrew,
        bool $newScope,
        bool $abortOnSameSignatureTwice = true,
    ): array {
        if ($passed) {
            return $this->repairResult(
                self::ORCHESTRATOR_CONTINUE,
                [],
                $attemptIndex,
                $maxAttempts,
                false,
                'repair_turned_green',
            );
        }

        $signals = [];
        if ($newScope) {
            $signals[] = FailureCapsuleBuilderSignals::SCOPE_VIOLATION;
        }
        if ($sameSignatureRepeated && $abortOnSameSignatureTwice) {
            $signals[] = FailureCapsuleBuilderSignals::SAME_SIGNATURE_TWICE;
        }
        if ($diffGrew) {
            $signals[] = FailureCapsuleBuilderSignals::DIFF_GROWTH;
        }

        if ($signals !== []) {
            return $this->repairResult(
                self::REPAIR_ESCALATE,
                $signals,
                $attemptIndex,
                $maxAttempts,
                true,
                'honest_dead_end_escalation',
            );
        }

        // Budget: a retry is allowed only while the next attempt index fits.
        $nextAttempt = $attemptIndex + 1;
        if ($nextAttempt > $maxAttempts) {
            return $this->repairResult(
                self::REPAIR_STOP,
                [FailureCapsuleBuilderSignals::MAX_ATTEMPTS_REACHED],
                $attemptIndex,
                $maxAttempts,
                true,
                'attempt_budget_exhausted',
            );
        }

        return $this->repairResult(
            self::REPAIR_RETRY,
            [],
            $attemptIndex,
            $maxAttempts,
            true,
            'retry_allowed_under_budget',
        );
    }

    /**
     * §12.1 — the desktop surface adapter boundary. The adapter MAY receive raw
     * intent, the workspace and UX selections; it MUST NOT receive the final
     * prompt, build a ProviderPromptProjection on the frontend, or accept a
     * surface-custom prompt.
     *
     * @return array{
     *   allowed_inputs:list<string>, forbidden_inputs:list<string>,
     *   builds_prompt_projection_on_frontend:bool, allows_custom_prompt:bool,
     *   adapter:string, runtime:string
     * }
     */
    public function surfaceAdapterBoundary(): array
    {
        return [
            'allowed_inputs' => ['raw_intent', 'workspace', 'ux_selections'],
            'forbidden_inputs' => ['final_prompt', 'provider_prompt_projection', 'surface_custom_prompt'],
            'builds_prompt_projection_on_frontend' => false,
            'allows_custom_prompt' => false,
            'adapter' => 'App\\Services\\Ai\\Surface\\Adapters\\AtlasDesktopAiSurfaceAdapter',
            'runtime' => 'App\\Services\\Ai\\Programming\\AtlasDevRuntimeService',
        ];
    }

    /**
     * §12.1 — does a proposed adapter input set respect the boundary? Any
     * forbidden input present makes the payload non-conformant (fail closed).
     *
     * @param  list<string>  $providedInputs
     * @return array{conformant:bool, violations:list<string>, reason:string}
     */
    public function evaluateSurfaceInputs(array $providedInputs): array
    {
        $boundary = $this->surfaceAdapterBoundary();
        $forbidden = $boundary['forbidden_inputs'];

        $violations = [];
        foreach ($providedInputs as $input) {
            if (in_array($input, $forbidden, true)) {
                $violations[] = $input;
            }
        }

        return [
            'conformant' => $violations === [],
            'violations' => $violations,
            'reason' => $violations === []
                ? 'inputs_within_adapter_boundary'
                : 'surface_passed_forbidden_input',
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
            'doc' => 'docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-07.md',
            'sections' => [
                '10_4_marco_3_one_call_visible_desktop',
                '10_4_confirmation_token_db_hmac',
                '10_4_streaming_snapshot_replay_then_close',
                '11_repair_loop_failure_capsule_and_orchestrator',
                '11_4_marco_4_repair_visible_desktop',
                '12_1_surface_parity_objective',
            ],
            'run_gate_status_codes' => [
                'missing_operator_confirmed' => self::HTTP_MISSING_CONFIRMATION,
                'invalid_task_contract_hash' => self::HTTP_INVALID_CONTRACT_HASH,
                'token_rejected' => self::HTTP_TOKEN_REJECTED,
                'key_missing' => self::HTTP_KEY_MISSING,
            ],
            'confirmation_token_ttl_seconds' => self::CONFIRMATION_TOKEN_TTL_SECONDS,
            'repair_decisions' => [
                self::ORCHESTRATOR_CONTINUE,
                self::REPAIR_RETRY,
                self::REPAIR_STOP,
                self::REPAIR_ESCALATE,
            ],
            'runtime_pointers' => [
                'confirmation_token' => 'App\\Services\\Ai\\Programming\\AtlasDev\\Security\\ConfirmationTokenService',
                'failure_capsule' => 'App\\Services\\Ai\\Programming\\AtlasDev\\Repair\\FailureCapsuleBuilder',
                'repair_orchestrator' => 'App\\Services\\Ai\\Kernel\\Repair\\AtlasRepairOrchestrator',
                'surface_adapter' => 'App\\Services\\Ai\\Surface\\Adapters\\AtlasDesktopAiSurfaceAdapter',
            ],
        ];
    }

    /**
     * @return array{verdict:string, http_status:int, accepted:bool, error:string|null, failed_condition:string|null, reason:string}
     */
    private function runResult(string $verdict, int $status, ?string $error, ?string $failedCondition, string $reason): array
    {
        return [
            'verdict' => $verdict,
            'http_status' => $status,
            'accepted' => $verdict === self::RUN_ACCEPT,
            'error' => $error,
            'failed_condition' => $failedCondition,
            'reason' => $reason,
        ];
    }

    /**
     * @return array{redeemable:bool, state:string, http_status:int, ttl_seconds:int, age_seconds:int, reason:string}
     */
    private function tokenResult(bool $redeemable, string $state, int $ageSeconds, string $reason): array
    {
        return [
            'redeemable' => $redeemable,
            'state' => $state,
            'http_status' => $redeemable ? self::HTTP_OK : self::HTTP_TOKEN_REJECTED,
            'ttl_seconds' => self::CONFIRMATION_TOKEN_TTL_SECONDS,
            'age_seconds' => $ageSeconds,
            'reason' => $reason,
        ];
    }

    /**
     * @param  list<string>  $signals
     * @return array{decision:string, signals:list<string>, attempt_index:int, max_attempts:int, retries_remaining:int, new_capsule:bool, reason:string}
     */
    private function repairResult(string $decision, array $signals, int $attemptIndex, int $maxAttempts, bool $newCapsule, string $reason): array
    {
        $remaining = $maxAttempts - ($attemptIndex + 1);

        return [
            'decision' => $decision,
            'signals' => array_values($signals),
            'attempt_index' => $attemptIndex,
            'max_attempts' => $maxAttempts,
            'retries_remaining' => max(0, $remaining),
            'new_capsule' => $newCapsule,
            'reason' => $reason,
        ];
    }
}

/**
 * Repair escalation signal tokens, mirrored from the runtime
 * {@see \App\Services\Ai\Programming\AtlasDev\Repair\FailureCapsuleBuilder}
 * so this decider names the exact same signals without importing the
 * provider-coupled builder.
 *
 * @internal
 */
final class FailureCapsuleBuilderSignals
{
    public const SAME_SIGNATURE_TWICE = 'same_signature_twice';

    public const DIFF_GROWTH = 'diff_growth';

    public const SCOPE_VIOLATION = 'scope_violation';

    public const MAX_ATTEMPTS_REACHED = 'max_attempts_reached';
}
