<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Probe;

use App\Http\Controllers\AtlasDev\Support\PipelineRunExecutor;
use App\Services\Ai\Programming\AtlasDev\Gate\AtlasDevVerificationCommandRunnerContract as VerificationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGateResult;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationScoreGate;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationTestingAdapter;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use App\Services\Ai\Programming\AtlasDev\Schemas\VerificationReceipt;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;
use Illuminate\Container\Container;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Gate\FakeCommandRunner;
use Tests\Unit\Ai\Programming\AtlasDev\Mutation\FakeMutationCommandRunner;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\AtlasDevProviderFixtures;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\FakeClaudeCliGateway;

/**
 * E3 mutation-score gate — turned ON at advisory (m2-e3-mutation).
 *
 * Drives a REAL {@see PipelineRunExecutor} with `e3.mode` set explicitly
 * (advisory / hard) against the fake-provider harness and a bound fake
 * {@see MutationTestingAdapter} (with a {@see FakeMutationCommandRunner} so
 * no real infection subprocess spawns), and proves the M2-E3 assertions:
 *
 *   - VAL-M2-014: the pcov coverage driver is provisioned for infection
 *     (the extension is loaded in the test environment).
 *   - VAL-M2-016: E3 advisory trip produces `needs_review` on a below-
 *     threshold MSI (the advisory channel, NOT `failed`).
 *   - VAL-M2-017: E3 hard trip produces `failed` on a below-threshold MSI
 *     (the advisory->hard promotion path — same fixture, only the mode
 *     differs).
 *   - VAL-M2-018: E3 reads the REAL infection-reported MSI (no self-declared
 *     score): the gate trips on `realMsi` below threshold even when
 *     infection's self-reported `msi` is inflated above threshold.
 *   - VAL-M2-019: E3 honest ceiling — a failed/unevaluable infection run
 *     never silently greens (advisory => needs_review + mutation_run_failed;
 *     hard => failed).
 *   - VAL-M2-032: E3 boundary is inclusive — MSI exactly equal to the
 *     threshold passes (no false floor trip), and MSI one increment below
 *     trips.
 *
 * The allowed file is a `tests/` file so the adapter's scope is non-empty
 * (MutationScope::isEmpty() is true only when no test files are touched;
 * a source-only patch is a documented no-op). The adapter is bound via the
 * container (`atlas_dev.e3.mutation_adapter`) with a FakeMutationCommandRunner
 * that returns scripted MSI outcomes. E1/E2/E4/E5/E6 are set to `off` in
 * setUp so only E3 can trip (isolation). The deterministic fast path is
 * disabled so the fake provider gateway provides the diff.
 */
final class E3AdvisoryGateTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private string $tmpStorage;

    private string $tmpWorkspace;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable the deterministic fast path so the fake provider gateway is
        // invoked and the post-gate E3 block runs on the gateway-returned
        // diff (the fast path bypasses the provider).
        config()->set('atlas_dev.efficient.deterministic_fast_path_enabled', false);

        // Isolate E3 from every other elevation so only E3 can trip. E1/E2
        // inspect intent fields (empty in the default fixture => no trip
        // anyway, but off guarantees isolation). E4 inspects PHP symbols via
        // a shadow-diff subprocess (off avoids a real PHP subprocess). E5
        // needs a bound regression baseline service (off avoids the capture).
        // E6 inspects the spec (a no-op with no ACs, but off guarantees it).
        config()->set('atlas_dev.elevations.e1.mode', 'off');
        config()->set('atlas_dev.elevations.e2.mode', 'off');
        config()->set('atlas_dev.elevations.e4.mode', 'off');
        config()->set('atlas_dev.elevations.e5.mode', 'off');
        config()->set('atlas_dev.elevations.e6.mode', 'off');

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-e3-mut-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-e3-mut-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpStorage);
        $this->rmrf($this->tmpWorkspace);
        parent::tearDown();
    }

    /**
     * VAL-M2-014: the pcov coverage driver is provisioned for infection.
     * The `pcov` extension is loaded in the test environment so the scoped
     * infection subprocess does NOT fail with a "missing coverage driver"
     * error. Without pcov, E3 must stay off (the adapter fail-closes on a
     * missing driver). This is the regression guard; the artisan-cli
     * evidence (`php -m | grep pcov`) is in the handoff interactiveChecks.
     */
    public function test_val_m2_014_pcov_coverage_driver_is_provisioned_for_infection(): void
    {
        $this->assertTrue(
            extension_loaded('pcov'),
            'VAL-M2-014: the pcov extension must be loaded so infection can use it as the coverage driver.',
        );
    }

    /**
     * VAL-M2-016: E3 advisory trip produces `needs_review` on a below-
     * threshold MSI (the advisory channel, NOT `failed`). The
     * `mutation_score_below_threshold` honesty flag drives the
     * CompletionStateGate PASSED -> needs_review downgrade.
     */
    public function test_val_m2_016_e3_advisory_trip_produces_needs_review_on_below_threshold_msi(): void
    {
        config()->set('atlas_dev.elevations.e3.mode', 'advisory');

        $runId = 'dev-e3-adv-trip-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId);
        $this->setupWorkspaceWithTestFile();

        // Below-threshold MSI (40% < 60% threshold) => the gate trips.
        $mutationRunner = new FakeMutationCommandRunner;
        $mutationRunner->queueOk(40.0, $this->tmpStorage.'/e3-summary.json');

        $executor = $this->makeExecutor($storage, $mutationRunner, e3Mode: 'advisory');
        $result = $executor->execute(
            envelope: $this->envelope(),
            taskContract: $this->taskContractFixture([
                'allowed_files' => ['tests/Unit/FooTest.php'],
                'validation_commands' => ['composer test'],
                'max_files_changed' => 1,
                'repair_policy' => [
                    'max_attempts' => 0,
                    'abort_on_same_signature_twice' => true,
                    'requires_failed_gate_output' => true,
                    'same_provider' => true,
                ],
            ]),
            promptProjection: $this->buildSendableProjection(envelope: $this->envelope()),
            runId: $runId,
        );

        // VAL-M2-016: completion is `needs_review` (the advisory downgrade),
        // NOT `failed` (advisory never forces STATUS_FAILED for the flag
        // alone) and NOT `passed` (no silent green over a weak test suite).
        $this->assertSame(
            'needs_review',
            $result->completionState,
            'VAL-M2-016: E3 advisory trip must produce needs_review, not failed/passed. Got: '.$result->completionState,
        );

        // The honesty flag is present in the persisted receipt.
        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertContains(
            MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD,
            $receipt->completion->honestyFlags,
            'VAL-M2-016: mutation_score_below_threshold flag must be present. Flags: '
            .json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * VAL-M2-017: E3 hard trip produces `failed` on a below-threshold MSI
     * (the advisory->hard promotion path). The SAME fixture as VAL-M2-016
     * with only `e3.mode` changed to `hard` — proving the promotion is a
     * mode flip, not a behavior change. The verification gate is forced to
     * STATUS_FAILED and the flag is retained for auditability.
     */
    public function test_val_m2_017_e3_hard_trip_produces_failed_on_below_threshold_msi(): void
    {
        config()->set('atlas_dev.elevations.e3.mode', 'hard');

        $runId = 'dev-e3-hard-trip-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId);
        $this->setupWorkspaceWithTestFile();

        // SAME below-threshold MSI (40% < 60%) as the advisory trip — only
        // the mode differs (hard).
        $mutationRunner = new FakeMutationCommandRunner;
        $mutationRunner->queueOk(40.0, $this->tmpStorage.'/e3-summary.json');

        $executor = $this->makeExecutor($storage, $mutationRunner, e3Mode: 'hard');
        $result = $executor->execute(
            envelope: $this->envelope(),
            taskContract: $this->taskContractFixture([
                'allowed_files' => ['tests/Unit/FooTest.php'],
                'validation_commands' => ['composer test'],
                'max_files_changed' => 1,
                'repair_policy' => [
                    'max_attempts' => 0,
                    'abort_on_same_signature_twice' => true,
                    'requires_failed_gate_output' => true,
                    'same_provider' => true,
                ],
            ]),
            promptProjection: $this->buildSendableProjection(envelope: $this->envelope()),
            runId: $runId,
        );

        // VAL-M2-017: completion is `failed` (the firm block), NOT
        // `needs_review` (the advisory channel is not active in hard mode).
        $this->assertSame(
            'failed',
            $result->completionState,
            'VAL-M2-017: E3 hard trip must produce failed, not needs_review. Got: '.$result->completionState,
        );

        // The verification gate is forced to STATUS_FAILED (sanctioned hard
        // channel).
        $this->assertSame(
            VerificationGateResult::STATUS_FAILED,
            $result->verificationStatus,
            'VAL-M2-017: E3 hard must force the verification gate to STATUS_FAILED.',
        );

        // The flag is retained for auditability.
        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertContains(
            MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD,
            $receipt->completion->honestyFlags,
            'VAL-M2-017: flag retained for auditability in hard mode. Flags: '
            .json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * VAL-M2-018: E3 reads the REAL infection-reported MSI (no self-declared
     * score). The gate uses `realMsi` (recomputed over the FULL mutant
     * population, totalMutantsCount as denominator), NOT infection's self-
     * reported `msi` (which subtracts skipped/ignored and can be inflated).
     * A patch whose realMsi is below threshold trips even when infection's
     * reported msi is above threshold.
     *
     * The fixture: totalMutantsCount=10, killedCount=4, ignoredCount=6.
     * Infection's reported msi = 4/(10-6) = 100% (inflated by ignoring 6
     * survivors). realMsi = 4/10 = 40% (the honest floor). The gate trips
     * on realMsi (40% < 60%).
     */
    public function test_val_m2_018_e3_reads_real_infection_msi_not_self_declared(): void
    {
        config()->set('atlas_dev.elevations.e3.mode', 'advisory');

        $runId = 'dev-e3-realmisi-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId);
        $this->setupWorkspaceWithTestFile();

        // Anti-gaming fixture: infection reports msi=100% (inflated by
        // ignoring survivors), but the real MSI over the full population is
        // 40% (4 killed / 10 total).
        $mutationRunner = new FakeMutationCommandRunner;
        $mutationRunner->queueRawStats(
            totalMutantsCount: 10,
            killedCount: 4,
            escapedCount: 0,
            ignoredCount: 6,
        );

        $executor = $this->makeExecutor($storage, $mutationRunner, e3Mode: 'advisory');
        $result = $executor->execute(
            envelope: $this->envelope(),
            taskContract: $this->taskContractFixture([
                'allowed_files' => ['tests/Unit/FooTest.php'],
                'validation_commands' => ['composer test'],
                'max_files_changed' => 1,
                'repair_policy' => [
                    'max_attempts' => 0,
                    'abort_on_same_signature_twice' => true,
                    'requires_failed_gate_output' => true,
                    'same_provider' => true,
                ],
            ]),
            promptProjection: $this->buildSendableProjection(envelope: $this->envelope()),
            runId: $runId,
        );

        // VAL-M2-018: the gate tripped on realMsi (40% < 60%) despite
        // infection's reported msi being 100%. The completion is NOT passed
        // (no silent green over an inflatable score).
        $this->assertNotSame(
            'passed',
            $result->completionState,
            'VAL-M2-018: a below-threshold realMsi must not silently pass despite an inflated reported msi. Got: '.$result->completionState,
        );

        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertContains(
            MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD,
            $receipt->completion->honestyFlags,
            'VAL-M2-018: the gate trips on realMsi below threshold. Flags: '
            .json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * VAL-M2-019: E3 honest ceiling — a failed/unevaluable infection run
     * never silently greens. When infection fails (non-zero exit), the MSI
     * is NULL. The gate MUST NOT silently green that case in a non-off mode:
     *   - advisory => needs_review with the `mutation_run_failed` flag;
     *   - hard     => failed (fail-closed).
     * The adapter refuses to fabricate an MSI over a failed run.
     */
    public function test_val_m2_019_e3_honest_ceiling_failed_infection_never_silently_greens_advisory(): void
    {
        config()->set('atlas_dev.elevations.e3.mode', 'advisory');

        $runId = 'dev-e3-honest-adv-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId);
        $this->setupWorkspaceWithTestFile();

        // A FAILED infection run (non-zero exit). The adapter surfaces
        // failed=true with a NULL MSI (never fabricates a score).
        $mutationRunner = new FakeMutationCommandRunner;
        $mutationRunner->queueFailure('e3: infection invocation failed (exit 1)');

        $executor = $this->makeExecutor($storage, $mutationRunner, e3Mode: 'advisory');
        $result = $executor->execute(
            envelope: $this->envelope(),
            taskContract: $this->taskContractFixture([
                'allowed_files' => ['tests/Unit/FooTest.php'],
                'validation_commands' => ['composer test'],
                'max_files_changed' => 1,
                'repair_policy' => [
                    'max_attempts' => 0,
                    'abort_on_same_signature_twice' => true,
                    'requires_failed_gate_output' => true,
                    'same_provider' => true,
                ],
            ]),
            promptProjection: $this->buildSendableProjection(envelope: $this->envelope()),
            runId: $runId,
        );

        // VAL-M2-019 (advisory): a failed infection run yields needs_review
        // (NOT passed — never a silent green) carrying the mutation_run_failed
        // flag.
        $this->assertNotSame(
            'passed',
            $result->completionState,
            'VAL-M2-019: a failed infection run must never silently green (advisory). Got: '.$result->completionState,
        );

        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertContains(
            MutationScoreGate::FLAG_MUTATION_RUN_FAILED,
            $receipt->completion->honestyFlags,
            'VAL-M2-019: mutation_run_failed flag present on a failed infection (advisory). Flags: '
            .json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );
    }

    public function test_val_m2_019_e3_honest_ceiling_failed_infection_never_silently_greens_hard(): void
    {
        config()->set('atlas_dev.elevations.e3.mode', 'hard');

        $runId = 'dev-e3-honest-hard-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId);
        $this->setupWorkspaceWithTestFile();

        $mutationRunner = new FakeMutationCommandRunner;
        $mutationRunner->queueFailure('e3: infection invocation failed (exit 1)');

        $executor = $this->makeExecutor($storage, $mutationRunner, e3Mode: 'hard');
        $result = $executor->execute(
            envelope: $this->envelope(),
            taskContract: $this->taskContractFixture([
                'allowed_files' => ['tests/Unit/FooTest.php'],
                'validation_commands' => ['composer test'],
                'max_files_changed' => 1,
                'repair_policy' => [
                    'max_attempts' => 0,
                    'abort_on_same_signature_twice' => true,
                    'requires_failed_gate_output' => true,
                    'same_provider' => true,
                ],
            ]),
            promptProjection: $this->buildSendableProjection(envelope: $this->envelope()),
            runId: $runId,
        );

        // VAL-M2-019 (hard): a failed infection run yields failed (fail-
        // closed), NOT passed.
        $this->assertSame(
            'failed',
            $result->completionState,
            'VAL-M2-019: a failed infection run must fail-closed in hard mode. Got: '.$result->completionState,
        );

        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertContains(
            MutationScoreGate::FLAG_MUTATION_RUN_FAILED,
            $receipt->completion->honestyFlags,
            'VAL-M2-019: mutation_run_failed flag present on a failed infection (hard). Flags: '
            .json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * VAL-M2-032: E3 boundary is inclusive — MSI exactly equal to the
     * threshold passes (no false floor trip), and MSI one increment below
     * trips. Proven in BOTH advisory and hard modes: a robust test sitting
     * exactly at the configured floor is not penalised.
     */
    public function test_val_m2_032_e3_boundary_inclusive_msi_equal_to_threshold_passes_advisory(): void
    {
        config()->set('atlas_dev.elevations.e3.mode', 'advisory');

        $runId = 'dev-e3-bound-adv-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId);
        $this->setupWorkspaceWithTestFile();

        // MSI == threshold (60.0 == 60.0) => boundary inclusive => passes
        // (no trip, no flag).
        $mutationRunner = new FakeMutationCommandRunner;
        $mutationRunner->queueOk(60.0, $this->tmpStorage.'/e3-summary.json');

        $executor = $this->makeExecutor($storage, $mutationRunner, e3Mode: 'advisory');
        $result = $executor->execute(
            envelope: $this->envelope(),
            taskContract: $this->taskContractFixture([
                'allowed_files' => ['tests/Unit/FooTest.php'],
                'validation_commands' => ['composer test'],
                'max_files_changed' => 1,
                'repair_policy' => [
                    'max_attempts' => 0,
                    'abort_on_same_signature_twice' => true,
                    'requires_failed_gate_output' => true,
                    'same_provider' => true,
                ],
            ]),
            promptProjection: $this->buildSendableProjection(envelope: $this->envelope()),
            runId: $runId,
        );

        // VAL-M2-032: MSI == threshold => no mutation_score_below_threshold
        // flag => the completion is NOT downgraded by E3 (no false floor
        // trip). It may be `passed` or another honest non-success from OTHER
        // gates, but NOT downgraded by E3.
        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertNotContains(
            MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD,
            $receipt->completion->honestyFlags,
            'VAL-M2-032: MSI == threshold must NOT trip (boundary inclusive). Flags: '
            .json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );

        // The verification gate stays STATUS_PASSED (E3 did not force
        // STATUS_FAILED — there was no trip).
        $this->assertSame(
            VerificationGateResult::STATUS_PASSED,
            $result->verificationStatus,
            'VAL-M2-032: MSI == threshold keeps the gate STATUS_PASSED (no false floor trip).',
        );
    }

    public function test_val_m2_032_e3_boundary_inclusive_msi_equal_to_threshold_passes_hard(): void
    {
        config()->set('atlas_dev.elevations.e3.mode', 'hard');

        $runId = 'dev-e3-bound-hard-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId);
        $this->setupWorkspaceWithTestFile();

        // MSI == threshold (60.0 == 60.0) => passes even in hard mode.
        $mutationRunner = new FakeMutationCommandRunner;
        $mutationRunner->queueOk(60.0, $this->tmpStorage.'/e3-summary.json');

        $executor = $this->makeExecutor($storage, $mutationRunner, e3Mode: 'hard');
        $result = $executor->execute(
            envelope: $this->envelope(),
            taskContract: $this->taskContractFixture([
                'allowed_files' => ['tests/Unit/FooTest.php'],
                'validation_commands' => ['composer test'],
                'max_files_changed' => 1,
                'repair_policy' => [
                    'max_attempts' => 0,
                    'abort_on_same_signature_twice' => true,
                    'requires_failed_gate_output' => true,
                    'same_provider' => true,
                ],
            ]),
            promptProjection: $this->buildSendableProjection(envelope: $this->envelope()),
            runId: $runId,
        );

        // VAL-M2-032 (hard): MSI == threshold => no trip => no STATUS_FAILED.
        $this->assertSame(
            VerificationGateResult::STATUS_PASSED,
            $result->verificationStatus,
            'VAL-M2-032: MSI == threshold keeps the gate STATUS_PASSED in hard mode (no false floor trip).',
        );

        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertNotContains(
            MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD,
            $receipt->completion->honestyFlags,
            'VAL-M2-032: no flag when MSI == threshold (hard). Flags: '
            .json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * VAL-M2-032 complement: MSI just ONE increment below the threshold
     * trips the gate (proving the boundary is a real >= comparison, not a
     * > comparison that would let a just-below score slip through).
     */
    public function test_val_m2_032_e3_msi_just_below_threshold_trips_advisory(): void
    {
        config()->set('atlas_dev.elevations.e3.mode', 'advisory');

        $runId = 'dev-e3-below-adv-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId);
        $this->setupWorkspaceWithTestFile();

        // MSI just below threshold (59.0 < 60.0) => trips.
        $mutationRunner = new FakeMutationCommandRunner;
        $mutationRunner->queueOk(59.0, $this->tmpStorage.'/e3-summary.json');

        $executor = $this->makeExecutor($storage, $mutationRunner, e3Mode: 'advisory');
        $executor->execute(
            envelope: $this->envelope(),
            taskContract: $this->taskContractFixture([
                'allowed_files' => ['tests/Unit/FooTest.php'],
                'validation_commands' => ['composer test'],
                'max_files_changed' => 1,
                'repair_policy' => [
                    'max_attempts' => 0,
                    'abort_on_same_signature_twice' => true,
                    'requires_failed_gate_output' => true,
                    'same_provider' => true,
                ],
            ]),
            promptProjection: $this->buildSendableProjection(envelope: $this->envelope()),
            runId: $runId,
        );

        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertContains(
            MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD,
            $receipt->completion->honestyFlags,
            'VAL-M2-032: MSI just below threshold must trip. Flags: '
            .json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function envelope(): OperationEnvelope
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
            rawIntent: 'Update the test file',
            normalizedIntent: 'Update the test file',
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

    /**
     * Build the executor with a bound fake mutation adapter (so no real
     * infection subprocess spawns). The adapter is constructed with the
     * given e3 mode so its isOff() check lets run() proceed; the gate's
     * mode comes from the live config (set per-test via config()->set).
     */
    private function makeExecutor(
        ReceiptStorage $storage,
        FakeMutationCommandRunner $mutationRunner,
        string $e3Mode,
    ): PipelineRunExecutor {
        $gateway = new FakeClaudeCliGateway;
        $gateway->queue($this->gatewayResponse(stdout: $this->gatewayDiff()));

        // FakeCommandRunner: defaultSuccess=true so all verification floor
        // commands (php -l, pint, composer test) pass => green gate, so the
        // E3 trip is the only thing forcing STATUS_FAILED / appending a flag.
        $commandRunner = new FakeCommandRunner;

        // The bound mutation adapter with the fake command runner. The
        // e3Config matches the test's mode so the adapter's isOff() check
        // lets run() proceed to computeScope + the fake runner.
        $adapter = new MutationTestingAdapter(
            commandRunner: $mutationRunner,
            e3Config: ElevationConfig::for('e3', ['mode' => $e3Mode]),
            repoRoot: $this->tmpWorkspace,
        );

        $container = new Container;
        $container->instance(ClaudeCliGateway::class, $gateway);
        $container->instance(VerificationCommandRunner::class, $commandRunner);
        $container->instance('atlas_dev.e3.mutation_adapter', $adapter);

        return new PipelineRunExecutor($container, $storage);
    }

    /**
     * Seed the persisted run artifacts (compact SDD + mini spec + open brain
     * projection) so the executor can load them during the post-gate block.
     * The default miniSpec carries no behavioral ACs and the default task
     * contract carries empty intent fields, so E1/E2/E6 (set off in setUp
     * anyway) do not trip.
     */
    private function seedRun(ReceiptStorage $storage, string $runId): void
    {
        $compactSdd = $this->compactSddFixture(['task_kind' => 'repair', 'risk_level' => 'R2']);
        $compactPayload = $compactSdd->toCanonicalArray();
        $compactPayload['compact_sdd_hash'] = $compactSdd->hash();
        $storage->writeAtomic($runId, ArtifactNames::COMPACT_SDD, $compactPayload);

        $miniSpec = $this->miniSpecFixture(['compact_sdd_hash' => $compactPayload['compact_sdd_hash']]);
        $storage->writeAtomic($runId, ArtifactNames::MINI_PROGRAMMING_SPEC, $miniSpec->toCanonicalArray());

        $storage->writeAtomic($runId, ArtifactNames::OPEN_BRAIN_PROJECTION, [
            'context_pack_hash' => 'atlas-dev:context_pack:'.bin2hex(random_bytes(4)),
        ]);
    }

    /**
     * Write the OLD test file content into the workspace so the executor's
     * diff application produces the NEW content. The file is a `tests/` file
     * so the mutation adapter's scope is non-empty (MutationScope::isEmpty()
     * is true only when no test files are touched).
     */
    private function setupWorkspaceWithTestFile(): void
    {
        $target = $this->tmpWorkspace.'/tests/Unit/FooTest.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nreturn true;\n");
    }

    /**
     * The diff the fake gateway returns: changes tests/Unit/FooTest.php
     * (return true -> return false). A valid PHP file so php -l passes.
     */
    private function gatewayDiff(): string
    {
        return <<<'DIFF'
--- a/tests/Unit/FooTest.php
+++ b/tests/Unit/FooTest.php
@@ -1,2 +1,2 @@
 <?php
-return true;
+return false;
DIFF;
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
