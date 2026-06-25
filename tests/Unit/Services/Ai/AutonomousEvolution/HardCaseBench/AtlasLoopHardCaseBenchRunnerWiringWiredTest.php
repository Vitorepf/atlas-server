<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\AutonomousEvolution\HardCaseBench;

use App\Services\Ai\AutonomousEvolution\HardCaseBench\AtlasLoopHardCaseBenchRunner;
use App\Services\Ai\AutonomousEvolution\HardCaseBench\AtlasLoopHardCaseDatasetRegistry;
use App\Services\Ai\AutonomousEvolution\HardCaseBench\HardCaseBenchResult;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * brain-orphan-38d608f98933 — prove HardCaseBenchResult is wired through the live
 * `atlas:loop:bench summary` CLI action (the new operator-facing tally) and the existing
 * `run` action — both consume the typed value object, not just toArray().
 */
class AtlasLoopHardCaseBenchRunnerWiringWiredTest extends TestCase
{
    private string $storageRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = sys_get_temp_dir().'/atlas-hard-bench-'.bin2hex(random_bytes(4));
        mkdir($this->storageRoot, 0o755, true);

        // Seed a registry with one synthetic case and bind a runner whose loop-runner stub
        // emits a deliberately divergent failure signature → outcome=pass.
        $registry = new AtlasLoopHardCaseDatasetRegistry();
        $registry->setStorageRootForTesting($this->storageRoot);
        $registry->register([
            'case_id' => 'demo-case',
            'slug' => 'demo-case',
            'captured_at' => '2026-06-25T00:00:00Z',
            'source' => 'give_back',
            'scope_root' => 'app/',
            'failure_signature' => 'historical_signature',
            'original_attempt_ledger_digest' => str_repeat('0', 64),
            'minimal_repro_seed' => ['k' => 1],
            'expected_failure_mode' => 'historical_signature',
        ]);
        app()->instance(AtlasLoopHardCaseDatasetRegistry::class, $registry);

        $runner = new AtlasLoopHardCaseBenchRunner(
            registry: $registry,
            loopRunner: static fn (array $seed): array => ['failure_signature' => 'something-different'],
            storageRoot: $this->storageRoot,
            clock: static fn (): string => '2026-06-25T12:00:00Z',
        );
        app()->instance(AtlasLoopHardCaseBenchRunner::class, $runner);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageRoot)) {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->storageRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iter as $entry) {
                /** @var \SplFileInfo $entry */
                $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
            @rmdir($this->storageRoot);
        }
        parent::tearDown();
    }

    public function test_runner_returns_typed_hard_case_bench_result_instances(): void
    {
        /** @var AtlasLoopHardCaseBenchRunner $runner */
        $runner = app(AtlasLoopHardCaseBenchRunner::class);
        $result = $runner->run('demo-case');

        self::assertInstanceOf(HardCaseBenchResult::class, $result);
        self::assertSame('demo-case', $result->caseId);
        self::assertSame(HardCaseBenchResult::OUTCOME_PASS, $result->outcome);
        self::assertSame('something-different', $result->observedFailureSignature);
    }

    public function test_cli_summary_action_invokes_the_runner_and_emits_typed_payload(): void
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:loop:bench', [
            'action' => 'summary',
            '--json' => true,
        ], $buf);

        self::assertSame(0, $exit);
        $payload = json_decode(trim($buf->fetch()), true);
        self::assertIsArray($payload);
        self::assertSame('ok', $payload['status']);
        self::assertSame(1, $payload['total_results']);
        self::assertSame(1, $payload['outcome_tally'][HardCaseBenchResult::OUTCOME_PASS]);
        self::assertNotEmpty($payload['results']);
        self::assertSame('demo-case', $payload['results'][0]['case_id']);
    }

    public function test_cli_run_action_invokes_runner_and_serializes_result_value_object(): void
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:loop:bench', [
            'action' => 'run',
            '--case' => 'demo-case',
            '--json' => true,
        ], $buf);

        self::assertSame(0, $exit);
        $rows = json_decode(trim($buf->fetch()), true);
        self::assertIsArray($rows);
        self::assertSame('demo-case', $rows[0]['case_id']);
        self::assertSame(HardCaseBenchResult::OUTCOME_PASS, $rows[0]['outcome']);
    }

    public function test_command_source_imports_and_consumes_the_value_object(): void
    {
        $source = (string) file_get_contents(
            base_path('app/Console/Commands/AtlasLoopBenchCommand.php')
        );
        self::assertStringContainsString(HardCaseBenchResult::class, $source);
        self::assertStringContainsString('HardCaseBenchResult::OUTCOME_PASS', $source);
        self::assertStringContainsString("'summary'", $source);
    }
}
