<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\Coverage\EngineeringExecutionCoverage;
use App\Services\Ai\EngineeringKernel\Coverage\EngineeringExecutionSurfaceRegistry;
use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Ai\EngineeringKernel\EngineeringModeExecutionOrderFactory;
use App\Services\Ai\EngineeringKernel\HermeticSandboxPort;
use App\Services\Ai\EngineeringKernel\ProviderPort;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class QualityFoundryExecutionCoverageEvidenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_05_17_220000_create_ai_autonomous_engineering_os_tables.php'))->up();
        (require database_path('migrations/2026_05_17_230000_create_ai_real_engineering_execution_kernel_tables.php'))->up();
        (require database_path('migrations/2026_05_17_232000_create_ai_engineering_company_runtime_tables.php'))->up();
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_19_050000_extend_atlas_ledger_events_with_timeline_fields.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        parent::tearDown();
    }

    public function test_every_registered_mutative_surface_emits_a_complete_kernel_coverage_receipt(): void
    {
        $scope = ['app/QualityFoundryCoverageProbe.php'];
        $surfaces = EngineeringExecutionSurfaceRegistry::ids();
        $provider = $this->createMock(ProviderPort::class);
        // P1b.1: pre-effect authority refuses before provider/sandbox when
        // decision events are not authoritative ledger facts.
        $provider->expects(self::never())->method('invoke');
        $sandbox = $this->createMock(HermeticSandboxPort::class);
        $sandbox->expects(self::never())->method('execute');
        $this->app->instance(ProviderPort::class, $provider);
        $this->app->instance(HermeticSandboxPort::class, $sandbox);
        $this->app->forgetInstance(EliteExecutorKernel::class);
        $kernel = $this->app->make(EliteExecutorKernel::class);

        foreach ($surfaces as $index => $surface) {
            $outcome = $kernel->execute((new EngineeringModeExecutionOrderFactory)->make([
                'run_id' => 'quality-foundry-coverage-'.$index,
                'delivery_id' => 'quality-foundry-coverage-'.$index,
                'mode' => $index % 2 === 0 ? 'dev' : 'forge',
                'risk_class' => 'R3',
                'complexity_band' => 'C3',
                'duration_regime' => 'interactive',
                'work_topology' => 'single',
                'product_intent_verdict_hash' => hash('sha256', 'qf-coverage-intent-'.$index),
                'spec_hash' => hash('sha256', 'qf-coverage-spec-'.$index),
                'world_model_snapshot_hash' => hash('sha256', 'qf-coverage-world-'.$index),
                'market_decision_hash' => hash('sha256', 'qf-coverage-market-'.$index),
                'workspace' => base_path(),
                'base_commit' => str_repeat('a', 40),
                'allowed_scope' => $scope,
                'forbidden_scope' => ['.env'],
                'authority_envelope' => [
                    'kind' => 'quality_foundry_coverage_probe',
                    'surface' => $surface,
                    'authority_hash' => hash('sha256', 'qf-coverage-authority-'.$index),
                    'sandbox_required' => true,
                    'source_workspace_read_only' => true,
                    'integration_lock_key' => 'quality-foundry-coverage-'.$index,
                ],
                'operator_contract' => ['presence' => 'confirmed'],
                'provider_route' => ['provider' => 'fixture', 'model' => 'fixture'],
                'decision_event_id' => 'quality-foundry-coverage-decision-'.$index,
                'mutate' => true,
                'experiment_ref' => 'quality-foundry-coverage',
                'idempotency_key' => 'quality-foundry:coverage:'.$surface,
            ]));
            self::assertSame('blocked', $outcome->status);
        }

        $report = $this->app->make(EngineeringExecutionCoverage::class)
            ->report(EngineeringExecutionCoverage::MODE_OBSERVE);
        $covered = array_values(array_unique(array_map(
            static fn (array $sample): string => (string) ($sample['surface'] ?? ''),
            array_filter(array_map(
                static fn (mixed $event): array => is_array($event) ? $event : [],
                \App\Models\AtlasLedgerEvent::query()
                    ->where('event_type', EngineeringExecutionCoverage::EVENT_TYPE)
                    ->get()
                    ->map(static fn ($event): mixed => $event->payload)
                    ->all(),
            )),
        )));

        self::assertSame(count($surfaces), $report['counts']['total_events']);
        self::assertSame(count($surfaces), $report['counts']['complete_events']);
        self::assertSame([], $report['incomplete_samples']);
        self::assertSame($surfaces, array_values(array_intersect($surfaces, $covered)));

        fwrite(STDOUT, "QUALITY_FOUNDRY_COVERAGE_JSON=".json_encode([
            'schema' => 'atlas.quality_foundry.coverage_evidence.v1',
            'covered_surfaces' => $covered,
            'registered_surfaces' => $surfaces,
            'complete_events' => $report['counts']['complete_events'],
            'total_events' => $report['counts']['total_events'],
            'coverage_percent' => 100,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    }
}
