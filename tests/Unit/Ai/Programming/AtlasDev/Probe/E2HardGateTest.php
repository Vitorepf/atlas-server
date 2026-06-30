<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Probe;

use App\Http\Controllers\AtlasDev\Support\PipelineRunExecutor;
use App\Services\Ai\Programming\AtlasDev\Gate\AtlasDevVerificationCommandRunnerContract as VerificationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandResult;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGateResult;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Probe\IntentCoverageProbe;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use App\Services\Ai\Programming\AtlasDev\Schemas\MiniProgrammingSpec;
use App\Services\Ai\Programming\AtlasDev\Schemas\VerificationReceipt;
use Illuminate\Container\Container;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Gate\FakeCommandRunner;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\AtlasDevProviderFixtures;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\FakeClaudeCliGateway;

/**
 * E2 hard-gate promotion (m2-e2-hard).
 *
 * Drives a REAL {@see PipelineRunExecutor} with `e2.mode=hard` against the
 * fake-provider harness and proves the three M2-E2 assertions:
 *
 *   - VAL-M2-006: a hard E2 trip (intent NOT backed by any behavioral AC
 *     with a real verification_ref) on a green gate produces a `failed`
 *     completion (NOT the advisory `needs_review`), with the verification
 *     gate forced to STATUS_FAILED and the `intent_not_tested` flag retained.
 *   - VAL-M2-007: a hard E2 does NOT false-fail when a behavioral AC with a
 *     real verification_ref backs the intent (the gate stays STATUS_PASSED,
 *     no `intent_not_tested` flag, the completion is not `failed` due to E2).
 *
 * Pre-M2 only the `isAdvisory()` branch was wired (flag -> needs_review);
 * this class proves the NEW hard branch that rebuilds the gate result to
 * STATUS_FAILED (mirroring E1's hard rebuild). The trip-fires and
 * does-not-false-fail methods are registered in
 * ElevationRolloutGuardTest::VERIFIED_FIXTURES.
 *
 * E1 is set to `off` in setUp so only E2 is active (E1 inspects the diff,
 * E2 inspects the spec — they are independent probes and must not cross-
 * contaminate). These tests set `e2.mode=hard` explicitly so they prove the
 * hard branch regardless of the shipped config default, and remain green
 * after the default is promoted.
 */
final class E2HardGateTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private string $tmpStorage;

    private string $tmpWorkspace;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable the deterministic fast path so the fake provider gateway is
        // invoked and the E2 post-gate probe runs on the gateway-returned diff
        // (the fast path bypasses the provider and would short-circuit E2).
        config()->set('atlas_dev.efficient.deterministic_fast_path_enabled', false);

        // E2 hard: the elevation under test.
        config()->set('atlas_dev.elevations.e2.mode', 'hard');

        // Isolate E2 from E1: E1 inspects the diff against intent verbs and
        // is irrelevant to the E2 spec-coverage probe. Setting E1 off ensures
        // only E2 can trip in these fixtures.
        config()->set('atlas_dev.elevations.e1.mode', 'off');

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-e2-hard-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-e2-hard-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpStorage);
        $this->rmrf($this->tmpWorkspace);
        parent::tearDown();
    }

    /**
     * VAL-M2-006: a hard E2 trip — the intent is NOT backed by any behavioral
     * AC with a real verification_ref (the default spec carries only a
     * tautological `ac_1`, not a `ac_behavior_*` AC) — produces a `failed`
     * completion (not `needs_review`), the verification gate is forced to
     * STATUS_FAILED, and the `intent_not_tested` honesty flag is retained for
     * auditability.
     *
     * This is the trip-fires fixture registered in the rollout guard. The NEW
     * hard branch (absent pre-M2) rebuilds the gate result to STATUS_FAILED,
     * mirroring E1's hard rebuild.
     */
    public function test_e2_hard_trip_produces_failed_when_intent_not_backed_by_behavioral_ac(): void
    {
        $runId = 'dev-e2-hard-trip-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);

        // Seed with the DEFAULT miniSpec (only `ac_1`, no `ac_behavior_*`
        // AC) so the IntentCoverageProbe reports intent_not_tested = true.
        $this->seedRun($storage, $runId, taskKind: 'repair', riskLevel: 'R2');

        $target = $this->tmpWorkspace.'/app/RateLimitService.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nreturn true;\n");

        // E2 inspects the SPEC, not the diff. The diff just needs to pass the
        // verification gate (green) so the E2 hard trip is the only thing
        // forcing STATUS_FAILED.
        $diff = <<<'DIFF'
--- a/app/RateLimitService.php
+++ b/app/RateLimitService.php
@@ -1,2 +1,2 @@
 <?php
-return true;
+return false;
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

        // VAL-M2-006: completion is `failed` (the firm block), NOT the
        // advisory `needs_review` soft flag.
        $this->assertSame(
            'failed',
            $result->completionState,
            'VAL-M2-006: E2 hard trip must produce a failed completion, not needs_review. '
            .'Got: '.$result->completionState,
        );

        // VAL-M2-006: the verification gate aggregate is forced to
        // STATUS_FAILED by the E2 hard rebuild (the sanctioned hard channel).
        $this->assertSame(
            VerificationGateResult::STATUS_FAILED,
            $result->verificationStatus,
            'VAL-M2-006: E2 hard must force the verification gate to STATUS_FAILED.',
        );

        // VAL-M2-006: the `intent_not_tested` honesty flag is retained in the
        // rebuilt VerificationGateResult / persisted receipt for auditability
        // (the operator can see WHY it failed).
        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertContains(
            IntentCoverageProbe::FLAG_INTENT_NOT_TESTED,
            $receipt->completion->honestyFlags,
            'VAL-M2-006: the intent_not_tested flag must be retained for auditability '
            .'when E2 hard forces STATUS_FAILED. Flags: '
            .json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * VAL-M2-007: a hard E2 does NOT false-fail when a behavioral AC with a
     * real verification_ref backs the intent. The spec carries an
     * `ac_behavior_*` AC with a non-empty verification_ref, so the
     * IntentCoverageProbe reports intent_not_tested = false: E2 does not
     * trip, the gate stays STATUS_PASSED, no `intent_not_tested` flag is
     * appended, and the completion is not `failed` due to E2.
     *
     * This is the does-not-false-fail fixture registered in the rollout guard.
     */
    public function test_e2_hard_does_not_false_fail_when_behavioral_ac_backs_intent(): void
    {
        $runId = 'dev-e2-hard-clear-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);

        // Seed with a miniSpec that carries a behavioral AC
        // (`ac_behavior_1`) with a real, non-empty verification_ref so the
        // IntentCoverageProbe reports intent_not_tested = false (the intent
        // IS tested).
        $this->seedRun(
            $storage,
            $runId,
            taskKind: 'repair',
            riskLevel: 'R2',
            acceptanceCriteria: [
                [
                    'description' => 'the rate-limit guard returns false after the fix',
                    'id' => 'ac_behavior_1',
                    'verification' => 'test',
                    'verification_ref' => 'tests/Unit/RateLimitServiceTest.php::test_check_returns_false',
                ],
            ],
        );

        $target = $this->tmpWorkspace.'/app/RateLimitService.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nreturn true;\n");

        $diff = <<<'DIFF'
--- a/app/RateLimitService.php
+++ b/app/RateLimitService.php
@@ -1,2 +1,2 @@
 <?php
-return true;
+return false;
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

        // VAL-M2-007: E2 hard did NOT force STATUS_FAILED — the gate stays
        // STATUS_PASSED (the intent IS tested by a behavioral AC). This is
        // the core false-fail guard: a legitimate green run is not blocked
        // by E2.
        $this->assertSame(
            VerificationGateResult::STATUS_PASSED,
            $result->verificationStatus,
            'VAL-M2-007: E2 hard must not force STATUS_FAILED when a behavioral AC backs the intent.',
        );

        // VAL-M2-007: no `intent_not_tested` flag is appended.
        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertNotContains(
            IntentCoverageProbe::FLAG_INTENT_NOT_TESTED,
            $receipt->completion->honestyFlags,
            'VAL-M2-007: no intent_not_tested flag when a behavioral AC backs the intent. Flags: '
            .json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );

        // VAL-M2-007: the completion is not `failed` due to E2. It may be
        // `passed` or an honest non-success from OTHER gates (e.g. the
        // senior critic flagging a test_gap), but never `failed` caused by
        // E2 hard on a behavioral-AC-backed intent.
        $this->assertNotSame(
            'failed',
            $result->completionState,
            'VAL-M2-007: a behavioral-AC-backed intent must not be failed by E2 hard. Got: '
            .$result->completionState,
        );
    }

    /**
     * VAL-M2-006/007 (isolated): assert the IntentCoverageProbe itself fires
     * on the trip fixture (no behavioral AC) and clears on the clear fixture
     * (behavioral AC with real verification_ref), so the hard-channel
     * behavior is anchored to a correct probe verdict (not a coincidence of
     * the spec parser).
     */
    public function test_e2_probe_verdict_matches_the_trip_and_clear_fixtures(): void
    {
        $contract = $this->taskContractFixture([
            'intent_text' => 'Corrija o bug em app/RateLimitService.php.',
        ]);

        $probe = new IntentCoverageProbe;

        // Trip fixture: spec with only a tautological AC (no `ac_behavior_*`).
        $tripSpec = MiniProgrammingSpec::fromArray(
            $this->loadSpecPayload(acceptanceCriteria: [
                ['description' => 'teste passa', 'id' => 'ac_1', 'verification' => 'test', 'verification_ref' => 'tests/SomeTest.php'],
            ]),
        );
        $this->assertTrue(
            $probe->isIntentNotTested($contract, $tripSpec),
            'The trip fixture spec (no behavioral AC) must be classified intent-not-tested by the probe.',
        );

        // Clear fixture: spec with a behavioral AC carrying a real verification_ref.
        $clearSpec = MiniProgrammingSpec::fromArray(
            $this->loadSpecPayload(acceptanceCriteria: [
                ['description' => 'behavioral AC', 'id' => 'ac_behavior_1', 'verification' => 'test', 'verification_ref' => 'tests/Unit/RateLimitServiceTest.php::test_check_returns_false'],
            ]),
        );
        $this->assertFalse(
            $probe->isIntentNotTested($contract, $clearSpec),
            'The clear fixture spec (behavioral AC with real verification_ref) must be classified intent-tested by the probe.',
        );

        // Edge: behavioral AC with an EMPTY verification_ref still trips
        // (the ref must be real/non-empty, not just the id prefix).
        $emptyRefSpec = MiniProgrammingSpec::fromArray(
            $this->loadSpecPayload(acceptanceCriteria: [
                ['description' => 'behavioral AC empty ref', 'id' => 'ac_behavior_1', 'verification' => 'test', 'verification_ref' => ''],
            ]),
        );
        $this->assertTrue(
            $probe->isIntentNotTested($contract, $emptyRefSpec),
            'A behavioral AC with an empty verification_ref must still trip (the ref must be real).',
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

    /**
     * Seed the persisted run artifacts. When $acceptanceCriteria is provided,
     * the miniSpec is overridden to carry those ACs (so the E2 probe sees a
     * behavioral AC or not, depending on the test).
     *
     * @param  list<array{id: string, description: string, verification: string, verification_ref: string}>  $acceptanceCriteria
     */
    private function seedRun(
        ReceiptStorage $storage,
        string $runId,
        string $taskKind,
        string $riskLevel,
        array $acceptanceCriteria = [],
    ): void {
        $compactSdd = $this->compactSddFixture(['task_kind' => $taskKind, 'risk_level' => $riskLevel]);
        $compactPayload = $compactSdd->toCanonicalArray();
        $compactPayload['compact_sdd_hash'] = $compactSdd->hash();
        $storage->writeAtomic($runId, ArtifactNames::COMPACT_SDD, $compactPayload);

        $miniSpecOverrides = ['compact_sdd_hash' => $compactPayload['compact_sdd_hash']];
        if ($acceptanceCriteria !== []) {
            $miniSpecOverrides['acceptance_criteria'] = $acceptanceCriteria;
        }
        $miniSpec = $this->miniSpecFixture($miniSpecOverrides);
        $storage->writeAtomic($runId, ArtifactNames::MINI_PROGRAMMING_SPEC, $miniSpec->toCanonicalArray());

        $storage->writeAtomic($runId, ArtifactNames::OPEN_BRAIN_PROJECTION, [
            'context_pack_hash' => 'atlas-dev:context_pack:'.bin2hex(random_bytes(4)),
        ]);
    }

    /**
     * Build a raw mini-spec payload (array) with the given acceptance
     * criteria, for the isolated probe verdict test.
     *
     * @param  list<array{id: string, description: string, verification: string, verification_ref: string}>  $acceptanceCriteria
     */
    private function loadSpecPayload(array $acceptanceCriteria): array
    {
        $base = $this->miniSpecFixture()->toCanonicalArray();
        $base['acceptance_criteria'] = $acceptanceCriteria;

        return $base;
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
