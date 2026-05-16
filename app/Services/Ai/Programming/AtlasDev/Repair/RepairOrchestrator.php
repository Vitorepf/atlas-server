<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Repair;

use App\Services\Ai\Programming\AtlasDev\Repair\Contracts\RepairAttemptEvaluator;
use App\Services\Ai\Programming\AtlasDev\Repair\Contracts\RepairAttemptOutcome;
use App\Services\Ai\Programming\AtlasDev\Schemas\FailureCapsule;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use InvalidArgumentException;

/**
 * Limited, honest repair loop. Drives at most `attempts_allowed` retry
 * attempts through a {@see RepairAttemptEvaluator} (which Provider/Gate
 * implement) until the gate goes green, the budget runs out, or a stop
 * signal fires.
 *
 * The orchestrator never:
 *   - calls Claude CLI or the verification command runner directly;
 *   - mutates `LightTaskContract.allowed_files`;
 *   - retries after a `same_signature_twice` or `scope_violation` capsule;
 *   - schedules an R4/R5 attempt (those return `not_attempted` immediately).
 */
final class RepairOrchestrator
{
    public function __construct(
        private readonly RepairAttemptLimits $limits,
        private readonly FailureCapsuleBuilder $capsuleBuilder,
        private readonly RepairPromptComposer $promptComposer,
    ) {}

    public function run(
        LightTaskContract $contract,
        ProviderPromptProjection $originalPrompt,
        FailureCapsule $initialCapsule,
        string $riskLevel,
        RepairAttemptEvaluator $evaluator,
    ): RepairLoopResult {
        if ($contract->taskContractHash !== $initialCapsule->taskContractHash) {
            throw new InvalidArgumentException(
                'RepairOrchestrator: contract and initial capsule task_contract_hash mismatch.'
            );
        }
        if ($contract->runId !== $initialCapsule->runId || $contract->runId !== $originalPrompt->runId) {
            throw new InvalidArgumentException(
                'RepairOrchestrator: run_id must be consistent across contract, prompt and initial capsule.'
            );
        }

        $attemptsAllowed = $this->limits->attemptsAllowed($riskLevel, $contract->repairPolicy->maxAttempts);

        // Track every capsule in attempt order. The initial capsule (index 0)
        // documents the failure that *triggered* the loop; retries start at
        // index 1.
        $capsules = [$initialCapsule];
        $signals = array_values($initialCapsule->escalationSignalDelta);

        if ($this->limits->escalateImmediately($riskLevel)) {
            $signals = $this->mergeSignals($signals, ['risk_level_forbids_repair']);

            return new RepairLoopResult(
                status: RepairLoopResult::STATUS_NOT_ATTEMPTED,
                attemptsExecuted: 0,
                attemptsAllowed: $attemptsAllowed,
                capsules: $capsules,
                escalationSignalDelta: $signals,
                lastCapsule: $initialCapsule,
            );
        }

        if ($initialCapsule->decision === FailureCapsule::DECISION_ESCALATE) {
            // Stop signals were already present at the initial failure — the
            // pipeline should escalate without consuming attempt budget.
            return new RepairLoopResult(
                status: RepairLoopResult::STATUS_ESCALATED,
                attemptsExecuted: 0,
                attemptsAllowed: $attemptsAllowed,
                capsules: $capsules,
                escalationSignalDelta: $signals,
                lastCapsule: $initialCapsule,
            );
        }

        if ($attemptsAllowed === 0) {
            return new RepairLoopResult(
                status: RepairLoopResult::STATUS_NOT_ATTEMPTED,
                attemptsExecuted: 0,
                attemptsAllowed: 0,
                capsules: $capsules,
                escalationSignalDelta: $this->mergeSignals($signals, ['policy_forbids_repair']),
                lastCapsule: $initialCapsule,
            );
        }

        $previousCapsule = $initialCapsule;
        $attemptsExecuted = 0;
        for ($attempt = 1; $attempt <= $attemptsAllowed; $attempt++) {
            $repairPrompt = $this->promptComposer->compose(
                original: $originalPrompt,
                capsule: $previousCapsule,
                contract: $contract,
                attemptIndex: $attempt,
                maxAttempts: $attemptsAllowed,
            );

            $outcome = $evaluator->evaluate(
                repairPrompt: $repairPrompt,
                contract: $contract,
                previousCapsule: $previousCapsule,
                attemptIndex: $attempt,
            );
            $attemptsExecuted++;

            if ($outcome->isPassed()) {
                return new RepairLoopResult(
                    status: RepairLoopResult::STATUS_RECOVERED,
                    attemptsExecuted: $attemptsExecuted,
                    attemptsAllowed: $attemptsAllowed,
                    capsules: $capsules,
                    escalationSignalDelta: $signals,
                    lastCapsule: $previousCapsule,
                );
            }

            $nextCapsule = $this->capsuleBuilder->buildFromOutcome(
                runId: $contract->runId,
                taskContractHash: $contract->taskContractHash,
                attemptIndex: $attempt,
                outcome: $outcome,
                previousCapsule: $previousCapsule,
                policy: $contract->repairPolicy,
                attemptsAllowed: $attemptsAllowed,
            );

            $capsules[] = $nextCapsule;
            $signals = $this->mergeSignals($signals, $nextCapsule->escalationSignalDelta);
            $previousCapsule = $nextCapsule;

            if ($nextCapsule->decision === FailureCapsule::DECISION_ESCALATE) {
                return new RepairLoopResult(
                    status: RepairLoopResult::STATUS_ESCALATED,
                    attemptsExecuted: $attemptsExecuted,
                    attemptsAllowed: $attemptsAllowed,
                    capsules: $capsules,
                    escalationSignalDelta: $signals,
                    lastCapsule: $nextCapsule,
                );
            }

            if ($nextCapsule->decision === FailureCapsule::DECISION_STOP) {
                return new RepairLoopResult(
                    status: RepairLoopResult::STATUS_EXHAUSTED,
                    attemptsExecuted: $attemptsExecuted,
                    attemptsAllowed: $attemptsAllowed,
                    capsules: $capsules,
                    escalationSignalDelta: $signals,
                    lastCapsule: $nextCapsule,
                );
            }
        }

        // Safety net: builder hit retry on the last attempt but the loop's
        // budget is now spent. Convert to "exhausted" honestly.
        return new RepairLoopResult(
            status: RepairLoopResult::STATUS_EXHAUSTED,
            attemptsExecuted: $attemptsExecuted,
            attemptsAllowed: $attemptsAllowed,
            capsules: $capsules,
            escalationSignalDelta: $signals,
            lastCapsule: $previousCapsule,
        );
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<string>
     */
    private function mergeSignals(array $a, array $b): array
    {
        $merged = array_values(array_unique([...$a, ...$b]));
        sort($merged, SORT_STRING);

        return $merged;
    }
}
