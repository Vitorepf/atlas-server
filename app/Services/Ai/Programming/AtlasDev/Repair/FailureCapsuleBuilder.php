<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Repair;

use App\Services\Ai\Programming\AtlasDev\Repair\Contracts\RepairAttemptOutcome;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\RepairPolicy;
use App\Services\Ai\Programming\AtlasDev\Schemas\FailureCapsule;

/**
 * Builds a {@see FailureCapsule} from observed failure inputs.
 *
 * The capsule's `decision` field is the orchestrator's stop rule:
 *   - `retry`    next attempt is allowed under the policy/risk window;
 *   - `stop`     attempt budget exhausted under the policy;
 *   - `escalate` honest dead-end (same signature twice OR diff grew).
 *
 * `escalation_signal_delta` is the per-capsule contribution that the
 * upstream {@see \App\Services\Ai\Programming\AtlasDev\Escalation\EscalationDecisionEngine}
 * folds into its score; capsules whose decision is `escalate` MUST carry
 * a non-empty delta (DTO invariant).
 *
 * This builder is deliberately *narrow*: it never reaches into provider
 * code, runs commands or computes diffs. Everything it needs arrives as
 * primitive data from the evaluator.
 */
final class FailureCapsuleBuilder
{
    public const SIGNAL_SAME_SIGNATURE_TWICE = 'same_signature_twice';
    public const SIGNAL_DIFF_GROWTH = 'diff_growth';
    public const SIGNAL_SCOPE_VIOLATION = 'scope_violation';
    public const SIGNAL_MAX_ATTEMPTS_REACHED = 'max_attempts_reached';
    public const SIGNAL_BLOCKED_PREFLIGHT = 'blocked_preflight';

    /**
     * Allowed slack between the previous attempt's change-set and a fresh
     * attempt before we treat the growth as "scope expansion". Repair is
     * supposed to shrink or preserve scope; growing by more than this hints
     * the model is unfolding new work instead of fixing the symptom.
     */
    private const DIFF_GROWTH_FILE_SLACK = 0;

    /**
     * Mirrors the DTO's 4kb invariant on `primary_error_excerpt`. We
     * truncate at the byte boundary and append a marker so the truncation
     * stays visible in the receipt.
     */
    private const EXCERPT_MAX_BYTES = 4096;
    private const EXCERPT_TRUNCATION_MARKER = ' …[truncated]';

    public function __construct(
        private readonly FailureSignatureHasher $hasher,
    ) {}

    /**
     * Convenience: build the first capsule from a verification/scope/test
     * failure observed by the main pipeline (attempt_index 0).
     */
    public function buildInitial(
        string $runId,
        string $taskContractHash,
        string $gate,
        ?string $command,
        ?int $exitCode,
        string $primaryErrorRaw,
        ?string $fullErrorLogPath,
        ?string $failingTest,
        ?string $diffHash,
        array $changedFiles,
        RepairPolicy $policy,
    ): FailureCapsule {
        return $this->build(
            runId: $runId,
            taskContractHash: $taskContractHash,
            attemptIndex: 0,
            gate: $gate,
            command: $command,
            exitCode: $exitCode,
            primaryErrorRaw: $primaryErrorRaw,
            fullErrorLogPath: $fullErrorLogPath,
            failingTest: $failingTest,
            diffHash: $diffHash,
            changedFiles: $changedFiles,
            previousCapsule: null,
            policy: $policy,
            attemptsAllowed: $policy->maxAttempts,
        );
    }

    /**
     * Build a capsule from a {@see RepairAttemptOutcome} reported by the
     * evaluator. The orchestrator passes `previousCapsule` and the per-risk
     * `attemptsAllowed` so this builder can decide retry/stop/escalate
     * deterministically and honestly.
     */
    public function buildFromOutcome(
        string $runId,
        string $taskContractHash,
        int $attemptIndex,
        RepairAttemptOutcome $outcome,
        ?FailureCapsule $previousCapsule,
        RepairPolicy $policy,
        int $attemptsAllowed,
    ): FailureCapsule {
        return $this->build(
            runId: $runId,
            taskContractHash: $taskContractHash,
            attemptIndex: $attemptIndex,
            gate: $outcome->gate ?? 'unknown_gate',
            command: $outcome->command,
            exitCode: $outcome->exitCode,
            primaryErrorRaw: $outcome->primaryErrorExcerpt,
            fullErrorLogPath: $outcome->fullErrorLogPath,
            failingTest: $outcome->failingTest,
            diffHash: $outcome->diffHash,
            changedFiles: $outcome->changedFiles,
            previousCapsule: $previousCapsule,
            policy: $policy,
            attemptsAllowed: $attemptsAllowed,
            outcomeStatus: $outcome->status,
        );
    }

    /**
     * @param  list<string>  $changedFiles
     */
    public function build(
        string $runId,
        string $taskContractHash,
        int $attemptIndex,
        string $gate,
        ?string $command,
        ?int $exitCode,
        string $primaryErrorRaw,
        ?string $fullErrorLogPath,
        ?string $failingTest,
        ?string $diffHash,
        array $changedFiles,
        ?FailureCapsule $previousCapsule,
        RepairPolicy $policy,
        int $attemptsAllowed,
        ?string $outcomeStatus = null,
    ): FailureCapsule {
        $normalizedExcerpt = $this->hasher->normalize($primaryErrorRaw);
        // Keep a non-empty excerpt when the raw input was non-empty so the
        // DTO's "excerpt required when exit_code != 0" invariant holds even
        // after the hasher strips volatile fragments.
        if ($normalizedExcerpt === '' && trim($primaryErrorRaw) !== '') {
            $normalizedExcerpt = trim($primaryErrorRaw);
        }
        $normalizedExcerpt = $this->clampExcerpt($normalizedExcerpt);

        $signature = FailureCapsule::signatureOf($gate, $normalizedExcerpt);
        $sameSignature = $previousCapsule !== null && $previousCapsule->failureSignature === $signature;
        $diffGrew = $this->detectDiffGrowth($changedFiles, $previousCapsule);
        $scopeViolation = $outcomeStatus === RepairAttemptOutcome::STATUS_SCOPE_VIOLATION;
        $blocked = $outcomeStatus === RepairAttemptOutcome::STATUS_BLOCKED;

        [$decision, $signals] = $this->decide(
            attemptIndex: $attemptIndex,
            attemptsAllowed: $attemptsAllowed,
            sameSignature: $sameSignature,
            diffGrew: $diffGrew,
            scopeViolation: $scopeViolation,
            blocked: $blocked,
            policy: $policy,
        );

        return FailureCapsule::issue(
            runId: $runId,
            taskContractHash: $taskContractHash,
            attemptIndex: $attemptIndex,
            gate: $gate,
            command: $command,
            exitCode: $exitCode,
            primaryErrorExcerpt: $normalizedExcerpt,
            fullErrorLogPath: $fullErrorLogPath,
            failingTest: $failingTest,
            diffHash: $diffHash,
            changedFiles: array_values($changedFiles),
            decision: $decision,
            escalationSignalDelta: $signals,
        );
    }

    /**
     * @return array{0:string,1:list<string>}
     */
    private function decide(
        int $attemptIndex,
        int $attemptsAllowed,
        bool $sameSignature,
        bool $diffGrew,
        bool $scopeViolation,
        bool $blocked,
        RepairPolicy $policy,
    ): array {
        $signals = [];

        if ($scopeViolation) {
            $signals[] = self::SIGNAL_SCOPE_VIOLATION;
        }
        if ($sameSignature && $policy->abortOnSameSignatureTwice) {
            $signals[] = self::SIGNAL_SAME_SIGNATURE_TWICE;
        }
        if ($diffGrew) {
            $signals[] = self::SIGNAL_DIFF_GROWTH;
        }
        if ($blocked) {
            $signals[] = self::SIGNAL_BLOCKED_PREFLIGHT;
        }

        if ($signals !== []) {
            return [FailureCapsule::DECISION_ESCALATE, $signals];
        }

        // attemptIndex 0 is the initial failure; the loop will increment to 1+
        // before evaluating again. We've already exhausted budget when the
        // *next* attempt index would exceed the allowance.
        $nextAttempt = $attemptIndex + 1;
        if ($nextAttempt > $attemptsAllowed) {
            return [FailureCapsule::DECISION_STOP, []];
        }

        return [FailureCapsule::DECISION_RETRY, []];
    }

    private function clampExcerpt(string $excerpt): string
    {
        if (strlen($excerpt) <= self::EXCERPT_MAX_BYTES) {
            return $excerpt;
        }
        $marker = self::EXCERPT_TRUNCATION_MARKER;
        $head = substr($excerpt, 0, self::EXCERPT_MAX_BYTES - strlen($marker));

        return $head.$marker;
    }

    /**
     * @param  list<string>  $changedFiles
     */
    private function detectDiffGrowth(array $changedFiles, ?FailureCapsule $previousCapsule): bool
    {
        if ($previousCapsule === null) {
            return false;
        }
        if ($previousCapsule->changedFiles === []) {
            return false;
        }

        return count($changedFiles) > count($previousCapsule->changedFiles) + self::DIFF_GROWTH_FILE_SLACK;
    }
}
