<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use App\Services\Ai\Programming\AtlasDev\Gate\CompletionDecision;
use App\Services\Ai\Programming\AtlasDev\Gate\CompletionStateGate;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGateResult;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParseResult;
use App\Services\Ai\Programming\AtlasDev\Provider\ProviderCallResult;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeBaseline;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeContractView;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeFileDiff;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeObserved;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\ScopeGuardReceipt;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationMode;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Repair\RepairFixtureFactory;

/**
 * Covers VAL-M0-007 (frozen baseline green - asserted via the frozen suite),
 * VAL-M0-008 (with every elevation off, a synthetic-diff run is byte-identical
 * to the pre-mission baseline), and the config-binding half of VAL-M0-009
 * (the real atlas_dev.elevations.* config block resolves each elevation to a
 * valid mode with the documented safe default).
 *
 * These are feature tests because they read the real Laravel config kernel.
 * The frozen-test greenness invariant (VAL-M0-007) is exercised by the frozen
 * suite directly (commands.frozen) and is not re-asserted here to avoid
 * duplicating 58 tests; this class focuses on the config-flag contract.
 */
final class ElevationsConfigFlagTest extends TestCase
{
    private const ALL_ELEVATIONS = ['e1', 'e2', 'e3', 'e4', 'e5', 'e6'];

    // -- VAL-M0-008: with every elevation off, a synthetic-diff run is
    //    byte-identical to the pre-mission baseline -------------------------

    public function test_with_every_elevation_off_the_completion_decision_is_unchanged_no_new_flags(): void
    {
        // Drive a synthetic-diff scenario through CompletionStateGate with
        // every elevation set to off. The decision, honesty flags, and reasons
        // must be byte-identical to the pre-mission baseline (no elevation
        // surfaces anything).
        [$decisionWithElevations, $decisionBaseline] = $this->runSyntheticDiffTwice(
            elevationsConfig: array_fill_keys(self::ALL_ELEVATIONS, ['mode' => 'off']),
        );

        $this->assertSame(
            $decisionBaseline->status,
            $decisionWithElevations->status,
            'off mode must not change completion status'
        );
        $this->assertSame(
            $decisionBaseline->honestyFlags,
            $decisionWithElevations->honestyFlags,
            'off mode must not add any honesty flag'
        );
        $this->assertSame(
            $decisionBaseline->residualRisks,
            $decisionWithElevations->residualRisks,
            'off mode must not add residual risks'
        );
    }

    public function test_with_every_elevation_off_no_elevation_appends_a_flag_or_blocks(): void
    {
        config([
            'atlas_dev.elevations' => array_fill_keys(self::ALL_ELEVATIONS, ['mode' => 'off']),
        ]);

        foreach (self::ALL_ELEVATIONS as $elevation) {
            $config = ElevationConfig::fromConfig($elevation);

            $this->assertTrue($config->isOff(), "[$elevation] must be off under the off config");
            $this->assertFalse($config->shouldAppendHonestyFlag(), "[$elevation] off must not flag");
            $this->assertFalse($config->shouldBlock(), "[$elevation] off must not block");
        }
    }

    // -- VAL-M0-009: real config block tri-state + safe default --------------

    public function test_real_config_block_resolves_each_elevation_to_a_valid_mode(): void
    {
        // Read the real config block (without overriding it). Each elevation
        // must resolve to one of off|advisory|hard without crashing.
        foreach (self::ALL_ELEVATIONS as $elevation) {
            $config = ElevationConfig::fromConfig($elevation);

            $this->assertContains(
                $config->mode()->value,
                ['off', 'advisory', 'hard'],
                "[$elevation] real config must resolve to a valid mode"
            );
        }
    }

    public function test_unknown_real_config_value_resolves_to_safe_default_without_crashing(): void
    {
        // Mutate the real config block to an invalid value and confirm the
        // helper degrades to the safe default (advisory) with no exception.
        config(['atlas_dev.elevations.e1.mode' => 'totally-unknown']);

        $config = ElevationConfig::fromConfig('e1');

        $this->assertSame(ElevationMode::ADVISORY, $config->mode());
        $this->assertTrue($config->isAdvisory());
    }

    public function test_missing_real_config_value_resolves_to_safe_default_without_crashing(): void
    {
        config(['atlas_dev.elevations.e2' => []]);

        $config = ElevationConfig::fromConfig('e2');

        $this->assertSame(ElevationMode::ADVISORY, $config->mode());
    }

    public function test_missing_whole_real_config_block_resolves_to_safe_default(): void
    {
        config(['atlas_dev.elevations' => null]);

        foreach (self::ALL_ELEVATIONS as $elevation) {
            $config = ElevationConfig::fromConfig($elevation);

            $this->assertSame(
                ElevationMode::ADVISORY,
                $config->mode(),
                "[$elevation] missing block must default to advisory"
            );
        }
    }

    public function test_advisory_config_routes_to_honesty_flag_only_never_status_failed(): void
    {
        config(['atlas_dev.elevations' => array_fill_keys(self::ALL_ELEVATIONS, ['mode' => 'advisory'])]);

        foreach (self::ALL_ELEVATIONS as $elevation) {
            $config = ElevationConfig::fromConfig($elevation);

            $this->assertTrue($config->isAdvisory(), "[$elevation]");
            $this->assertTrue($config->shouldAppendHonestyFlag(), "[$elevation] advisory flags");
            $this->assertFalse($config->shouldBlock(), "[$elevation] advisory never blocks");
            $this->assertNotSame(
                VerificationGateResult::STATUS_FAILED,
                $config->advisoryGateStatus(),
                "[$elevation] advisory never STATUS_FAILED for the flag alone"
            );
        }
    }

    public function test_hard_config_routes_to_block_via_a_sanctioned_channel(): void
    {
        config(['atlas_dev.elevations' => array_fill_keys(self::ALL_ELEVATIONS, ['mode' => 'hard'])]);

        foreach (self::ALL_ELEVATIONS as $elevation) {
            $config = ElevationConfig::fromConfig($elevation);

            $this->assertTrue($config->isHard(), "[$elevation]");
            $this->assertTrue($config->shouldBlock(), "[$elevation] hard blocks");
            $this->assertFalse($config->shouldAppendHonestyFlag(), "[$elevation] hard does not settle for a flag");
        }
    }

    /**
     * VAL-M2-001: the E1 (intent-falsification / coverage probe) elevation
     * ships a `hard` config default. A runtime read of the REAL config kernel
     * (no env override, no in-test config mutation) classifies E1 as hard:
     * ElevationConfig::fromConfig('e1')->isHard() === true. This is the
     * Feature-level complement to AtlasDevFeatureFlagsTest (which reads the
     * config source directly with env unset).
     */
    public function test_e1_mode_default_is_hard_via_real_config_kernel(): void
    {
        // Read the real, unmutated config block for e1 (no config() override).
        $config = ElevationConfig::fromConfig('e1');

        $this->assertTrue(
            $config->isHard(),
            'VAL-M2-001: the real atlas_dev.elevations.e1 config must resolve to hard by default.',
        );
        $this->assertFalse($config->isAdvisory(), 'VAL-M2-001: e1 must not be advisory by default.');
        $this->assertFalse($config->isOff());
    }

    // -- VAL-M0-008 helper: the advisory flag, when appended, forces the
    //    sanctioned downgrade (passed -> needs_review), never green ---------

    public function test_advisory_honesty_flag_forces_downgrade_passed_to_needs_review_never_green(): void
    {
        // The CompletionDecision ctor invariant (passed forbids flags) is the
        // mechanical guarantee. Demonstrate it: a passed gate + an advisory
        // honesty flag cannot yield a passed completion.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('passed forbids honesty_flags');

        new CompletionDecision(
            status: CompletionSummary::STATUS_PASSED,
            honestyFlags: ['elevation_e1_advisory'],
            residualRisks: [],
        );
    }

    public function test_advisory_honesty_flag_downgrades_a_passed_gate_to_needs_review(): void
    {
        // Drives a green synthetic diff + one advisory honesty flag through
        // CompletionStateGate. The result is needs_review, not passed/failed.
        $decision = $this->decide(
            gateStatus: VerificationGateResult::STATUS_PASSED,
            honestyFlags: ['elevation_e3_advisory'],
        );

        $this->assertSame(CompletionSummary::STATUS_NEEDS_REVIEW, $decision->status);
        $this->assertContains('elevation_e3_advisory', $decision->honestyFlags);
        $this->assertContains('passed_downgraded_due_to_honesty_flags', $decision->reasons);
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * Runs a synthetic-diff green scenario through CompletionStateGate twice:
     * once with the given elevations config wired, once with the pre-mission
     * baseline (no elevations config at all). Returns both decisions so the
     * caller can assert byte-identical behavior.
     *
     * @param  array<string, array{mode?: string}>  $elevationsConfig
     * @return array{0: CompletionDecision, 1: CompletionDecision}
     */
    private function runSyntheticDiffTwice(array $elevationsConfig): array
    {
        // With elevations wired (all off). The helper reads config but, being
        // off, appends no flags and blocks nothing; the decision is computed
        // against the same inputs as the baseline run.
        config(['atlas_dev.elevations' => $elevationsConfig]);
        $decisionWithElevations = $this->decide(
            gateStatus: VerificationGateResult::STATUS_PASSED,
            honestyFlags: [],
        );

        // Pre-mission baseline: no elevations config present at all.
        config(['atlas_dev.elevations' => null]);
        $decisionBaseline = $this->decide(
            gateStatus: VerificationGateResult::STATUS_PASSED,
            honestyFlags: [],
        );

        return [$decisionWithElevations, $decisionBaseline];
    }

    /**
     * @param  list<string>  $honestyFlags
     */
    private function decide(string $gateStatus, array $honestyFlags): CompletionDecision
    {
        $gate = new CompletionStateGate;

        $verificationResult = new VerificationGateResult(
            tests: [],
            gates: [],
            aggregateStatus: $gateStatus,
            honestyFlags: $honestyFlags,
        );

        return $gate->decide(
            taskContract: $this->buildTaskContract(),
            scopeReceipt: $this->buildScopeReceipt(),
            verificationResult: $verificationResult,
            callResult: ProviderCallResult::fromStdout(
                runId: 'run-elevations-test',
                actualProvider: 'hermes_cli',
                actualModelFamily: 'minimax-m3',
                exitStatus: 0,
                stdout: 'ok',
                stderr: '',
                durationMs: 100,
            ),
            diffResult: $this->buildValidDiffResult(),
        );
    }

    private function buildTaskContract(): LightTaskContract
    {
        return RepairFixtureFactory::lightTaskContract(
            runId: 'run-elevations-test',
            allowedFiles: ['app/Services/Ai/Programming/AtlasDev/Support/Elevations/ElevationConfig.php'],
        );
    }

    private function buildScopeReceipt(): ScopeGuardReceipt
    {
        $fileDiffs = [
            new ScopeFileDiff(
                path: 'app/Services/Ai/Programming/AtlasDev/Support/Elevations/ElevationConfig.php',
                added: 10,
                removed: 0,
                fileHashAfter: hash('sha256', 'elevation-config'),
            ),
        ];

        return ScopeGuardReceipt::issue(
            runId: 'run-elevations-test',
            taskContractHash: 'elevation-test-hash',
            baseline: new ScopeBaseline('clean', null),
            observed: new ScopeObserved(
                gitDiffHash: hash('sha256', 'diff'),
                changedFiles: ['app/Services/Ai/Programming/AtlasDev/Support/Elevations/ElevationConfig.php'],
                changedFilesCount: 1,
                fileDiffs: $fileDiffs,
            ),
            scopeContract: new ScopeContractView(
                allowedFiles: ['app/Services/Ai/Programming/AtlasDev/Support/Elevations/ElevationConfig.php'],
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

    private function buildValidDiffResult(): DiffParseResult
    {
        return DiffParseResult::patch(
            diff: "--- a/file\n+++ b/file\n@@\n+added\n",
            changedFiles: ['app/Services/Ai/Programming/AtlasDev/Support/Elevations/ElevationConfig.php'],
        );
    }
}
