<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\HardCaseBench\AtlasLoopHardCaseBenchRunner;
use App\Services\Ai\AutonomousEvolution\HardCaseBench\AtlasLoopHardCaseDatasetRegistry;
use App\Services\Ai\AutonomousEvolution\HardCaseBench\HardCaseBenchResult;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Proves the hard-case bench runner: when the stubbed LoopRunner reproduces the historical failure_signature
 * the run is `fail` and a result file lands on disk; when it produces a different signature the run is
 * `pass`; HardCaseBenchResult has NO score/grade property (reflection-asserted).
 */
final class AtlasLoopHardCaseBenchRunnerTest extends TestCase
{
    private string $registryDir;

    private string $runsDir;

    private AtlasLoopHardCaseDatasetRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(6));
        $this->registryDir = sys_get_temp_dir().'/atlas_hardcase_bench_reg_'.$tag;
        $this->runsDir = sys_get_temp_dir().'/atlas_hardcase_bench_runs_'.$tag;
        mkdir($this->registryDir, 0775, true);
        mkdir($this->runsDir, 0775, true);
        $this->registry = new AtlasLoopHardCaseDatasetRegistry($this->registryDir);
        $this->registry->register([
            'case_id' => 'hc-test-1',
            'slug' => 'demo-slug',
            'captured_at' => '2026-06-24T00:00:00Z',
            'source' => 'give_back',
            'scope_root' => 'app/X',
            'failure_signature' => 'sigA',
            'original_attempt_ledger_digest' => 'led1',
            'minimal_repro_seed' => ['k' => 'v'],
            'expected_failure_mode' => 'sigA',
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->registryDir.'/registry.json');
        @rmdir($this->registryDir);
        foreach (glob($this->runsDir.'/*.json') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->runsDir);
        parent::tearDown();
    }

    private function runner(callable $loopRunner): AtlasLoopHardCaseBenchRunner
    {
        return new AtlasLoopHardCaseBenchRunner(
            registry: $this->registry,
            loopRunner: $loopRunner,
            storageRoot: $this->runsDir,
            clock: static fn (): string => '2026-06-25T00:00:00Z',
        );
    }

    public function test_loop_reproduces_historical_signature_outcome_is_fail_and_file_landed(): void
    {
        $runner = $this->runner(static fn (array $seed): array => ['failure_signature' => 'sigA']);

        $result = $runner->run('hc-test-1');

        $this->assertSame(HardCaseBenchResult::OUTCOME_FAIL, $result->outcome);
        $this->assertSame('sigA', $result->observedFailureSignature);
        $this->assertFileExists($result->evidencePath);
    }

    public function test_loop_handles_case_differently_outcome_is_pass_and_signature_differs(): void
    {
        $runner = $this->runner(static fn (array $seed): array => ['failure_signature' => 'sigB-better']);

        $result = $runner->run('hc-test-1');

        $this->assertSame(HardCaseBenchResult::OUTCOME_PASS, $result->outcome);
        $this->assertNotSame('sigA', $result->observedFailureSignature);
        $this->assertFileExists($result->evidencePath);
    }

    public function test_unknown_case_id_yields_unknown_outcome(): void
    {
        $runner = $this->runner(static fn (array $seed): array => ['failure_signature' => 'anything']);

        $result = $runner->run('hc-NOT-REGISTERED');
        $this->assertSame(HardCaseBenchResult::OUTCOME_UNKNOWN, $result->outcome);
        $this->assertSame('', $result->evidencePath);
    }

    public function test_run_all_iterates_every_case(): void
    {
        $this->registry->register([
            'case_id' => 'hc-test-2',
            'slug' => 'second',
            'captured_at' => '2026-06-24T00:00:00Z',
            'source' => 'cancellation',
            'scope_root' => 'app/Y',
            'failure_signature' => 'sigQ',
            'original_attempt_ledger_digest' => 'led2',
            'minimal_repro_seed' => [],
            'expected_failure_mode' => 'sigQ',
        ]);

        $runner = $this->runner(static fn (array $seed): array => ['failure_signature' => 'sigA']); // matches case 1, not case 2

        $results = $runner->runAll();
        $this->assertCount(2, $results);
        $byId = [];
        foreach ($results as $r) {
            $byId[$r->caseId] = $r;
        }
        $this->assertSame(HardCaseBenchResult::OUTCOME_FAIL, $byId['hc-test-1']->outcome);
        $this->assertSame(HardCaseBenchResult::OUTCOME_PASS, $byId['hc-test-2']->outcome);
    }

    public function test_result_class_has_no_score_or_grade_property(): void
    {
        $reflection = new ReflectionClass(HardCaseBenchResult::class);
        foreach ($reflection->getProperties() as $p) {
            $this->assertDoesNotMatchRegularExpression(
                '/score|grade|rank|percent/i',
                $p->getName(),
                'HardCaseBenchResult must not carry scoring property: '.$p->getName(),
            );
        }
    }

    public function test_runner_throwing_loop_runner_is_captured_as_observed_signature(): void
    {
        $runner = $this->runner(static function (): array { throw new \RuntimeException('engine down'); });

        $result = $runner->run('hc-test-1');
        $this->assertStringContainsString('runner_threw', (string) $result->observedFailureSignature);
    }
}
