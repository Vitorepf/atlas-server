<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\MinimaxFirst;

use App\Models\AiJob;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderHealthCheck;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\Programming\AtlasDev\MinimaxFirst\AtlasCodexPlannerService;
use App\Services\Ai\Programming\AtlasDev\MinimaxFirst\AtlasMinimaxContextCompilerService;
use App\Services\Ai\Programming\AtlasDev\MinimaxFirst\AtlasMinimaxFirstWorkerService;
use App\Services\Ai\Programming\AtlasMinimaxM27CliRuntimeExecutor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * WIRE-OBSERVE pin: the provider diff quality gate envelope
 * (atlas.dev.minimax_first.provider_diff_quality_gate.v1) now carries the
 * advisory `destructive_test_coverage_removal` contract computed from the same
 * per-file numstat the gate already parses. The passed/blockers verdict is
 * byte-identical to before.
 */
final class AtlasMinimaxFirstWorkerDestructiveTestRemovalWireTest extends TestCase
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

    public function test_test_shrinking_diff_records_coverage_removed_contract_on_the_gate_envelope(): void
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
            public function __construct(private readonly string $content) {}

            public function execute(array $manifest): array
            {
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

        // Existing verdict byte-identical: the large deletion still blocks.
        $this->assertSame('blocked', $result['status']);
        $this->assertContains('large_test_deletion', $result['blockers']);

        $contract = $result['diff_quality_gate']['destructive_test_coverage_removal'];
        $this->assertIsArray($contract);
        $this->assertSame(
            'atlas.software_company_stewardship.destructive_test_coverage_removal.v1',
            $contract['schema_version'],
        );
        // Inputs mirror the gate's own numstat summary for this diff.
        $this->assertSame(
            $result['diff_quality_gate']['summary']['test_deletions'],
            $contract['inputs']['test_deletions'],
        );
        $this->assertGreaterThan($contract['inputs']['test_insertions'], $contract['inputs']['test_deletions']);
        $this->assertSame(0, $contract['inputs']['product_insertions']);
        $this->assertSame(0, $contract['inputs']['test_files_deleted_count'], 'file rewritten, not deleted');
        // Property: net test-line drop with zero product growth => coverage removed.
        $this->assertSame('coverage_removed', $contract['outputs']['verdict']);
        $this->assertTrue($contract['outputs']['coverage_removed']);
        $this->assertContains(
            'net_test_lines_dropped_without_product_growth',
            $contract['outputs']['trigger_reasons'],
        );
    }

    private function service(AtlasMinimaxM27CliRuntimeExecutor $executor): AtlasMinimaxFirstWorkerService
    {
        $planner = new AtlasCodexPlannerService($this->codexManagerReturning(new AiProviderResult(
            ok: false,
            output: '',
            command: ['codex'],
            exitCode: 1,
            durationMs: 1,
            stdout: '',
            stderr: 'planner disabled for worker tests',
            errorCode: 'planner_unavailable',
        )));

        return new AtlasMinimaxFirstWorkerService(
            $executor,
            $planner,
            new AtlasMinimaxContextCompilerService(),
        );
    }

    private function codexManagerReturning(AiProviderResult $result): AiProviderManager
    {
        $provider = new class($result) implements AiProvider {
            public function __construct(private readonly AiProviderResult $result) {}

            public function key(): string
            {
                return 'codex_cli';
            }

            public function run(AiJob $job, string $prompt): AiProviderResult
            {
                return $this->result;
            }

            public function runStreaming(AiJob $job, string $prompt, ?callable $onEvent = null): AiProviderResult
            {
                return $this->run($job, $prompt);
            }

            public function health(): AiProviderHealthCheck
            {
                return new AiProviderHealthCheck('codex_cli', 'online', 'test provider');
            }
        };

        return new class($provider) extends AiProviderManager {
            public function __construct(private readonly AiProvider $provider) {}

            public function get(?string $provider = null, bool $skipCoverage = false): AiProvider
            {
                return $this->provider;
            }
        };
    }

    private function gitFixture(): string
    {
        $dir = rtrim((string) realpath(sys_get_temp_dir()), '/').'/atlas-mmfirst-dtcr-'.bin2hex(random_bytes(6));
        $this->tempDirs[] = $dir;

        mkdir($dir.'/app/Demo', 0777, true);
        mkdir($dir.'/tests/Unit/Demo', 0777, true);

        file_put_contents($dir.'/app/Demo/BigService.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Demo;\n\nfinal class BigService\n{\n    public function go(): int\n    {\n        return 1;\n    }\n}\n");
        file_put_contents($dir.'/tests/Unit/Demo/BigServiceTest.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Tests\\Unit\\Demo;\n\nuse PHPUnit\\Framework\\TestCase;\n\nfinal class BigServiceTest extends TestCase\n{\n    public function test_placeholder(): void\n    {\n        self::assertTrue(true);\n    }\n}\n");

        $this->runProcess(['git', 'init', '-q'], $dir);
        $this->runProcess(['git', 'config', 'user.email', 'atlas-test@example.test'], $dir);
        $this->runProcess(['git', 'config', 'user.name', 'Atlas Test'], $dir);
        $this->runProcess(['git', 'add', 'app/Demo/BigService.php', 'tests/Unit/Demo/BigServiceTest.php'], $dir);
        $this->runProcess(['git', 'commit', '-q', '-m', 'initial fixture'], $dir);

        return $dir;
    }

    private function commitLargeExistingTest(string $worktree): void
    {
        $methods = [];
        foreach (range(1, 120) as $index) {
            $methods[] = "    public function test_signal_{$index}(): void\n    {\n        self::assertSame({$index}, {$index});\n    }\n";
        }
        $large = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Tests\\Unit\\Demo;\n\nuse PHPUnit\\Framework\\TestCase;\n\nfinal class BigServiceTest extends TestCase\n{\n"
            .implode("\n", $methods)
            ."}\n";

        file_put_contents($worktree.'/tests/Unit/Demo/BigServiceTest.php', $large);
        $this->runProcess(['git', 'add', 'tests/Unit/Demo/BigServiceTest.php'], $worktree);
        $this->runProcess(['git', 'commit', '-q', '-m', 'large existing test fixture'], $worktree);
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
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
