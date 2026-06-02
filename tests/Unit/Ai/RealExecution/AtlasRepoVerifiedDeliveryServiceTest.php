<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\RealExecution;

use App\Models\AiJob;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderHealthCheck;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\RealExecution\AtlasRepoVerifiedDeliveryService;
use Mockery;
use Tests\TestCase;

class AtlasRepoVerifiedDeliveryServiceTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            $this->rmrf($d);
        }
        Mockery::close();
        parent::tearDown();
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir.DIRECTORY_SEPARATOR.$f;
            is_dir($p) ? $this->rmrf($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function service(callable $runHandler, object $cleanupSpy): AtlasRepoVerifiedDeliveryService
    {
        $provider = new class($runHandler) implements AiProvider
        {
            public function __construct(private $runHandler) {}

            public function key(): string
            {
                return 'fake';
            }

            public function run(AiJob $job, string $prompt): AiProviderResult
            {
                return ($this->runHandler)($job, $prompt);
            }

            public function runStreaming(AiJob $job, string $prompt, ?callable $onEvent = null): AiProviderResult
            {
                return $this->run($job, $prompt);
            }

            public function health(): AiProviderHealthCheck
            {
                return new AiProviderHealthCheck(true, 'ok', null);
            }
        };

        $mgr = Mockery::mock(AiProviderManager::class);
        $mgr->shouldReceive('get')->andReturn($provider);

        $svc = new AtlasRepoVerifiedDeliveryService($mgr);
        $svc->setWorktreeFactoryForTesting(function () use ($cleanupSpy): array {
            $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'atlas_rvd_test_'.uniqid('', true);
            @mkdir($dir, 0775, true);
            $this->dirs[] = $dir;

            return ['path' => $dir, 'cleanup' => function () use ($cleanupSpy): void {
                $cleanupSpy->called = true;
            }];
        });

        return $svc;
    }

    public function test_writes_both_files_into_the_worktree_and_invokes_cleanup(): void
    {
        $output = "=== FILE: app/Services/Ai/Generated/AtlasGeneratedArtifact.php ===\n<?php\nnamespace App\\Services\\Ai\\Generated;\nclass AtlasGeneratedArtifact { public function v(): int { return 7; } }\n"
            ."=== FILE: tests/Unit/Generated/AtlasGeneratedArtifactTest.php ===\n<?php\nnamespace Tests\\Unit\\Generated;\nuse Tests\\TestCase;\nclass AtlasGeneratedArtifactTest extends TestCase { public function test_v(): void { \$this->assertSame(7, 7); } }\n";
        $spy = (object) ['called' => false];
        $svc = $this->service(fn (): AiProviderResult => new AiProviderResult(true, $output, [], 0, 5, $output, ''), $spy);

        $env = $svc->deliver('a generated artifact + test', [
            'impl_file' => 'app/Services/Ai/Generated/AtlasGeneratedArtifact.php',
            'test_file' => 'tests/Unit/Generated/AtlasGeneratedArtifactTest.php',
        ]);

        // Both files were written into the worktree dir (orchestration proven).
        $wt = end($this->dirs);
        $this->assertFileExists($wt.'/app/Services/Ai/Generated/AtlasGeneratedArtifact.php');
        $this->assertFileExists($wt.'/tests/Unit/Generated/AtlasGeneratedArtifactTest.php');
        // Cleanup always runs (finally), never merges.
        $this->assertTrue($spy->called, 'worktree cleanup must always run');
        $this->assertFalse($env['merged_to_repo']);
        $this->assertSame('app/Services/Ai/Generated/AtlasGeneratedArtifact.php', $env['impl_file']);
        $this->assertArrayHasKey('test_run', $env);
    }

    public function test_blocks_when_provider_omits_impl_or_test(): void
    {
        $output = "=== FILE: app/Services/Ai/Generated/OnlyImpl.php ===\n<?php\nclass OnlyImpl {}\n";
        $spy = (object) ['called' => false];
        $svc = $this->service(fn (): AiProviderResult => new AiProviderResult(true, $output, [], 0, 5, $output, ''), $spy);

        $env = $svc->deliver('only one file', [
            'impl_file' => 'app/Services/Ai/Generated/OnlyImpl.php',
            'test_file' => 'tests/Unit/Generated/OnlyImplTest.php',
        ]);

        $this->assertSame(AtlasRepoVerifiedDeliveryService::STATUS_BLOCKED, $env['status']);
        $this->assertSame('provider_did_not_return_impl_and_test', $env['blocked_reason']);
        $this->assertTrue($spy->called, 'cleanup runs even on a blocked delivery');
    }

    public function test_rejects_unsafe_paths(): void
    {
        $spy = (object) ['called' => false];
        $svc = $this->service(fn (): AiProviderResult => new AiProviderResult(true, '<?php', [], 0, 5, '', ''), $spy);

        $env = $svc->deliver('x', ['impl_file' => '../escape.php', 'test_file' => 'tests/X.php']);

        $this->assertSame('unsafe_target_path', $env['blocked_reason']);
    }

    public function test_self_repair_loop_iterates_up_to_max_attempts_on_failure(): void
    {
        // The stub worktree has no harness, so every attempt's test run fails —
        // proving the loop regenerates up to max_attempts (repair prompt used).
        $calls = (object) ['n' => 0];
        $output = "=== FILE: app/Services/Ai/Generated/X.php ===\n<?php\nnamespace App\\Services\\Ai\\Generated;\nclass X {}\n"
            ."=== FILE: tests/Unit/Generated/XTest.php ===\n<?php\nnamespace Tests\\Unit\\Generated;\nuse Tests\\TestCase;\nclass XTest extends TestCase { public function test_x(): void { \$this->assertTrue(true); } }\n";
        $spy = (object) ['called' => false];
        $svc = $this->service(function () use ($calls, $output): AiProviderResult {
            $calls->n++;

            return new AiProviderResult(true, $output, [], 0, 5, $output, '');
        }, $spy);

        $env = $svc->deliver('iterate', [
            'impl_file' => 'app/Services/Ai/Generated/X.php',
            'test_file' => 'tests/Unit/Generated/XTest.php',
            'max_attempts' => 3,
        ]);

        $this->assertSame(3, $calls->n, 'provider must be re-invoked up to max_attempts on repeated failure');
        $this->assertSame(3, $env['attempt_count']);
        $this->assertSame(AtlasRepoVerifiedDeliveryService::STATUS_BLOCKED, $env['status']);
        $this->assertTrue($spy->called);
    }
}
