<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunRealService;
use Tests\TestCase;

/**
 * Hardens the orchestrator's separation between fixture-staging blockers
 * (pre-run setup issues — never poison dirty_after_run) and workspace
 * blockers (post-arm contamination from out-of-scope writes, tracked
 * .pyc, unregistered artifacts).
 *
 * The bug these tests guard against: a single seed file outside the
 * case's allowed_files_scope contaminating the aggregate dirty signal
 * and tripping the verdict_comparable + dirty_after_run_false hard
 * gates even though no arm dirtied any workspace.
 */
final class AtlasForgeRivalsRunBatteryWorkspaceBlockersTest extends TestCase
{
    public function test_aggregate_receipt_keeps_workspace_blockers_empty_when_no_post_arm_contamination(): void
    {
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $reflection = new \ReflectionMethod($runReal, 'aggregateReceipt');
        $reflection->setAccessible(true);

        $base = [
            'arm' => 'atlas',
            'exit_code' => 0,
            'test_exit_code' => 0,
            'patch_diff_bytes' => 2_000,
            'changed_files' => [],
            'out_of_scope_files' => [],
            'bytecode_artifacts' => [],
            'workspace_blockers' => [],
            'killed' => false,
        ];
        $aggregated = $reflection->invoke(
            $runReal,
            $base,
            [['exit_code' => 0, 'test_exit_code' => 0, 'patch_diff_bytes' => 2_000, 'killed' => false]],
            ['app/Domain/Captures/CaptureService.php'],
            [],
            [],
        );

        $this->assertSame([], $aggregated['workspace_blockers']);
        $this->assertFalse($aggregated['workspace_has_blocking_changes']);
    }

    public function test_aggregate_receipt_flags_out_of_scope_changes(): void
    {
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $reflection = new \ReflectionMethod($runReal, 'aggregateReceipt');
        $reflection->setAccessible(true);

        $base = [
            'arm' => 'atlas', 'exit_code' => 0, 'test_exit_code' => 0,
            'patch_diff_bytes' => 1_000, 'changed_files' => [], 'out_of_scope_files' => [],
            'bytecode_artifacts' => [], 'workspace_blockers' => [], 'killed' => false,
        ];
        $aggregated = $reflection->invoke(
            $runReal,
            $base,
            [['exit_code' => 0, 'test_exit_code' => 0, 'patch_diff_bytes' => 1_000, 'killed' => false]],
            ['app/Domain/Captures/CaptureService.php', 'bad/elsewhere.php'],
            ['bad/elsewhere.php'],
            [],
        );

        $this->assertContains('out_of_scope_change:bad/elsewhere.php', $aggregated['workspace_blockers']);
        $this->assertTrue($aggregated['workspace_has_blocking_changes']);
    }

    public function test_aggregate_receipt_flags_tracked_pyc_artifacts(): void
    {
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $reflection = new \ReflectionMethod($runReal, 'aggregateReceipt');
        $reflection->setAccessible(true);

        $aggregated = $reflection->invoke(
            $runReal,
            ['arm' => 'atlas', 'exit_code' => 0, 'test_exit_code' => 0, 'patch_diff_bytes' => 500, 'changed_files' => [], 'out_of_scope_files' => [], 'bytecode_artifacts' => [], 'workspace_blockers' => [], 'killed' => false],
            [['exit_code' => 0, 'test_exit_code' => 0, 'patch_diff_bytes' => 500, 'killed' => false]],
            ['runtimes/python/__pycache__/foo.pyc'],
            [],
            ['runtimes/python/__pycache__/foo.pyc'],
        );

        $this->assertContains('bytecode_artifact_after_run:runtimes/python/__pycache__/foo.pyc', $aggregated['workspace_blockers']);
    }

    public function test_is_read_only_fixture_target_accepts_docs_and_tests_and_fixtures(): void
    {
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $reflection = new \ReflectionMethod($runReal, 'isReadOnlyFixtureTarget');
        $reflection->setAccessible(true);

        // Read-only context — must NOT trip seed staging:
        $this->assertTrue($reflection->invoke($runReal, 'docs/planning/migration/risks.md'));
        $this->assertTrue($reflection->invoke($runReal, 'docs/quality/mutation-survivors-template.md'));
        $this->assertTrue($reflection->invoke($runReal, 'docs/adr/0001-context.md'));
        $this->assertTrue($reflection->invoke($runReal, 'tests/Unit/SomeTest.php'));
        $this->assertTrue($reflection->invoke($runReal, 'tests/fixtures/contracts/v1.json'));
        $this->assertTrue($reflection->invoke($runReal, 'docs/planning/inbox/feature.md'));
        $this->assertTrue($reflection->invoke($runReal, 'storage/forge-rivals-work/context.md'));

        // Code targets — must NOT be read-only by default (arms write to them):
        $this->assertFalse($reflection->invoke($runReal, 'app/Domain/Captures/CaptureService.php'));
        $this->assertFalse($reflection->invoke($runReal, 'app/Support/StringNormalizer.php'));
    }

    public function test_expected_changed_files_inside_scope_never_register_as_workspace_blocker(): void
    {
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $reflection = new \ReflectionMethod($runReal, 'matchesAnyAllowedScope');
        $reflection->setAccessible(true);

        $expected = ['app/Domain/Captures/CaptureService.php', 'tests/Unit/Captures/PublicApiReadmeTest.php'];
        foreach ($expected as $file) {
            $this->assertTrue(
                $reflection->invoke($runReal, $file, $expected),
                "{$file} must match the allowed/expected scope and not register as a blocker",
            );
        }
        $this->assertFalse($reflection->invoke($runReal, 'bad/elsewhere.php', $expected));
    }
}
