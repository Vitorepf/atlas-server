<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AgentExecution;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\AtlasDev\Schemas\FailureCapsule;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipStringListNormalizer;

/**
 * AP-799 · Repair Agent and Failure Capsule planner.
 *
 * When a multi-agent task execution fails its focused validation or a universal
 * gate, the loop must neither blindly re-run the same provider nor silently
 * quarantine the candidate. This service turns a failure into a governed
 * decision: it captures exactly what failed (the failure capsule), classifies
 * WHY it failed, and only then decides whether a bounded repair attempt is
 * allowed, on which files, on which branch and with how much budget left.
 *
 * It is a pure planner/judge. It NEVER invokes a provider, runs a command,
 * merges, deploys, accesses secrets or writes to the provider/session store. It
 * only prepares the {@see self::REPAIR_LANE_INPUT_SCHEMA} input that the AP-797
 * repair_agent lane consumes. Merge stays governed by AP-769/AP-774.
 *
 * Reuse over duplication: the deterministic failure signature is borrowed from
 * the AtlasDev {@see FailureCapsule::signatureOf} normalization, the executable
 * slice shape is AP-794's, the branch strategy vocabulary is AP-793's, and the
 * R4/R5-never-auto-repair rule mirrors AtlasDev `RepairAttemptLimits`.
 */
final class MultiAgentRepairPlannerService
{
    public const REPAIR_PLAN_SCHEMA = 'atlas.agent_execution.repair_plan.v1';

    public const FAILURE_CAPSULE_SCHEMA = 'atlas.agent_execution.failure_capsule.v1';

    public const REPAIR_LANE_INPUT_SCHEMA = 'atlas.agent_execution.repair_lane_input.v1';

    // --- Failure classification (precedence: hard safety signals win) --------
    public const CLASS_SECURITY_BLOCKER = 'security_blocker_non_retryable';

    public const CLASS_SCOPE_VIOLATION = 'scope_violation_non_retryable';

    public const CLASS_MISSING_DEPENDENCY = 'missing_dependency_operator_required';

    public const CLASS_PROVIDER_TIMEOUT = 'provider_timeout_transient';

    public const CLASS_RATE_LIMIT = 'rate_limit_transient';

    public const CLASS_RETRYABLE_VALIDATION = 'retryable_validation_failure';

    // --- Repair decisions ----------------------------------------------------
    public const DECISION_REPAIR = 'repair';

    public const DECISION_TRANSIENT_RETRY = 'transient_retry';

    public const DECISION_NON_RETRYABLE = 'non_retryable';

    public const DECISION_OPERATOR_REVIEW = 'operator_review';

    public const DECISION_BLOCKED_RETRY_EXHAUSTED = 'blocked_retry_exhausted';

    public const DECISION_NO_REPAIR_NEEDED = 'no_repair_needed';

    // --- Branch strategy (AP-793 §4) ----------------------------------------
    public const BRANCH_REPAIR = 'repair_branch';

    public const BRANCH_REUSE_CANDIDATE = 'reuse_candidate_branch';

    public const BRANCH_NONE = 'none_operator_review';

    /** Default repair-attempt ceiling when the slice declares none. */
    private const DEFAULT_MAX_REPAIR_ATTEMPTS = 2;

    /** Default transient (timeout/rate-limit) re-dispatch ceiling. */
    private const DEFAULT_MAX_TRANSIENT_ATTEMPTS = 2;

    private const STDERR_MAX_BYTES = 4096;

    /**
     * Plan a repair lane for a failed multi-agent task execution.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function plan(array $input): array
    {
        $validation = is_array($input['validation_result'] ?? null) ? $input['validation_result'] : [];
        $gateFailures = $this->normalizeGateFailures($input['gate_failures'] ?? []);
        $laneResult = is_array($input['lane_result'] ?? null) ? $input['lane_result'] : [];
        $slice = is_array($input['executable_slice'] ?? null) ? $input['executable_slice'] : [];
        $diff = is_array($input['diff_summary'] ?? null) ? $input['diff_summary'] : [];

        $allowedFiles = StewardshipStringListNormalizer::trimmedStrings($slice['allowed_files'] ?? []);
        $forbiddenFiles = StewardshipStringListNormalizer::trimmedStrings($slice['forbidden_files'] ?? []);
        $changedFiles = StewardshipStringListNormalizer::trimmedStrings($diff['changed_files'] ?? $laneResult['changed_files'] ?? []);
        $validationCommands = $this->validationCommands($slice, $validation);
        $failingTests = StewardshipStringListNormalizer::trimmedStrings($validation['failing_tests'] ?? []);

        $failed = $this->detectFailure($validation, $gateFailures, $laneResult, $failingTests);
        $scopeEscape = $this->scopeEscape($changedFiles, $allowedFiles, $forbiddenFiles);

        $classification = $failed
            ? $this->classify($validation, $gateFailures, $laneResult, $scopeEscape)
            : null;

        $riskLevel = $this->riskLevel($slice);
        $transient = in_array($classification, [self::CLASS_PROVIDER_TIMEOUT, self::CLASS_RATE_LIMIT], true);
        $budget = $this->retryBudget($slice, $riskLevel, $transient, $input, $validation, $gateFailures, $laneResult);

        $failedCommand = $this->failedCommand($validation, $validationCommands, $gateFailures);
        $stderrExcerpt = $this->stderrExcerpt($validation, $gateFailures, $laneResult);
        $signature = $this->failureSignature($classification, $failedCommand, $failingTests, $stderrExcerpt);

        $decision = $this->decide(
            classification: $classification,
            transient: $transient,
            scopeEscape: $scopeEscape,
            validationCommands: $validationCommands,
            budget: $budget,
            signature: $signature,
            priorCapsules: $this->normalizePriorCapsules($input['prior_capsules'] ?? []),
        );

        $branchStrategy = $this->branchStrategy($decision, $transient);
        $repairAllowed = in_array($decision, [self::DECISION_REPAIR, self::DECISION_TRANSIENT_RETRY], true);
        $allowedRepairFiles = $repairAllowed ? $this->repairFiles($allowedFiles, $changedFiles) : [];

        $capsule = $this->failureCapsule(
            classification: $classification,
            failedCommand: $failedCommand,
            exitCode: $this->exitCode($validation, $laneResult),
            stderrExcerpt: $stderrExcerpt,
            failingTests: $failingTests,
            changedFiles: $changedFiles,
            allowedRepairFiles: $allowedRepairFiles,
            forbiddenFiles: $forbiddenFiles,
            budget: $budget,
            branchStrategy: $branchStrategy,
            signature: $signature,
        );

        $blockers = $this->blockers($decision, $classification, $validationCommands);

        $plan = [
            'schema_version' => self::REPAIR_PLAN_SCHEMA,
            'ap_contract' => 'AP-799',
            'classification' => $classification,
            'repair_decision' => $decision,
            'repair_allowed' => $repairAllowed,
            'transient' => $transient,
            'permanent_quarantine' => $this->permanentQuarantine($decision, $transient),
            'risk_level' => $riskLevel,
            'failure_capsule' => $capsule,
            'repair_branch_strategy' => $branchStrategy,
            'retry_budget' => $budget,
            'repair_lane_input' => $repairAllowed
                ? $this->repairLaneInput($slice, $capsule, $allowedRepairFiles, $forbiddenFiles, $validationCommands, $branchStrategy, $budget, $transient)
                : null,
            'blockers' => $blockers,
            'next_action' => $this->nextAction($decision, $classification, $blockers),
            'claim_policy' => $this->claimPolicy(),
        ];

        $plan['repair_plan_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($plan));
        $plan['generated_at'] = gmdate('c');

        return $plan;
    }

    // --- Classification ------------------------------------------------------

    /**
     * @param  array<string,mixed>  $validation
     * @param  list<array<string,string>>  $gateFailures
     * @param  array<string,mixed>  $laneResult
     */
    private function classify(array $validation, array $gateFailures, array $laneResult, bool $scopeEscape): string
    {
        $haystack = $this->signalHaystack($validation, $gateFailures, $laneResult);

        // 1. Security / destructive — highest precedence, always non-retryable.
        if ($this->isSecurity($gateFailures, $laneResult, $haystack)) {
            return self::CLASS_SECURITY_BLOCKER;
        }

        // 2. Scope violation — the attempt escaped its allowed files.
        if ($scopeEscape || $this->matchesGate($gateFailures, ['scope']) || $this->containsAny($haystack, ['scope_violation', 'outside allowed', 'forbidden file'])) {
            return self::CLASS_SCOPE_VIOLATION;
        }

        // 3. Missing dependency — operator must provision it.
        if ($this->containsAny($haystack, [
            'could not be found',
            'class not found',
            'class "',
            'no such file',
            'command not found',
            'package not installed',
            'missing dependency',
            'composer require',
            'composer install',
            'undefined function',
            'module not found',
        ])) {
            return self::CLASS_MISSING_DEPENDENCY;
        }

        // 4. Provider timeout — transient.
        if ((bool) ($laneResult['timed_out'] ?? false)
            || $this->errorCode($laneResult, ['timeout', 'timed_out', 'deadline_exceeded'])
            || $this->containsAny($haystack, ['timed out', 'timeout', 'deadline exceeded'])) {
            return self::CLASS_PROVIDER_TIMEOUT;
        }

        // 5. Rate limit — transient.
        if ((bool) ($laneResult['rate_limited'] ?? false)
            || $this->errorCode($laneResult, ['rate_limit', 'rate_limited', '429', 'too_many_requests'])
            || $this->containsAny($haystack, ['rate limit', 'rate-limit', 'too many requests', 'http 429'])) {
            return self::CLASS_RATE_LIMIT;
        }

        // 6. Default: a focused validation/gate failure that is safe to repair.
        return self::CLASS_RETRYABLE_VALIDATION;
    }

    /**
     * @param  list<array<string,string>>  $gateFailures
     * @param  array<string,mixed>  $laneResult
     */
    private function isSecurity(array $gateFailures, array $laneResult, string $haystack): bool
    {
        if ((bool) ($laneResult['secret_access'] ?? false) || (bool) ($laneResult['destructive_change'] ?? false)) {
            return true;
        }
        if ($this->matchesGate($gateFailures, ['security', 'secret', 'destructive', 'credential', 'injection'])) {
            return true;
        }

        return $this->containsAny($haystack, [
            'security blocker',
            'secret access',
            'destructive operation',
            'destructive change',
            'credential leak',
            'hardcoded secret',
        ]);
    }

    // --- Decision ------------------------------------------------------------

    /**
     * @param  array{max:int,used:int,remaining:int,transient:bool}  $budget
     * @param  list<array{failure_signature:string,attempts:int}>  $priorCapsules
     * @param  list<string>  $validationCommands
     */
    private function decide(
        ?string $classification,
        bool $transient,
        bool $scopeEscape,
        array $validationCommands,
        array $budget,
        string $signature,
        array $priorCapsules,
    ): string {
        if ($classification === null) {
            return self::DECISION_NO_REPAIR_NEEDED;
        }

        if ($classification === self::CLASS_SECURITY_BLOCKER) {
            return self::DECISION_OPERATOR_REVIEW;
        }
        if ($classification === self::CLASS_SCOPE_VIOLATION) {
            return self::DECISION_NON_RETRYABLE;
        }
        if ($classification === self::CLASS_MISSING_DEPENDENCY) {
            return self::DECISION_OPERATOR_REVIEW;
        }

        // No-repeat guard: an identical failure that already exhausted its
        // budget must never be retried, regardless of nominal remaining budget.
        if ($this->signatureExhausted($signature, $budget['max'], $priorCapsules)) {
            return self::DECISION_BLOCKED_RETRY_EXHAUSTED;
        }

        if ($budget['remaining'] <= 0) {
            return self::DECISION_BLOCKED_RETRY_EXHAUSTED;
        }

        if ($transient) {
            // Re-dispatch the same slice; never a permanent quarantine.
            return self::DECISION_TRANSIENT_RETRY;
        }

        // Retryable validation failure: a fix can only be allowed if it can be
        // proven by a validation command and stays inside scope.
        if ($validationCommands === []) {
            return self::DECISION_OPERATOR_REVIEW;
        }
        if ($scopeEscape) {
            return self::DECISION_NON_RETRYABLE;
        }

        return self::DECISION_REPAIR;
    }

    /**
     * @param  list<array{failure_signature:string,attempts:int}>  $priorCapsules
     */
    private function signatureExhausted(string $signature, int $max, array $priorCapsules): bool
    {
        if ($max <= 0) {
            return false;
        }
        foreach ($priorCapsules as $prior) {
            if ($prior['failure_signature'] === $signature && $prior['attempts'] >= $max) {
                return true;
            }
        }

        return false;
    }

    private function branchStrategy(string $decision, bool $transient): string
    {
        if ($decision === self::DECISION_REPAIR) {
            return self::BRANCH_REPAIR;
        }
        if ($decision === self::DECISION_TRANSIENT_RETRY) {
            return self::BRANCH_REUSE_CANDIDATE;
        }
        // A transient failure with no budget keeps the candidate branch for a
        // later retry; everything else routes to operator review.
        return $transient ? self::BRANCH_REUSE_CANDIDATE : self::BRANCH_NONE;
    }

    private function permanentQuarantine(string $decision, bool $transient): bool
    {
        if ($transient) {
            return false;
        }

        return in_array($decision, [self::DECISION_NON_RETRYABLE, self::DECISION_BLOCKED_RETRY_EXHAUSTED], true);
    }

    // --- Budget --------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $slice
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $validation
     * @param  list<array<string,string>>  $gateFailures
     * @param  array<string,mixed>  $laneResult
     * @return array{max:int,used:int,remaining:int,transient:bool}
     */
    private function retryBudget(array $slice, string $riskLevel, bool $transient, array $input, array $validation, array $gateFailures, array $laneResult): array
    {
        // R4/R5/critical never receive an autonomous repair attempt.
        if ($this->escalateImmediately($riskLevel)) {
            return ['max' => 0, 'used' => 0, 'remaining' => 0, 'transient' => $transient];
        }

        $retryPolicy = is_array($slice['retry_policy'] ?? null) ? $slice['retry_policy'] : [];
        $max = $transient
            ? $this->positiveInt($retryPolicy['transient_retries'] ?? $retryPolicy['transient_count'] ?? null, self::DEFAULT_MAX_TRANSIENT_ATTEMPTS)
            : $this->positiveInt($retryPolicy['count'] ?? $retryPolicy['max_attempts'] ?? null, self::DEFAULT_MAX_REPAIR_ATTEMPTS);

        $used = max(0, (int) ($input['retry_attempts_used'] ?? 0));
        // Risk cap mirrors AtlasDev RepairAttemptLimits (floor under the slice).
        $max = min($max, $this->riskCap($riskLevel));

        return [
            'max' => $max,
            'used' => $used,
            'remaining' => max(0, $max - $used),
            'transient' => $transient,
        ];
    }

    private function riskCap(string $riskLevel): int
    {
        return match ($riskLevel) {
            'R0', 'R1', 'low' => 1,
            'R2', 'R3', 'medium' => 2,
            'R4', 'R5', 'high', 'critical' => 0,
            default => self::DEFAULT_MAX_REPAIR_ATTEMPTS,
        };
    }

    private function escalateImmediately(string $riskLevel): bool
    {
        return in_array($riskLevel, ['R4', 'R5', 'critical'], true);
    }

    // --- Failure capsule -----------------------------------------------------

    /**
     * @param  list<string>  $failingTests
     * @param  list<string>  $changedFiles
     * @param  list<string>  $allowedRepairFiles
     * @param  list<string>  $forbiddenFiles
     * @param  array{max:int,used:int,remaining:int,transient:bool}  $budget
     * @return array<string,mixed>
     */
    private function failureCapsule(
        ?string $classification,
        string $failedCommand,
        ?int $exitCode,
        string $stderrExcerpt,
        array $failingTests,
        array $changedFiles,
        array $allowedRepairFiles,
        array $forbiddenFiles,
        array $budget,
        string $branchStrategy,
        string $signature,
    ): array {
        $capsule = [
            'schema_version' => self::FAILURE_CAPSULE_SCHEMA,
            'failed_command' => $failedCommand,
            'exit_code' => $exitCode,
            'stderr_excerpt' => $stderrExcerpt,
            'failing_tests' => $failingTests,
            'changed_files' => $changedFiles,
            'suspected_root_cause' => $this->suspectedRootCause($classification, $failingTests, $changedFiles),
            'allowed_repair_files' => $allowedRepairFiles,
            'forbidden_files' => $forbiddenFiles,
            'retry_budget' => $budget,
            'repair_branch_strategy' => $branchStrategy,
            'classification' => $classification,
            'failure_signature' => $signature,
        ];
        $capsule['capsule_hash'] = 'sha256:'.MissionCanonicalHash::sha256($capsule);

        return $capsule;
    }

    /**
     * @param  list<string>  $failingTests
     * @param  list<string>  $changedFiles
     */
    private function suspectedRootCause(?string $classification, array $failingTests, array $changedFiles): string
    {
        return match ($classification) {
            self::CLASS_SECURITY_BLOCKER => 'A security/secret/destructive gate flagged the change; operator review required before any further attempt.',
            self::CLASS_SCOPE_VIOLATION => 'The attempt modified files outside the slice allowed_files (or touched a forbidden file); the slice scope must be widened or re-planned by the operator/architect.',
            self::CLASS_MISSING_DEPENDENCY => 'A required dependency/package/binary is missing; the agent cannot install it honestly, so the operator must provision it.',
            self::CLASS_PROVIDER_TIMEOUT => 'The provider call timed out before producing a mergeable result; this is transient and the slice can be re-dispatched.',
            self::CLASS_RATE_LIMIT => 'The provider was rate limited; this is transient and the slice can be re-dispatched after backoff.',
            self::CLASS_RETRYABLE_VALIDATION => $failingTests !== []
                ? 'Focused validation failed on '.implode(', ', array_slice($failingTests, 0, 3)).'; a bounded repair inside '.($changedFiles !== [] ? implode(', ', array_slice($changedFiles, 0, 3)) : 'the allowed files').' should make it pass.'
                : 'A focused validation/gate failed within scope; apply the smallest correct repair inside allowed_files and rerun the same validation command.',
            default => 'No failure detected; no repair is needed.',
        };
    }

    /**
     * @param  list<string>  $failingTests
     */
    private function failureSignature(?string $classification, string $failedCommand, array $failingTests, string $stderrExcerpt): string
    {
        // Reuse the AtlasDev normalization so the signature is consistent across
        // the repair surfaces. The "gate" encodes the classification + failing
        // tests so distinct failures never collapse to one signature.
        $gate = ($classification ?? 'unclassified').'|'.$failedCommand.'|'.implode(',', $failingTests);

        return FailureCapsule::signatureOf($gate, $stderrExcerpt);
    }

    // --- Repair lane input (for AP-797) -------------------------------------

    /**
     * @param  array<string,mixed>  $slice
     * @param  array<string,mixed>  $capsule
     * @param  list<string>  $allowedRepairFiles
     * @param  list<string>  $forbiddenFiles
     * @param  list<string>  $validationCommands
     * @param  array{max:int,used:int,remaining:int,transient:bool}  $budget
     * @return array<string,mixed>
     */
    private function repairLaneInput(
        array $slice,
        array $capsule,
        array $allowedRepairFiles,
        array $forbiddenFiles,
        array $validationCommands,
        string $branchStrategy,
        array $budget,
        bool $transient,
    ): array {
        $objective = $transient
            ? 'Re-dispatch the same slice after a transient provider failure; do not change the diff scope.'
            : 'Apply the smallest correct repair inside allowed_repair_files so the failing validation passes; do not grow the diff scope.';

        return [
            'schema_version' => self::REPAIR_LANE_INPUT_SCHEMA,
            'lane' => 'repair_agent',
            'write_authority' => 'repair_branch_worktree_only',
            'objective' => $objective,
            'failure_capsule' => $capsule,
            'allowed_repair_files' => $allowedRepairFiles,
            'forbidden_files' => $forbiddenFiles,
            'validation_commands' => $validationCommands,
            'branch_strategy' => $branchStrategy,
            'retry_budget' => $budget,
            'max_runtime_seconds' => $this->positiveInt($slice['max_runtime_seconds'] ?? null, 900),
            'evidence_obligations' => StewardshipStringListNormalizer::trimmedStrings($slice['evidence_obligations'] ?? []),
            'provider_fit' => $slice['provider_fit'] ?? null,
            'merge_policy' => 'review_required',
            'stop_condition' => 'same failure signature twice OR retry budget exhausted',
        ];
    }

    // --- Blockers / next action ---------------------------------------------

    /**
     * @param  list<string>  $validationCommands
     * @return list<string>
     */
    private function blockers(string $decision, ?string $classification, array $validationCommands): array
    {
        return match ($decision) {
            self::DECISION_OPERATOR_REVIEW => $classification === self::CLASS_SECURITY_BLOCKER
                ? ['security_or_destructive_blocker']
                : ($classification === self::CLASS_MISSING_DEPENDENCY
                    ? ['missing_dependency_operator_required']
                    : ($validationCommands === [] ? ['validation_command_missing'] : ['operator_review_required'])),
            self::DECISION_NON_RETRYABLE => ['scope_violation'],
            self::DECISION_BLOCKED_RETRY_EXHAUSTED => $classification !== null && in_array($classification, [self::CLASS_PROVIDER_TIMEOUT, self::CLASS_RATE_LIMIT], true)
                ? ['transient_retry_budget_exhausted']
                : ['retry_budget_exhausted'],
            default => [],
        };
    }

    /**
     * @param  list<string>  $blockers
     */
    private function nextAction(string $decision, ?string $classification, array $blockers): string
    {
        return match ($decision) {
            self::DECISION_REPAIR => 'Dispatch the AP-797 repair_agent lane on a repair_branch using repair_lane_input; re-run the validation commands before merge governance.',
            self::DECISION_TRANSIENT_RETRY => 'Re-dispatch the same slice on the candidate branch after provider backoff; the failure is transient and the candidate is NOT quarantined.',
            self::DECISION_NON_RETRYABLE => 'Operator/architect must re-scope the slice (allowed_files) before any further attempt; the change escaped its allowed scope.',
            self::DECISION_OPERATOR_REVIEW => $classification === self::CLASS_SECURITY_BLOCKER
                ? 'Operator review required: a security/secret/destructive gate fired. No autonomous repair is permitted.'
                : ($classification === self::CLASS_MISSING_DEPENDENCY
                    ? 'Operator must provision the missing dependency, then re-run the slice.'
                    : 'Operator review required: '.($blockers[0] ?? 'no safe repair path').'.'),
            self::DECISION_BLOCKED_RETRY_EXHAUSTED => 'Retry budget exhausted for this failure; do not repeat the same repair. Escalate to the operator or re-slice the finding.',
            default => 'Validation passed and no gate failed; no repair is needed.',
        };
    }

    // --- Input normalization helpers ----------------------------------------

    /**
     * @param  array<string,mixed>  $validation
     * @param  list<array<string,string>>  $gateFailures
     * @param  array<string,mixed>  $laneResult
     * @param  list<string>  $failingTests
     */
    private function detectFailure(array $validation, array $gateFailures, array $laneResult, array $failingTests): bool
    {
        if ($gateFailures !== []) {
            return true;
        }
        if ($failingTests !== []) {
            return true;
        }
        $passed = $validation['passed'] ?? null;
        if ($passed === false) {
            return true;
        }
        $exit = $this->exitCode($validation, $laneResult);
        if ($exit !== null && $exit !== 0) {
            return true;
        }
        if ((bool) ($laneResult['timed_out'] ?? false) || (bool) ($laneResult['rate_limited'] ?? false)) {
            return true;
        }
        $status = strtolower(trim((string) ($laneResult['status'] ?? '')));

        return in_array($status, ['failed', 'blocked', 'error'], true)
            || StewardshipStringListNormalizer::trimmedStrings($laneResult['blockers'] ?? []) !== [];
    }

    /**
     * @param  list<string>  $changedFiles
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $forbiddenFiles
     */
    private function scopeEscape(array $changedFiles, array $allowedFiles, array $forbiddenFiles): bool
    {
        foreach ($changedFiles as $file) {
            if (in_array($file, $forbiddenFiles, true)) {
                return true;
            }
        }
        // Only enforce the allow-list when one is declared; an empty allow-list
        // means scope is governed elsewhere, not that everything is forbidden.
        if ($allowedFiles === [] || $changedFiles === []) {
            return false;
        }
        foreach ($changedFiles as $file) {
            if (! $this->withinAllowed($file, $allowedFiles)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $allowedFiles
     */
    private function withinAllowed(string $file, array $allowedFiles): bool
    {
        foreach ($allowedFiles as $allowed) {
            if ($file === $allowed) {
                return true;
            }
            // Narrow glob/prefix match (e.g. "app/Services/Foo/*" or a directory).
            $prefix = rtrim(str_replace('*', '', $allowed), '/');
            if ($prefix !== '' && str_contains($allowed, '*') && str_starts_with($file, $prefix)) {
                return true;
            }
            if (str_ends_with($allowed, '/') && str_starts_with($file, $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $changedFiles
     * @return list<string>
     */
    private function repairFiles(array $allowedFiles, array $changedFiles): array
    {
        // The repair_agent may touch the files it already changed (kept within
        // scope), plus the declared allowed_files. Forbidden files are excluded
        // upstream because a forbidden touch is a scope violation.
        $files = $changedFiles;
        foreach ($allowedFiles as $file) {
            if (! in_array($file, $files, true)) {
                $files[] = $file;
            }
        }

        return array_values($files);
    }

    /**
     * @param  array<string,mixed>  $slice
     * @param  array<string,mixed>  $validation
     * @return list<string>
     */
    private function validationCommands(array $slice, array $validation): array
    {
        $commands = StewardshipStringListNormalizer::trimmedStrings($slice['validation_commands'] ?? []);
        if ($commands !== []) {
            return $commands;
        }

        return StewardshipStringListNormalizer::trimmedStrings($validation['commands'] ?? []);
    }

    /**
     * @param  array<string,mixed>  $validation
     * @param  list<string>  $validationCommands
     * @param  list<array<string,string>>  $gateFailures
     */
    private function failedCommand(array $validation, array $validationCommands, array $gateFailures): string
    {
        $explicit = trim((string) ($validation['failed_command'] ?? $validation['command'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }
        if ($validationCommands !== []) {
            return $validationCommands[0];
        }
        if ($gateFailures !== []) {
            return 'gate:'.$gateFailures[0]['gate'];
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $validation
     * @param  array<string,mixed>  $laneResult
     */
    private function exitCode(array $validation, array $laneResult): ?int
    {
        foreach ([$validation['exit_code'] ?? null, $laneResult['exit_code'] ?? null] as $candidate) {
            if (is_int($candidate)) {
                return $candidate;
            }
            if (is_string($candidate) && $candidate !== '' && ctype_digit(ltrim($candidate, '-'))) {
                return (int) $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $validation
     * @param  list<array<string,string>>  $gateFailures
     * @param  array<string,mixed>  $laneResult
     */
    private function stderrExcerpt(array $validation, array $gateFailures, array $laneResult): string
    {
        $raw = trim((string) (
            $validation['stderr_excerpt']
            ?? $validation['stderr']
            ?? $validation['primary_error']
            ?? $validation['output']
            ?? ''
        ));
        if ($raw === '' && $gateFailures !== []) {
            $raw = implode('; ', array_map(
                static fn (array $g): string => $g['gate'].': '.$g['reason'],
                $gateFailures,
            ));
        }
        if ($raw === '') {
            $raw = trim((string) ($laneResult['error'] ?? ''));
        }

        $normalized = strtolower(trim((string) preg_replace('/\s+/', ' ', $raw)));
        if (strlen($normalized) <= self::STDERR_MAX_BYTES) {
            return $normalized;
        }

        return substr($normalized, 0, self::STDERR_MAX_BYTES - 14).' …[truncated]';
    }

    /**
     * @param  array<string,mixed>  $slice
     */
    private function riskLevel(array $slice): string
    {
        $risk = trim((string) ($slice['risk_level'] ?? ''));

        return $risk !== '' ? $risk : 'R2';
    }

    /**
     * @param  mixed  $value
     * @return list<array<string,string>>
     */
    private function normalizeGateFailures(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $gate = trim($item);
                if ($gate !== '') {
                    $out[] = ['gate' => $gate, 'reason' => '', 'kind' => ''];
                }

                continue;
            }
            if (! is_array($item)) {
                continue;
            }
            $gate = trim((string) ($item['gate'] ?? $item['name'] ?? ''));
            if ($gate === '') {
                continue;
            }
            $out[] = [
                'gate' => $gate,
                'reason' => trim((string) ($item['reason'] ?? $item['message'] ?? '')),
                'kind' => trim((string) ($item['kind'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * @param  mixed  $value
     * @return list<array{failure_signature:string,attempts:int}>
     */
    private function normalizePriorCapsules(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }
            $sig = trim((string) ($item['failure_signature'] ?? ''));
            if ($sig === '') {
                continue;
            }
            $out[] = [
                'failure_signature' => $sig,
                'attempts' => max(0, (int) ($item['attempts'] ?? 0)),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $validation
     * @param  list<array<string,string>>  $gateFailures
     * @param  array<string,mixed>  $laneResult
     */
    private function signalHaystack(array $validation, array $gateFailures, array $laneResult): string
    {
        $parts = [
            (string) ($validation['stderr_excerpt'] ?? ''),
            (string) ($validation['stderr'] ?? ''),
            (string) ($validation['primary_error'] ?? ''),
            (string) ($validation['output'] ?? ''),
            (string) ($laneResult['error'] ?? ''),
            (string) ($laneResult['status'] ?? ''),
        ];
        foreach ($gateFailures as $gate) {
            $parts[] = $gate['gate'].' '.$gate['reason'].' '.$gate['kind'];
        }
        foreach (StewardshipStringListNormalizer::trimmedStrings($laneResult['blockers'] ?? []) as $blocker) {
            $parts[] = $blocker;
        }
        foreach (StewardshipStringListNormalizer::trimmedStrings($laneResult['error_codes'] ?? []) as $code) {
            $parts[] = $code;
        }

        return strtolower(implode(' ', $parts));
    }

    /**
     * @param  list<array<string,string>>  $gateFailures
     * @param  list<string>  $needles
     */
    private function matchesGate(array $gateFailures, array $needles): bool
    {
        foreach ($gateFailures as $gate) {
            $hay = strtolower($gate['gate'].' '.$gate['kind'].' '.$gate['reason']);
            foreach ($needles as $needle) {
                if (str_contains($hay, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $laneResult
     * @param  list<string>  $codes
     */
    private function errorCode(array $laneResult, array $codes): bool
    {
        $haystack = array_map('strtolower', StewardshipStringListNormalizer::trimmedStrings($laneResult['error_codes'] ?? []));
        foreach ($codes as $code) {
            if (in_array($code, $haystack, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function positiveInt(mixed $value, int $default): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(): array
    {
        return [
            'mode' => 'pure_repair_planner',
            'providers_invoked' => false,
            'executes_provider' => false,
            'runs_command' => false,
            'merge_performed' => false,
            'deploy_performed' => false,
            'external_push_performed' => false,
            'secret_access' => false,
            'writes_session_store' => false,
            'operator_review_required' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function identity(array $plan): array
    {
        $copy = $plan;
        unset($copy['repair_plan_hash'], $copy['generated_at']);

        return $copy;
    }

}
