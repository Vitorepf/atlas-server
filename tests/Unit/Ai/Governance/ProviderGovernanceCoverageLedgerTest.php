<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Governance;

use App\Services\Ai\AiProviderManager;
use App\Services\Ai\Policy\AtlasAiRuntimeSettings;
use App\Services\Ai\ClaudeCliProvider;
use App\Services\Ai\CodexCliProvider;
use App\Services\Ai\GeminiCliProvider;
use App\Services\Ai\Governance\ProviderGovernanceCoverageLedger;
use App\Services\Ai\HermesCliProvider;
use App\Services\Ai\JarvisMlxProvider;
use App\Services\Ai\MinimaxM27CliProvider;
use App\Services\Ai\Programming\AtlasForgeProviderProcessRunner;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * SLICE 1 gate — the bypass meter is REAL and honest.
 *
 * Proves: a manager resolution counts as COVERED, a muscle spawn counts as
 * BYPASS, the rate math is correct, and an empty ledger reports a 0-baseline
 * (never fabricated). Pure PHPUnit — no DB, no Laravel boot.
 */
final class ProviderGovernanceCoverageLedgerTest extends TestCase
{
    // ponytail: extends Tests\TestCase (not plain PHPUnit) only because
    // AiProviderManager's constructor reads config(); the ledger + runner cases
    // are DB-free. No RefreshDatabase — this suite never touches the database.

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas_provider_coverage_'.uniqid('', true).'/coverage.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @rmdir(dirname($this->path));
        parent::tearDown();
    }

    private function ledger(): ProviderGovernanceCoverageLedger
    {
        $ledger = new ProviderGovernanceCoverageLedger;
        $ledger->setLogPathForTesting($this->path);

        return $ledger;
    }

    public function test_empty_ledger_reports_honest_zero_baseline(): void
    {
        $summary = $this->ledger()->summary();

        self::assertSame(0, $summary['total']);
        self::assertSame(0, $summary['covered']);
        self::assertSame(0, $summary['bypass']);
        self::assertSame(0.0, $summary['bypass_rate']);
        self::assertSame(0.0, $summary['covered_rate']);
    }

    public function test_covered_and_bypass_records_produce_a_real_rate(): void
    {
        $ledger = $this->ledger();
        $ledger->recordCovered('hermes_cli');
        $ledger->recordBypass('claude_cli', ProviderGovernanceCoverageLedger::SURFACE_DEV_CLAUDE_GATEWAY);

        $summary = $ledger->summary();

        self::assertSame(2, $summary['total']);
        self::assertSame(1, $summary['covered']);
        self::assertSame(1, $summary['bypass']);
        self::assertSame(0.5, $summary['bypass_rate']);
        self::assertSame(0.5, $summary['covered_rate']);
        self::assertSame(1, $summary['by_surface'][ProviderGovernanceCoverageLedger::SURFACE_DEV_CLAUDE_GATEWAY]['bypass']);
    }

    public function test_reset_clears_the_window(): void
    {
        $ledger = $this->ledger();
        $ledger->recordBypass('codex_cli', ProviderGovernanceCoverageLedger::SURFACE_FORGE_PROCESS_RUNNER);
        self::assertSame(1, $ledger->summary()['total']);

        $ledger->reset();
        self::assertSame(0, $ledger->summary()['total']);
    }

    public function test_manager_resolution_is_counted_as_covered(): void
    {
        $ledger = $this->ledger();
        $hermes = $this->createMock(HermesCliProvider::class);
        $settings = $this->createMock(AtlasAiRuntimeSettings::class);
        $settings->method('defaultProvider')->willReturn('hermes_cli');

        $manager = new AiProviderManager(
            $this->createMock(ClaudeCliProvider::class),
            $this->createMock(CodexCliProvider::class),
            $this->createMock(GeminiCliProvider::class),
            $this->createMock(JarvisMlxProvider::class),
            $hermes,
            $this->createMock(MinimaxM27CliProvider::class),
            $settings,
        );
        $manager->setCoverageLedger($ledger);

        // A resolution through the governed manager (get) must be COVERED.
        self::assertSame($hermes, $manager->get('hermes_cli'));

        $summary = $ledger->summary();
        self::assertSame(1, $summary['covered']);
        self::assertSame(0, $summary['bypass']);
        self::assertSame(1, $summary['by_surface'][ProviderGovernanceCoverageLedger::SURFACE_MANAGER]['covered']);
    }

    public function test_unwired_manager_records_nothing(): void
    {
        $hermes = $this->createMock(HermesCliProvider::class);
        $settings = $this->createMock(AtlasAiRuntimeSettings::class);
        $settings->method('defaultProvider')->willReturn('hermes_cli');

        $manager = new AiProviderManager(
            $this->createMock(ClaudeCliProvider::class),
            $this->createMock(CodexCliProvider::class),
            $this->createMock(GeminiCliProvider::class),
            $this->createMock(JarvisMlxProvider::class),
            $hermes,
            $this->createMock(MinimaxM27CliProvider::class),
            $settings,
        );

        // No coverage ledger wired => byte-identical, no ledger file created.
        self::assertSame($hermes, $manager->get('hermes_cli'));
        self::assertFileDoesNotExist($this->path);
    }

    public function test_muscle_spawn_is_counted_as_bypass(): void
    {
        $ledger = $this->ledger();
        $runner = new AtlasForgeProviderProcessRunner($ledger);
        $runner->setProcessFactory(
            static fn (array $argv, ?string $cwd, ?array $env, int $timeout): Process => new Process(['true']),
        );

        $runner->run([
            'argv' => ['codex', 'exec'],
            'provider' => 'codex_cli',
            'timeout_seconds' => 5,
        ]);

        $summary = $ledger->summary();
        self::assertSame(0, $summary['covered']);
        self::assertSame(1, $summary['bypass']);
        self::assertSame(1, $summary['by_surface'][ProviderGovernanceCoverageLedger::SURFACE_FORGE_PROCESS_RUNNER]['bypass']);
        self::assertSame(1, $summary['by_provider']['codex_cli']['bypass']);
    }

    public function test_blocked_spawn_records_no_bypass(): void
    {
        $ledger = $this->ledger();
        $runner = new AtlasForgeProviderProcessRunner($ledger);

        // Empty argv => blocked BEFORE any spawn => not a real execution => no record.
        $runner->run(['argv' => [], 'provider' => 'codex_cli']);

        self::assertSame(0, $ledger->summary()['total']);
    }
}
