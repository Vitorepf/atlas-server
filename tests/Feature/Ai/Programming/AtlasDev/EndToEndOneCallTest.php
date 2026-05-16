<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use App\Services\Ai\Programming\AtlasDev\Gate\CompletionStateGate;
use App\Services\Ai\Programming\AtlasDev\Gate\ReceiptComposer;
use App\Services\Ai\Programming\AtlasDev\Gate\ScopeGuard;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandResult;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGate;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParser;
use App\Services\Ai\Programming\AtlasDev\Provider\SonnetClaudeCliAdapter;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Gate\FakeCommandRunner;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\AtlasDevProviderFixtures;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\FakeClaudeCliGateway;

/**
 * Feature test for the Atlas Dev one-call slice end-to-end:
 *   ProviderPromptProjection
 *   -> SonnetClaudeCliAdapter (fake gateway)
 *   -> DiffParser
 *   -> ScopeGuard
 *   -> VerificationGate (fake runner)
 *   -> CompletionStateGate
 *   -> ReceiptComposer -> VerificationReceipt
 *
 * No real provider, no real shell, no real filesystem writes. Repair loop is
 * out of scope here (Fatia 4).
 */
final class EndToEndOneCallTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private function runPipeline(
        string $providerStdout,
        ?VerificationCommandResult $verificationCommandResult = null,
        array $taskContractOverrides = [],
    ): array {
        $gateway = new FakeClaudeCliGateway();
        $gateway->queue($this->gatewayResponse(stdout: $providerStdout));

        $adapter = new SonnetClaudeCliAdapter($gateway);

        $projection = $this->buildSendableProjection(
            taskContract: $this->taskContractFixture($taskContractOverrides),
        );

        $taskContract = $this->taskContractFixture($taskContractOverrides);

        $callResult = $adapter->executeOneCall(
            promptProjection: $projection,
            taskContract: $taskContract,
            workspace: '/tmp/atlas-dev-feature',
        );

        $diffResult = (new DiffParser())->parse($callResult->stdout);

        $scopeReceipt = (new ScopeGuard())->check(
            envelope: $this->envelopeFixture(),
            taskContract: $taskContract,
            diffResult: $diffResult,
        );

        $runner = new FakeCommandRunner();
        if ($verificationCommandResult !== null) {
            $runner->queue($verificationCommandResult);
        }
        $verificationResult = (new VerificationGate($runner))->run(
            taskContract: $taskContract,
            callResult: $callResult,
            scopeReceipt: $scopeReceipt,
            workspace: '/tmp/atlas-dev-feature',
        );

        $decision = (new CompletionStateGate())->decide(
            taskContract: $taskContract,
            scopeReceipt: $scopeReceipt,
            verificationResult: $verificationResult,
            callResult: $callResult,
            diffResult: $diffResult,
        );

        $receipt = (new ReceiptComposer())->compose(
            envelope: $this->envelopeFixture(),
            taskContract: $taskContract,
            promptProjection: $projection,
            callResult: $callResult,
            diffResult: $diffResult,
            scopeReceipt: $scopeReceipt,
            verificationResult: $verificationResult,
            completion: $decision,
            contextPackHash: 'context-pack-hash-feature-test',
            taskKind: 'repair',
            riskLevel: 'R2',
            modelLabel: 'claude-sonnet-4-6',
        );

        return [
            'projection' => $projection,
            'call_result' => $callResult,
            'diff' => $diffResult,
            'scope' => $scopeReceipt,
            'verification' => $verificationResult,
            'decision' => $decision,
            'receipt' => $receipt,
        ];
    }

    public function test_happy_path_one_call_reaches_completion_passed(): void
    {
        $providerStdout = <<<DIFF
Here is the patch:

```diff
--- a/app/Services/Ai/Cli/AtlasCliDevWorkflowService.php
+++ b/app/Services/Ai/Cli/AtlasCliDevWorkflowService.php
@@ -1,3 +1,3 @@
-old impl
+new impl
 keep
```
DIFF;

        $bag = $this->runPipeline(
            providerStdout: $providerStdout,
            verificationCommandResult: new VerificationCommandResult(
                command: 'composer test -- --filter=AtlasCliDevWorkflowServiceTest::test_workspace_resolution',
                exitCode: 0,
                stdout: 'OK (1 test)',
                stderr: '',
                durationMs: 1342,
            ),
        );

        $this->assertTrue($bag['call_result']->ok());
        $this->assertTrue($bag['diff']->hasPatch());
        $this->assertSame(['app/Services/Ai/Cli/AtlasCliDevWorkflowService.php'], $bag['diff']->changedFiles);
        $this->assertSame('passed', $bag['scope']->status);
        $this->assertSame('passed', $bag['verification']->aggregateStatus);
        $this->assertSame(CompletionSummary::STATUS_PASSED, $bag['decision']->status);
        $this->assertSame(CompletionSummary::STATUS_PASSED, $bag['receipt']->completion->status);
        $this->assertSame('claude_cli', $bag['receipt']->provider);
        $this->assertSame('claude-sonnet-4-6', $bag['receipt']->model);
        $this->assertSame(1, $bag['receipt']->cost->providerCalls);
        $this->assertNotSame('', $bag['receipt']->receiptHash);
    }

    public function test_diff_out_of_scope_reaches_completion_failed(): void
    {
        $providerStdout = <<<DIFF
```diff
--- a/vendor/laravel/framework/src/Foo.php
+++ b/vendor/laravel/framework/src/Foo.php
@@ -1,1 +1,1 @@
-old
+new
```
DIFF;

        $bag = $this->runPipeline(
            providerStdout: $providerStdout,
            verificationCommandResult: new VerificationCommandResult('composer test', 0, 'ok', '', 100),
        );

        $this->assertSame('failed', $bag['scope']->status);
        $this->assertSame(CompletionSummary::STATUS_FAILED, $bag['decision']->status);
        $this->assertSame(CompletionSummary::STATUS_FAILED, $bag['receipt']->completion->status);
    }

    public function test_no_patch_needed_reaches_completion_no_patch_needed(): void
    {
        $providerStdout = "no_patch_needed: true\nreason: AtlasCliDevWorkflowServiceTest already passes; no code change required.\n";

        $bag = $this->runPipeline(
            providerStdout: $providerStdout,
            verificationCommandResult: new VerificationCommandResult('composer test', 0, 'ok', '', 100),
        );

        $this->assertTrue($bag['diff']->isNoPatchNeeded());
        $this->assertSame(CompletionSummary::STATUS_NO_PATCH_NEEDED, $bag['decision']->status);
        $this->assertSame(CompletionSummary::STATUS_NO_PATCH_NEEDED, $bag['receipt']->completion->status);
        $noPatchEvidence = array_filter(
            $bag['receipt']->evidenceRefs,
            fn ($ref) => $ref->kind === 'no_patch_reason',
        );
        $this->assertNotEmpty($noPatchEvidence);
    }

    public function test_blocked_response_reaches_completion_blocked(): void
    {
        $providerStdout = "blocked: true\nquestion: should I change resolveWorkspace() or the test fixture?\n";

        $bag = $this->runPipeline(
            providerStdout: $providerStdout,
            verificationCommandResult: new VerificationCommandResult('composer test', 0, 'ok', '', 100),
        );

        $this->assertTrue($bag['diff']->isBlocked());
        $this->assertSame(CompletionSummary::STATUS_BLOCKED, $bag['decision']->status);
        $this->assertSame(CompletionSummary::STATUS_BLOCKED, $bag['receipt']->completion->status);
    }

    public function test_verification_failure_reaches_completion_failed(): void
    {
        $providerStdout = <<<DIFF
```diff
--- a/app/Services/Ai/Cli/AtlasCliDevWorkflowService.php
+++ b/app/Services/Ai/Cli/AtlasCliDevWorkflowService.php
@@ -1,1 +1,1 @@
-old
+new
```
DIFF;

        $bag = $this->runPipeline(
            providerStdout: $providerStdout,
            verificationCommandResult: new VerificationCommandResult(
                command: 'composer test',
                exitCode: 2,
                stdout: '',
                stderr: '1 test failed',
                durationMs: 1100,
            ),
        );

        $this->assertSame('passed', $bag['scope']->status);
        $this->assertSame('failed', $bag['verification']->aggregateStatus);
        $this->assertSame(CompletionSummary::STATUS_FAILED, $bag['receipt']->completion->status);
    }
}
