<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\HardCaseBench\AtlasLoopHardCaseBenchRunner;
use App\Services\Ai\AutonomousEvolution\HardCaseBench\AtlasLoopHardCaseCoverageReporter;
use App\Services\Ai\AutonomousEvolution\HardCaseBench\AtlasLoopHardCaseDatasetRegistry;
use App\Services\Ai\AutonomousEvolution\HardCaseBench\HardCaseBenchResult;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the atlas:loop:bench CLI: list / run / coverage subcommands all return JSON envelopes and exit
 * cleanly against a seeded fixture registry + stubbed bench runner + stubbed coverage reporter.
 */
final class AtlasLoopBenchCommandTest extends TestCase
{
    private string $tmpDir;

    private string $runsDir;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(6));
        $this->tmpDir = sys_get_temp_dir().'/atlas_bench_cli_reg_'.$tag;
        $this->runsDir = sys_get_temp_dir().'/atlas_bench_cli_runs_'.$tag;
        mkdir($this->tmpDir, 0775, true);
        mkdir($this->runsDir, 0775, true);

        $registry = new AtlasLoopHardCaseDatasetRegistry($this->tmpDir);
        $registry->register([
            'case_id' => 'fixture-case-1', 'slug' => 'f', 'captured_at' => 't', 'source' => 'give_back',
            'scope_root' => 'app/Demo', 'failure_signature' => 'sigF',
            'original_attempt_ledger_digest' => 'l', 'minimal_repro_seed' => [], 'expected_failure_mode' => 'sigF',
        ]);
        $this->app->instance(AtlasLoopHardCaseDatasetRegistry::class, $registry);

        $runner = new AtlasLoopHardCaseBenchRunner(
            registry: $registry,
            loopRunner: static fn (array $seed): array => ['failure_signature' => 'sigDIFFERENT'],
            storageRoot: $this->runsDir,
            clock: static fn (): string => '2026-06-25T00:00:00Z',
        );
        $this->app->instance(AtlasLoopHardCaseBenchRunner::class, $runner);

        $reporter = new AtlasLoopHardCaseCoverageReporter(
            registry: $registry,
            capabilitiesSource: static fn (): array => ['originate', 'decompose', 'certify', 'merge'],
            lastRunOutcomeProvider: static fn (string $caseId): ?string => null,
        );
        $this->app->instance(AtlasLoopHardCaseCoverageReporter::class, $reporter);
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpDir.'/registry.json');
        @rmdir($this->tmpDir);
        foreach (glob($this->runsDir.'/*.json') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->runsDir);
        parent::tearDown();
    }

    public function test_artisan_command_is_registered(): void
    {
        $this->assertArrayHasKey('atlas:loop:bench', Artisan::all());
    }

    public function test_list_action_emits_json_array_of_registry_cases(): void
    {
        $exit = Artisan::call('atlas:loop:bench', ['action' => 'list', '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
        $this->assertSame('fixture-case-1', $decoded[0]['case_id']);
    }

    public function test_run_action_emits_outcome_row_for_specified_case(): void
    {
        $exit = Artisan::call('atlas:loop:bench', ['action' => 'run', '--case' => 'fixture-case-1', '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertCount(1, $decoded);
        $this->assertSame('fixture-case-1', $decoded[0]['case_id']);
        // The stub LoopRunner produced a DIFFERENT signature from expected ⇒ outcome = pass.
        $this->assertSame(HardCaseBenchResult::OUTCOME_PASS, $decoded[0]['outcome']);
    }

    public function test_coverage_action_emits_capabilities_with_zero_cases_key(): void
    {
        $exit = Artisan::call('atlas:loop:bench', ['action' => 'coverage', '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertArrayHasKey('capabilities_with_zero_cases', $decoded);
        $this->assertIsArray($decoded['capabilities_with_zero_cases']);
        // give_back maps to 'originate' by default, so decompose/certify/merge should all be blind spots.
        foreach (['decompose', 'certify', 'merge'] as $expected) {
            $this->assertContains($expected, $decoded['capabilities_with_zero_cases']);
        }
    }

    public function test_list_action_filters_by_source(): void
    {
        $exit = Artisan::call('atlas:loop:bench', ['action' => 'list', '--source' => 'cancellation', '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame([], $decoded, 'no cancellation cases in fixture ⇒ empty list');
    }

    public function test_unknown_action_returns_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:bench', ['action' => 'bogus', '--json' => true]);
        $this->assertNotSame(0, $exit);
        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertSame('usage_error', $decoded['status']);
    }
}
