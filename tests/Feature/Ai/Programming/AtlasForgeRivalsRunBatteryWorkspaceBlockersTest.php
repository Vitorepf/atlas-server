<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunPathResolver;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunRealService;
use Symfony\Component\Process\Process;
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
    public function test_run_path_resolver_keeps_arm_worktrees_outside_metadata_root(): void
    {
        $paths = app(AtlasForgeRivalsRunPathResolver::class)->paths('test-isolation-layout');

        $this->assertStringContainsString('/Atlas-rivals/runs/test-isolation-layout', $paths['base']);
        $this->assertStringContainsString('/Atlas-rivals/arms/test-isolation-layout-atlas/workspace', $paths['atlas']);
        $this->assertStringContainsString('/Atlas-rivals/arms/test-isolation-layout-rival/workspace', $paths['rival']);
        $this->assertFalse(str_starts_with($paths['atlas'], $paths['base'].'/'));
        $this->assertFalse(str_starts_with($paths['rival'], $paths['base'].'/'));
        $this->assertSame(dirname($paths['root']).'/arms', $paths['arms_root']);
    }

    public function test_provider_output_that_mentions_metadata_or_sibling_arm_is_blocked_as_isolation_leak(): void
    {
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $runId = 'test-isolation-leak-'.bin2hex(random_bytes(4));
        $paths = app(AtlasForgeRivalsRunPathResolver::class)->paths($runId);

        $detect = new \ReflectionMethod($runReal, 'detectProviderIsolationLeak');
        $detect->setAccessible(true);
        $result = $detect->invoke(
            $runReal,
            $runId,
            'rival',
            'debug listing: '.$paths['base'].' sibling='.$paths['atlas'],
            '',
        );

        $this->assertTrue($result['detected']);
        $this->assertContains('isolation_leak:rival:metadata_run_root', $result['blockers']);
        $this->assertContains('isolation_leak:rival:sibling_arm_worktree', $result['blockers']);
    }

    public function test_composer_autoload_that_points_to_sibling_arm_is_blocked_before_provider_run(): void
    {
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $runId = 'test-composer-autoload-leak-'.bin2hex(random_bytes(4));
        $paths = app(AtlasForgeRivalsRunPathResolver::class)->paths($runId);

        @mkdir($paths['atlas'].'/vendor/composer', 0o755, true);
        @mkdir($paths['rival'], 0o755, true);
        file_put_contents(
            $paths['atlas'].'/vendor/composer/autoload_psr4.php',
            "<?php\nreturn ['App\\\\' => ['".$paths['rival']."/app']];\n",
        );

        try {
            $detect = new \ReflectionMethod($runReal, 'composerAutoloadLeakBlockers');
            $detect->setAccessible(true);
            $blockers = $detect->invoke($runReal, $runId, 'atlas', $paths['atlas']);

            $this->assertContains('composer_autoload_leak:sibling_arm_worktree:autoload_psr4.php', $blockers);

            file_put_contents(
                $paths['atlas'].'/vendor/composer/autoload_psr4.php',
                "<?php\nreturn ['App\\\\' => [dirname(__DIR__, 2).'/app']];\n",
            );
            $this->assertSame([], $detect->invoke($runReal, $runId, 'atlas', $paths['atlas']));
        } finally {
            $this->removeDirectory($paths['base']);
            $this->removeDirectory($paths['atlas_arm_base']);
            $this->removeDirectory($paths['rival_arm_base']);
        }
    }

    public function test_directory_scope_patterns_match_nested_files(): void
    {
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $glob = new \ReflectionMethod($runReal, 'globMatches');
        $glob->setAccessible(true);

        $this->assertTrue($glob->invoke(
            $runReal,
            'app/Domain/Captures/Format/Strategies/',
            'app/Domain/Captures/Format/Strategies/JsonFormatterStrategy.php',
        ));
        $this->assertTrue($glob->invoke(
            $runReal,
            'app/Domain/Captures/Format/Strategies/**',
            'app/Domain/Captures/Format/Strategies/JsonFormatterStrategy.php',
        ));
    }

    public function test_scope_check_ignores_generated_frontend_dependency_artifacts(): void
    {
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $worktree = sys_get_temp_dir().'/atlas-rivals-generated-scope-'.bin2hex(random_bytes(6));
        @mkdir($worktree.'/atlas-desktop/src/components/forge', 0o755, true);
        @mkdir($worktree.'/atlas-desktop/node_modules/react', 0o755, true);
        file_put_contents($worktree.'/atlas-desktop/src/components/forge/SignupSubmitButton.tsx', "export const SignupSubmitButton = () => null;\n");
        file_put_contents($worktree.'/atlas-desktop/node_modules/react/index.js', str_repeat("generated dependency\n", 128));
        file_put_contents($worktree.'/atlas-desktop/package-lock.json', "{\"lockfileVersion\":3}\n");

        (new Process(['git', '-C', $worktree, 'init']))->mustRun();

        try {
            $scope = new \ReflectionMethod($runReal, 'scopeCheck');
            $scope->setAccessible(true);
            $result = $scope->invoke($runReal, $worktree, [
                'id' => 'frontend-l1-button-loading-state',
                'case_source' => 'provider_arena_corpus',
                'allowed_files' => ['atlas-desktop/src/components/forge/**'],
                'expected_changed_files' => ['atlas-desktop/src/components/forge/SignupSubmitButton.tsx'],
            ]);

            $this->assertSame(['atlas-desktop/src/components/forge/SignupSubmitButton.tsx'], $result['changed_files']);
            $this->assertSame([], $result['out_of_scope_files']);
            $this->assertSame([], $result['blockers']);
        } finally {
            $this->removeDirectory($worktree);
        }
    }

    public function test_capture_patch_streams_untracked_files_and_excludes_generated_artifacts(): void
    {
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $runId = 'test-generated-patch-'.bin2hex(random_bytes(6));
        $worktree = sys_get_temp_dir().'/atlas-rivals-generated-patch-'.bin2hex(random_bytes(6));
        $runRoot = '/Users/vitorepf/develop/Atlas-rivals/runs/'.$runId;
        @mkdir($worktree.'/atlas-desktop/src/components/forge', 0o755, true);
        @mkdir($worktree.'/atlas-desktop/node_modules/huge-package', 0o755, true);
        file_put_contents($worktree.'/atlas-desktop/src/components/forge/SignupSubmitButton.tsx', "export const SignupSubmitButton = () => 'done';\n");
        file_put_contents($worktree.'/atlas-desktop/node_modules/huge-package/index.js', str_repeat("generated dependency should not enter patch\n", 4096));

        (new Process(['git', '-C', $worktree, 'init']))->mustRun();

        try {
            $capture = new \ReflectionMethod($runReal, 'capturePatch');
            $capture->setAccessible(true);
            $patch = $capture->invoke($runReal, $runId, 'atlas', $worktree, [
                'id' => 'frontend-l1-button-loading-state',
                'case_source' => 'provider_arena_corpus',
                'expected_changed_files' => ['atlas-desktop/src/components/forge/SignupSubmitButton.tsx'],
            ]);

            $content = file_get_contents($patch['path']);
            $this->assertIsString($content);
            $this->assertStringContainsString('SignupSubmitButton.tsx', $content);
            $this->assertStringNotContainsString('node_modules', $content);
            $this->assertStringNotContainsString('generated dependency should not enter patch', $content);
            $this->assertLessThan(20_000, $patch['bytes']);
        } finally {
            $this->removeDirectory($worktree);
            $this->removeDirectory($runRoot);
        }
    }

    public function test_scope_check_still_blocks_out_of_scope_source_changes(): void
    {
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $worktree = sys_get_temp_dir().'/atlas-rivals-out-of-scope-source-'.bin2hex(random_bytes(6));
        @mkdir($worktree.'/atlas-desktop/src/components/forge', 0o755, true);
        @mkdir($worktree.'/atlas-desktop/src/admin', 0o755, true);
        file_put_contents($worktree.'/atlas-desktop/src/components/forge/SignupSubmitButton.tsx', "export const SignupSubmitButton = () => null;\n");
        file_put_contents($worktree.'/atlas-desktop/src/admin/SecretPanel.tsx', "export const SecretPanel = () => null;\n");

        (new Process(['git', '-C', $worktree, 'init']))->mustRun();

        try {
            $scope = new \ReflectionMethod($runReal, 'scopeCheck');
            $scope->setAccessible(true);
            $result = $scope->invoke($runReal, $worktree, [
                'id' => 'frontend-l1-button-loading-state',
                'case_source' => 'provider_arena_corpus',
                'allowed_files' => ['atlas-desktop/src/components/forge/**'],
                'expected_changed_files' => ['atlas-desktop/src/components/forge/SignupSubmitButton.tsx'],
            ]);

            $this->assertContains('atlas-desktop/src/admin/SecretPanel.tsx', $result['out_of_scope_files']);
            $this->assertContains('out_of_scope_change:atlas-desktop/src/admin/SecretPanel.tsx', $result['blockers']);
        } finally {
            $this->removeDirectory($worktree);
        }
    }

    public function test_expected_changed_fixture_file_is_allowed_as_competitor_output(): void
    {
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $worktree = sys_get_temp_dir().'/atlas-rivals-expected-fixture-'.bin2hex(random_bytes(6));
        $target = 'atlas-desktop/src/components/forge/SignupSubmitButton.tsx';
        @mkdir($worktree.'/'.dirname($target), 0o755, true);
        file_put_contents($worktree.'/'.$target, "export const SignupSubmitButton = () => null;\n");
        $fixtureHash = hash_file('sha256', $worktree.'/'.$target);
        file_put_contents($worktree.'/'.$target, "export const SignupSubmitButton = () => <button aria-busy=\"false\" />;\n");

        (new Process(['git', '-C', $worktree, 'init']))->mustRun();

        try {
            $scope = new \ReflectionMethod($runReal, 'scopeCheck');
            $scope->setAccessible(true);
            $result = $scope->invoke($runReal, $worktree, [
                'id' => 'frontend-l1-button-loading-state',
                'case_source' => 'provider_arena_corpus',
                'allowed_files' => [
                    'atlas-desktop/src/components/forge/SignupSubmitButton.tsx',
                    'atlas-desktop/src/components/forge/__tests__/SignupSubmitButton.test.tsx',
                ],
                'expected_changed_files' => [$target],
                '_fixture_baseline_hashes' => [$target => $fixtureHash],
            ]);

            $this->assertSame([$target], $result['changed_files']);
            $this->assertSame([], $result['out_of_scope_files']);
            $this->assertSame([], $result['blockers']);
        } finally {
            $this->removeDirectory($worktree);
        }
    }

    public function test_read_only_fixture_file_still_blocks_when_modified(): void
    {
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $worktree = sys_get_temp_dir().'/atlas-rivals-readonly-fixture-'.bin2hex(random_bytes(6));
        $target = 'atlas-desktop/src/components/forge/__tests__/SignupSubmitButton.test.tsx';
        @mkdir($worktree.'/'.dirname($target), 0o755, true);
        file_put_contents($worktree.'/'.$target, "it('expects original fixture', () => {});\n");
        $fixtureHash = hash_file('sha256', $worktree.'/'.$target);
        file_put_contents($worktree.'/'.$target, "it('was changed by the provider', () => {});\n");

        (new Process(['git', '-C', $worktree, 'init']))->mustRun();

        try {
            $scope = new \ReflectionMethod($runReal, 'scopeCheck');
            $scope->setAccessible(true);
            $result = $scope->invoke($runReal, $worktree, [
                'id' => 'frontend-l1-button-loading-state',
                'case_source' => 'provider_arena_corpus',
                'allowed_files' => [
                    'atlas-desktop/src/components/forge/SignupSubmitButton.tsx',
                    $target,
                ],
                'expected_changed_files' => ['atlas-desktop/src/components/forge/SignupSubmitButton.tsx'],
                '_fixture_baseline_hashes' => [$target => $fixtureHash],
            ]);

            $this->assertContains($target, $result['out_of_scope_files']);
            $this->assertContains('fixture_file_modified:'.$target, $result['blockers']);
        } finally {
            $this->removeDirectory($worktree);
        }
    }

    public function test_flat_phpunit_seed_file_is_staged_from_namespace_and_ignored_when_unchanged(): void
    {
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $runId = 'test-flat-phpunit-seed-'.bin2hex(random_bytes(6));
        $worktree = sys_get_temp_dir().'/atlas-rivals-flat-phpunit-seed-'.bin2hex(random_bytes(6));
        @mkdir($worktree, 0o755, true);
        (new Process(['git', '-C', $worktree, 'init']))->mustRun();

        try {
            $stage = new \ReflectionMethod($runReal, 'stageCaseFixture');
            $stage->setAccessible(true);
            $staged = $stage->invoke($runReal, $runId, 'atlas', $worktree, [
                'id' => 'refactor-l1-extract-method',
                'case_source' => 'provider_arena_corpus',
                'setup_fixture' => ['seed_dir' => 'storage/forge-rivals-corpus/refactor-l1-extract-method/seed'],
                'allowed_files' => ['app/Domain/Inbox/InboxRankingService.php'],
                'expected_changed_files' => ['app/Domain/Inbox/InboxRankingService.php'],
            ]);

            $this->assertSame('ok', $staged['status']);
            $this->assertContains('tests/Unit/Inbox/InboxRankingServiceTest.php', $staged['staged_files']);
            $this->assertArrayHasKey('tests/Unit/Inbox/InboxRankingServiceTest.php', $staged['file_hashes']);

            $scope = new \ReflectionMethod($runReal, 'scopeCheck');
            $scope->setAccessible(true);
            $result = $scope->invoke($runReal, $worktree, [
                'id' => 'refactor-l1-extract-method',
                'case_source' => 'provider_arena_corpus',
                'allowed_files' => ['app/Domain/Inbox/InboxRankingService.php'],
                'expected_changed_files' => ['app/Domain/Inbox/InboxRankingService.php'],
                '_fixture_baseline_hashes' => $staged['file_hashes'],
            ]);

            $this->assertSame([], $result['changed_files']);
            $this->assertSame([], $result['out_of_scope_files']);
            $this->assertSame([], $result['blockers']);
        } finally {
            $this->removeDirectory($worktree);
            $this->removeDirectory('/Users/vitorepf/develop/Atlas-rivals/runs/'.$runId);
        }
    }

    public function test_frontend_vitest_command_is_self_contained_inside_fixture_workspace(): void
    {
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $testCommand = new \ReflectionMethod($runReal, 'testCommand');
        $testCommand->setAccessible(true);

        $command = $testCommand->invoke($runReal, [
            'id' => 'frontend-l1-button-loading-state',
            'test_command' => 'vitest run components/forge/__tests__/SignupSubmitButton.test.tsx --reporter=basic',
        ]);

        $this->assertSame(
            'cd atlas-desktop && npm install --silent && npx --yes vitest run src/components/forge/__tests__/SignupSubmitButton.test.tsx --reporter=basic',
            $command,
        );
    }

    public function test_frontend_vitest_harness_is_staged_as_unchanged_fixture_context(): void
    {
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $runId = 'test-frontend-harness-'.bin2hex(random_bytes(6));
        $worktree = sys_get_temp_dir().'/atlas-rivals-frontend-harness-'.bin2hex(random_bytes(6));
        @mkdir($worktree, 0o755, true);
        (new Process(['git', '-C', $worktree, 'init']))->mustRun();

        try {
            $stage = new \ReflectionMethod($runReal, 'stageCaseFixture');
            $stage->setAccessible(true);
            $staged = $stage->invoke($runReal, $runId, 'atlas', $worktree, [
                'id' => 'frontend-l1-button-loading-state',
                'case_source' => 'provider_arena_corpus',
                'setup_fixture' => ['seed_dir' => 'storage/forge-rivals-corpus/frontend-l1-button-loading-state/seed'],
                'allowed_files' => [
                    'atlas-desktop/src/components/forge/SignupSubmitButton.tsx',
                    'atlas-desktop/src/components/forge/__tests__/SignupSubmitButton.test.tsx',
                ],
                'expected_changed_files' => ['atlas-desktop/src/components/forge/SignupSubmitButton.tsx'],
                'test_command' => 'vitest run components/forge/__tests__/SignupSubmitButton.test.tsx --reporter=basic',
            ]);

            $this->assertSame('ok', $staged['status']);
            $this->assertFileExists($worktree.'/atlas-desktop/package.json');
            $this->assertFileExists($worktree.'/atlas-desktop/vitest.config.ts');
            $this->assertFileExists($worktree.'/atlas-desktop/vitest.setup.ts');
            $setup = (string) file_get_contents($worktree.'/atlas-desktop/vitest.setup.ts');
            $this->assertStringContainsString('import { cleanup } from "@testing-library/react";', $setup);
            $this->assertStringContainsString('import { afterEach } from "vitest";', $setup);
            $this->assertStringContainsString('afterEach(() => {', $setup);
            $this->assertArrayHasKey('atlas-desktop/package.json', $staged['file_hashes']);
            $this->assertArrayHasKey('atlas-desktop/vitest.config.ts', $staged['file_hashes']);

            $scope = new \ReflectionMethod($runReal, 'scopeCheck');
            $scope->setAccessible(true);
            $result = $scope->invoke($runReal, $worktree, [
                'id' => 'frontend-l1-button-loading-state',
                'case_source' => 'provider_arena_corpus',
                'expected_changed_files' => ['atlas-desktop/src/components/forge/SignupSubmitButton.tsx'],
                '_fixture_baseline_hashes' => $staged['file_hashes'],
            ]);

            $this->assertSame([], $result['changed_files']);
            $this->assertSame([], $result['blockers']);
        } finally {
            $this->removeDirectory($worktree);
            $this->removeDirectory('/Users/vitorepf/develop/Atlas-rivals/runs/'.$runId);
        }
    }

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

    public function test_aggregate_receipt_records_out_of_scope_as_arm_contract_failure(): void
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

        $this->assertSame([], $aggregated['workspace_blockers']);
        $this->assertFalse($aggregated['workspace_has_blocking_changes']);
        $this->assertContains('out_of_scope_change:bad/elsewhere.php', $aggregated['arm_contract_blockers']);
        $this->assertTrue($aggregated['arm_contract_has_failures']);
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

    public function test_inter_case_reset_preserves_runtime_files_but_removes_provider_artifacts(): void
    {
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $worktree = sys_get_temp_dir().'/atlas-rivals-runtime-reset-'.bin2hex(random_bytes(6));
        @mkdir($worktree.'/vendor', 0o755, true);
        file_put_contents($worktree.'/tracked.txt', "tracked\n");
        file_put_contents($worktree.'/vendor/autoload.php', "<?php\n// local runtime\n");
        file_put_contents($worktree.'/.env', "APP_ENV=testing\n");
        file_put_contents($worktree.'/.env.testing', "APP_ENV=testing\n");

        (new Process(['git', '-C', $worktree, 'init']))->mustRun();
        (new Process(['git', '-C', $worktree, 'config', 'user.email', 'atlas-rivals@example.test']))->mustRun();
        (new Process(['git', '-C', $worktree, 'config', 'user.name', 'Atlas Rivals']))->mustRun();
        (new Process(['git', '-C', $worktree, 'add', 'tracked.txt']))->mustRun();
        (new Process(['git', '-C', $worktree, 'commit', '-m', 'seed']))->mustRun();

        file_put_contents($worktree.'/provider-output.tmp', "remove me\n");
        file_put_contents($worktree.'/tracked.txt', "provider changed\n");

        try {
            $reset = new \ReflectionMethod($runReal, 'resetWorktree');
            $reset->setAccessible(true);
            $reset->invoke($runReal, $worktree);

            $this->assertFileExists($worktree.'/vendor/autoload.php');
            $this->assertFileExists($worktree.'/.env');
            $this->assertFileExists($worktree.'/.env.testing');
            $this->assertFileDoesNotExist($worktree.'/provider-output.tmp');
            $this->assertSame("tracked\n", file_get_contents($worktree.'/tracked.txt'));
        } finally {
            $this->removeDirectory($worktree);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file instanceof \SplFileInfo && $file->isDir()
                ? @rmdir($file->getPathname())
                : @unlink($file->getPathname());
        }
        @rmdir($path);
    }
}
