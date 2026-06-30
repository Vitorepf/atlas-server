<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Probe;

use App\Http\Controllers\AtlasDev\Support\PipelineRunExecutor;
use App\Services\Ai\Programming\AtlasDev\Gate\AtlasDevVerificationCommandRunnerContract as VerificationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandResult;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGateResult;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Probe\SpecDrivenConstitutionGate;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use App\Services\Ai\Programming\AtlasDev\Schemas\MiniProgrammingSpec;
use App\Services\Ai\Programming\AtlasDev\Schemas\VerificationReceipt;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;
use Illuminate\Container\Container;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Gate\FakeCommandRunner;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\AtlasDevProviderFixtures;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\FakeClaudeCliGateway;

/**
 * E6 Spec-Driven Constitution Gate (m2-e6-constitution-gate).
 *
 * Drives a REAL {@see PipelineRunExecutor} with E6 active (and ALL other
 * elevations E1-E5 set to `off` for isolation) against the fake-provider
 * harness and proves the eight M2-E6 assertions:
 *
 *   - VAL-M2-020: E6 gate class exists, resolveE6Config exists, E6 is wired
 *     in PipelineRunExecutor, and `off` mode is a byte-identical no-op.
 *   - VAL-M2-021: E6 advisory flag fires when the diff does not honor a
 *     stated acceptance criterion (out-of-spec scope creep).
 *   - VAL-M2-022: E6 hard trip produces `failed` when the diff violates the
 *     spec/constitution.
 *   - VAL-M2-023: E6 does NOT false-fail when the diff honors the spec and
 *     stays in-scope (both advisory and hard).
 *   - VAL-M2-024: E6 detects out-of-spec scope creep (forbidden files and
 *     non-goals) that stays within the contract's allowed_files.
 *   - VAL-M2-025: `e6.mode` config default is `advisory` (safe rollout).
 *   - VAL-M2-033: E6 honest ceiling — an unevaluable/errored spec check
 *     never silently greens (advisory => needs_review; hard => failed).
 *   - VAL-M2-034: E6 is a no-op on a task that declares no spec / acceptance
 *     criteria.
 *
 * Elevation isolation (CRITICAL per dev-worker skill): ALL other elevations
 * (E1-E5) are set to `off` in setUp so only E6 can fire. E1 inspects the
 * diff against intent verbs; E2 inspects the spec for behavioral ACs; E3
 * spawns infection; E4 runs shadow-diff; E5 diffs the regression baseline.
 * Any of them could independently trip on a test fixture and contaminate
 * the E6 assertions. Setting them all to `off` ensures only E6's verdict
 * can influence the completion.
 */
final class E6ConstitutionGateTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private string $tmpStorage;

    private string $tmpWorkspace;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable the deterministic fast path so the fake provider gateway is
        // invoked and the E6 post-gate probe runs on the gateway-returned diff
        // (the fast path bypasses the provider and would short-circuit E6).
        config()->set('atlas_dev.efficient.deterministic_fast_path_enabled', false);

        // ELEVATION ISOLATION: set ALL other elevations to off so only E6
        // can trip. E6 is the elevation under test.
        config()->set('atlas_dev.elevations.e1.mode', 'off');
        config()->set('atlas_dev.elevations.e2.mode', 'off');
        config()->set('atlas_dev.elevations.e3.mode', 'off');
        config()->set('atlas_dev.elevations.e4.mode', 'off');
        config()->set('atlas_dev.elevations.e5.mode', 'off');

        // E6 default is advisory (VAL-M2-025); individual tests override to
        // hard/off as needed.
        config()->set('atlas_dev.elevations.e6.mode', 'advisory');

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-e6-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-e6-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpStorage);
        $this->rmrf($this->tmpWorkspace);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // VAL-M2-020: structural existence + off-mode no-op
    // ------------------------------------------------------------------

    /**
     * VAL-M2-020: the E6 gate class exists, resolveE6Config exists in
     * PipelineRunExecutor, and `off` mode is a byte-identical no-op (no
     * flag, no STATUS_FAILED).
     */
    public function test_e6_gate_class_and_resolve_e6_config_exist_and_off_mode_is_noop(): void
    {
        // Structural: the gate class exists.
        $this->assertTrue(
            class_exists(SpecDrivenConstitutionGate::class),
            'VAL-M2-020: SpecDrivenConstitutionGate class must exist.',
        );

        // Structural: resolveE6Config exists in PipelineRunExecutor.
        $this->assertTrue(
            method_exists(PipelineRunExecutor::class, 'resolveE6Config'),
            'VAL-M2-020: resolveE6Config method must exist in PipelineRunExecutor.',
        );

        // Structural: ElevationConfig::fromConfig('e6') works.
        $e6Config = ElevationConfig::for('e6', ['mode' => 'off']);
        $this->assertTrue($e6Config->isOff(), 'e6 off mode is recognized.');

        // Behavioral: off mode is a byte-identical no-op. Set up a scenario
        // that WOULD trip E6 (out-of-spec file) and verify that with e6.mode
        // = off, no spec_constitution_violation flag appears and the
        // completion is not failed due to E6.
        config()->set('atlas_dev.elevations.e6.mode', 'off');

        $runId = 'dev-e6-off-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);

        $this->seedRun($storage, $runId, specOverrides: [
            'allowed_files' => ['app/InScope.php'],
            'expected_files' => ['app/InScope.php'],
            'forbidden_files' => [],
            'non_goals' => [],
            'acceptance_criteria' => [
                ['description' => 'behavioral AC', 'id' => 'ac_behavior_1', 'verification' => 'test', 'verification_ref' => 'tests/Unit/InScopeTest.php'],
            ],
            'expected_behavior' => [
                ['description' => 'in-scope behavior', 'observable_by' => 'test'],
            ],
        ]);

        $target = $this->tmpWorkspace.'/app/OutOfScope.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nreturn true;\n");

        $diff = $this->diffFor('app/OutOfScope.php');

        $executor = $this->makeExecutor($storage, $diff);
        $envelope = $this->envelope('Fix the bug in app/OutOfScope.php.');
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['app/OutOfScope.php'],
            'forbidden_files' => [],
            'validation_commands' => ['composer test'],
            'max_files_changed' => 1,
            'intent_text' => 'Fix the bug in app/OutOfScope.php.',
            'intent_verbs' => ['fix'],
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

        // Off mode => no E6 flag.
        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertNotContains(
            SpecDrivenConstitutionGate::FLAG_SPEC_CONSTITUTION_VIOLATION,
            $receipt->completion->honestyFlags,
            'VAL-M2-020: e6.mode=off must not append spec_constitution_violation. Flags: '
            .json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );
        $this->assertNotContains(
            SpecDrivenConstitutionGate::FLAG_SPEC_UNEVALUABLE,
            $receipt->completion->honestyFlags,
            'VAL-M2-020: e6.mode=off must not append spec_unevaluable.',
        );

        // Off mode => E6 did not force STATUS_FAILED.
        $this->assertNotSame(
            VerificationGateResult::STATUS_FAILED,
            $result->verificationStatus,
            'VAL-M2-020: e6.mode=off must not force STATUS_FAILED.',
        );
    }

    // ------------------------------------------------------------------
    // VAL-M2-021: advisory flag fires when diff does not honor AC
    // ------------------------------------------------------------------

    /**
     * VAL-M2-021: when the diff does not honor a stated acceptance criterion
     * (the diff touches a file NOT justified by any acceptance criterion —
     * out-of-spec scope creep), E6 in advisory mode appends the
     * spec_constitution_violation honesty flag so the CompletionStateGate
     * auto-downgrades PASSED -> needs_review. NOT `failed` (advisory channel),
     * NOT `passed` (silent green over a spec violation).
     */
    public function test_e6_advisory_flag_fires_when_diff_does_not_honor_acceptance_criterion(): void
    {
        $runId = 'dev-e6-adv-trip-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);

        // Spec declares ACs and scope for app/InScope.php only.
        $this->seedRun($storage, $runId, specOverrides: [
            'allowed_files' => ['app/InScope.php'],
            'expected_files' => ['app/InScope.php'],
            'forbidden_files' => [],
            'non_goals' => [],
            'acceptance_criteria' => [
                ['description' => 'in-scope behavior', 'id' => 'ac_behavior_1', 'verification' => 'test', 'verification_ref' => 'tests/Unit/InScopeTest.php'],
            ],
            'expected_behavior' => [
                ['description' => 'in-scope behavior is correct', 'observable_by' => 'test'],
            ],
        ]);

        // Diff touches app/OutOfScope.php — NOT in the spec's allowed/expected
        // files. The taskContract allows it (so ScopeGuard passes), but E6
        // catches the out-of-spec scope creep.
        $target = $this->tmpWorkspace.'/app/OutOfScope.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nreturn true;\n");

        $diff = $this->diffFor('app/OutOfScope.php');

        $executor = $this->makeExecutor($storage, $diff);
        $envelope = $this->envelope('Fix the bug in app/OutOfScope.php.');
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['app/OutOfScope.php'],
            'forbidden_files' => [],
            'validation_commands' => ['composer test'],
            'max_files_changed' => 1,
            'intent_text' => 'Fix the bug in app/OutOfScope.php.',
            'intent_verbs' => ['fix'],
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

        // VAL-M2-021: advisory => needs_review (NOT failed, NOT passed).
        $this->assertSame(
            'needs_review',
            $result->completionState,
            'VAL-M2-021: E6 advisory trip must produce needs_review, not failed/passed. Got: '
            .$result->completionState,
        );

        // VAL-M2-021: the spec_constitution_violation flag is present.
        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertContains(
            SpecDrivenConstitutionGate::FLAG_SPEC_CONSTITUTION_VIOLATION,
            $receipt->completion->honestyFlags,
            'VAL-M2-021: spec_constitution_violation flag must be present. Flags: '
            .json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );
    }

    // ------------------------------------------------------------------
    // VAL-M2-022: hard trip produces failed
    // ------------------------------------------------------------------

    /**
     * VAL-M2-022: when e6.mode=hard and the diff violates the
     * spec/constitution (out-of-spec scope creep), the completion is
     * `failed` (NOT the advisory `needs_review`). The verification gate is
     * forced to STATUS_FAILED and the spec_constitution_violation flag is
     * retained for auditability.
     */
    public function test_e6_hard_trip_produces_failed_when_diff_violates_spec_constitution(): void
    {
        config()->set('atlas_dev.elevations.e6.mode', 'hard');

        $runId = 'dev-e6-hard-trip-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);

        $this->seedRun($storage, $runId, specOverrides: [
            'allowed_files' => ['app/InScope.php'],
            'expected_files' => ['app/InScope.php'],
            'forbidden_files' => [],
            'non_goals' => [],
            'acceptance_criteria' => [
                ['description' => 'in-scope behavior', 'id' => 'ac_behavior_1', 'verification' => 'test', 'verification_ref' => 'tests/Unit/InScopeTest.php'],
            ],
            'expected_behavior' => [
                ['description' => 'in-scope behavior is correct', 'observable_by' => 'test'],
            ],
        ]);

        $target = $this->tmpWorkspace.'/app/OutOfScope.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nreturn true;\n");

        $diff = $this->diffFor('app/OutOfScope.php');

        $executor = $this->makeExecutor($storage, $diff);
        $envelope = $this->envelope('Fix the bug in app/OutOfScope.php.');
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['app/OutOfScope.php'],
            'forbidden_files' => [],
            'validation_commands' => ['composer test'],
            'max_files_changed' => 1,
            'intent_text' => 'Fix the bug in app/OutOfScope.php.',
            'intent_verbs' => ['fix'],
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

        // VAL-M2-022: hard => failed (the firm block, NOT needs_review).
        $this->assertSame(
            'failed',
            $result->completionState,
            'VAL-M2-022: E6 hard trip must produce failed, not needs_review. Got: '
            .$result->completionState,
        );

        // VAL-M2-022: the verification gate is forced to STATUS_FAILED.
        $this->assertSame(
            VerificationGateResult::STATUS_FAILED,
            $result->verificationStatus,
            'VAL-M2-022: E6 hard must force the verification gate to STATUS_FAILED.',
        );

        // VAL-M2-022: the flag is retained for auditability.
        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertContains(
            SpecDrivenConstitutionGate::FLAG_SPEC_CONSTITUTION_VIOLATION,
            $receipt->completion->honestyFlags,
            'VAL-M2-022: spec_constitution_violation flag must be retained. Flags: '
            .json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );
    }

    // ------------------------------------------------------------------
    // VAL-M2-023: does not false-fail when diff honors spec
    // ------------------------------------------------------------------

    /**
     * VAL-M2-023: a diff that stays within the spec's allowed/expected
     * files, does not touch forbidden files, and does not implement non-goals
     * does NOT trip E6, in either advisory or hard mode. The completion is
     * not blocked by E6 — a spec-compliant run stays green on the E6 axis.
     *
     * Tested in HARD mode (the strongest): if E6 does not false-fail in hard,
     * it will not false-fail in advisory either.
     */
    public function test_e6_does_not_false_fail_when_diff_honors_spec_and_stays_in_scope(): void
    {
        config()->set('atlas_dev.elevations.e6.mode', 'hard');

        $runId = 'dev-e6-clear-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);

        // Spec declares scope for app/InScope.php — the diff will touch this
        // file (in-scope).
        $this->seedRun($storage, $runId, specOverrides: [
            'allowed_files' => ['app/InScope.php'],
            'expected_files' => ['app/InScope.php'],
            'forbidden_files' => [],
            'non_goals' => [],
            'acceptance_criteria' => [
                ['description' => 'in-scope behavior', 'id' => 'ac_behavior_1', 'verification' => 'test', 'verification_ref' => 'tests/Unit/InScopeTest.php'],
            ],
            'expected_behavior' => [
                ['description' => 'in-scope behavior is correct', 'observable_by' => 'test'],
            ],
        ]);

        // Diff touches app/InScope.php — IS in the spec's allowed/expected
        // files. E6 should NOT trip.
        $target = $this->tmpWorkspace.'/app/InScope.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nreturn true;\n");

        $diff = $this->diffFor('app/InScope.php');

        $executor = $this->makeExecutor($storage, $diff);
        $envelope = $this->envelope('Fix the bug in app/InScope.php.');
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['app/InScope.php'],
            'forbidden_files' => [],
            'validation_commands' => ['composer test'],
            'max_files_changed' => 1,
            'intent_text' => 'Fix the bug in app/InScope.php.',
            'intent_verbs' => ['fix'],
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

        // VAL-M2-023: no spec_constitution_violation flag.
        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertNotContains(
            SpecDrivenConstitutionGate::FLAG_SPEC_CONSTITUTION_VIOLATION,
            $receipt->completion->honestyFlags,
            'VAL-M2-023: no spec_constitution_violation flag on a spec-compliant diff. Flags: '
            .json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );

        // VAL-M2-023: E6 hard did NOT force STATUS_FAILED — the gate stays
        // STATUS_PASSED (the diff honors the spec).
        $this->assertNotSame(
            VerificationGateResult::STATUS_FAILED,
            $result->verificationStatus,
            'VAL-M2-023: E6 hard must not force STATUS_FAILED on a spec-compliant diff.',
        );

        // VAL-M2-023: the completion is not `failed` due to E6.
        $this->assertNotSame(
            'failed',
            $result->completionState,
            'VAL-M2-023: a spec-compliant diff must not be failed by E6. Got: '
            .$result->completionState,
        );
    }

    // ------------------------------------------------------------------
    // VAL-M2-024: detects out-of-spec scope creep (forbidden files / non-goals)
    // ------------------------------------------------------------------

    /**
     * VAL-M2-024 (forbidden files): a diff that touches a forbidden_file
     * declared in the spec — while staying within the contract's
     * allowed_files mechanically (ScopeGuard passes) — trips E6. This is
     * the SEMANTIC scope check distinct from the ScopeGuard.
     */
    public function test_e6_detects_forbidden_file_scope_creep(): void
    {
        $runId = 'dev-e6-forbidden-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);

        // Spec declares app/Config.php as forbidden, but the scope includes
        // both app/Config.php and app/Service.php so check 4 doesn't trip
        // (isolate the forbidden-file check).
        $this->seedRun($storage, $runId, specOverrides: [
            'allowed_files' => ['app/Config.php', 'app/Service.php'],
            'expected_files' => ['app/Service.php'],
            'forbidden_files' => ['app/Config.php'],
            'non_goals' => [],
            'acceptance_criteria' => [
                ['description' => 'service behavior', 'id' => 'ac_behavior_1', 'verification' => 'test', 'verification_ref' => 'tests/Unit/ServiceTest.php'],
            ],
            'expected_behavior' => [
                ['description' => 'service works', 'observable_by' => 'test'],
            ],
        ]);

        // Diff touches app/Config.php — in the taskContract's allowed_files
        // (ScopeGuard passes) but in the spec's forbidden_files (E6 trips).
        $target = $this->tmpWorkspace.'/app/Config.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nreturn true;\n");

        $diff = $this->diffFor('app/Config.php');

        $executor = $this->makeExecutor($storage, $diff);
        $envelope = $this->envelope('Modify app/Config.php.');
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['app/Config.php'],
            'forbidden_files' => [],
            'validation_commands' => ['composer test'],
            'max_files_changed' => 1,
            'intent_text' => 'Modify app/Config.php.',
            'intent_verbs' => ['modify'],
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

        // VAL-M2-024: E6 trips on the forbidden file.
        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertContains(
            SpecDrivenConstitutionGate::FLAG_SPEC_CONSTITUTION_VIOLATION,
            $receipt->completion->honestyFlags,
            'VAL-M2-024: E6 must trip on a forbidden-file touch. Flags: '
            .json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );

        // Advisory => needs_review (not passed, not failed).
        $this->assertSame(
            'needs_review',
            $result->completionState,
            'VAL-M2-024: E6 advisory trip on forbidden file must produce needs_review. Got: '
            .$result->completionState,
        );
    }

    /**
     * VAL-M2-024 (non-goals): a diff that implements a non-goal declared in
     * the spec — touching a file referenced in a non-goal string, while
     * staying within the contract's allowed_files and the spec's
     * allowed_files — trips E6. The non-goal check catches semantic scope
     * creep the ScopeGuard does not.
     */
    public function test_e6_detects_non_goal_implementation(): void
    {
        $runId = 'dev-e6-nongoal-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);

        // Spec declares a non-goal referencing app/Config.php. The allowed
        // files include app/Config.php so check 4 doesn't trip (isolate the
        // non-goal check). No forbidden files.
        $this->seedRun($storage, $runId, specOverrides: [
            'allowed_files' => ['app/Config.php', 'app/Service.php'],
            'expected_files' => ['app/Service.php'],
            'forbidden_files' => [],
            'non_goals' => ['do not modify app/Config.php'],
            'acceptance_criteria' => [
                ['description' => 'service behavior', 'id' => 'ac_behavior_1', 'verification' => 'test', 'verification_ref' => 'tests/Unit/ServiceTest.php'],
            ],
            'expected_behavior' => [
                ['description' => 'service works', 'observable_by' => 'test'],
            ],
        ]);

        // Diff touches app/Config.php — referenced in the non-goal "do not
        // modify app/Config.php". ScopeGuard passes (in contract's
        // allowed_files). E6's non-goal check trips.
        $target = $this->tmpWorkspace.'/app/Config.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nreturn true;\n");

        $diff = $this->diffFor('app/Config.php');

        $executor = $this->makeExecutor($storage, $diff);
        $envelope = $this->envelope('Modify app/Config.php.');
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['app/Config.php'],
            'forbidden_files' => [],
            'validation_commands' => ['composer test'],
            'max_files_changed' => 1,
            'intent_text' => 'Modify app/Config.php.',
            'intent_verbs' => ['modify'],
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

        // VAL-M2-024: E6 trips on the non-goal implementation.
        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertContains(
            SpecDrivenConstitutionGate::FLAG_SPEC_CONSTITUTION_VIOLATION,
            $receipt->completion->honestyFlags,
            'VAL-M2-024: E6 must trip on a non-goal implementation. Flags: '
            .json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );

        // Advisory => needs_review.
        $this->assertSame(
            'needs_review',
            $result->completionState,
            'VAL-M2-024: E6 advisory trip on non-goal must produce needs_review. Got: '
            .$result->completionState,
        );
    }

    // ------------------------------------------------------------------
    // VAL-M2-025: e6.mode config default is advisory
    // ------------------------------------------------------------------

    /**
     * VAL-M2-025: the config source ships `elevations.e6.mode` with an
     * `advisory` default (not `hard` — E6 is newly built and never starts
     * at hard). A runtime ElevationConfig::fromConfig('e6') read with no
     * override returns isAdvisory() === true.
     */
    public function test_e6_mode_config_default_is_advisory(): void
    {
        // Config source: the literal default in config/atlas_dev.php is
        // 'advisory'.
        $configSource = (string) file_get_contents(
            dirname(__DIR__, 6).'/config/atlas_dev.php',
        );
        $pattern = "/env\(\s*['\"]ATLAS_DEV_ELEVATION_E6_MODE['\"]\s*,\s*['\"]([a-z]+)['\"]\s*\)/";
        $this->assertMatchesRegularExpression(
            $pattern,
            $configSource,
            'VAL-M2-025: config source must contain the e6.mode env fallback.',
        );
        $this->assertMatchesRegularExpression(
            "/env\(\s*['\"]ATLAS_DEV_ELEVATION_E6_MODE['\"]\s*,\s*['\"]advisory['\"]\s*\)/",
            $configSource,
            'VAL-M2-025: e6.mode config default must be advisory (not hard, not off).',
        );

        // Runtime: ElevationConfig::for('e6', null) (missing block) resolves
        // to advisory (the safe default).
        $config = ElevationConfig::for('e6', null);
        $this->assertTrue(
            $config->isAdvisory(),
            'VAL-M2-025: ElevationConfig for e6 with null block must be advisory.',
        );
        $this->assertFalse(
            $config->isHard(),
            'VAL-M2-025: e6 must NOT resolve to hard (never starts at hard).',
        );
        $this->assertFalse(
            $config->isOff(),
            'VAL-M2-025: e6 must NOT resolve to off (built and turned on at advisory).',
        );
    }

    // ------------------------------------------------------------------
    // VAL-M2-033: honest ceiling — unevaluable spec never silently greens
    // ------------------------------------------------------------------

    /**
     * VAL-M2-033 (advisory): when the spec is unreadable/corrupt, E6 in
     * advisory mode appends the spec_unevaluable honesty flag (-> needs_review).
     * Never `passed` (silent green), no crash.
     */
    public function test_e6_honest_ceiling_unevaluable_spec_advisory_needs_review(): void
    {
        $runId = 'dev-e6-uneval-adv-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);

        // Seed the run but write a CORRUPT mini_programming_spec (invalid
        // JSON) so storage->read throws and E6 surfaces unevaluable.
        $this->seedRunCorruptSpec($storage, $runId);

        $target = $this->tmpWorkspace.'/app/InScope.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nreturn true;\n");

        $diff = $this->diffFor('app/InScope.php');

        $executor = $this->makeExecutor($storage, $diff);
        $envelope = $this->envelope('Fix the bug in app/InScope.php.');
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['app/InScope.php'],
            'forbidden_files' => [],
            'validation_commands' => ['composer test'],
            'max_files_changed' => 1,
            'intent_text' => 'Fix the bug in app/InScope.php.',
            'intent_verbs' => ['fix'],
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

        // VAL-M2-033: advisory => needs_review (NOT passed, NOT failed).
        $this->assertNotSame(
            'passed',
            $result->completionState,
            'VAL-M2-033: an unevaluable E6 check must never silently green (passed). Got: '
            .$result->completionState,
        );

        // VAL-M2-033: the spec_unevaluable flag is present.
        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertContains(
            SpecDrivenConstitutionGate::FLAG_SPEC_UNEVALUABLE,
            $receipt->completion->honestyFlags,
            'VAL-M2-033: spec_unevaluable flag must be present in advisory. Flags: '
            .json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * VAL-M2-033 (hard): when the spec is unreadable/corrupt, E6 in hard
     * mode fail-closes (STATUS_FAILED -> `failed`) with the spec_unevaluable
     * flag. Never `passed`, no crash.
     */
    public function test_e6_honest_ceiling_unevaluable_spec_hard_failed(): void
    {
        config()->set('atlas_dev.elevations.e6.mode', 'hard');

        $runId = 'dev-e6-uneval-hard-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);

        $this->seedRunCorruptSpec($storage, $runId);

        $target = $this->tmpWorkspace.'/app/InScope.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nreturn true;\n");

        $diff = $this->diffFor('app/InScope.php');

        $executor = $this->makeExecutor($storage, $diff);
        $envelope = $this->envelope('Fix the bug in app/InScope.php.');
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['app/InScope.php'],
            'forbidden_files' => [],
            'validation_commands' => ['composer test'],
            'max_files_changed' => 1,
            'intent_text' => 'Fix the bug in app/InScope.php.',
            'intent_verbs' => ['fix'],
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

        // VAL-M2-033: hard => failed (fail-closed, never passed).
        $this->assertSame(
            'failed',
            $result->completionState,
            'VAL-M2-033: an unevaluable E6 check in hard mode must fail-close to failed. Got: '
            .$result->completionState,
        );

        // VAL-M2-033: the spec_unevaluable flag is present.
        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertContains(
            SpecDrivenConstitutionGate::FLAG_SPEC_UNEVALUABLE,
            $receipt->completion->honestyFlags,
            'VAL-M2-033: spec_unevaluable flag must be present in hard. Flags: '
            .json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );
    }

    // ------------------------------------------------------------------
    // VAL-M2-034: no-op on task with no spec / acceptance criteria
    // ------------------------------------------------------------------

    /**
     * VAL-M2-034: E6 is a no-op on a task that declares no spec / acceptance
     * criteria. A spec with zero acceptance criteria, non-goals, forbidden
     * files, and expected behavior has no constitution to validate. E6
     * surfaces no spec-violation flag and does not downgrade or fail the
     * completion, in both advisory and hard modes.
     */
    public function test_e6_noop_on_task_with_no_spec_or_acceptance_criteria(): void
    {
        // Test in HARD mode (strongest): if E6 does not trip on a spec-less
        // task in hard, it won't in advisory either.
        config()->set('atlas_dev.elevations.e6.mode', 'hard');

        $runId = 'dev-e6-noop-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);

        // Seed a spec with NO constitution: empty acceptance_criteria,
        // non_goals, forbidden_files, expected_behavior. The allowed_files
        // and expected_files are set so the compact_sdd_hash pin check
        // passes and the spec is valid.
        $this->seedRun($storage, $runId, specOverrides: [
            'allowed_files' => ['app/InScope.php'],
            'expected_files' => ['app/InScope.php'],
            'forbidden_files' => [],
            'non_goals' => [],
            'acceptance_criteria' => [],
            'expected_behavior' => [],
        ]);

        $target = $this->tmpWorkspace.'/app/InScope.php';
        mkdir(dirname($target), 0o755, true);
        file_put_contents($target, "<?php\nreturn true;\n");

        $diff = $this->diffFor('app/InScope.php');

        $executor = $this->makeExecutor($storage, $diff);
        $envelope = $this->envelope('Fix the bug in app/InScope.php.');
        $taskContract = $this->taskContractFixture([
            'allowed_files' => ['app/InScope.php'],
            'forbidden_files' => [],
            'validation_commands' => ['composer test'],
            'max_files_changed' => 1,
            'intent_text' => 'Fix the bug in app/InScope.php.',
            'intent_verbs' => ['fix'],
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

        // VAL-M2-034: no spec_constitution_violation flag.
        $receipt = $this->loadReceipt($storage, $runId);
        $this->assertNotContains(
            SpecDrivenConstitutionGate::FLAG_SPEC_CONSTITUTION_VIOLATION,
            $receipt->completion->honestyFlags,
            'VAL-M2-034: no spec_constitution_violation flag on a spec-less task. Flags: '
            .json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );

        // VAL-M2-034: no spec_unevaluable flag (the spec IS readable, it just
        // has no constitution).
        $this->assertNotContains(
            SpecDrivenConstitutionGate::FLAG_SPEC_UNEVALUABLE,
            $receipt->completion->honestyFlags,
            'VAL-M2-034: no spec_unevaluable flag on a spec-less task. Flags: '
            .json_encode($receipt->completion->honestyFlags, JSON_THROW_ON_ERROR),
        );

        // VAL-M2-034: E6 hard did NOT force STATUS_FAILED (no constitution
        // to violate).
        $this->assertNotSame(
            VerificationGateResult::STATUS_FAILED,
            $result->verificationStatus,
            'VAL-M2-034: E6 must not force STATUS_FAILED on a spec-less task.',
        );

        // VAL-M2-034: the completion is not `failed` due to E6.
        $this->assertNotSame(
            'failed',
            $result->completionState,
            'VAL-M2-034: a spec-less task must not be failed by E6. Got: '
            .$result->completionState,
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
     * Seed the persisted run artifacts (compact_sdd, mini_programming_spec,
     * open_brain_projection). The $specOverrides are merged into the
     * mini_programming_spec fixture payload.
     *
     * @param  array<string, mixed>  $specOverrides
     */
    private function seedRun(
        ReceiptStorage $storage,
        string $runId,
        array $specOverrides = [],
    ): void {
        $compactSdd = $this->compactSddFixture(['task_kind' => 'repair', 'risk_level' => 'R2']);
        $compactPayload = $compactSdd->toCanonicalArray();
        $compactPayload['compact_sdd_hash'] = $compactSdd->hash();
        $storage->writeAtomic($runId, ArtifactNames::COMPACT_SDD, $compactPayload);

        $miniSpecPayload = $this->miniSpecFixture()->toCanonicalArray();
        $miniSpecPayload['compact_sdd_hash'] = $compactPayload['compact_sdd_hash'];
        foreach ($specOverrides as $key => $value) {
            $miniSpecPayload[$key] = $value;
        }
        // Recompute the mini_spec_hash so the spec is internally consistent.
        $miniSpec = MiniProgrammingSpec::fromArray($miniSpecPayload);
        $storage->writeAtomic($runId, ArtifactNames::MINI_PROGRAMMING_SPEC, $miniSpec->toCanonicalArray());

        $storage->writeAtomic($runId, ArtifactNames::OPEN_BRAIN_PROJECTION, [
            'context_pack_hash' => 'atlas-dev:context_pack:'.bin2hex(random_bytes(4)),
        ]);
    }

    /**
     * Seed the run with a CORRUPT mini_programming_spec: a valid JSON object
     * that passes the compact_sdd_hash pin check (it carries the hash) but
     * is missing the `verification_plan` field, so
     * MiniProgrammingSpec::fromArray throws when E6 tries to parse it
     * (VAL-M2-033 honest ceiling — the spec is present but unevaluable).
     *
     * The pin check (which runs early in the executor) only needs
     * `compact_sdd_hash` to be a non-empty string matching the compact_sdd
     * hash. fromArray needs the full schema. By providing the hash but
     * omitting `verification_plan`, the pin check passes but fromArray
     * throws InvalidArgumentException inside E6's try/catch, surfacing the
     * unevaluable verdict.
     */
    private function seedRunCorruptSpec(ReceiptStorage $storage, string $runId): void
    {
        $compactSdd = $this->compactSddFixture(['task_kind' => 'repair', 'risk_level' => 'R2']);
        $compactPayload = $compactSdd->toCanonicalArray();
        $compactPayload['compact_sdd_hash'] = $compactSdd->hash();
        $storage->writeAtomic($runId, ArtifactNames::COMPACT_SDD, $compactPayload);

        // Write a spec that has compact_sdd_hash (passes the pin check) but
        // is missing verification_plan (causes fromArray to throw in E6).
        // This is a structurally-corrupt spec: present but unevaluable.
        $storage->writeAtomic($runId, ArtifactNames::MINI_PROGRAMMING_SPEC, [
            'compact_sdd_hash' => $compactPayload['compact_sdd_hash'],
            'run_id' => $runId,
            // Intentionally missing: goal, non_goals, canonical_context,
            // expected_behavior, assumptions, expected_files, allowed_files,
            // forbidden_files, acceptance_criteria, verification_plan,
            // rollback_or_containment, completion_criteria, mini_spec_hash.
            // The pin check only needs compact_sdd_hash; fromArray needs
            // verification_plan and will throw.
        ]);

        $storage->writeAtomic($runId, ArtifactNames::OPEN_BRAIN_PROJECTION, [
            'context_pack_hash' => 'atlas-dev:context_pack:'.bin2hex(random_bytes(4)),
        ]);
    }

    /**
     * Build a simple unified diff for a single file that changes
     * `return true;` to `return false;`.
     */
    private function diffFor(string $relativePath): string
    {
        return <<<DIFF
--- a/{$relativePath}
+++ b/{$relativePath}
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
