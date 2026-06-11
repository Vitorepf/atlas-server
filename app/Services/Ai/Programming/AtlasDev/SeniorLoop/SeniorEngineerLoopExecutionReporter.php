<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\SeniorLoop;

use App\Http\Controllers\AtlasDev\Support\RunExecutionResult;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGateResult;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\GenericArtifactPersister;
use App\Services\Ai\Programming\AtlasDev\Repair\FailureCapsuleBuilder;
use App\Services\Ai\Programming\AtlasDev\Repair\RepairAttemptLimits;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ObservedSignals;
use App\Services\Ai\Programming\AtlasDev\Schemas\FastPathErrorLedgerEntry;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\ScopeGuardReceipt;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;
use App\Services\Ai\Programming\AtlasDev\Telemetry\ErrorLedgerWriter;

final class SeniorEngineerLoopExecutionReporter
{
    public function __construct(
        private readonly ErrorLedgerWriter $ledgerWriter,
        private readonly FailureCapsuleBuilder $capsuleBuilder,
        private readonly RepairAttemptLimits $repairLimits,
        private readonly GenericArtifactPersister $persister,
    ) {}

    /**
     * @param  array<string,mixed>|null  $planAudit
     */
    public function fromRunResult(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        RunExecutionResult $result,
        ?array $planAudit,
    ): SeniorEngineerLoopExecution {
        $blockers = $this->blockers($result);
        $ledger = $this->writeLedgerIfNeeded($envelope, $taskContract, $result);
        [$failureCapsule, $failureCapsulePath] = $this->writeFailureCapsuleIfNeeded($taskContract, $result);
        $execution = new SeniorEngineerLoopExecution(
            runId: $envelope->runId,
            status: $this->status($result, $blockers),
            steps: [
                [
                    'id' => 'step_1',
                    'name' => 'load_persisted_plan',
                    'status' => 'completed',
                    'evidence_ref' => 'receipts/'.$envelope->runId.'/'.ArtifactNames::TASK_CONTRACT,
                ],
                [
                    'id' => 'step_2',
                    'name' => 'execute_provider_or_deterministic_patch',
                    'status' => 'completed',
                    'evidence_ref' => $this->refFor($envelope->runId, $result, ArtifactNames::PROVIDER_CALL_RESULT),
                ],
                [
                    'id' => 'step_3',
                    'name' => 'apply_scope_and_verification_gates',
                    'status' => $blockers === [] ? 'completed' : 'needs_review',
                    'evidence_ref' => $this->refFor($envelope->runId, $result, ArtifactNames::VERIFICATION_RECEIPT),
                ],
                [
                    'id' => 'step_4',
                    'name' => 'handoff_learning_without_auto_apply',
                    'status' => 'completed',
                    'evidence_ref' => 'receipts/'.$envelope->runId.'/'.ArtifactNames::SENIOR_ENGINEER_LOOP_EXECUTION,
                ],
            ],
            runSummary: [
                'completion_state' => $result->completionState,
                'scope_guard_status' => $result->scopeGuardStatus,
                'verification_status' => $result->verificationStatus,
                'verification_receipt_hash' => $result->verificationReceiptHash,
                'scope_guard_receipt_hash' => $result->scopeGuardReceiptHash,
                'diff_hash' => $result->diffHash,
                'provider_call' => $result->providerCallSummary,
                'persisted_receipt_refs' => $this->refs($envelope->runId, $result->persistedReceiptPaths),
                'plan_audit_ref' => $planAudit === null ? null : 'receipts/'.$envelope->runId.'/'.ArtifactNames::SENIOR_ENGINEER_LOOP_AUDIT,
            ],
            debugLoop: [
                'mode' => $blockers === []
                    ? 'single_attempt_verified_execution'
                    : 'bounded_repair_triage',
                'attempts_executed' => 1,
                'attempts_allowed' => $this->repairLimits->attemptsAllowed(
                    'R2',
                    $taskContract->repairPolicy->maxAttempts,
                ),
                'failure_capsules' => $failureCapsule === null ? [] : [
                    [
                        'attempt_index' => $failureCapsule->attemptIndex,
                        'capsule_hash' => $failureCapsule->capsuleHash,
                        'decision' => $failureCapsule->decision,
                        'failure_signature' => $failureCapsule->failureSignature,
                        'ref' => $failureCapsulePath === null ? null : 'receipts/'.$envelope->runId.'/'.basename($failureCapsulePath),
                    ],
                ],
                'max_attempts' => $taskContract->repairPolicy->maxAttempts,
                'stop_signals' => [
                    'same_signature_twice',
                    'scope_violation',
                    'diff_growth',
                    'max_attempts_reached',
                    'risk_level_forbids_repair',
                    'risk_level_at_or_above_r4',
                    'verification_command_unsafe',
                ],
                'diff_parse' => $result->diffParseSummary,
                'recovered' => $blockers === [],
                'stop_reason' => $blockers === [] ? 'verification_passed' : 'verification_or_scope_failed',
            ],
            learning: [
                'auto_apply' => false,
                'curator' => 'programming_curator',
                'proposal_inbox_required' => true,
                'error_ledger_candidate' => $this->shouldRecordLearning($result->completionState),
                'error_ledger_recorded' => $ledger !== null,
                'error_ledger_ref' => $ledger === null
                    ? 'receipts/'.$envelope->runId.'/'.ArtifactNames::ERROR_LEDGER_BASE
                    : 'receipts/'.$envelope->runId.'/'.ArtifactNames::ERROR_LEDGER_BASE.'.v'.$ledger['version'].'.json',
                'signals' => $this->learningSignals($result),
            ],
            blockers: $blockers,
        );

        return $execution->withHash();
    }

    /**
     * @param  array<string,string>  $paths
     * @return array<string,string>
     */
    private function refs(string $runId, array $paths): array
    {
        $refs = [];
        foreach ($paths as $name => $path) {
            $refs[$name] = 'receipts/'.$runId.'/'.basename($path);
        }
        ksort($refs);

        return $refs;
    }

    private function refFor(string $runId, RunExecutionResult $result, string $name): ?string
    {
        $path = $result->persistedReceiptPaths[$name] ?? null;

        return is_string($path) ? 'receipts/'.$runId.'/'.basename($path) : null;
    }

    /**
     * @return list<string>
     */
    private function blockers(RunExecutionResult $result): array
    {
        $blockers = [];
        if ($result->scopeGuardStatus !== ScopeGuardReceipt::STATUS_PASSED) {
            $blockers[] = 'scope_guard_'.$result->scopeGuardStatus;
        }
        if ($result->verificationStatus === VerificationGateResult::STATUS_FAILED) {
            $blockers[] = 'verification_failed';
        }
        if ($result->completionState === CompletionSummary::STATUS_BLOCKED) {
            $blockers[] = 'completion_blocked';
        }

        return AtlasDevStringListNormalizer::uniqueTrimmedStrings($blockers);
    }

    /**
     * @param  list<string>  $blockers
     */
    private function status(RunExecutionResult $result, array $blockers): string
    {
        return match ($result->completionState) {
            CompletionSummary::STATUS_PASSED,
            CompletionSummary::STATUS_NO_PATCH_NEEDED => $blockers === [] ? 'passed' : 'needs_review',
            CompletionSummary::STATUS_BLOCKED => 'blocked',
            CompletionSummary::STATUS_NEEDS_REVIEW => 'needs_review',
            default => 'failed',
        };
    }

    private function shouldRecordLearning(string $completionState): bool
    {
        return in_array($completionState, [
            CompletionSummary::STATUS_FAILED,
            CompletionSummary::STATUS_NEEDS_REVIEW,
            CompletionSummary::STATUS_BLOCKED,
            CompletionSummary::STATUS_ESCALATE_FORGE,
        ], true);
    }

    /**
     * @return array{path:string, version:int}|null
     */
    private function writeLedgerIfNeeded(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        RunExecutionResult $result,
    ): ?array {
        if (! $this->ledgerWriter->shouldRecord($result->completionState)) {
            return null;
        }

        return $this->ledgerWriter->append(FastPathErrorLedgerEntry::issue(
            runId: $envelope->runId,
            failureSignature: hash('sha256', implode('|', [
                $envelope->runId,
                $result->completionState,
                $result->scopeGuardStatus,
                $result->verificationStatus,
                $result->diffHash ?? 'no-diff',
                'senior_loop_execution',
            ])),
            completionState: $result->completionState,
            actualFailureMode: $this->failureMode($result),
            shouldHaveEscalated: null,
            missingEscalationSignals: [],
            observedSignals: new ObservedSignals(
                fileCount: max(0, count($taskContract->allowedFiles)),
                layersTouched: max(1, count(array_filter([
                    $result->diffParseSummary,
                    $result->scopeGuardReceiptHash,
                    $result->verificationReceiptHash,
                ]))),
                riskKeywords: array_values(array_filter(
                    (array) ($result->providerCallSummary['error_codes'] ?? []),
                    static fn (mixed $value): bool => is_string($value) && $value !== '',
                )),
                testCoverageGap: $result->verificationStatus !== VerificationGateResult::STATUS_PASSED,
            ),
            correctionRecommendation: [
                'Curator must inspect senior_loop_execution and verification_receipt before promotion.',
                'Do not auto-apply learning from failed or blocked Senior Loop runs.',
            ],
        ));
    }

    private function failureMode(RunExecutionResult $result): string
    {
        if ($result->scopeGuardStatus !== ScopeGuardReceipt::STATUS_PASSED) {
            return FastPathErrorLedgerEntry::FAILURE_MODE_WRONG_SCOPE;
        }
        if ($result->verificationStatus === VerificationGateResult::STATUS_FAILED) {
            return FastPathErrorLedgerEntry::FAILURE_MODE_MISSED_TEST;
        }

        return FastPathErrorLedgerEntry::FAILURE_MODE_OTHER;
    }

    /**
     * @return array{0:FailureCapsule|null,1:string|null}
     */
    private function writeFailureCapsuleIfNeeded(
        LightTaskContract $taskContract,
        RunExecutionResult $result,
    ): array {
        if ($this->blockers($result) === []) {
            return [null, null];
        }

        $capsule = $this->capsuleBuilder->buildInitial(
            runId: $taskContract->runId,
            taskContractHash: $taskContract->taskContractHash,
            gate: $this->failedGate($result),
            command: $taskContract->validationCommands[0] ?? null,
            exitCode: $this->exitCode($result),
            primaryErrorRaw: $this->primaryError($result),
            fullErrorLogPath: null,
            failingTest: null,
            diffHash: $result->diffHash,
            changedFiles: $this->changedFiles($taskContract, $result),
            policy: $taskContract->repairPolicy,
        );

        $persisted = $this->persister->writeFailureCapsule($capsule);

        return [$capsule, $persisted['path']];
    }

    private function failedGate(RunExecutionResult $result): string
    {
        if ($result->scopeGuardStatus !== ScopeGuardReceipt::STATUS_PASSED) {
            return 'scope_guard_light';
        }
        if ($result->verificationStatus !== VerificationGateResult::STATUS_PASSED) {
            return 'verification_gate';
        }
        if ($result->completionState !== CompletionSummary::STATUS_PASSED) {
            return 'completion_state_gate';
        }

        return 'senior_loop_gate';
    }

    private function exitCode(RunExecutionResult $result): ?int
    {
        $exitCode = $result->providerCallSummary['exit_code'] ?? null;

        return is_int($exitCode) ? $exitCode : null;
    }

    private function primaryError(RunExecutionResult $result): string
    {
        $errors = $result->providerCallSummary['error_codes'] ?? [];
        if (is_array($errors) && $errors !== []) {
            return implode('; ', array_map(static fn (mixed $error): string => (string) $error, $errors));
        }

        return 'Senior Loop execution did not pass: completion='.$result->completionState
            .', scope='.$result->scopeGuardStatus
            .', verification='.$result->verificationStatus;
    }

    /**
     * @return list<string>
     */
    private function changedFiles(LightTaskContract $taskContract, RunExecutionResult $result): array
    {
        $changedFiles = [];
        foreach ((array) (($result->diffParseSummary ?? [])['changed_files'] ?? []) as $file) {
            if (is_string($file) && $file !== '') {
                $changedFiles[] = $file;
            }
        }

        return $changedFiles === [] ? array_values($taskContract->allowedFiles) : $changedFiles;
    }

    /**
     * @return list<string>
     */
    private function learningSignals(RunExecutionResult $result): array
    {
        $signals = [];
        if ($result->scopeGuardStatus !== ScopeGuardReceipt::STATUS_PASSED) {
            $signals[] = 'scope_guard_'.$result->scopeGuardStatus;
        }
        if ($result->verificationStatus !== VerificationGateResult::STATUS_PASSED) {
            $signals[] = 'verification_'.$result->verificationStatus;
        }
        foreach ((array) ($result->providerCallSummary['error_codes'] ?? []) as $error) {
            if (is_string($error) && $error !== '') {
                $signals[] = 'provider_error:'.$error;
            }
        }
        if ($signals === []) {
            $signals[] = 'run_completed_without_learning_error';
        }

        return AtlasDevStringListNormalizer::uniqueTrimmedStrings($signals);
    }
}
