<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use App\Services\Ai\Programming\AtlasDev\Gate\CompletionDecision;
use App\Services\Ai\Programming\AtlasDev\Gate\CompletionStateGate;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGateResult;
use App\Services\Ai\Programming\AtlasDev\Probe\IntentCoverageProbe;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParseResult;
use App\Services\Ai\Programming\AtlasDev\Provider\ProviderCallResult;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ProviderLock;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\RepairPolicy;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeBaseline;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeContractView;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeFileDiff;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeObserved;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\VerificationPlan;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\MiniProgrammingSpec;
use App\Services\Ai\Programming\AtlasDev\Schemas\ScopeGuardReceipt;
use Tests\TestCase;

/**
 * E2 — intent_not_tested honesty flag end-to-end propagation.
 *
 * Covers VAL-E2-009 and VAL-E2-010 through the synthetic-diff harness:
 * the IntentCoverageProbe computes the signal, the honesty flag is appended
 * to VerificationGateResult via withHonestyFlags(), and the
 * CompletionStateGate auto-downgrades PASSED -> needs_review (the
 * passed-forbids-flags invariant guarantees no green-with-flag).
 *
 * This is the integration-level proof that the advisory channel works
 * end-to-end for the E2 intent-coverage probe: the probe's verdict maps to
 * the sanctioned honesty-flag channel, the flag drives the downgrade, and
 * the completion decision never reports passed over an untested intent.
 */
final class IntentNotTestedFlagTest extends TestCase
{
    // -- VAL-E2-009: intent_not_tested fires when only tautological ACs ------

    public function test_val_e2_009_intent_not_tested_downgrades_passed_to_needs_review(): void
    {
        // Write task (intent_text non-empty) with only tautological ACs.
        // The probe fires intent_not_tested; the honesty flag is appended;
        // CompletionStateGate downgrades PASSED -> needs_review.
        $contract = $this->makeContract(intentText: 'corrija o bug');
        $spec = $this->makeSpec(acceptanceCriteria: [
            ['id' => 'ac_cmd_1', 'description' => "comando 'composer test' termina com exit_code=0", 'verification' => 'test', 'verification_ref' => 'composer test'],
            ['id' => 'ac_scope', 'description' => 'diff in scope', 'verification' => 'scope_guard', 'verification_ref' => null],
        ]);

        $probe = new IntentCoverageProbe;
        $intentNotTested = $probe->isIntentNotTested($contract, $spec);

        $this->assertTrue($intentNotTested, 'fixture: probe must fire for tautology-only AC set');

        // Build a green gate result and append the flag (simulating what the
        // PipelineRunExecutor does after the probe).
        $verificationResult = $this->greenGateResult();
        $verificationResult = $verificationResult->withHonestyFlags([
            IntentCoverageProbe::FLAG_INTENT_NOT_TESTED,
        ]);

        $this->assertContains(
            IntentCoverageProbe::FLAG_INTENT_NOT_TESTED,
            $verificationResult->honestyFlags,
            'VAL-E2-009: intent_not_tested must be in the honesty flags',
        );

        $decision = $this->decide($contract, $verificationResult);

        $this->assertNotSame(
            CompletionSummary::STATUS_PASSED,
            $decision->status,
            'VAL-E2-009: completion must NOT be passed when intent_not_tested fires',
        );
        $this->assertSame(
            CompletionSummary::STATUS_NEEDS_REVIEW,
            $decision->status,
            'VAL-E2-009: advisory flag downgrades PASSED -> needs_review',
        );
        $this->assertContains(
            'passed_downgraded_due_to_honesty_flags',
            $decision->reasons,
            'VAL-E2-009: downgrade reason must cite the honesty flags',
        );
    }

    public function test_val_e2_009_intent_not_tested_with_empty_ac_set_downgrades(): void
    {
        $contract = $this->makeContract(intentText: 'corrija');
        $spec = $this->makeSpec(acceptanceCriteria: []);

        $probe = new IntentCoverageProbe;
        $this->assertTrue($probe->isIntentNotTested($contract, $spec));

        $verificationResult = $this->greenGateResult()->withHonestyFlags([
            IntentCoverageProbe::FLAG_INTENT_NOT_TESTED,
        ]);

        $decision = $this->decide($contract, $verificationResult);

        $this->assertNotSame(CompletionSummary::STATUS_PASSED, $decision->status);
    }

    // -- VAL-E2-010: intent_not_tested does NOT fire when intent is tested ----

    public function test_val_e2_010_intent_not_tested_absent_when_behavioral_ac_backs_intent(): void
    {
        // Write task with a behavioral AC carrying a real verification_ref.
        // The probe does NOT fire; no flag is appended; a green gate may
        // report passed (VAL-E2-010: passed reachable when all gates green).
        $contract = $this->makeContract(intentText: 'corrija o bug');
        $spec = $this->makeSpec(acceptanceCriteria: [
            ['id' => 'ac_behavior_corrigir', 'description' => "diff implementa 'corrigir'", 'verification' => 'test', 'verification_ref' => 'composer test'],
            ['id' => 'ac_cmd_1', 'description' => "comando 'composer test' exit_code=0", 'verification' => 'test', 'verification_ref' => 'composer test'],
        ]);

        $probe = new IntentCoverageProbe;
        $intentNotTested = $probe->isIntentNotTested($contract, $spec);

        $this->assertFalse(
            $intentNotTested,
            'VAL-E2-010: probe must NOT fire when a behavioral AC with a real verification_ref backs the intent',
        );

        // No flag appended (the executor only appends when the probe fires).
        $verificationResult = $this->greenGateResult();

        $this->assertNotContains(
            IntentCoverageProbe::FLAG_INTENT_NOT_TESTED,
            $verificationResult->honestyFlags,
            'VAL-E2-010: intent_not_tested must be absent from honesty flags',
        );

        $decision = $this->decide($contract, $verificationResult);

        $this->assertSame(
            CompletionSummary::STATUS_PASSED,
            $decision->status,
            'VAL-E2-010: a green gate with no flag may report passed',
        );
        $this->assertNotContains(
            IntentCoverageProbe::FLAG_INTENT_NOT_TESTED,
            $decision->honestyFlags,
            'VAL-E2-010: no intent_not_tested in the completion decision',
        );
    }

    public function test_val_e2_010_multi_verb_all_tested_no_flag_passed_reachable(): void
    {
        $contract = $this->makeContract(intentText: 'rename X e remova Y');
        $spec = $this->makeSpec(acceptanceCriteria: [
            ['id' => 'ac_behavior_renomear', 'description' => 'rename', 'verification' => 'test', 'verification_ref' => 'composer test'],
            ['id' => 'ac_behavior_remover', 'description' => 'remove', 'verification' => 'test', 'verification_ref' => 'composer test'],
        ]);

        $probe = new IntentCoverageProbe;
        $this->assertFalse($probe->isIntentNotTested($contract, $spec));

        $decision = $this->decide($contract, $this->greenGateResult());

        $this->assertSame(CompletionSummary::STATUS_PASSED, $decision->status);
    }

    // -- Passed-forbids-flags invariant -------------------------------------

    public function test_passed_completion_can_never_carry_intent_not_tested_flag(): void
    {
        // The CompletionDecision ctor invariant (passed forbids flags) makes
        // a green-with-flag impossible by construction.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('passed forbids honesty_flags');

        new CompletionDecision(
            status: CompletionSummary::STATUS_PASSED,
            honestyFlags: [IntentCoverageProbe::FLAG_INTENT_NOT_TESTED],
            residualRisks: [],
        );
    }

    // -- Helpers -------------------------------------------------------------

    private function greenGateResult(): VerificationGateResult
    {
        return new VerificationGateResult(
            tests: [],
            gates: [],
            aggregateStatus: VerificationGateResult::STATUS_PASSED,
            honestyFlags: [],
        );
    }

    private function decide(LightTaskContract $contract, VerificationGateResult $verificationResult): CompletionDecision
    {
        return (new CompletionStateGate)->decide(
            taskContract: $contract,
            scopeReceipt: $this->buildScopeReceipt(),
            verificationResult: $verificationResult,
            callResult: ProviderCallResult::fromStdout(
                runId: 'run-e2-test',
                actualProvider: 'hermes_cli',
                actualModelFamily: 'minimax-m3',
                exitStatus: 0,
                stdout: 'ok',
                stderr: '',
                durationMs: 100,
            ),
            diffResult: DiffParseResult::patch(
                diff: "--- a/file\n+++ b/file\n@@\n+added\n",
                changedFiles: ['app/Foo.php'],
            ),
        );
    }

    private function makeContract(string $intentText = ''): LightTaskContract
    {
        return new LightTaskContract(
            runId: 'run-e2-test',
            taskId: 'task-e2',
            specHash: 'spec-hash-e2',
            allowedTools: ['read', 'write', 'grep', 'run_test'],
            blockedActions: ['production_write'],
            allowedFiles: ['app/Foo.php'],
            watchedFiles: [],
            forbiddenFiles: [],
            maxFilesChanged: 1,
            validationCommands: ['composer test'],
            evidenceRequired: ['verification_receipt'],
            repairPolicy: new RepairPolicy(
                maxAttempts: 1,
                sameProvider: true,
                requiresFailedGateOutput: true,
                abortOnSameSignatureTwice: true,
            ),
            escalationOn: [],
            providerLock: new ProviderLock(
                provider: 'hermes_cli',
                modelFamily: 'minimax-m3',
                fallbackAllowed: false,
            ),
            taskContractHash: 'tch-e2',
            noTestReason: null,
            intentText: $intentText,
        );
    }

    private function buildScopeReceipt(): ScopeGuardReceipt
    {
        return ScopeGuardReceipt::issue(
            runId: 'run-e2-test',
            taskContractHash: 'tch-e2',
            baseline: new ScopeBaseline('clean', null),
            observed: new ScopeObserved(
                gitDiffHash: hash('sha256', 'diff'),
                changedFiles: ['app/Foo.php'],
                changedFilesCount: 1,
                fileDiffs: [
                    new ScopeFileDiff(
                        path: 'app/Foo.php',
                        added: 5,
                        removed: 0,
                        fileHashAfter: hash('sha256', 'foo'),
                    ),
                ],
            ),
            scopeContract: new ScopeContractView(
                allowedFiles: ['app/Foo.php'],
                watchedFiles: [],
                forbiddenFiles: [],
                expectedMaxFiles: 1,
            ),
            violations: [],
            status: ScopeGuardReceipt::STATUS_PASSED,
            statusReason: 'all_files_allowed',
            userPreExistingChanges: [],
        );
    }

    /**
     * @param  list<array{id:string,description:string,verification:string,verification_ref:?string}>  $acceptanceCriteria
     */
    private function makeSpec(array $acceptanceCriteria): MiniProgrammingSpec
    {
        return new MiniProgrammingSpec(
            runId: 'run-e2-test',
            compactSddHash: 'compact-hash',
            goal: 'test goal',
            nonGoals: [],
            canonicalContext: [],
            expectedBehavior: [],
            assumptions: [],
            expectedFiles: [],
            allowedFiles: [],
            forbiddenFiles: [],
            acceptanceCriteria: $acceptanceCriteria,
            verificationPlan: new VerificationPlan(
                profile: 'php_laravel',
                commands: ['composer test'],
                noTestReason: null,
            ),
            rollbackOrContainment: 'git checkout -- .',
            completionCriteria: [],
            miniSpecHash: 'ms-hash',
        );
    }
}
