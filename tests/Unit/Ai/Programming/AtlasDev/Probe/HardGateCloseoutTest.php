<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Probe;

use App\Http\Controllers\AtlasDev\Support\PipelineRunExecutor;
use App\Services\Ai\Programming\AtlasDev\Gate\AtlasDevVerificationCommandRunnerContract as VerificationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGateResult;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationTestingAdapter;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Probe\IntentFalsificationProbe;
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
 * M2 hard-gate closeout (m2-hard-gate-closeout).
 *
 * Consolidates the umbrella M2 invariants across all the hard elevations
 * (E1/E2/E4/E5 hard + E3/E6 advisory) that the per-elevation fixture tests
 * (E1HardGateTest .. E6ConstitutionGateTest) prove individually:
 *
 *   - VAL-M2-026: a hard gate failure ALWAYS produces an honest non-passed
 *     status (never `passed`), specifically `failed`, with the hard branch
 *     forcing STATUS_FAILED. The CompletionSummary `passed`-forbids-flags
 *     invariant is never weakened.
 *   - VAL-M2-031: when multiple elevations surface on one run, a hard trip
 *     DOMINATES an advisory flag (the completion is `failed`, never softened
 *     to `needs_review` because an advisory flag was added after the hard
 *     rebuild), and EVERY surfaced honesty flag is retained (no flag lost
 *     when a later elevation rebuilds the VerificationGateResult).
 *   - VAL-M2-030 (receipt layer): the persisted verification receipt carries
 *     the tripped elevation's honesty flag, and the run's `reasons` array
 *     names the specific trip condition (e.g. `intent_likely_not_addressed`)
 *     so the operator can tell WHICH gate tripped and WHY.
 *
 * Elevations apply sequentially in PipelineRunExecutor (E2->E1->E3->E5->E4,
 * then E6), each rebuilding the VerificationGateResult and merging its
 * honesty flag via `withHonestyFlags` (deduped, order-preserving). These
 * tests prove the sequential merge preserves every flag and that a hard
 * rebuild's STATUS_FAILED is never softened by a later advisory append.
 *
 * The fixture combines E1 (hard, intent-missing diff) with E3 (advisory /
 * hard, below-threshold MSI) on the SAME green-gate run: the allowed file is
 * a `tests/` file (so the mutation adapter scope is non-empty) carrying an
 * intent-missing added line (so E1 trips). E2/E4/E5/E6 are set `off` for
 * isolation so only E1 + E3 can surface.
 */
final class HardGateCloseoutTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private string $tmpStorage;

    private string $tmpWorkspace;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable the deterministic fast path so the fake provider gateway
        // provides the diff and the post-gate elevation block runs (the fast
        // path bypasses the provider).
        config()->set('atlas_dev.efficient.deterministic_fast_path_enabled', false);

        // E1 is the hard elevation under test (trip on intent-missing diff).
        config()->set('atlas_dev.elevations.e1.mode', 'hard');

        // Isolate every elevation EXCEPT E1 and E3. E2 inspects the spec for
        // behavioral ACs (off avoids an independent trip). E4 shadow-diffs PHP
        // symbols via a subprocess (off avoids a real PHP subprocess + an
        // independent divergence trip on the test file). E5 needs a bound
        // regression baseline service (off avoids the capture). E6 inspects the
        // spec (off avoids an independent spec trip). Only E1 + E3 can surface.
        config()->set('atlas_dev.elevations.e2.mode', 'off');
        config()->set('atlas_dev.elevations.e4.mode', 'off');
        config()->set('atlas_dev.elevations.e5.mode', 'off');
        config()->set('atlas_dev.elevations.e6.mode', 'off');

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-m2-closeout-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-m2-closeout-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpStorage);
        $this->rmrf($this->tmpWorkspace);
        parent::tearDown();
    }

    /**
     * VAL-M2-031: a hard elevation trip DOMINATES an advisory flag on the
     * same run. With e1.mode=hard (trips on the intent-missing diff) AND
     * e3.mode=advisory (trips on below-threshold MSI), the completion is
     * `failed` (the hard trip's STATUS_FAILED is NOT softened to
     * `needs_review` by the advisory flag appended after the hard rebuild),
     * and BOTH flags are retained in the receipt.
     *
     * Sequential merge trace (E2->E1->E3):
     *   - E2 (off): no-op.
     *   - E1 (hard, trips): rebuilds the gate to STATUS_FAILED carrying
     *     `intent_likely_not_addressed`.
     *   - E3 (advisory, trips): appends `mutation_score_below_threshold`
     *     via withHonestyFlags (the aggregate stays STATUS_FAILED — advisory
     *     never mutates the status, only appends a flag).
     * Result: aggregateStatus=STATUS_FAILED, flags=[intent_likely_not_addressed,
     * mutation_score_below_threshold] -> completion `failed`.
     */
    public function test_hard_e1_trip_dominates_advisory_e3_trip_both_flags_retained(): void
    {
        config()->set('atlas_dev.elevations.e3.mode', 'advisory');

        $runId = 'dev-m2-closeout-hard-adv-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId);
        $this->setupWorkspaceWithTestFile();

        $mutationRunner = new FakeMutationCommandRunner;
        $mutationRunner->queueOk(40.0, $this->tmpStorage.'/closeout-summary.json');

        $executor = $this->makeExecutor($storage, $mutationRunner, e3Mode: 'advisory');
        $result = $executor->execute(
            envelope: $this->envelope(),
            taskContract: $this->closeoutTaskContract(),
            promptProjection: $this->buildSendableProjection(
                envelope: $this->envelope(),
                taskContract: $this->closeoutTaskContract(),
            ),
            runId: $runId,
        );

        // VAL-M2-031: the hard E1 trip dominates -> `failed`, NOT the advisory
        // `needs_review` (the advisory flag appended after the hard rebuild
        // does not soften STATUS_FAILED).
        $this->assertSame(
            'failed',
            $result->completionState,
            'VAL-M2-031: a hard trip + an advisory flag on the same run must resolve to failed, not needs_review. Got: '
            .$result->completionState,
        );

        // The hard trip forced the verification gate to STATUS_FAILED.
        $this->assertSame(
            VerificationGateResult::STATUS_FAILED,
            $result->verificationStatus,
            'VAL-M2-031: the hard E1 trip must force STATUS_FAILED (dominance).',
        );

        // VAL-M2-031: EVERY surfaced flag is retained — both the hard E1 flag
        // AND the advisory E3 flag survive the sequential merge.
        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertContains(
            IntentFalsificationProbe::FLAG_INTENT_LIKELY_NOT_ADDRESSED,
            $receipt->completion->honestyFlags,
            'VAL-M2-031: the hard E1 flag must be retained. Flags: '
            .json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );
        $this->assertContains(
            MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD,
            $receipt->completion->honestyFlags,
            'VAL-M2-031: the advisory E3 flag must be retained alongside the hard flag. Flags: '
            .json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * VAL-M2-031 + VAL-M2-026: a hard+hard case. With e1.mode=hard AND
     * e3.mode=hard both tripping on the same run, the completion is `failed`
     * (both rebuilds force STATUS_FAILED) and BOTH flags are retained (the
     * second hard rebuild merges its flag onto the first via
     * withHonestyFlags, never replacing).
     *
     * Sequential merge trace (E2->E1->E3):
     *   - E1 (hard, trips): rebuilds to STATUS_FAILED + intent_likely_not_addressed.
     *   - E3 (hard, trips): rebuilds to STATUS_FAILED (already failed) merging
     *     mutation_score_below_threshold onto the existing flags.
     * Result: aggregateStatus=STATUS_FAILED, flags=[intent_likely_not_addressed,
     * mutation_score_below_threshold] -> completion `failed`.
     */
    public function test_hard_e1_and_hard_e3_both_trip_failed_both_flags_retained(): void
    {
        config()->set('atlas_dev.elevations.e3.mode', 'hard');

        $runId = 'dev-m2-closeout-hard-hard-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId);
        $this->setupWorkspaceWithTestFile();

        $mutationRunner = new FakeMutationCommandRunner;
        $mutationRunner->queueOk(40.0, $this->tmpStorage.'/closeout-summary.json');

        $executor = $this->makeExecutor($storage, $mutationRunner, e3Mode: 'hard');
        $result = $executor->execute(
            envelope: $this->envelope(),
            taskContract: $this->closeoutTaskContract(),
            promptProjection: $this->buildSendableProjection(
                envelope: $this->envelope(),
                taskContract: $this->closeoutTaskContract(),
            ),
            runId: $runId,
        );

        // VAL-M2-026 / VAL-M2-031: both hard trips -> `failed` (never `passed`,
        // never softened to `needs_review`).
        $this->assertSame(
            'failed',
            $result->completionState,
            'VAL-M2-026/031: two hard trips must resolve to failed, never passed/needs_review. Got: '
            .$result->completionState,
        );

        $this->assertSame(
            VerificationGateResult::STATUS_FAILED,
            $result->verificationStatus,
            'VAL-M2-026: a hard gate trip must force STATUS_FAILED.',
        );

        // VAL-M2-031: BOTH flags retained through the second hard rebuild.
        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertContains(
            IntentFalsificationProbe::FLAG_INTENT_LIKELY_NOT_ADDRESSED,
            $receipt->completion->honestyFlags,
            'VAL-M2-031 (hard+hard): the E1 flag must survive the E3 hard rebuild. Flags: '
            .json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );
        $this->assertContains(
            MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD,
            $receipt->completion->honestyFlags,
            'VAL-M2-031 (hard+hard): the E3 flag must be retained. Flags: '
            .json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * VAL-M2-026 + VAL-M2-030 (receipt layer): a hard gate trip NEVER yields
     * `passed` (specifically `failed`), the persisted receipt carries the
     * tripped elevation's honesty flag, and the run's `reasons` array names
     * the specific trip condition (`intent_likely_not_addressed`) alongside
     * the generic `verification_failed` — so the operator can tell WHICH
     * gate tripped and WHY from the CLI receipt.
     *
     * E3 is set `off` here so the trip is solely E1 hard (isolating the
     * reasons-surface proof to a single trip condition).
     */
    public function test_hard_gate_trip_never_yields_passed_and_names_trip_condition_in_reasons(): void
    {
        // E3 off: only E1 trips, isolating the reasons surface.
        config()->set('atlas_dev.elevations.e3.mode', 'off');

        $runId = 'dev-m2-closeout-reasons-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);
        $this->seedRun($storage, $runId);
        $this->setupWorkspaceWithTestFile();

        $executor = $this->makeExecutor($storage, new FakeMutationCommandRunner, e3Mode: 'off');
        $result = $executor->execute(
            envelope: $this->envelope(),
            taskContract: $this->closeoutTaskContract(),
            promptProjection: $this->buildSendableProjection(
                envelope: $this->envelope(),
                taskContract: $this->closeoutTaskContract(),
            ),
            runId: $runId,
        );

        // VAL-M2-026: the hard trip produces `failed`, NEVER `passed` (and not
        // the advisory `needs_review`).
        $this->assertSame(
            'failed',
            $result->completionState,
            'VAL-M2-026: a hard gate trip must produce failed, never passed. Got: '.$result->completionState,
        );
        $this->assertNotSame('passed', $result->completionState, 'VAL-M2-026: a hard trip must never yield passed.');

        $this->assertSame(
            VerificationGateResult::STATUS_FAILED,
            $result->verificationStatus,
            'VAL-M2-026: the hard branch must force STATUS_FAILED.',
        );

        // VAL-M2-030: the persisted receipt carries the tripped elevation's
        // honesty flag (the operator can see WHY it failed).
        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertContains(
            IntentFalsificationProbe::FLAG_INTENT_LIKELY_NOT_ADDRESSED,
            $receipt->completion->honestyFlags,
            'VAL-M2-030: the receipt must carry the tripped elevation flag. Flags: '
            .json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );

        // VAL-M2-030: the run's reasons array names the specific trip
        // condition (the elevation flag), not just a bare `verification_failed`.
        $this->assertIsArray($result->reasons, 'VAL-M2-030: the run result must carry a reasons array.');
        $this->assertContains(
            'verification_failed',
            $result->reasons,
            'VAL-M2-030: reasons must include the generic verification_failed. Reasons: '
            .json_encode($result->reasons, JSON_THROW_ON_ERROR),
        );
        $this->assertContains(
            IntentFalsificationProbe::FLAG_INTENT_LIKELY_NOT_ADDRESSED,
            $result->reasons,
            'VAL-M2-030: reasons must name the specific trip condition (the elevation flag). Reasons: '
            .json_encode($result->reasons, JSON_THROW_ON_ERROR),
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
            rawIntent: 'Corrija o bug em tests/Unit/FooTest.php para que retorne false.',
            normalizedIntent: 'Corrija o bug em tests/Unit/FooTest.php para que retorne false.',
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
     * The task contract for the closeout fixture: an intent-missing write
     * task on a `tests/` file. `intent_verbs=['corrigir']` lets E1 trip on
     * the `return 42;` added line (no 'fix'/'corrigir' surface form); the
     * `tests/` allowed file keeps the E3 mutation adapter scope non-empty.
     */
    private function closeoutTaskContract()
    {
        return $this->taskContractFixture([
            'allowed_files' => ['tests/Unit/FooTest.php'],
            'validation_commands' => ['composer test'],
            'max_files_changed' => 1,
            'intent_text' => 'Corrija o bug em tests/Unit/FooTest.php para que retorne false.',
            'intent_verbs' => ['corrigir'],
            'repair_policy' => [
                'max_attempts' => 0,
                'abort_on_same_signature_twice' => true,
                'requires_failed_gate_output' => true,
                'same_provider' => true,
            ],
        ]);
    }

    /**
     * Build the executor with a bound fake mutation adapter (so no real
     * infection subprocess spawns) and a fake provider gateway returning the
     * intent-missing diff on the test file. The FakeCommandRunner succeeds
     * every verification floor command (green gate) so only the elevation
     * trips force STATUS_FAILED / append flags.
     */
    private function makeExecutor(
        ReceiptStorage $storage,
        FakeMutationCommandRunner $mutationRunner,
        string $e3Mode,
    ): PipelineRunExecutor {
        $gateway = new FakeClaudeCliGateway;
        $gateway->queue($this->gatewayResponse(stdout: $this->gatewayDiff()));

        $commandRunner = new FakeCommandRunner;

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
     * so the mutation adapter's scope is non-empty.
     */
    private function setupWorkspaceWithTestFile(): void
    {
        $target = $this->tmpWorkspace.'/tests/Unit/FooTest.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nreturn true;\n");
    }

    /**
     * The diff the fake gateway returns: changes tests/Unit/FooTest.php
     * (return true -> return 42). The added line `return 42;` implements
     * NEITHER the 'corrigir' verb surface form ('fix'/'corrigir') NOR any
     * distinctive intent subject token, so E1 hard trips (intent-missing).
     * The file is a `tests/` file so E3's mutation scope is non-empty.
     */
    private function gatewayDiff(): string
    {
        return <<<'DIFF'
--- a/tests/Unit/FooTest.php
+++ b/tests/Unit/FooTest.php
@@ -1,2 +1,2 @@
 <?php
-return true;
+return 42;
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
