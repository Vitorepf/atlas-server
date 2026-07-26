<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev\Http;

use App\Http\Controllers\AtlasDev\Support\KernelRunExecutor;
use App\Http\Controllers\AtlasDev\Support\PipelineRunExecutor;
use App\Http\Controllers\AtlasDev\Support\RunExecutor;
use App\Models\AtlasDevConfirmationToken;
use App\Services\Ai\Programming\AtlasDev\Gate\AtlasDevVerificationCommandRunnerContract as VerificationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandResult;
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
 * HTTP smoke for the live {@see KernelRunExecutor} (production DI).
 *
 * Live DI binds `RunExecutor → KernelRunExecutor`. This suite leaves that
 * binding in place and proves the operate-path safety that used to live only
 * on legacy PRE:
 *
 *   POST /ai/interactions/atlas-dev/plan
 *     → orchestrator persists compact_sdd.json + confirmation_token pin
 *   POST /ai/interactions/atlas-dev/run
 *     → KernelRunExecutor validates CompactSDD integrity BEFORE kernel spend
 *       (missing / invalid / hash-tampered → 422 COMPACT_SDD_*)
 *
 * Collaborators that would spawn real subprocesses are still faked when
 * present on the path:
 *   - {@see ClaudeCliGateway} → {@see FakeClaudeCliGateway}
 *   - {@see VerificationCommandRunner} → {@see FakeCommandRunner}
 *
 * Happy-path completion through the full Elite kernel court is environment-
 * dependent (real git base commit, court evidence). This smoke therefore
 * asserts CompactSDD fail-closed on the live Kernel entry and that a valid
 * CompactSDD never yields COMPACT_SDD_* 422 — not PRE-only receipt composition.
 */
final class PipelineRunExecutorHttpSmokeTest extends AtlasDevHttpTestCase
{
    private FakeClaudeCliGateway $gateway;

    private FakeCommandRunner $commandRunner;

    protected function setUp(): void
    {
        parent::setUp();

        // Bind production collaborators to deterministic fakes. Live DI is
        // `RunExecutor → KernelRunExecutor` (AtlasDevServiceProvider). PRE is
        // retained only as legacy under R103 until full port+delete.
        $this->gateway = new FakeClaudeCliGateway;
        $this->commandRunner = new FakeCommandRunner;
        $this->app->instance(ClaudeCliGateway::class, $this->gateway);
        $this->app->instance(VerificationCommandRunner::class, $this->commandRunner);
    }

    public function test_container_resolves_live_kernel_run_executor_not_fake(): void
    {
        // Live DI truth (R103 / ASDD D3): RunExecutor → KernelRunExecutor.
        // PRE remains on disk for legacy tests until port+delete completes.
        $executor = $this->app->make(RunExecutor::class);
        $this->assertInstanceOf(
            KernelRunExecutor::class,
            $executor,
            'AtlasDevServiceProvider must bind RunExecutor → KernelRunExecutor (live DI).',
        );
        $this->assertNotInstanceOf(
            PipelineRunExecutor::class,
            $executor,
            'Live DI must not resolve legacy PipelineRunExecutor.',
        );
        $this->assertNotInstanceOf(
            FakeRunExecutor::class,
            $executor,
            'HTTP smoke for the real executor must not resolve a FakeRunExecutor.',
        );
        $this->app->forgetInstance(RunExecutor::class);
    }

    public function test_live_kernel_run_accepts_valid_compact_sdd_without_compact_sdd_error(): void
    {
        $this->queuePassingProviderResponse();
        $this->queuePassingVerificationResults();

        $plan = $this->plan();

        $compactSdd = $this->readArtifact($plan['run_id'], ArtifactNames::COMPACT_SDD);
        $this->assertIsArray($compactSdd);
        $this->assertIsString($compactSdd['task_kind']);
        $this->assertIsString($compactSdd['risk_level']);
        $this->assertIsString($compactSdd['compact_sdd_hash'] ?? null);

        $response = $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/run', [
                'run_id' => $plan['run_id'],
                'task_contract_hash' => $plan['task_contract_hash'],
                'confirmation_token' => $plan['confirmation_token'],
                'operator_confirmed' => true,
            ]);

        // Valid CompactSDD must never fail-closed as COMPACT_SDD_*. Kernel may
        // still block later (base commit / court evidence) — that is not a
        // CompactSDD integrity regression.
        $this->assertNotSame(
            422,
            $response->status(),
            'Valid compact_sdd must not map to COMPACT_SDD_* 422 on live Kernel. body='.json_encode($response->json()),
        );
        $response->assertStatus(200);
        $errorCode = (string) $response->json('error.code');
        $this->assertStringNotContainsString('COMPACT_SDD', $errorCode);
        $completion = (string) $response->json('data.completion_state');
        $this->assertContains(
            $completion,
            [
                CompletionSummary::STATUS_PASSED,
                CompletionSummary::STATUS_BLOCKED,
                CompletionSummary::STATUS_NEEDS_REVIEW,
            ],
            'Live Kernel must return a typed completion_state after CompactSDD gate.',
        );

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
        $this->assertArrayNotHasKey('persisted_receipt_paths', $response->json('data'));
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
