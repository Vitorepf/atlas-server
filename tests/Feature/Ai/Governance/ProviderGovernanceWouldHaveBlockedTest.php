<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Governance;

use App\Services\Ai\AtlasDecide\AtlasDecideGatewayConsultationService;
use App\Services\Ai\AtlasDecide\AtlasDecideMetaLearningService;
use App\Services\Ai\Caching\AiCallCostGuard;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Governance\AtlasTrustBudgetService;
use App\Services\Ai\Governance\ProviderGovernanceConsult;
use App\Services\Ai\Governance\ProviderGovernanceCoverageLedger;
use App\Services\Ai\Tokens\AtlasTokenEconomyBudgetPolicyService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * ENG-10 gate — would-have-blocked telemetry on the advisory governance path.
 */
final class StubAdmlForWouldHaveBlocked extends AtlasDecideMetaLearningService
{
    public function __construct() {}

    public function activeRouteFor(string $taskCategory, string $role, ?string $framework = null): ?array
    {
        return null;
    }
}

final class ProviderGovernanceWouldHaveBlockedTest extends TestCase
{
    private string $coveragePath;

    private string $admlPath;

    private string $trustBudgetPath;

    protected function setUp(): void
    {
        parent::setUp();
        $u = uniqid('', true);
        $this->coveragePath = sys_get_temp_dir()."/atlas_eng10_{$u}/coverage.jsonl";
        $this->admlPath = sys_get_temp_dir()."/atlas_eng10_{$u}/adml.jsonl";
        $this->trustBudgetPath = sys_get_temp_dir()."/atlas_eng10_{$u}/trust_budget.jsonl";

        config([
            'atlas.ai.governance.enforce' => false,
            'atlas.ai.cache.cost_guard' => [
                'soft_units' => 0.0,
                'hard_units' => 0.0,
                'hard_units_candidate' => 0.0,
                'hard_units_candidate_percentile' => 0.99,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->coveragePath);
        @unlink($this->admlPath);
        @unlink($this->trustBudgetPath);
        @rmdir(dirname($this->coveragePath));
        parent::tearDown();
    }

    private function coverage(): ProviderGovernanceCoverageLedger
    {
        $ledger = new ProviderGovernanceCoverageLedger;
        $ledger->setLogPathForTesting($this->coveragePath);

        return $ledger;
    }

    private function seam(ProviderGovernanceCoverageLedger $coverage): ProviderGovernanceConsult
    {
        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting($this->admlPath.'.kernel');
        $admission = new AtlasAutonomyAdmissionService($kernel);
        $adml = new AtlasDecideGatewayConsultationService(new StubAdmlForWouldHaveBlocked, $kernel, $admission);
        $adml->setLogPathForTesting($this->admlPath);
        $trustBudget = new AtlasTrustBudgetService;
        $trustBudget->setLogPathForTesting($this->trustBudgetPath);

        return new ProviderGovernanceConsult(
            $adml,
            new AiCallCostGuard(new AtlasTokenEconomyBudgetPolicyService),
            $coverage,
            $trustBudget,
        );
    }

    public function test_synthetic_above_budget_call_records_would_have_blocked_with_teeth(): void
    {
        config([
            'atlas.ai.cache.cost_guard.hard_units_candidate' => 0.0001,
        ]);

        $coverage = $this->coverage();
        $advisory = $this->seam($coverage)->consultBeforeSpawn([
            'provider' => 'codex_cli',
            'surface' => ProviderGovernanceCoverageLedger::SURFACE_FORGE_PROCESS_RUNNER,
            'prompt' => str_repeat('expensive prompt ', 200),
        ]);

        self::assertFalse($advisory['enforce']);
        self::assertFalse($advisory['should_block'], 'real enforce stays OFF');
        self::assertTrue($advisory['would_have_blocked'], 'candidate guard must have teeth');
        self::assertSame(
            ProviderGovernanceCoverageLedger::REASON_COST_GUARD_CANDIDATE_HARD_EXCEEDED,
            $advisory['would_have_blocked_reason'],
        );

        $summary = $coverage->summary();
        self::assertSame(1, $summary['would_have_blocked_total']);
        self::assertSame(1.0, $summary['would_have_blocked_rate']);
        self::assertSame(ProviderGovernanceCoverageLedger::FP_DEFINITION, $summary['fp_definition']);
    }

    public function test_candidate_is_derived_from_observed_cost_distribution_when_env_unset(): void
    {
        $coverage = $this->coverage();

        foreach ([1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0, 100.0] as $units) {
            $coverage->recordConsulted('codex_cli', ProviderGovernanceCoverageLedger::SURFACE_FORGE_PROCESS_RUNNER, [
                'pre_cost_units' => $units,
            ]);
        }

        $derived = $coverage->deriveCandidateHardUnits();
        self::assertNotNull($derived);
        self::assertGreaterThan(9.0, $derived);
        self::assertLessThanOrEqual(100.0, $derived);

        $snapshot = $coverage->candidateDerivationSnapshot();
        self::assertSame('ledger_percentile', $snapshot['source']);
        self::assertSame(10, $snapshot['samples']);
        self::assertFalse($snapshot['env_override']);
        self::assertSame(0.99, $snapshot['percentile']);
    }

    public function test_false_positive_definition_is_codified_in_ledger(): void
    {
        $coverage = $this->coverage();

        $coverage->recordConsulted('codex_cli', ProviderGovernanceCoverageLedger::SURFACE_FORGE_PROCESS_RUNNER, [
            'would_have_blocked' => true,
            'pre_cost_units' => 5.0,
            'post_cost_units' => 4.5,
            'completion_outcome' => 'green',
        ]);
        $coverage->recordConsulted('codex_cli', ProviderGovernanceCoverageLedger::SURFACE_FORGE_PROCESS_RUNNER, [
            'would_have_blocked' => true,
            'pre_cost_units' => 5.0,
            'post_cost_units' => 6.0,
            'completion_outcome' => 'green',
        ]);
        $coverage->recordConsulted('codex_cli', ProviderGovernanceCoverageLedger::SURFACE_FORGE_PROCESS_RUNNER, [
            'would_have_blocked' => true,
            'pre_cost_units' => 5.0,
            'post_cost_units' => 4.0,
            'completion_outcome' => 'failed',
        ]);

        $summary = $coverage->summary();
        self::assertSame(3, $summary['would_have_blocked_total']);
        self::assertSame(1, $summary['false_positive_total']);
        self::assertSame(round(1 / 3, 4), $summary['false_positive_rate']);
        self::assertTrue($coverage->isFalsePositive([
            'would_have_blocked' => true,
            'pre_cost_units' => 5.0,
            'post_cost_units' => 4.5,
            'completion_outcome' => 'green',
        ]));
    }

    public function test_provider_coverage_command_json_exposes_would_have_blocked_fields(): void
    {
        config([
            'atlas.ai.cache.cost_guard.hard_units_candidate' => 0.0001,
        ]);

        $ledger = $this->coverage();
        $this->seam($ledger)->consultBeforeSpawn([
            'provider' => 'codex_cli',
            'surface' => ProviderGovernanceCoverageLedger::SURFACE_FORGE_PROCESS_RUNNER,
            'prompt' => str_repeat('expensive prompt ', 200),
        ]);

        $this->app->instance(ProviderGovernanceCoverageLedger::class, $ledger);

        $exitCode = Artisan::call('atlas:provider:coverage', ['--json' => true]);
        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim(Artisan::output()), true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('would_have_blocked_total', $decoded);
        self::assertArrayHasKey('would_have_blocked_rate', $decoded);
        self::assertArrayHasKey('fp_definition', $decoded);
        self::assertSame(1, $decoded['would_have_blocked_total']);
        self::assertSame(ProviderGovernanceCoverageLedger::FP_DEFINITION, $decoded['fp_definition']);
    }
}
