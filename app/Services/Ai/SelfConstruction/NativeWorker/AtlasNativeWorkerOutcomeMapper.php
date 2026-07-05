<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NativeWorker;

/**
 * Honest, deterministic mapper from native-worker execution facts into a reportable Atlas
 * task outcome ('success' | 'give_back' | 'failed'). Pure — no queue mutation, no report write,
 * no provider call, no file I/O. NEVER converts a red verdict into success.
 *
 * Decision precedence:
 *   1. give_back when the envelope encodes an IMPOSSIBLE task (impossible_scope, missing
 *      implementation path, acceptance contradiction, dependency_missing, forbidden_file_required,
 *      non_atlas_native_dependency, unsafe_command_plan, missing_allowed_files).
 *   2. poison_failure when execution touched files outside allowed_files (scope violation).
 *   3. failed when envelope hash mismatch (execution ran against a different/stale task).
 *   4. retryable_failure for stale lease or transient command failure.
 *   5. failed when verification.passed is false OR command/patch results are red OR required
 *      evidence is incomplete.
 *   6. success only when verification.passed is true AND every required evidence ref is present
 *      AND every command/patch result is green AND envelope hash matches AND no unresolved
 *      blockers remain.
 */
final class AtlasNativeWorkerOutcomeMapper
{
    public const SCHEMA = 'atlas.native_worker.outcome_mapper.v1';

    public const OUTCOME_SUCCESS = 'success';

    public const OUTCOME_GIVE_BACK = 'give_back';

    public const OUTCOME_FAILED = 'failed';

    /**
     * A no_claimable_task incident is queue STARVATION, not a worker outcome at all — it must
     * surface as a structured repair signal Learning/Task Fabric can act on, distinct from a
     * neutral idle give_back and distinct from an intentional operator stop / disabled worker.
     */
    public const OUTCOME_QUEUE_REPAIR_SIGNAL = 'queue_repair_signal';

    /** A verification failure the caller has explicitly classified as transient (worth another
     *  attempt) — opt-in via execution/verification `failure_class` so legacy callers that never
     *  supply it keep the original generic OUTCOME_FAILED behavior unchanged. */
    public const OUTCOME_RETRYABLE_FAILURE = 'retryable_failure';

    /** A verification failure explicitly classified as poison (never worth retrying), OR a scope
     *  violation (execution touched files outside the envelope's allowed_files) — the same
     *  dishonesty pattern as claiming success while acting outside the declared scope. */
    public const OUTCOME_POISON_FAILURE = 'poison_failure';

    /** @var list<string> */
    private const GIVE_BACK_FACTS = [
        'impossible_scope',
        'missing_implementation_path',
        'acceptance_contradiction',
        'dependency_missing',
        'forbidden_file_required',
        'non_atlas_native_dependency',
        'unsafe_command_plan',
        'missing_allowed_files',
    ];

    /**
     * @param  array<string,mixed>  $envelope
     * @param  array<string,mixed>  $execution
     * @param  array<string,mixed>  $verification
     * @return array<string,mixed>
     */
    public function map(array $envelope, array $execution, array $verification): array
    {
        // Detect missing allowed_files: execution reports changed_files but envelope has no scope.
        if (array_key_exists('changed_files', $execution) && empty($envelope['allowed_files'] ?? null)) {
            $envelope['missing_allowed_files'] = true;
        }

        $giveBackReasons = $this->collectGiveBackReasons($envelope, $execution);
        if ($giveBackReasons !== []) {
            $isNoClaimableTaskIncident = in_array('queue_starvation:no_claimable_task', $giveBackReasons, true);
            $outcome = $isNoClaimableTaskIncident ? self::OUTCOME_QUEUE_REPAIR_SIGNAL : self::OUTCOME_GIVE_BACK;
            $result = $this->emit($outcome, $giveBackReasons[0], $giveBackReasons, $envelope, $execution, $verification);

            if ($isNoClaimableTaskIncident) {
                $result['worker_feed_feedback'] = $this->workerFeedFeedback($execution);
                $result['queue_repair_signal'] = [
                    'claimable_depth' => isset($execution['claimable_depth']) ? (int) $execution['claimable_depth'] : null,
                    'active_workers' => isset($execution['active_worker_count']) ? (int) $execution['active_worker_count'] : null,
                    'claimable_per_active_worker' => $execution['claimable_per_active_worker'] ?? null,
                ];
                $result['outcome_hash'] = $this->outcomeHash($result);
            }

            return $result;
        }

        // SCOPE VIOLATION — opt-in via execution['changed_files']: any changed file outside the
        // envelope's declared allowed_files is the same self-serving dishonesty as claiming
        // success while acting beyond the declared scope. Legacy callers that never supply
        // changed_files are entirely unaffected.
        if (array_key_exists('changed_files', $execution)) {
            $allowedFiles = array_values(array_map('strval', (array) ($envelope['allowed_files'] ?? [])));
            $changedFiles = array_values(array_map('strval', (array) $execution['changed_files']));
            $outOfScope = array_values(array_diff($changedFiles, $allowedFiles));
            if ($outOfScope !== []) {
                return $this->emit(
                    self::OUTCOME_POISON_FAILURE,
                    'scope_violation',
                    array_map(static fn (string $f): string => 'scope_violation:'.$f, $outOfScope),
                    $envelope,
                    $execution,
                    $verification,
                );
            }
        }

        // ENVELOPE HASH INTEGRITY — if execution or verification provides an expected envelope
        // hash, it must match the canonical hash of the envelope's identity fields. A mismatch
        // means the execution ran against a different or stale task identity — never success.
        $expectedHash = (string) ($execution['envelope_hash'] ?? $verification['envelope_hash'] ?? '');
        if ($expectedHash !== '' && ! $this->envelopeHashMatches($envelope, $expectedHash)) {
            return $this->emit(
                self::OUTCOME_FAILED,
                'envelope_hash_mismatch',
                ['envelope_hash_mismatch'],
                $envelope,
                $execution,
                $verification,
            );
        }

        // STALE LEASE — opt-in via execution/verification `lease_expired`. A stale lease is a
        // transient condition worth retrying, not a permanent failure.
        $leaseExpired = (bool) ($execution['lease_expired'] ?? $verification['lease_expired'] ?? false);
        if ($leaseExpired) {
            return $this->emit(
                self::OUTCOME_RETRYABLE_FAILURE,
                'stale_lease',
                ['stale_lease'],
                $envelope,
                $execution,
                $verification,
            );
        }

        // TRANSIENT COMMAND FAILURE — if command_status signals a transient error, retry.
        $commandStatus = array_key_exists('command_status', $execution) ? (string) $execution['command_status'] : null;
        if ($commandStatus === 'transient_error') {
            return $this->emit(
                self::OUTCOME_RETRYABLE_FAILURE,
                'transient_command_failure',
                ['transient_command_failure'],
                $envelope,
                $execution,
                $verification,
            );
        }

        $verificationPassed = (bool) ($verification['passed'] ?? false);
        $executionGreen = $this->executionIsGreen($execution);
        $evidenceComplete = $this->evidenceComplete($envelope, $execution, $verification);
        $unresolvedBlockers = $this->unresolvedBlockers($execution, $verification);

        if (! $verificationPassed) {
            // Opt-in failure classification: a caller that explicitly names the failure as
            // retryable or poison gets a distinct, more actionable outcome. Absent this field,
            // behavior is byte-identical to before (OUTCOME_FAILED).
            $failureClass = (string) ($execution['failure_class'] ?? $verification['failure_class'] ?? '');
            $blockers = array_values(array_unique(array_merge(
                ['verification_failed'],
                array_values((array) ($verification['blockers'] ?? [])),
            )));
            if ($failureClass === 'retryable') {
                return $this->emit(self::OUTCOME_RETRYABLE_FAILURE, 'verification_failed', $blockers, $envelope, $execution, $verification);
            }
            if ($failureClass === 'poison') {
                return $this->emit(self::OUTCOME_POISON_FAILURE, 'verification_failed', $blockers, $envelope, $execution, $verification);
            }

            return $this->emit(self::OUTCOME_FAILED, 'verification_failed', $blockers, $envelope, $execution, $verification);
        }
        if (! $executionGreen) {
            return $this->emit(self::OUTCOME_FAILED, 'execution_red_results', array_values((array) ($execution['failed_results'] ?? ['execution_red_results'])), $envelope, $execution, $verification);
        }
        if (! $evidenceComplete) {
            return $this->emit(self::OUTCOME_FAILED, 'evidence_incomplete', ['evidence_incomplete'], $envelope, $execution, $verification);
        }
        if ($unresolvedBlockers !== []) {
            return $this->emit(self::OUTCOME_FAILED, 'unresolved_blockers', $unresolvedBlockers, $envelope, $execution, $verification);
        }

        return $this->emit(self::OUTCOME_SUCCESS, 'all_green', [], $envelope, $execution, $verification);
    }

    /**
     * Compute a canonical hash from the envelope's identity fields and compare against
     * the expected hash provided by execution or verification.
     *
     * @param  array<string,mixed>  $envelope
     */
    private function envelopeHashMatches(array $envelope, string $expectedHash): bool
    {
        $identity = [
            'task_packet_id' => (string) ($envelope['task_packet_id'] ?? ''),
            'allowed_files' => array_values(array_map('strval', (array) ($envelope['allowed_files'] ?? []))),
            'required_evidence' => array_values(array_map('strval', (array) ($envelope['required_evidence'] ?? []))),
        ];
        $canonical = json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $computed = 'env_'.substr(hash('sha256', (string) $canonical), 0, 32);

        return hash_equals($computed, $expectedHash);
    }

    /**
     * Structured worker-feed feedback for a genuine queue-starvation give_back
     * (no_claimable_task) — distinct from the generic give_back reasons list so
     * Learning/Task Fabric consumers can convert real starvation into better
     * future packets instead of treating it like a packet-quality failure.
     *
     * @param  array<string,mixed>  $execution
     * @return array<string,mixed>
     */
    private function workerFeedFeedback(array $execution): array
    {
        return [
            'no_claimable_task_incident' => 1,
            'suggested_replenish_reason' => 'queue_starvation_observed_by_native_worker',
            'claimable_depth_at_incident' => isset($execution['claimable_depth']) ? (int) $execution['claimable_depth'] : null,
            'active_worker_count_at_incident' => isset($execution['active_worker_count']) ? (int) $execution['active_worker_count'] : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $envelope
     * @param  array<string,mixed>  $execution
     * @return list<string>
     */
    private function collectGiveBackReasons(array $envelope, array $execution): array
    {
        $reasons = [];
        foreach (self::GIVE_BACK_FACTS as $key) {
            if ((bool) ($envelope[$key] ?? false) || (bool) ($execution[$key] ?? false)) {
                $reasons[] = $key;
            }
        }

        // Execution-level command outcomes that signal the task cannot be completed here.
        if (array_key_exists('command_status', $execution)) {
            $cs = (string) $execution['command_status'];
            if ($cs === 'denied') {
                $reasons[] = 'command_execution_denied';
            } elseif ($cs === 'timeout') {
                $reasons[] = 'command_execution_timeout';
            } elseif ($cs === 'no_claimable_task') {
                // Queue starvation, not a worker failure — never reported as a generic/empty result.
                $reasons[] = 'queue_starvation:no_claimable_task';
            } elseif ($cs === 'no_self_sufficient_task') {
                $reasons[] = 'queue_starvation:no_self_sufficient_task';
            }
        }
        if ((bool) ($execution['empty_results'] ?? false)) {
            $reasons[] = 'empty_results';
        }

        $execReasons = array_values(array_filter(
            array_map('strval', (array) ($execution['give_back_reasons'] ?? [])),
            static fn (string $s): bool => $s !== '',
        ));
        foreach ($execReasons as $r) {
            if (! in_array($r, $reasons, true)) {
                $reasons[] = $r;
            }
        }

        return $reasons;
    }

    /**
     * @param  array<string,mixed>  $execution
     */
    private function executionIsGreen(array $execution): bool
    {
        // Use null to distinguish "key absent" (no command ran — OK) from "" (set but unknown — not green).
        $command = array_key_exists('command_status', $execution) ? (string) $execution['command_status'] : null;
        $patch = array_key_exists('patch_status', $execution) ? (string) $execution['patch_status'] : null;
        $results = array_values((array) ($execution['results'] ?? []));
        foreach ($results as $row) {
            if (is_array($row) && (string) ($row['status'] ?? '') !== 'green') {
                return false;
            }
        }

        return ($command === null || $command === 'green') && ($patch === null || $patch === 'green');
    }

    /**
     * @param  array<string,mixed>  $envelope
     * @param  array<string,mixed>  $execution
     * @param  array<string,mixed>  $verification
     */
    private function evidenceComplete(array $envelope, array $execution, array $verification): bool
    {
        $required = array_values(array_map('strval', (array) ($envelope['required_evidence'] ?? [])));
        if ($required === []) {
            // No required evidence declared ⇒ trust verification.passed.
            return true;
        }
        $refs = array_values(array_map('strval', (array) ($execution['evidence_refs'] ?? [])));
        $verificationRefs = array_values(array_map('strval', (array) ($verification['evidence_refs'] ?? [])));
        $all = array_unique(array_merge($refs, $verificationRefs));
        foreach ($required as $r) {
            if (! in_array($r, $all, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $execution
     * @param  array<string,mixed>  $verification
     * @return list<string>
     */
    private function unresolvedBlockers(array $execution, array $verification): array
    {
        $merged = array_merge(
            array_values((array) ($execution['blockers'] ?? [])),
            array_values((array) ($verification['blockers'] ?? [])),
        );

        return array_values(array_unique(array_map('strval', $merged)));
    }

    /**
     * @param  list<string>  $deficiencies
     * @param  array<string,mixed>  $envelope
     * @param  array<string,mixed>  $execution
     * @param  array<string,mixed>  $verification
     * @return array<string,mixed>
     */
    private function emit(string $outcome, string $reason, array $deficiencies, array $envelope, array $execution, array $verification): array
    {
        $required = array_values(array_map('strval', (array) ($envelope['required_evidence'] ?? [])));
        $observed = array_values(array_unique(array_map('strval', array_merge(
            (array) ($execution['evidence_refs'] ?? []),
            (array) ($verification['evidence_refs'] ?? []),
        ))));

        $envelopeOut = [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'report_outcome' => $outcome,
            'report_reason' => $reason,
            'evidence_refs' => [
                'required' => $required,
                'observed' => $observed,
            ],
            'blocking_deficiencies' => array_values($deficiencies),
        ];
        $envelopeOut['outcome_hash'] = $this->outcomeHash($envelopeOut);

        return $envelopeOut;
    }

    /**
     * @param  array<string,mixed>  $envelope
     */
    private function outcomeHash(array $envelope): string
    {
        unset($envelope['outcome_hash']);
        $canonical = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'outcome_'.substr(hash('sha256', (string) $canonical), 0, 32);
    }
}
