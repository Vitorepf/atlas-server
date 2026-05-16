<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Repair;

use App\Services\Ai\Programming\AtlasDev\Repair\Contracts\RepairAttemptEvaluator;
use App\Services\Ai\Programming\AtlasDev\Repair\Contracts\RepairAttemptOutcome;
use App\Services\Ai\Programming\AtlasDev\Repair\FailureCapsuleBuilder;
use App\Services\Ai\Programming\AtlasDev\Repair\FailureSignatureHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\PromptSections;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ProviderLock;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\QualityChecks;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\RepairPolicy;
use App\Services\Ai\Programming\AtlasDev\Schemas\FailureCapsule;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;

/**
 * Shared builders for repair/escalation unit tests. Constructs canonical
 * Atlas Dev DTOs without touching the real persistence/provider layers.
 */
final class RepairFixtureFactory
{
    public static function lightTaskContract(
        string $runId = 'run-repair-1',
        int $maxAttempts = 2,
        array $allowedFiles = ['app/Foo.php'],
        array $watchedFiles = [],
        array $forbiddenFiles = ['.env'],
        array $validationCommands = ['composer test -- --filter=FooTest'],
        ?string $noTestReason = null,
    ): LightTaskContract {
        $providerLock = new ProviderLock(
            provider: 'claude_cli',
            modelFamily: 'sonnet',
            fallbackAllowed: false,
        );
        $repairPolicy = new RepairPolicy(
            maxAttempts: $maxAttempts,
            sameProvider: true,
            requiresFailedGateOutput: true,
            abortOnSameSignatureTwice: true,
        );

        $hashBody = [
            'allowed_files' => array_values($allowedFiles),
            'allowed_tools' => ['read', 'write'],
            'blocked_actions' => ['provider_fallback'],
            'escalation_on' => ['scope_explosion'],
            'evidence_required' => ['verification_log'],
            'forbidden_files' => array_values($forbiddenFiles),
            'max_files_changed' => 2,
            'no_test_reason' => $noTestReason,
            'owner' => LightTaskContract::OWNER,
            'provider_lock' => $providerLock->toCanonicalArray(),
            'provider_safe' => true,
            'repair_policy' => $repairPolicy->toCanonicalArray(),
            'run_id' => $runId,
            'schema_version' => LightTaskContract::SCHEMA_VERSION,
            'spec_hash' => 'spec-hash-deterministic',
            'task_id' => 'task-1',
            'validation_commands' => array_values($validationCommands),
            'watched_files' => array_values($watchedFiles),
        ];
        // Compute the hash exactly the way LightTaskContract::hash does.
        $hashSource = $hashBody;
        unset($hashSource['task_contract_hash']);
        $taskContractHash = CanonicalHasher::hash($hashSource);

        return new LightTaskContract(
            runId: $runId,
            taskId: 'task-1',
            specHash: 'spec-hash-deterministic',
            allowedTools: ['read', 'write'],
            blockedActions: ['provider_fallback'],
            allowedFiles: array_values($allowedFiles),
            watchedFiles: array_values($watchedFiles),
            forbiddenFiles: array_values($forbiddenFiles),
            maxFilesChanged: 2,
            validationCommands: array_values($validationCommands),
            evidenceRequired: ['verification_log'],
            repairPolicy: $repairPolicy,
            escalationOn: ['scope_explosion'],
            providerLock: $providerLock,
            taskContractHash: $taskContractHash,
            noTestReason: $noTestReason,
        );
    }

    public static function providerPromptProjection(
        string $runId = 'run-repair-1',
        string $taskContractHash = 'tch-deterministic',
        array $allowedFiles = ['app/Foo.php'],
        array $forbiddenFiles = ['.env'],
        string $renderedBody = "# Original Prompt\nDo the thing.",
    ): ProviderPromptProjection {
        $sections = new PromptSections(
            objective: 'Fix the failing FooTest::test_x without expanding scope.',
            operatingRules: ['no_provider_fallback', 'preserve_user_changes'],
            miniSpecRef: 'mini_spec.json#sha256:ms',
            taskContractRef: 'task_contract.json#sha256:'.$taskContractHash,
            contextRefs: ['ctx_a#sha256:ca'],
            codeDiscoveryRef: 'code_discovery.json#sha256:cd',
            allowedFiles: array_values($allowedFiles),
            forbiddenFiles: array_values($forbiddenFiles),
            expectedTests: ['composer test -- --filter=FooTest'],
            acceptanceCriteria: ['gate_passes', 'no_scope_growth'],
            stopConditions: ['stop_if_diff_grows'],
            escalationConditions: ['escalate_if_risk_grows'],
            outputContract: ['unified_diff', 'changed_files_list'],
            providerSafe: true,
        );

        $renderedHash = hash('sha256', $renderedBody);

        $projection = new ProviderPromptProjection(
            runId: $runId,
            upstreamHashes: ['task_contract_hash' => $taskContractHash],
            sections: $sections,
            qualityChecks: QualityChecks::allPassing(),
            renderedPromptText: $renderedBody,
            renderedPromptHash: $renderedHash,
            providerSafe: true,
            promptProjectionHash: 'pending',
        );
        $finalHash = $projection->hash();

        return new ProviderPromptProjection(
            runId: $runId,
            upstreamHashes: $projection->upstreamHashes,
            sections: $projection->sections,
            qualityChecks: $projection->qualityChecks,
            renderedPromptText: $projection->renderedPromptText,
            renderedPromptHash: $projection->renderedPromptHash,
            providerSafe: true,
            promptProjectionHash: $finalHash,
        );
    }

    /**
     * Route fixture capsules through the canonical
     * {@see FailureCapsuleBuilder} so the hasher normalizes excerpts the
     * same way production does. Tests that want the raw DTO path can call
     * {@see FailureCapsule::issue} directly.
     */
    public static function failureCapsule(
        string $runId = 'run-repair-1',
        string $taskContractHash = 'tch-deterministic',
        int $attemptIndex = 0,
        array $changedFiles = ['app/Foo.php'],
        string $primaryError = 'PHPUnit failure: FooTest::test_x expected 1 got 0',
        int $contractMaxAttempts = 2,
    ): FailureCapsule {
        $policy = new \App\Services\Ai\Programming\AtlasDev\Schemas\Components\RepairPolicy(
            maxAttempts: $contractMaxAttempts,
            sameProvider: true,
            requiresFailedGateOutput: true,
            abortOnSameSignatureTwice: true,
        );
        $builder = new FailureCapsuleBuilder(new FailureSignatureHasher());
        if ($attemptIndex === 0) {
            return $builder->buildInitial(
                runId: $runId,
                taskContractHash: $taskContractHash,
                gate: 'verification_gate',
                command: 'composer test -- --filter=FooTest',
                exitCode: 1,
                primaryErrorRaw: $primaryError,
                fullErrorLogPath: '/tmp/fake-log.log',
                failingTest: 'tests/FooTest.php::test_x',
                diffHash: 'sha256:diff-1',
                changedFiles: array_values($changedFiles),
                policy: $policy,
            );
        }

        return $builder->build(
            runId: $runId,
            taskContractHash: $taskContractHash,
            attemptIndex: $attemptIndex,
            gate: 'verification_gate',
            command: 'composer test -- --filter=FooTest',
            exitCode: 1,
            primaryErrorRaw: $primaryError,
            fullErrorLogPath: '/tmp/fake-log.log',
            failingTest: 'tests/FooTest.php::test_x',
            diffHash: 'sha256:diff-1',
            changedFiles: array_values($changedFiles),
            previousCapsule: null,
            policy: $policy,
            attemptsAllowed: $contractMaxAttempts,
        );
    }

    public static function scriptedEvaluator(array $outcomes): RepairAttemptEvaluator
    {
        return new class($outcomes) implements RepairAttemptEvaluator {
            /** @var list<RepairAttemptOutcome> */
            private array $remaining;

            public int $calls = 0;

            /** @var list<array{attempt:int,prompt_hash:string,allowed_files:list<string>,provider:string,model:string}> */
            public array $observed = [];

            /** @param list<RepairAttemptOutcome> $outcomes */
            public function __construct(array $outcomes)
            {
                $this->remaining = $outcomes;
            }

            public function evaluate(
                ProviderPromptProjection $repairPrompt,
                LightTaskContract $contract,
                FailureCapsule $previousCapsule,
                int $attemptIndex,
            ): RepairAttemptOutcome {
                $this->calls++;
                $this->observed[] = [
                    'attempt' => $attemptIndex,
                    'prompt_hash' => $repairPrompt->promptProjectionHash,
                    'allowed_files' => $repairPrompt->sections->allowedFiles,
                    'provider' => $contract->providerLock->provider,
                    'model' => $contract->providerLock->modelFamily,
                ];

                if ($this->remaining === []) {
                    throw new \LogicException("scriptedEvaluator: ran out of scripted outcomes at attempt {$attemptIndex}.");
                }

                return array_shift($this->remaining);
            }
        };
    }
}
