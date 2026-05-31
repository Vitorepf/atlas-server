<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\MinimaxFirst;

use App\Services\Ai\Programming\AtlasDev\MinimaxFirst\AtlasCodexPlannerService;
use App\Services\Ai\Programming\AtlasDev\MinimaxFirst\AtlasMinimaxContextCompilerService;
use App\Services\Ai\Programming\AtlasDev\MinimaxFirst\AtlasMinimaxFirstWorkerService;
use App\Services\Ai\Programming\AtlasMinimaxM27CliRuntimeExecutor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AtlasMinimaxFirstWorkerServiceTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }

        parent::tearDown();
    }

    public function test_blocks_large_product_diff_before_spending_repair_call(): void
    {
        $worktree = $this->gitFixture();

        $unsafeProductRewrite = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Demo;

final class BigService
{
    public function score(): int
    {
        return 1;
    }
}
PHP;

        $executor = new class($unsafeProductRewrite) extends AtlasMinimaxM27CliRuntimeExecutor {
            public int $calls = 0;

            public function __construct(private readonly string $content) {}

            public function execute(array $manifest): array
            {
                $this->calls++;

                return [
                    'status' => 'completed',
                    'text' => "// FILE: app/Demo/BigService.php\n".$this->content,
                    'input_tokens' => 100,
                    'output_tokens' => 50,
                    'provider_called' => true,
                    'duration_ms' => 10,
                    'error' => '',
                ];
            }
        };

        $result = $this->service($executor)->run([
            'finding' => [
                'finding_id' => 'factory_max_missing_test_for_big_service',
                'title' => 'Missing test for BigService',
                'description' => 'Add the missing focused unit test without rewriting product behavior.',
            ],
            'allowed_files' => [
                'app/Demo/BigService.php',
                'tests/Unit/Demo/BigServiceTest.php',
            ],
            'validation_commands' => ['false'],
            'worktree_path' => $worktree,
            'repo_root' => $worktree,
            'max_repairs' => 2,
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains(AtlasMinimaxFirstWorkerService::PROVIDER_DIFF_QUALITY_BLOCKER, $result['blockers']);
        $this->assertContains('required_test_update_missing', $result['blockers']);
        $this->assertContains('large_product_deletion_without_test_update', $result['blockers']);
        $this->assertSame(['app/Demo/BigService.php'], $result['files_modified']);
        $this->assertSame(1, $result['run_summary']['provider_call']['provider_calls']);
        $this->assertSame(0, $result['run_summary']['repair_count']);
        $this->assertSame(1, $executor->calls, 'unsafe provider diff must block before a repair provider call is attempted');
        $this->assertFalse($result['diff_quality_gate']['passed']);
        $this->assertFalse($result['diff_quality_gate']['summary']['test_changed']);
    }

    public function test_scores_untracked_product_file_when_required_test_is_missing(): void
    {
        $worktree = $this->gitFixture();

        $newProductFile = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Demo;

final class NewService
{
    public function enabled(): bool
    {
        return true;
    }
}
PHP;

        $executor = new class($newProductFile) extends AtlasMinimaxM27CliRuntimeExecutor {
            public int $calls = 0;

            public function __construct(private readonly string $content) {}

            public function execute(array $manifest): array
            {
                $this->calls++;

                return [
                    'status' => 'completed',
                    'text' => "// FILE: app/Demo/NewService.php\n".$this->content,
                    'input_tokens' => 30,
                    'output_tokens' => 20,
                    'provider_called' => true,
                    'duration_ms' => 10,
                    'error' => '',
                ];
            }
        };

        $result = $this->service($executor)->run([
            'finding' => [
                'finding_id' => 'factory_max_missing_test_for_new_service',
                'title' => 'Add missing unit test for NewService',
                'expected_test_path' => 'tests/Unit/Demo/NewServiceTest.php',
            ],
            'allowed_files' => [
                'app/Demo/NewService.php',
                'tests/Unit/Demo/NewServiceTest.php',
            ],
            'validation_commands' => ['false'],
            'worktree_path' => $worktree,
            'repo_root' => $worktree,
            'max_repairs' => 2,
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('required_test_update_missing', $result['blockers']);
        $this->assertSame(['app/Demo/NewService.php'], $result['files_modified']);
        $this->assertSame(['app/Demo/NewService.php'], $result['diff_quality_gate']['summary']['product_changed_files']);
        $this->assertSame(1, $executor->calls);
    }

    public function test_quality_gate_uses_real_worktree_changes_when_provider_payload_is_partial(): void
    {
        $worktree = $this->gitFixture();

        $smallReplacement = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Demo;

final class BigService
{
    public function method1(): int
    {
        return 1;
    }
}
PHP;

        $updatedTest = <<<'PHP'
<?php

declare(strict_types=1);

namespace Tests\Unit\Demo;

use PHPUnit\Framework\TestCase;

final class BigServiceTest extends TestCase
{
    public function test_method_one_runtime_signal(): void
    {
        self::assertSame(1, 1);
    }
}
PHP;

        $executor = new class($smallReplacement, $updatedTest) extends AtlasMinimaxM27CliRuntimeExecutor {
            public int $calls = 0;

            public function __construct(
                private readonly string $productContent,
                private readonly string $testContent,
            ) {}

            public function execute(array $manifest): array
            {
                $this->calls++;
                file_put_contents(
                    rtrim((string) $manifest['workspace_path'], '/').'/tests/Unit/Demo/BigServiceTest.php',
                    $this->testContent,
                );

                return [
                    'status' => 'completed',
                    'text' => "// FILE: app/Demo/BigService.php\n".$this->productContent,
                    'input_tokens' => 100,
                    'output_tokens' => 50,
                    'provider_called' => true,
                    'duration_ms' => 10,
                    'error' => '',
                ];
            }
        };

        $result = $this->service($executor)->run([
            'finding' => [
                'finding_id' => 'factory_max_runtime_signal_for_big_service',
                'title' => 'Update runtime signal for BigService with focused test',
                'expected_test_path' => 'tests/Unit/Demo/BigServiceTest.php',
            ],
            'allowed_files' => [
                'app/Demo/BigService.php',
                'tests/Unit/Demo/BigServiceTest.php',
            ],
            'validation_commands' => [],
            'worktree_path' => $worktree,
            'repo_root' => $worktree,
            'max_repairs' => 2,
        ]);

        $files = $result['files_modified'];
        sort($files);

        $this->assertSame('completed', $result['status']);
        $this->assertSame([
            'app/Demo/BigService.php',
            'tests/Unit/Demo/BigServiceTest.php',
        ], $files);
        $this->assertSame(1, $executor->calls);
    }

    public function test_blocks_large_test_deletion_before_spending_repair_call(): void
    {
        $worktree = $this->gitFixture();
        $this->commitLargeExistingTest($worktree);

        $destructiveTestRewrite = <<<'PHP'
<?php

declare(strict_types=1);

namespace Tests\Unit\Demo;

use PHPUnit\Framework\TestCase;

final class BigServiceTest extends TestCase
{
    public function test_single_signal(): void
    {
        self::assertTrue(true);
    }
}
PHP;

        $executor = new class($destructiveTestRewrite) extends AtlasMinimaxM27CliRuntimeExecutor {
            public int $calls = 0;

            public function __construct(private readonly string $content) {}

            public function execute(array $manifest): array
            {
                $this->calls++;

                return [
                    'status' => 'completed',
                    'text' => "// FILE: tests/Unit/Demo/BigServiceTest.php\n".$this->content,
                    'input_tokens' => 100,
                    'output_tokens' => 50,
                    'provider_called' => true,
                    'duration_ms' => 10,
                    'error' => '',
                ];
            }
        };

        $result = $this->service($executor)->run([
            'finding' => [
                'finding_id' => 'factory_max_runtime_signal_for_big_service',
                'title' => 'Add an E2E contract test count gate for BigService',
                'expected_test_path' => 'tests/Unit/Demo/BigServiceTest.php',
            ],
            'allowed_files' => [
                'tests/Unit/Demo/BigServiceTest.php',
            ],
            'validation_commands' => ['false'],
            'worktree_path' => $worktree,
            'repo_root' => $worktree,
            'max_repairs' => 2,
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains(AtlasMinimaxFirstWorkerService::PROVIDER_DIFF_QUALITY_BLOCKER, $result['blockers']);
        $this->assertContains('large_test_deletion', $result['blockers']);
        $this->assertSame(['tests/Unit/Demo/BigServiceTest.php'], $result['files_modified']);
        $this->assertSame(1, $result['run_summary']['provider_call']['provider_calls']);
        $this->assertSame(0, $result['run_summary']['repair_count']);
        $this->assertSame(1, $executor->calls, 'destructive test deletion must block before repair provider calls');
        $this->assertFalse($result['diff_quality_gate']['passed']);
        $this->assertGreaterThanOrEqual(80, $result['diff_quality_gate']['summary']['test_deletions']);
        $this->assertNotEmpty($result['diff_quality_gate']['summary']['large_deleted_test_files']);
    }

    private function service(AtlasMinimaxM27CliRuntimeExecutor $executor): AtlasMinimaxFirstWorkerService
    {
        $planner = new AtlasCodexPlannerService();
        $planner->setProcessFactoryForTesting(
            static fn (array $cmd, string $cwd, array $env, int $timeout): Process => new Process([PHP_BINARY, '-r', 'exit(1);'], $cwd, $env, null, (float) $timeout),
        );

        return new AtlasMinimaxFirstWorkerService(
            $executor,
            $planner,
            new AtlasMinimaxContextCompilerService(),
        );
    }

    private function gitFixture(): string
    {
        $dir = rtrim((string) realpath(sys_get_temp_dir()), '/').'/atlas-mmfirst-worker-'.bin2hex(random_bytes(6));
        $this->tempDirs[] = $dir;

        mkdir($dir.'/app/Demo', 0777, true);
        mkdir($dir.'/tests/Unit/Demo', 0777, true);

        file_put_contents($dir.'/app/Demo/BigService.php', $this->largeService());
        file_put_contents($dir.'/tests/Unit/Demo/BigServiceTest.php', $this->existingTest());

        $this->runProcess(['git', 'init', '-q'], $dir);
        $this->runProcess(['git', 'config', 'user.email', 'atlas-test@example.test'], $dir);
        $this->runProcess(['git', 'config', 'user.name', 'Atlas Test'], $dir);
        $this->runProcess(['git', 'add', 'app/Demo/BigService.php', 'tests/Unit/Demo/BigServiceTest.php'], $dir);
        $this->runProcess(['git', 'commit', '-q', '-m', 'initial fixture'], $dir);

        return $dir;
    }

    private function commitLargeExistingTest(string $worktree): void
    {
        file_put_contents($worktree.'/tests/Unit/Demo/BigServiceTest.php', $this->largeExistingTest());
        $this->runProcess(['git', 'add', 'tests/Unit/Demo/BigServiceTest.php'], $worktree);
        $this->runProcess(['git', 'commit', '-q', '-m', 'large existing test fixture'], $worktree);
    }

    private function largeService(): string
    {
        $methods = [];
        foreach (range(1, 120) as $index) {
            $methods[] = "    public function method{$index}(): int\n    {\n        return {$index};\n    }\n";
        }

        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Demo;\n\nfinal class BigService\n{\n"
            .implode("\n", $methods)
            ."}\n";
    }

    private function existingTest(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace Tests\Unit\Demo;

use PHPUnit\Framework\TestCase;

final class BigServiceTest extends TestCase
{
    public function test_placeholder(): void
    {
        self::assertTrue(true);
    }
}
PHP;
    }

    private function largeExistingTest(): string
    {
        $methods = [];
        foreach (range(1, 120) as $index) {
            $methods[] = "    public function test_signal_{$index}(): void\n    {\n        self::assertSame({$index}, {$index});\n    }\n";
        }

        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace Tests\\Unit\\Demo;\n\nuse PHPUnit\\Framework\\TestCase;\n\nfinal class BigServiceTest extends TestCase\n{\n"
            .implode("\n", $methods)
            ."}\n";
    }

    /**
     * @param  list<string>  $cmd
     */
    private function runProcess(array $cmd, string $cwd): void
    {
        $process = new Process($cmd, $cwd, null, null, 30.0);
        $process->run();

        if (! $process->isSuccessful()) {
            self::fail(implode(' ', $cmd).' failed: '.$process->getOutput().$process->getErrorOutput());
        }
    }

    private function removeDir(string $dir): void
    {
        if ($dir === '' || ! is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir() && ! $item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }
}
