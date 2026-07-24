<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\SeniorLoop;

use App\Http\Controllers\AtlasDev\Support\RunExecutionResult;
use App\Http\Controllers\AtlasDev\Support\RunExecutor;
use App\Services\Ai\Programming\AtlasDev\Escalation\EscalationDecisionEngine;
use App\Services\Ai\Programming\AtlasDev\Escalation\EscalationSignalsInput;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\GenericArtifactPersister;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Pipeline\AtlasDevFastPathOrchestrator;
use App\Services\Ai\Programming\AtlasDev\Repair\FailureCapsuleBuilder;
use App\Services\Ai\Programming\AtlasDev\Repair\RepairAttemptLimits;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevFailureCapsuleRuntimeService;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevTaskPacketRuntimeService;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ObservedSignals;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\RepairPolicy;
use App\Services\Ai\Programming\AtlasDev\Schemas\FailureCapsule;
use App\Services\Ai\Programming\AtlasDev\Schemas\FastPathErrorLedgerEntry;
use App\Services\Ai\Programming\AtlasDev\Schemas\ScopeGuardReceipt;
use App\Services\Ai\Programming\AtlasDev\Support\WorkspaceOriginIdentity;
use App\Services\Ai\Programming\AtlasDev\Telemetry\ErrorLedgerWriter;
use App\Services\AtlasCode\DevToForgePromotionService;

final class SeniorEngineerLoopExecutor
{
    public function __construct(
        private readonly AtlasDevFastPathOrchestrator $orchestrator,
        private readonly RunExecutor $runExecutor,
        private readonly ReceiptStorage $storage,
        private readonly ErrorLedgerWriter $ledgerWriter,
        private readonly FailureCapsuleBuilder $capsuleBuilder,
        private readonly RepairAttemptLimits $repairLimits,
        private readonly GenericArtifactPersister $persister,
        private readonly EscalationDecisionEngine $escalationEngine,
    ) {}

    /**
     * @param  list<string>  $userConstraints
     * @param  array<string,mixed>  $surfaceHints
     */
    public function run(
        string $surfaceId,
        string $workspace,
        string $rawIntent,
        array $userConstraints = [],
        array $surfaceHints = [],
    ): SeniorEngineerLoopExecution {
        $plan = $this->orchestrator->planOnly($surfaceId, $workspace, $rawIntent, $userConstraints, $surfaceHints);
        $steps = [
            $this->step('plan', 'completed', 'receipts/'.$plan->envelope->runId.'/'.ArtifactNames::SENIOR_ENGINEER_LOOP_AUDIT),
        ];

        if (! $plan->isFastPath()) {
            $execution = new SeniorEngineerLoopExecution(
                runId: $plan->envelope->runId,
                status: 'blocked',
                steps: [
                    ...$steps,
                    $this->step('execute', 'blocked', 'routing_decision='.$plan->routingKind()),
                ],
                runSummary: [
                    'routing_decision' => $plan->routingKind(),
                    'completion_state' => null,
                    'scope_guard_status' => null,
                    'verification_status' => null,
                ],
                debugLoop: [
                    'mode' => 'not_started',
                    'reason' => 'routing_not_executable',
                ],
                learning: $this->learningSummary($plan->envelope->runId, recorded: false, reason: 'routing_not_executable'),
                blockers: array_values($plan->blockers ?: ['routing_not_executable']),
            );

            return $this->persist($execution->withHash());
        }

        $run = $this->runExecutor->execute(
            envelope: $plan->envelope,
            taskContract: $plan->taskContract,
            promptProjection: $plan->promptProjection,
            runId: $plan->envelope->runId,
            expectedCompactSddHash: $plan->compactSdd->compactSddHash,
        );

        $steps[] = $this->step('execute', 'completed', 'receipts/'.$plan->envelope->runId.'/'.ArtifactNames::VERIFICATION_RECEIPT);
        $passed = $run->completionState === 'passed'
            && $run->scopeGuardStatus === 'passed'
            && $run->verificationStatus === 'passed';

        $ledger = null;
        $failureCapsule = null;
        $failureCapsulePath = null;
        $escalationDecision = null;
        $escalationDecisionPath = null;
        if (! $passed) {
            $entry = $this->errorLedgerEntry($plan->envelope->runId, $run->completionState, count($plan->miniSpec->allowedFiles));
            $ledger = $this->ledgerWriter->append($entry);
            $failureCapsule = $this->failureCapsule(
                $plan->envelope->runId,
                $plan->taskContract->taskContractHash,
                $plan->taskContract->validationCommands[0] ?? null,
                $run,
                $plan->taskContract->repairPolicy,
                $plan->taskContract->allowedFiles,
            );
            $failureCapsulePath = $this->persister->writeFailureCapsule($failureCapsule)['path'];

            // Escalation small→strong: the scorer inputs were ALWAYS computed
            // (capsule delta + the M2 repair loop's repair_attempts /
            // repair_abort_reason on the provider-call summary) but nothing
            // consumed them — the run exited failed and the decision was left
            // to the operator. Feed them into the (previously orphaned)
            // EscalationDecisionEngine so a failed run deterministically
            // resolves to forge / obra_candidate / no escalation, persisted
            // as escalation_decision.json. The live signals: an M2 repair
            // loop that aborted on same_signature_twice, the number of repair
            // attempts burned in this run, and the failure blast radius
            // (changed-file count). R4/R5 never reaches execution (routing
            // sends it to forge preview), so the risk term stays a no-op
            // here by construction. `forge` always keeps
            // human_action_required=true (Dev never auto-creates an Obra);
            // this block only DECIDES and records, it never invokes a
            // stronger provider by itself.
            // Fail-open: the decision is telemetry over an ALREADY-FAILED
            // run — a bad scorer input (e.g. a rehydrated CompactSdd whose
            // risk_level was never validated by fromArray()) must degrade to
            // "no escalation decision", never crash the senior loop after
            // the capsule + ledger were already persisted.
            try {
                $escalationDecision = $this->escalationEngine->decide(
                    input: new EscalationSignalsInput(
                        riskLevel: $plan->compactSdd->riskLevel,
                        fileCount: count($failureCapsule->changedFiles),
                        layersTouched: 1,
                        riskKeywords: [],
                        sameSignatureTwice: ($run->providerCallSummary['repair_abort_reason'] ?? null) === 'same_signature_twice'
                            || in_array(FailureCapsuleBuilder::SIGNAL_SAME_SIGNATURE_TWICE, $failureCapsule->escalationSignalDelta, true),
                        diffGrew: in_array(FailureCapsuleBuilder::SIGNAL_DIFF_GROWTH, $failureCapsule->escalationSignalDelta, true),
                        testCoverageGap: false,
                        priorFailureInArea: false,
                        contextRequiredChars: null,
                        threadMessages: null,
                        priorFailureCount: max(0, (int) ($run->providerCallSummary['repair_attempts'] ?? 0)),
                        loopEscalationSignalDelta: $failureCapsule->escalationSignalDelta,
                    ),
                    runId: $plan->envelope->runId,
                    taskContractHash: $plan->taskContract->taskContractHash,
                    triggeredAtIso: now()->toIso8601String(),
                );
                if ($escalationDecision !== null) {
                    // Own fail-open: a persist failure must not throw the
                    // successfully computed decision away — run_summary still
                    // surfaces it (with a null ref).
                    try {
                        $escalationDecisionPath = $this->persister->writeEscalationDecision($escalationDecision);
                    } catch (\Throwable) {
                        $escalationDecisionPath = null;
                    }

                    // The decision's missing consumer: bridge it into the
                    // promotion-candidate registry so the Attention control
                    // plane surfaces it to the operator (readEscalationDecision
                    // had ZERO callers — decide-and-record was a dead end).
                    // Always obra_candidate + pending_decision: Dev never
                    // auto-creates an Obra from a run. Own fail-open: a bridge
                    // hiccup must not erase the already-persisted decision
                    // from run_summary.
                    try {
                        app(DevToForgePromotionService::class)->candidateFromRunEscalation(
                            $escalationDecision,
                            $plan->envelope->normalizedIntent,
                            $plan->envelope->workspace,
                            $failureCapsule->changedFiles,
                            $failureCapsule->primaryErrorExcerpt,
                        );
                    } catch (\Throwable) {
                        // fail-open: Attention bridge is best-effort
                    }
                }
            } catch (\Throwable) {
                $escalationDecision = null;
                $escalationDecisionPath = null;
            }

            // M5 write side — compounding failure memory. The
            // DevFailureCapsulePromptInjector (read side) was armed in the
            // planning path since M5, but NOTHING on the delivery path ever
            // persisted a capsule row: the AtlasDevFailureCapsule table was
            // empty and every run's known-failure-modes injection was [].
            // Persist the JSON capsule as a DB row, anchored to a task
            // packet whose workspace_slug matches what the orchestrator
            // passes to injectFor(), so the NEXT run touching the same files
            // in the same REPO sees this failure in its prompt. The slug is
            // the stable ORIGIN identity, not the checkout path: sandboxed
            // flows run in per-run temp dirs, so raw envelope workspaces
            // never repeat and exact-slug injection would be mathematically
            // empty there. Fail-open: learning must never break the run.
            try {
                $packet = app(DevTaskPacketRuntimeService::class)->persist([
                    'run_id' => $plan->envelope->runId,
                    'task_id' => 'senior-loop-'.$plan->envelope->runId,
                    'objective' => $plan->envelope->normalizedIntent,
                    'workspace_slug' => WorkspaceOriginIdentity::slug($plan->envelope->workspace),
                    'allowed_files' => $plan->taskContract->allowedFiles,
                    'source' => 'senior_engineer_loop',
                ]);
                app(DevFailureCapsuleRuntimeService::class)->persist([
                    'run_id' => $plan->envelope->runId,
                    'task_id' => 'senior-loop-'.$plan->envelope->runId,
                    'failing_gate' => $failureCapsule->gate,
                    'error_excerpt' => $failureCapsule->primaryErrorExcerpt,
                    'changed_files' => $failureCapsule->changedFiles,
                ], $packet);
            } catch (\Throwable) {
                // fail-open
            }
        }

        $execution = new SeniorEngineerLoopExecution(
            runId: $plan->envelope->runId,
            status: $passed ? 'passed' : $this->statusFromCompletion($run->completionState),
            steps: [
                ...$steps,
                $this->step('verify', $passed ? 'completed' : 'failed', 'receipts/'.$plan->envelope->runId.'/'.ArtifactNames::VERIFICATION_RECEIPT),
                $this->step('learning_handoff', 'completed', $ledger === null ? 'no_ledger_entry_needed' : 'error_ledger_version='.$ledger['version']),
            ],
            runSummary: [
                'completion_state' => $run->completionState,
                'diff_hash' => $run->diffHash,
                'provider_call' => $run->providerCallSummary,
                'scope_guard_status' => $run->scopeGuardStatus,
                'verification_receipt_hash' => $run->verificationReceiptHash,
                'verification_status' => $run->verificationStatus,
                'escalation' => $escalationDecision === null ? null : [
                    'target' => $escalationDecision->target,
                    'score' => $escalationDecision->score,
                    'human_action_required' => $escalationDecision->humanActionRequired,
                    'ref' => $this->receiptRef($plan->envelope->runId, $escalationDecisionPath),
                ],
            ],
            debugLoop: [
                'mode' => $passed ? 'single_attempt_verified_execution' : 'bounded_repair_triage',
                'attempts_executed' => 1,
                'attempts_allowed' => $this->repairLimits->attemptsAllowed(
                    $plan->compactSdd->riskLevel,
                    $plan->taskContract->repairPolicy->maxAttempts,
                ),
                'failure_capsules' => $failureCapsule === null ? [] : [
                    [
                        'attempt_index' => $failureCapsule->attemptIndex,
                        'capsule_hash' => $failureCapsule->capsuleHash,
                        'decision' => $failureCapsule->decision,
                        'failure_signature' => $failureCapsule->failureSignature,
                        'ref' => $this->receiptRef($plan->envelope->runId, $failureCapsulePath),
                    ],
                ],
                'recovered' => $passed,
                'stop_signals' => [
                    'same_signature_twice',
                    'scope_violation',
                    'diff_growth',
                    'max_attempts_reached',
                    'risk_level_forbids_repair',
                ],
                'stop_reason' => $passed ? 'verification_passed' : 'verification_or_scope_failed',
            ],
            learning: $this->learningSummary(
                $plan->envelope->runId,
                recorded: $ledger !== null,
                reason: $ledger === null ? 'healthy_run' : 'failure_recorded_for_curator',
                ledgerVersion: $ledger['version'] ?? null,
            ),
            blockers: $passed ? [] : ['senior_loop_execution_not_passed'],
        );

        return $this->persist($execution->withHash());
    }

    /**
     * @param  list<string>  $allowedFiles
     */
    private function failureCapsule(
        string $runId,
        string $taskContractHash,
        ?string $validationCommand,
        RunExecutionResult $run,
        RepairPolicy $repairPolicy,
        array $allowedFiles,
    ): FailureCapsule {
        $gate = $this->failedGate((string) $run->scopeGuardStatus, (string) $run->verificationStatus, (string) $run->completionState);
        $changedFiles = [];
        foreach ((array) (($run->diffParseSummary ?? [])['changed_files'] ?? []) as $file) {
            if (is_string($file) && $file !== '') {
                $changedFiles[] = $file;
            }
        }
        if ($changedFiles === []) {
            $changedFiles = array_values($allowedFiles);
        }

        return $this->capsuleBuilder->buildInitial(
            runId: $runId,
            taskContractHash: $taskContractHash,
            gate: $gate,
            command: $this->failedTestCommand($runId) ?? $validationCommand,
            exitCode: $this->failedTestExitCode($runId) ?? $this->exitCode($run),
            primaryErrorRaw: $this->primaryError($run, $runId),
            fullErrorLogPath: $this->failedTestOutputPath($runId),
            failingTest: $this->failedTestCommand($runId),
            diffHash: is_string($run->diffHash ?? null) ? $run->diffHash : null,
            changedFiles: $changedFiles,
            policy: $repairPolicy,
        );
    }

    private function failedGate(string $scopeGuardStatus, string $verificationStatus, string $completionState): string
    {
        if ($scopeGuardStatus !== ScopeGuardReceipt::STATUS_PASSED) {
            return 'scope_guard_light';
        }
        if ($verificationStatus !== 'passed') {
            return 'verification_gate';
        }
        if ($completionState !== CompletionSummary::STATUS_PASSED) {
            return 'completion_state_gate';
        }

        return 'senior_loop_gate';
    }

    private function exitCode(object $run): ?int
    {
        $exitCode = $run->providerCallSummary['exit_code'] ?? null;

        return is_int($exitCode) ? $exitCode : null;
    }

    private function primaryError(object $run, string $runId): string
    {
        $errors = $run->providerCallSummary['error_codes'] ?? [];
        if (is_array($errors) && $errors !== []) {
            return implode('; ', array_map(static fn (mixed $error): string => (string) $error, $errors));
        }

        $failedTest = $this->failedTest($runId);
        if ($failedTest !== null) {
            $command = trim((string) ($failedTest['command'] ?? ''));
            $exitCode = $failedTest['exit_code'] ?? null;
            $outputPath = trim((string) ($failedTest['output_path'] ?? ''));

            return 'Verification command failed'
                .($command !== '' ? ': '.$command : '')
                .(is_int($exitCode) ? ' (exit='.$exitCode.')' : '')
                .($outputPath !== '' ? '; output_path='.$outputPath : '');
        }

        return 'Senior Loop execution did not pass: completion='.(string) $run->completionState
            .', scope='.(string) $run->scopeGuardStatus
            .', verification='.(string) $run->verificationStatus;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function failedTest(string $runId): ?array
    {
        $receipt = $this->storage->read($runId, ArtifactNames::VERIFICATION_RECEIPT);
        foreach ((array) ($receipt['tests'] ?? []) as $test) {
            if (is_array($test) && ($test['ok'] ?? true) === false) {
                return $test;
            }
        }

        return null;
    }

    private function failedTestCommand(string $runId): ?string
    {
        $command = $this->failedTest($runId)['command'] ?? null;

        return is_string($command) && trim($command) !== '' ? $command : null;
    }

    private function failedTestExitCode(string $runId): ?int
    {
        $exitCode = $this->failedTest($runId)['exit_code'] ?? null;

        return is_int($exitCode) ? $exitCode : null;
    }

    private function failedTestOutputPath(string $runId): ?string
    {
        $path = $this->failedTest($runId)['output_path'] ?? null;

        return is_string($path) && trim($path) !== '' ? $path : null;
    }

    private function receiptRef(string $runId, ?string $path): ?string
    {
        return is_string($path) && $path !== ''
            ? 'receipts/'.$runId.'/'.basename($path)
            : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function step(string $id, string $status, string $evidenceRef): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'evidence_ref' => $evidenceRef,
        ];
    }

    private function persist(SeniorEngineerLoopExecution $execution): SeniorEngineerLoopExecution
    {
        $this->storage->writeAtomic(
            $execution->runId,
            ArtifactNames::SENIOR_ENGINEER_LOOP_EXECUTION,
            $execution->toCanonicalArray(),
        );

        return $execution;
    }

    private function errorLedgerEntry(string $runId, string $completionState, int $fileCount): FastPathErrorLedgerEntry
    {
        return FastPathErrorLedgerEntry::issue(
            runId: $runId,
            failureSignature: hash('sha256', $runId.'|'.$completionState.'|senior_loop_execution'),
            completionState: $completionState,
            actualFailureMode: FastPathErrorLedgerEntry::FAILURE_MODE_OTHER,
            shouldHaveEscalated: null,
            missingEscalationSignals: [],
            observedSignals: new ObservedSignals(
                fileCount: $fileCount,
                layersTouched: 1,
                riskKeywords: [],
                testCoverageGap: false,
            ),
            correctionRecommendation: [
                'Curator must inspect senior_loop_execution and verification_receipt before promotion.',
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function learningSummary(string $runId, bool $recorded, string $reason, ?int $ledgerVersion = null): array
    {
        return [
            'auto_apply' => false,
            'curator_required' => true,
            'error_ledger_recorded' => $recorded,
            'error_ledger_ref' => $ledgerVersion === null
                ? null
                : 'receipts/'.$runId.'/'.ArtifactNames::ERROR_LEDGER_BASE.'.v'.$ledgerVersion.'.json',
            'reason' => $reason,
        ];
    }

    private function statusFromCompletion(string $completionState): string
    {
        return match ($completionState) {
            'needs_review' => 'needs_review',
            'blocked' => 'blocked',
            default => 'failed',
        };
    }
}
