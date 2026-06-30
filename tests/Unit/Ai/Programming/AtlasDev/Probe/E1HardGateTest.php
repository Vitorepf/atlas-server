<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Probe;

use App\Http\Controllers\AtlasDev\Support\PipelineRunExecutor;
use App\Services\Ai\Programming\AtlasDev\Gate\AtlasDevVerificationCommandRunnerContract as VerificationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandResult;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGateResult;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Probe\IntentFalsificationProbe;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParseResult;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use App\Services\Ai\Programming\AtlasDev\Schemas\VerificationReceipt;
use Illuminate\Container\Container;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Gate\FakeCommandRunner;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\AtlasDevProviderFixtures;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\FakeClaudeCliGateway;

/**
 * E1 hard-gate promotion (m2-e1-hard).
 *
 * Drives a REAL {@see PipelineRunExecutor} with `e1.mode=hard` against the
 * fake-provider harness and proves the four M2-E1 assertions:
 *
 *   - VAL-M2-002: a hard E1 trip on an intent-missing diff produces a
 *     `failed` completion (NOT the advisory `needs_review`), with the
 *     verification gate forced to STATUS_FAILED.
 *   - VAL-M2-003: a hard E1 does NOT false-fail on a genuine-intent diff
 *     (the gate stays STATUS_PASSED; the completion is not `failed` due to
 *     E1; no `intent_likely_not_addressed` flag).
 *   - VAL-M2-004: when E1 hard forces STATUS_FAILED, the
 *     `intent_likely_not_addressed` honesty flag is retained in the rebuilt
 *     VerificationGateResult / persisted receipt for auditability.
 *
 * The hard branch already exists in PipelineRunExecutor (precondition); this
 * class is the fixture proof the rollout guard (VAL-M2-028) requires BEFORE
 * the `e1.mode` config default flips to `hard`. The trip-fires and
 * does-not-false-fail methods are registered in
 * ElevationRolloutGuardTest::VERIFIED_FIXTURES.
 *
 * These tests set `e1.mode=hard` explicitly (via the config kernel) so they
 * prove the hard branch regardless of the shipped config default, and remain
 * green after the default is promoted.
 */
final class E1HardGateTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private string $tmpStorage;

    private string $tmpWorkspace;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable the deterministic fast path so the fake provider gateway is
        // invoked and the E1 post-gate probe runs on the gateway-returned diff
        // (the fast path bypasses the provider and would short-circuit E1).
        config()->set('atlas_dev.efficient.deterministic_fast_path_enabled', false);

        // E1 hard: the elevation under test.
        config()->set('atlas_dev.elevations.e1.mode', 'hard');

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-e1-hard-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-e1-hard-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpStorage);
        $this->rmrf($this->tmpWorkspace);
        parent::tearDown();
    }

    /**
     * VAL-M2-002 + VAL-M2-004: a hard E1 trip on an intent-missing diff
     * produces a `failed` completion (not `needs_review`), the verification
     * gate is forced to STATUS_FAILED, and the `intent_likely_not_addressed`
     * honesty flag is retained for auditability.
     *
     * This is the trip-fires fixture registered in the rollout guard.
     */
    public function test_e1_hard_trip_produces_failed_on_intent_missing_diff(): void
    {
        $runId = 'dev-e1-hard-trip-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');

        $target = $this->tmpWorkspace.'/app/RateLimitService.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nreturn true;\n");

        // Intent-missing diff: the added line "return 42;" implements NEITHER
        // the 'corrigir' verb surface form ('fix'/'corrigir') NOR any
        // distinctive intent subject token (ratelimitservice/check/false/...).
        // The verification gate is GREEN (command runner exit 0); only E1
        // hard trips.
        $diff = <<<'DIFF'
--- a/app/RateLimitService.php
+++ b/app/RateLimitService.php
@@ -1,2 +1,2 @@
 <?php
-return true;
+return 42;
DIFF;

        $executor = $this->makeExecutor($storage, $diff);
        $envelope = $this->envelope(
            intent: 'Corrija o bug em app/RateLimitService.php para que o metodo check retorne false.',
        );
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['app/RateLimitService.php'],
            'validation_commands' => ['composer test'],
            'max_files_changed' => 1,
            'intent_text' => 'Corrija o bug em app/RateLimitService.php para que o metodo check retorne false.',
            'intent_verbs' => ['corrigir'],
            'repair_policy' => [
                'max_attempts' => 0,
                'abort_on_same_signature_twice' => true,
                'requires_failed_gate_output' => true,
                'same_provider' => true,
            ],
        ]);

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        // VAL-M2-002: completion is `failed` (the firm block), NOT the
        // advisory `needs_review` soft flag.
        $this->assertSame(
            'failed',
            $result->completionState,
            'VAL-M2-002: E1 hard trip must produce a failed completion, not needs_review. '
            .'Got: '.$result->completionState,
        );

        // VAL-M2-002: the verification gate aggregate is forced to
        // STATUS_FAILED by the E1 hard rebuild (the sanctioned hard channel).
        $this->assertSame(
            VerificationGateResult::STATUS_FAILED,
            $result->verificationStatus,
            'VAL-M2-002: E1 hard must force the verification gate to STATUS_FAILED.',
        );

        // VAL-M2-004: the `intent_likely_not_addressed` honesty flag is
        // retained in the rebuilt VerificationGateResult / persisted receipt
        // for auditability (the operator can see WHY it failed).
        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertContains(
            IntentFalsificationProbe::FLAG_INTENT_LIKELY_NOT_ADDRESSED,
            $receipt->completion->honestyFlags,
            'VAL-M2-004: the intent_likely_not_addressed flag must be retained for auditability '
            .'when E1 hard forces STATUS_FAILED. Flags: '.json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * VAL-M2-003: a hard E1 does NOT false-fail on a genuine-intent diff.
     * The diff traceably implements the 'corrigir' verb (the added line
     * carries the 'fix' surface form + the intent subject), so E1 does not
     * trip: the gate stays STATUS_PASSED, no `intent_likely_not_addressed`
     * flag is appended, and the completion is not `failed` due to E1.
     *
     * This is the does-not-false-fail fixture registered in the rollout guard.
     */
    public function test_e1_hard_does_not_false_fail_on_genuine_intent_diff(): void
    {
        $runId = 'dev-e1-hard-clear-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');

        $target = $this->tmpWorkspace.'/app/RateLimitService.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nreturn true;\n");

        // Genuine-intent diff: the added line carries the 'fix' verb surface
        // form (TIER 1) AND the intent subject ('false'/'ratelimit'), so the
        // IntentFalsificationProbe clears and E1 hard does not trip.
        $diff = <<<'DIFF'
--- a/app/RateLimitService.php
+++ b/app/RateLimitService.php
@@ -1,2 +1,2 @@
 <?php
-return true;
+return false; // fix the rate-limit guard
DIFF;

        $executor = $this->makeExecutor($storage, $diff);
        $envelope = $this->envelope(
            intent: 'Corrija o bug em app/RateLimitService.php para que o metodo check retorne false.',
        );
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['app/RateLimitService.php'],
            'validation_commands' => ['composer test'],
            'max_files_changed' => 1,
            'intent_text' => 'Corrija o bug em app/RateLimitService.php para que o metodo check retorne false.',
            'intent_verbs' => ['corrigir'],
            'repair_policy' => [
                'max_attempts' => 0,
                'abort_on_same_signature_twice' => true,
                'requires_failed_gate_output' => true,
                'same_provider' => true,
            ],
        ]);

        $result = $executor->execute(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $this->buildSendableProjection(envelope: $envelope, taskContract: $taskContract),
            runId: $runId,
        );

        // VAL-M2-003: E1 hard did NOT force STATUS_FAILED — the gate stays
        // STATUS_PASSED (the intent IS addressed). This is the core
        // false-fail guard: a legitimate green run is not blocked by E1.
        $this->assertSame(
            VerificationGateResult::STATUS_PASSED,
            $result->verificationStatus,
            'VAL-M2-003: E1 hard must not force STATUS_FAILED on a genuine-intent diff.',
        );

        // VAL-M2-003: no `intent_likely_not_addressed` flag is appended.
        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertNotContains(
            IntentFalsificationProbe::FLAG_INTENT_LIKELY_NOT_ADDRESSED,
            $receipt->completion->honestyFlags,
            'VAL-M2-003: no intent_likely_not_addressed flag on a genuine-intent diff. Flags: '
            .json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );

        // VAL-M2-003: the completion is not `failed` due to E1. It may be
        // `passed` or an honest non-success from OTHER gates (e.g. the
        // senior critic flagging a test_gap), but never `failed` caused by
        // E1 hard on a genuine-intent diff.
        $this->assertNotSame(
            'failed',
            $result->completionState,
            'VAL-M2-003: a genuine-intent diff must not be failed by E1 hard. Got: '
            .$result->completionState,
        );
    }

    /**
     * VAL-M2-004 (isolated): assert the IntentFalsificationProbe itself fires
     * on the intent-missing fixture and clears on the genuine-intent fixture,
     * so the hard-channel behavior is anchored to a correct probe verdict
     * (not a coincidence of the diff parser).
     */
    public function test_e1_probe_verdict_matches_the_trip_and_clear_fixtures(): void
    {
        $contract = $this->taskContractFixture([
            'intent_text' => 'Corrija o bug em app/RateLimitService.php para que o metodo check retorne false.',
            'intent_verbs' => ['corrigir'],
        ]);

        $probe = new IntentFalsificationProbe;

        $intentMissingDiff = DiffParseResult::patch(
            diff: "--- a/app/RateLimitService.php\n+++ b/app/RateLimitService.php\n@@\n+return 42;\n",
            changedFiles: ['app/RateLimitService.php'],
        );
        $this->assertTrue(
            $probe->isIntentLikelyNotAddressed($contract, $intentMissingDiff),
            'The trip fixture diff must be classified intent-missing by the probe.',
        );

        $genuineIntentDiff = DiffParseResult::patch(
            diff: "--- a/app/RateLimitService.php\n+++ b/app/RateLimitService.php\n@@\n+return false; // fix the rate-limit guard\n",
            changedFiles: ['app/RateLimitService.php'],
        );
        $this->assertFalse(
            $probe->isIntentLikelyNotAddressed($contract, $genuineIntentDiff),
            'The clear fixture diff must be classified intent-addressed by the probe.',
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function envelope(string $intent): OperationEnvelope
    {
        return new OperationEnvelope(
            runId: 'unused-by-executor',
            surfaceId: 'atlas_desktop_ai',
            surfaceContext: new SurfaceContext(
                productSurface: 'atlas_ai_desktop_mac',
                composerMode: 'programming',
                composerTask: 'dev',
                providerChoice: null,
            ),
            workspace: $this->tmpWorkspace,
            workspaceHash: hash('sha256', $this->tmpWorkspace),
            gitState: new GitState(
                headSha: null, dirty: false, untrackedCount: 0, pendingChangesCount: 0,
            ),
            rawIntent: $intent,
            normalizedIntent: $intent,
            userConstraints: [],
            intentClarityLevel: 'high',
            dirtyWorktreePolicy: 'preserve_pre_existing_changes',
            preflight: new Preflight(
                workspaceResolved: true,
                permissionMode: 'write_allowed',
                writeAllowed: true,
                operatorExplicit: false,
            ),
            envelopeHash: str_repeat('e', 64),
        );
    }

    private function makeExecutor(ReceiptStorage $storage, string $gatewayStdout): PipelineRunExecutor
    {
        $gateway = new FakeClaudeCliGateway;
        $gateway->queue($this->gatewayResponse(stdout: $gatewayStdout));

        $commandRunner = new FakeCommandRunner;
        $commandRunner->queue(new VerificationCommandResult(
            command: 'composer test',
            exitCode: 0,
            stdout: 'ok',
            stderr: '',
            durationMs: 10,
        ));

        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);

        return new PipelineRunExecutor($container, $storage);
    }

    private function seedRun(ReceiptStorage $storage, string $runId, string $taskKind, string $riskLevel): void
    {
        $compactSdd = $this->compactSddFixture(['task_kind' => $taskKind, 'risk_level' => $riskLevel]);
        $compactPayload = $compactSdd->toCanonicalArray();
        $compactPayload['compact_sdd_hash'] = $compactSdd->hash();
        $storage->writeAtomic($runId, ArtifactNames::COMPACT_SDD, $compactPayload);

        $miniSpec = $this->miniSpecFixture(['compact_sdd_hash' => $compactPayload['compact_sdd_hash']]);
        $storage->writeAtomic($runId, ArtifactNames::MINI_PROGRAMMING_SPEC, $miniSpec->toCanonicalArray());

        $storage->writeAtomic($runId, ArtifactNames::OPEN_BRAIN_PROJECTION, [
            'context_pack_hash' => 'atlas-dev:context_pack:'.bin2hex(random_bytes(4)),
        ]);
    }

    private function loadReceipt(ReceiptStorage $storage, string $runId): VerificationReceipt
    {
        $payload = $storage->read($runId, ArtifactNames::VERIFICATION_RECEIPT);
        $this->assertIsArray($payload, 'verification_receipt.json must be persisted');

        return VerificationReceipt::fromArray($payload);
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($path)) {
                $this->rmrf($path);
            } else {
                @chmod($path, 0o600);
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
