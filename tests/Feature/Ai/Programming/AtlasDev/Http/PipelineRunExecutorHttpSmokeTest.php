<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev\Http;

use App\Http\Controllers\AtlasDev\Support\PipelineRunExecutor;
use App\Http\Controllers\AtlasDev\Support\RunExecutor;
use App\Models\AtlasDevConfirmationToken;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandResult;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliResponse;
use App\Services\Ai\Programming\AtlasDev\Provider\SonnetClaudeCliAdapter;
use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\VerificationReceipt;
use Tests\Unit\Ai\Programming\AtlasDev\Gate\FakeCommandRunner;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\FakeClaudeCliGateway;

/**
 * HTTP smoke for the real {@see PipelineRunExecutor}.
 *
 * Closes the residual risk left behind by the previous slice: every other HTTP
 * test in this folder swaps the executor for {@see FakeRunExecutor::passing()},
 * so the controller wiring is exercised but the production receipt-composition
 * path is never proved end-to-end. This file does the opposite — it leaves the
 * production executor in place and only swaps the two collaborators that would
 * otherwise spawn real subprocesses / network calls:
 *
 *   - {@see ClaudeCliGateway}        → {@see FakeClaudeCliGateway} (deterministic)
 *   - {@see VerificationCommandRunner} → {@see FakeCommandRunner} (no shell)
 *
 * The test then drives the full HTTP cycle:
 *
 *   POST /ai/interactions/atlas-dev/plan
 *     → orchestrator persists compact_sdd.json + envelope + task_contract +
 *       prompt_projection (and mints the single-use confirmation_token)
 *   POST /ai/interactions/atlas-dev/run
 *     → PipelineRunExecutor reads compact_sdd.json, derives task_kind /
 *       risk_level, calls the fake gateway once, runs the fake verification
 *       command, composes the VerificationReceipt and writes it to disk.
 *
 * Asserts:
 *   1. Provider was invoked exactly once for the executable run.
 *   2. verification_receipt.json was persisted with task_kind / risk_level
 *      that match the persisted compact_sdd.json (NOT the surface dropdown).
 *   3. If compact_sdd.json is tampered or removed AFTER plan, the run returns
 *      422 with COMPACT_SDD_INVALID / COMPACT_SDD_MISSING and the provider is
 *      NOT called — failure is closed before any token cost.
 *   4. The HTTP response body never leaks absolute filesystem paths (workspace
 *      or receipts directory).
 */
final class PipelineRunExecutorHttpSmokeTest extends AtlasDevHttpTestCase
{
    private FakeClaudeCliGateway $gateway;

    private FakeCommandRunner $commandRunner;

    protected function setUp(): void
    {
        parent::setUp();

        // Bind production collaborators to deterministic fakes. The container
        // binding `RunExecutor → PipelineRunExecutor` declared by
        // AtlasDevServiceProvider stays untouched, so the real executor will
        // resolve these fakes via the container at run time.
        $this->gateway = new FakeClaudeCliGateway;
        $this->commandRunner = new FakeCommandRunner;
        $this->app->instance(ClaudeCliGateway::class, $this->gateway);
        $this->app->instance(VerificationCommandRunner::class, $this->commandRunner);
    }

    public function test_container_resolves_real_pipeline_run_executor_not_fake(): void
    {
        // Defence in depth: the rest of this file asserts behaviour, but if a
        // future change ever rebinds RunExecutor::class to a fake at the
        // provider level, this assertion catches it before behavioural drift
        // is silently masked. AtlasDevHttpTestCase does NOT touch the binding.
        $executor = $this->app->make(RunExecutor::class);
        $this->assertInstanceOf(
            PipelineRunExecutor::class,
            $executor,
            'AtlasDevServiceProvider must bind RunExecutor → PipelineRunExecutor for HTTP requests.',
        );
        $this->assertNotInstanceOf(
            FakeRunExecutor::class,
            $executor,
            'HTTP smoke for the real executor must not resolve a FakeRunExecutor.',
        );
        $this->app->forgetInstance(RunExecutor::class);
    }

    public function test_real_executor_composes_receipt_with_task_kind_and_risk_level_from_compact_sdd(): void
    {
        $this->queuePassingProviderResponse();
        $this->queuePassingVerificationResults();

        $plan = $this->plan();

        // Sanity: the orchestrator persisted a compact_sdd.json. The receipt
        // we build downstream must mirror these exact values, NOT the surface
        // composer_task / a hardcoded default.
        $compactSdd = $this->readArtifact($plan['run_id'], ArtifactNames::COMPACT_SDD);
        $this->assertIsArray($compactSdd);
        $this->assertIsString($compactSdd['task_kind']);
        $this->assertIsString($compactSdd['risk_level']);
        $expectedTaskKind = $compactSdd['task_kind'];
        $expectedRiskLevel = $compactSdd['risk_level'];

        $response = $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/run', [
                'run_id' => $plan['run_id'],
                'task_contract_hash' => $plan['task_contract_hash'],
                'confirmation_token' => $plan['confirmation_token'],
                'operator_confirmed' => true,
            ]);

        $response->assertStatus(200);
        // Completion is the load-bearing assertion: the executor must compose
        // a receipt with status=passed for an executable run with no-patch +
        // passing verification. Anything else means we silently completed
        // unverified, which is the exact gap this smoke is supposed to close.
        $response->assertJsonPath('data.completion_state', CompletionSummary::STATUS_PASSED);
        $response->assertJsonPath('data.provider_call.provider', SonnetClaudeCliAdapter::PROVIDER);
        $response->assertJsonPath('data.provider_call.model_family', SonnetClaudeCliAdapter::MODEL_FAMILY);
        $response->assertJsonPath('data.provider_call.provider_calls', 1);
        $response->assertJsonPath('data.provider_call.exit_code', 0);
        $this->assertSame(
            [],
            $response->json('data.provider_call.error_codes'),
            'provider_call.error_codes must be empty for a successful run.',
        );

        // (1) the production adapter dispatched exactly one request to the
        // fake gateway — proving the real executor walked the provider path,
        // not the FakeRunExecutor shortcut.
        $this->assertCount(
            1,
            $this->gateway->requests,
            'PipelineRunExecutor must dispatch the provider exactly once for an executable run.',
        );

        // (2) verification_receipt.json was persisted by ReceiptComposer with
        // task_kind / risk_level honestly mirrored from compact_sdd.json.
        $receiptPayload = $this->readArtifact($plan['run_id'], ArtifactNames::VERIFICATION_RECEIPT);
        $this->assertIsArray($receiptPayload, 'verification_receipt.json must exist on disk');
        $receipt = VerificationReceipt::fromArray($receiptPayload);
        $this->assertSame($expectedTaskKind, $receipt->taskKind);
        $this->assertSame($expectedRiskLevel, $receipt->riskLevel);
        $this->assertSame($plan['run_id'], $receipt->runId);
        $this->assertSame(
            CompletionSummary::STATUS_PASSED,
            $receipt->completion->status,
            'Persisted receipt completion.status must match the HTTP completion_state.',
        );
        $this->assertSame(
            [],
            $receipt->completion->honestyFlags,
            'Passed completion forbids honesty flags (CompletionSummary invariant).',
        );

        // The verification_gate consumed the queued command runner result —
        // proves the gate ran through the fake (no shell, no real composer).
        $this->assertNotEmpty(
            $this->commandRunner->calls,
            'VerificationGate must have called the fake command runner at least once.',
        );

        // (4) the HTTP body never leaks absolute filesystem paths. Both the
        // temp workspace and the temp receipts directory live under
        // sys_get_temp_dir(), so they're easy to assert against.
        $rawBody = (string) $response->getContent();
        $this->assertStringNotContainsString(
            $this->tmpWorkspace,
            $rawBody,
            'Run response must not leak the absolute workspace path.',
        );
        $this->assertStringNotContainsString(
            $this->tmpStorage,
            $rawBody,
            'Run response must not leak the absolute receipts storage path.',
        );

        // persisted_receipt_paths is the internal struct shape. The redactor
        // converts it to persisted_receipt_refs before serialising; the raw
        // form must not survive into the HTTP body.
        $this->assertArrayNotHasKey('persisted_receipt_paths', $response->json('data'));
        $this->assertIsArray($response->json('data.persisted_receipt_refs'));
        foreach ($response->json('data.persisted_receipt_refs') as $ref) {
            $this->assertIsString($ref);
            $this->assertStringStartsWith('receipts/'.$plan['run_id'].'/', $ref);
        }
    }

    public function test_invalid_compact_sdd_returns_422_and_provider_is_never_called(): void
    {
        $this->queuePassingProviderResponse();
        $this->queuePassingVerificationResults();

        $plan = $this->plan();

        // Tamper the persisted compact_sdd.json with a risk_level that the
        // VerificationReceipt enum rejects. Real PipelineRunExecutor must
        // detect this on read and fail closed before invoking the provider.
        $this->mutateArtifact($plan['run_id'], ArtifactNames::COMPACT_SDD, function (array $payload): array {
            $payload['risk_level'] = 'R99';

            return $payload;
        });

        $response = $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/run', [
                'run_id' => $plan['run_id'],
                'task_contract_hash' => $plan['task_contract_hash'],
                'confirmation_token' => $plan['confirmation_token'],
                'operator_confirmed' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'COMPACT_SDD_INVALID');
        $response->assertJsonPath('error.run_id', $plan['run_id']);
        $this->assertStringContainsString('risk_level', (string) $response->json('error.detail'));

        $this->assertSame(
            [],
            $this->gateway->requests,
            'Provider MUST NOT be called when compact_sdd.json is invalid — token cost stays zero.',
        );
        $this->assertSame([], $this->commandRunner->calls);

        // verification_receipt.json must NOT have been persisted: the run was
        // aborted before ReceiptComposer ever ran.
        $this->assertNull(
            $this->readArtifact($plan['run_id'], ArtifactNames::VERIFICATION_RECEIPT),
            'verification_receipt.json must not exist after a fail-closed run.',
        );
    }

    public function test_hash_tampered_compact_sdd_returns_422_and_provider_is_never_called(): void
    {
        $this->queuePassingProviderResponse();
        $this->queuePassingVerificationResults();

        $plan = $this->plan();

        // R3 is a valid VerificationReceipt risk level, so enum validation
        // would pass. The run must still fail because the CompactSDD payload
        // no longer matches the hash pinned during Plan.
        $this->mutateArtifact($plan['run_id'], ArtifactNames::COMPACT_SDD, function (array $payload): array {
            $payload['risk_level'] = 'R3';

            return $payload;
        });

        $response = $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/run', [
                'run_id' => $plan['run_id'],
                'task_contract_hash' => $plan['task_contract_hash'],
                'confirmation_token' => $plan['confirmation_token'],
                'operator_confirmed' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'COMPACT_SDD_TAMPERED');
        $response->assertJsonPath('error.reason', 'tampered');
        $response->assertJsonPath('error.run_id', $plan['run_id']);

        $this->assertSame(
            [],
            $this->gateway->requests,
            'Provider MUST NOT be called when compact_sdd.json was hash-tampered.',
        );
        $this->assertSame([], $this->commandRunner->calls);
        $this->assertNull($this->readArtifact($plan['run_id'], ArtifactNames::VERIFICATION_RECEIPT));
    }

    public function test_rehashed_compact_sdd_still_fails_when_server_side_pin_disagrees(): void
    {
        $this->queuePassingProviderResponse();
        $this->queuePassingVerificationResults();

        $plan = $this->plan();

        // Stronger tamper attempt: mutate to another valid risk level and
        // update compact_sdd_hash to match the mutated payload. The self hash
        // now passes, but the confirmation_token DB row still pins the
        // original hash captured at Plan time.
        $this->mutateArtifact($plan['run_id'], ArtifactNames::COMPACT_SDD, function (array $payload): array {
            $payload['risk_level'] = 'R3';
            $payload['compact_sdd_hash'] = CompactSdd::fromArray($payload)->hash();

            return $payload;
        });

        $response = $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/run', [
                'run_id' => $plan['run_id'],
                'task_contract_hash' => $plan['task_contract_hash'],
                'confirmation_token' => $plan['confirmation_token'],
                'operator_confirmed' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'COMPACT_SDD_TAMPERED');
        $response->assertJsonPath('error.reason', 'tampered');
        $this->assertStringContainsString('server-side pin', (string) $response->json('error.detail'));
        $this->assertSame([], $this->gateway->requests);
        $this->assertSame([], $this->commandRunner->calls);
    }

    public function test_rehashed_compact_sdd_still_fails_when_mini_spec_pin_disagrees(): void
    {
        $this->queuePassingProviderResponse();
        $this->queuePassingVerificationResults();

        $plan = $this->plan();

        // Stronger tamper attempt: mutate to another valid risk level and
        // update compact_sdd_hash to match the mutated payload. The self hash
        // now passes, and this test updates the DB-side pin intentionally so
        // the mini_programming_spec pin is the next independent guard.
        $mutatedHash = null;
        $this->mutateArtifact($plan['run_id'], ArtifactNames::COMPACT_SDD, function (array $payload) use (&$mutatedHash): array {
            $payload['risk_level'] = 'R3';
            $mutatedHash = CompactSdd::fromArray($payload)->hash();
            $payload['compact_sdd_hash'] = $mutatedHash;

            return $payload;
        });
        $this->assertIsString($mutatedHash);

        // This test intentionally bypasses the DB-side compact_sdd pin so the
        // next independent guard can be proven: mini_programming_spec still
        // pins the original compact_sdd hash through the plan artifacts.
        AtlasDevConfirmationToken::query()
            ->where('run_id', $plan['run_id'])
            ->update(['compact_sdd_hash' => $mutatedHash]);

        $response = $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/run', [
                'run_id' => $plan['run_id'],
                'task_contract_hash' => $plan['task_contract_hash'],
                'confirmation_token' => $plan['confirmation_token'],
                'operator_confirmed' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'COMPACT_SDD_TAMPERED');
        $response->assertJsonPath('error.reason', 'tampered');
        $this->assertStringContainsString('mini_programming_spec', (string) $response->json('error.detail'));
        $this->assertSame([], $this->gateway->requests);
        $this->assertSame([], $this->commandRunner->calls);
    }

    public function test_missing_compact_sdd_returns_422_and_provider_is_never_called(): void
    {
        $this->queuePassingProviderResponse();
        $this->queuePassingVerificationResults();

        $plan = $this->plan();

        // Delete the persisted compact_sdd.json. Real executor must read,
        // detect absence, and raise CompactSddUnavailableException::missing —
        // the controller maps that to 422 COMPACT_SDD_MISSING.
        $storage = $this->app->make(ReceiptStorage::class);
        $path = $storage->path($plan['run_id'], ArtifactNames::COMPACT_SDD);
        $this->assertFileExists($path, 'plan-only must have persisted compact_sdd.json');
        @chmod($path, 0o644);
        unlink($path);

        $response = $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/run', [
                'run_id' => $plan['run_id'],
                'task_contract_hash' => $plan['task_contract_hash'],
                'confirmation_token' => $plan['confirmation_token'],
                'operator_confirmed' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'COMPACT_SDD_MISSING');
        $response->assertJsonPath('error.run_id', $plan['run_id']);

        $this->assertSame(
            [],
            $this->gateway->requests,
            'Provider MUST NOT be called when compact_sdd.json is missing.',
        );
        $this->assertSame([], $this->commandRunner->calls);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return array{run_id:string, task_contract_hash:string, confirmation_token:string}
     */
    private function plan(): array
    {
        $data = $this->postPlan($this->defaultRepairPayload());
        $this->assertSame('atlas_dev_fast_path', $data['routing']['kind']);
        $this->assertIsArray($data['confirmation']);

        return [
            'run_id' => $data['run_id'],
            'task_contract_hash' => $data['hashes']['task_contract'],
            'confirmation_token' => $data['confirmation']['token'],
        ];
    }

    private function queuePassingProviderResponse(): void
    {
        // A tiny in-scope patch drives the full provider → diff parser →
        // scope guard → patch applier → verification → receipt path. This is
        // intentionally stronger than `no_patch_needed`, because the smoke is
        // proving the HTTP production executor can complete a real write run.
        $this->gateway->queue(new ClaudeCliResponse(
            actualProvider: SonnetClaudeCliAdapter::PROVIDER,
            actualModelFamily: SonnetClaudeCliAdapter::MODEL_FAMILY,
            exitCode: 0,
            stdout: <<<'DIFF'
```diff
diff --git a/tests/Unit/Services/Foo/FooServiceTest.php b/tests/Unit/Services/Foo/FooServiceTest.php
--- a/tests/Unit/Services/Foo/FooServiceTest.php
+++ b/tests/Unit/Services/Foo/FooServiceTest.php
@@ -1,2 +1,5 @@
 <?php
-class FooServiceTest {}
+class FooServiceTest
+{
+    public function test_service(): void {}
+}
```
DIFF,
            stderr: '',
            durationMs: 1200,
            tokensIn: 100,
            tokensOut: 50,
            costEstimateUsd: 0.0042,
        ));
    }

    private function queuePassingVerificationResults(): void
    {
        // The task_contract.validation_commands list is short in the canonical
        // repair-R2 fixture; queueing a few passing results is enough — extras
        // are simply unused by the runner.
        for ($i = 0; $i < 5; $i++) {
            $this->commandRunner->queue(new VerificationCommandResult(
                command: 'composer test',
                exitCode: 0,
                stdout: 'PASS',
                stderr: '',
                durationMs: 10,
            ));
        }
    }

    private function readArtifact(string $runId, string $artifact): ?array
    {
        $storage = $this->app->make(ReceiptStorage::class);

        return $storage->read($runId, $artifact);
    }

    /**
     * Read an artifact JSON, pass it through the rewriter, and re-write it
     * atomically. Used to tamper with persisted plan artifacts mid-flow.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $rewriter
     */
    private function mutateArtifact(string $runId, string $artifact, callable $rewriter): void
    {
        $storage = $this->app->make(ReceiptStorage::class);
        $path = $storage->path($runId, $artifact);
        $this->assertFileExists($path, "plan-only must have persisted {$artifact}");
        $payload = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($payload);

        $rewritten = $rewriter($payload);
        @chmod($path, 0o644);
        file_put_contents($path, json_encode($rewritten, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
