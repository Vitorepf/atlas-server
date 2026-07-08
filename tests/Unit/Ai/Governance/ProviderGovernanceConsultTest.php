<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Governance;

use App\Services\Ai\AtlasDecide\AtlasDecideGatewayConsultationService;
use App\Services\Ai\AtlasDecide\AtlasDecideMetaLearningService;
use App\Services\Ai\Caching\AiCallCostGuard;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Governance\ProviderGovernanceConsult;
use App\Services\Ai\Governance\ProviderGovernanceCoverageLedger;
use App\Services\Ai\Programming\AtlasForgeProviderProcessRunner;
use App\Services\Ai\Tokens\AtlasTokenEconomyBudgetPolicyService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * A stub ADML with no learned route — keeps the consult DB-free.
 */
final class StubAdmlForConsult extends AtlasDecideMetaLearningService
{
    public function __construct() {}

    public function activeRouteFor(string $taskCategory, string $role, ?string $framework = null): ?array
    {
        return null;
    }
}

/**
 * SLICE 2 gate — the muscle paths consult the SAME cost-guard + ADML seam, the
 * governed rate rises off the SLICE 1 bypass baseline, and enforce-OFF preserves
 * today's behavior (advisory only). No DB.
 */
final class ProviderGovernanceConsultTest extends TestCase
{
    private string $coveragePath;

    private string $admlPath;

    protected function setUp(): void
    {
        parent::setUp();
        $u = uniqid('', true);
        $this->coveragePath = sys_get_temp_dir()."/atlas_gov_consult_{$u}/coverage.jsonl";
        $this->admlPath = sys_get_temp_dir()."/atlas_gov_consult_{$u}/adml.jsonl";
        // Default OFF unless a test flips it; a hard threshold so enforce CAN bite.
        config(['atlas.ai.governance.enforce' => false]);
        config(['atlas.ai.cache.cost_guard' => ['soft_units' => 0.0, 'hard_units' => 0.0]]);
    }

    protected function tearDown(): void
    {
        @unlink($this->coveragePath);
        @unlink($this->admlPath);
        @rmdir(dirname($this->coveragePath));
        parent::tearDown();
    }

    private function coverage(): ProviderGovernanceCoverageLedger
    {
        $c = new ProviderGovernanceCoverageLedger;
        $c->setLogPathForTesting($this->coveragePath);

        return $c;
    }

    private function seam(ProviderGovernanceCoverageLedger $coverage): ProviderGovernanceConsult
    {
        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting($this->admlPath.'.kernel');
        $admission = new AtlasAutonomyAdmissionService($kernel);
        $adml = new AtlasDecideGatewayConsultationService(new StubAdmlForConsult, $kernel, $admission);
        $adml->setLogPathForTesting($this->admlPath);

        return new ProviderGovernanceConsult(
            $adml,
            new AiCallCostGuard(new AtlasTokenEconomyBudgetPolicyService),
            $coverage,
        );
    }

    public function test_consulting_a_muscle_spawn_records_governed_not_bypass(): void
    {
        $coverage = $this->coverage();
        $advisory = $this->seam($coverage)->consultBeforeSpawn([
            'provider' => 'codex_cli',
            'surface' => ProviderGovernanceCoverageLedger::SURFACE_FORGE_PROCESS_RUNNER,
            'prompt' => 'refactor the widget',
        ]);

        // The seam actually consulted cost-guard + ADML.
        self::assertNotSame('', $advisory['adml_verdict']);
        self::assertArrayHasKey('pre_cost_units', $advisory['cost']);

        $summary = $coverage->summary();
        self::assertSame(1, $summary['consulted']);
        self::assertSame(0, $summary['bypass']);
        self::assertSame(1, $summary['governed']);
        self::assertSame(1.0, $summary['governed_rate']);
    }

    public function test_governed_rate_rises_from_the_bypass_baseline(): void
    {
        $coverage = $this->coverage();
        // SLICE 1 baseline: a blind muscle spawn.
        $coverage->recordBypass('codex_cli', ProviderGovernanceCoverageLedger::SURFACE_FORGE_PROCESS_RUNNER);
        self::assertSame(1.0, $coverage->summary()['bypass_rate']);
        self::assertSame(0.0, $coverage->summary()['governed_rate']);

        // SLICE 2: the next execution consults the shared seam.
        $this->seam($coverage)->consultBeforeSpawn([
            'provider' => 'codex_cli',
            'surface' => ProviderGovernanceCoverageLedger::SURFACE_FORGE_PROCESS_RUNNER,
            'prompt' => 'fix the bug',
        ]);

        $summary = $coverage->summary();
        self::assertSame(2, $summary['total']);
        self::assertSame(0.5, $summary['bypass_rate']);   // dropped from 1.0
        self::assertSame(0.5, $summary['governed_rate']); // rose from 0.0
    }

    public function test_enforce_off_is_advisory_and_never_blocks(): void
    {
        // Enforce OFF (default) + a hard threshold that WOULD bite if enforcing.
        config(['atlas.ai.governance.enforce' => false]);
        config(['atlas.ai.cache.cost_guard' => ['soft_units' => 0.0, 'hard_units' => 0.0001]]);

        $advisory = $this->seam($this->coverage())->consultBeforeSpawn([
            'provider' => 'codex_cli',
            'surface' => ProviderGovernanceCoverageLedger::SURFACE_FORGE_PROCESS_RUNNER,
            'prompt' => str_repeat('expensive prompt ', 200),
        ]);

        self::assertFalse($advisory['enforce']);
        self::assertFalse($advisory['should_block']);
    }

    public function test_enforce_on_blocks_only_when_hard_threshold_exceeded(): void
    {
        // Operator flips enforce ON and sets a tiny hard threshold.
        config(['atlas.ai.governance.enforce' => true]);
        config(['atlas.ai.cache.cost_guard' => ['soft_units' => 0.0, 'hard_units' => 0.0001]]);

        $advisory = $this->seam($this->coverage())->consultBeforeSpawn([
            'provider' => 'codex_cli',
            'surface' => ProviderGovernanceCoverageLedger::SURFACE_FORGE_PROCESS_RUNNER,
            'prompt' => str_repeat('expensive prompt ', 200),
        ]);

        self::assertTrue($advisory['enforce']);
        self::assertTrue($advisory['should_block']);
        self::assertSame('cost_guard_hard_exceeded', $advisory['reason']);
    }

    public function test_process_runner_defers_to_seam_when_governed(): void
    {
        $coverage = $this->coverage();
        $runner = new AtlasForgeProviderProcessRunner($coverage);
        $runner->setProcessFactory(
            static fn (array $argv, ?string $cwd, ?array $env, int $timeout): Process => new Process(['true']),
        );

        // governed=true => the seam already recorded CONSULTED, so the runner
        // must NOT double-count a bypass.
        $runner->run(['argv' => ['codex', 'exec'], 'provider' => 'codex_cli', 'governed' => true]);
        self::assertSame(0, $coverage->summary()['bypass']);
        self::assertSame(0, $coverage->summary()['total']);

        // governed absent => SLICE 1 behavior: a blind bypass is recorded.
        $runner->run(['argv' => ['codex', 'exec'], 'provider' => 'codex_cli']);
        self::assertSame(1, $coverage->summary()['bypass']);
    }
}
