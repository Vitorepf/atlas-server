<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Ai\EngineeringKernel\EngineeringModeExecutionOrderFactory;
use App\Services\Ai\EngineeringKernel\HermeticSandboxPort;
use App\Services\Ai\EngineeringKernel\ProviderPort;
use App\Services\Ai\EngineeringKernel\Coverage\EngineeringExecutionCoverage;
use App\Models\AtlasLedgerEvent;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class QualityFoundryExactlyOnceEvidenceTest extends TestCase
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

    public function test_mutative_kernel_fixture_invokes_provider_and_sandbox_once_then_blocks_without_release(): void
    {
        $scope = ['app/QualityFoundryProbe.php'];
        // P1b.1: without an authoritative decision ledger event, mutative path
        // must refuse before provider/sandbox (pre-effect authority replay).
        $provider = $this->createMock(ProviderPort::class);
        $provider->expects(self::never())->method('invoke');
        $sandbox = $this->createMock(HermeticSandboxPort::class);
        $sandbox->expects(self::never())->method('execute');
        $this->app->instance(ProviderPort::class, $provider);
        $this->app->instance(HermeticSandboxPort::class, $sandbox);
        $this->app->forgetInstance(EliteExecutorKernel::class);

        $outcome = $this->app->make(EliteExecutorKernel::class)->execute(
            (new EngineeringModeExecutionOrderFactory)->make([
                'run_id' => 'quality-foundry-exactly-once',
                'delivery_id' => 'quality-foundry-exactly-once',
                'mode' => 'dev',
                'risk_class' => 'R3',
                'complexity_band' => 'C3',
                'duration_regime' => 'interactive',
                'work_topology' => 'single',
                'product_intent_verdict_hash' => hash('sha256', 'qf-exactly-once-intent'),
                'spec_hash' => hash('sha256', 'qf-exactly-once-spec'),
                'world_model_snapshot_hash' => hash('sha256', 'qf-exactly-once-world'),
                'market_decision_hash' => hash('sha256', 'qf-exactly-once-market'),
                'workspace' => base_path(),
                'base_commit' => str_repeat('a', 40),
                'allowed_scope' => $scope,
                'forbidden_scope' => ['.env'],
                'authority_envelope' => [
                    'kind' => 'quality_foundry_exactly_once',
                    'authority_hash' => hash('sha256', 'qf-exactly-once-authority'),
                ],
                'operator_contract' => ['presence' => 'confirmed'],
                'provider_route' => ['provider' => 'fixture', 'model' => 'fixture'],
                'decision_event_id' => 'quality-foundry-exactly-once-decision',
                'mutate' => true,
                'experiment_ref' => 'quality-foundry-exactly-once',
                'idempotency_key' => 'quality-foundry:exactly-once',
            ]),
        );

        self::assertSame('blocked', $outcome->status);
        self::assertFalse($outcome->claimEligible);
        self::assertSame('not_authorized', $outcome->releaseReceipt['status']);
        self::assertSame('not_applicable', $outcome->canaryRollbackReceipt['status']);
        self::assertNotSame('ok', $outcome->providerReceipt['status'] ?? null);
        self::assertTrue(
            str_contains(json_encode($outcome->toArray(), JSON_THROW_ON_ERROR), 'pre_effect_decision_authority'),
            'blocked outcome must cite pre-effect decision authority refusal',
        );

        $coverage = AtlasLedgerEvent::query()
            ->where('event_type', EngineeringExecutionCoverage::EVENT_TYPE)
            ->firstOrFail();
        self::assertTrue((bool) data_get($coverage->payload, 'complete'));
        self::assertSame([], data_get($coverage->payload, 'missing_fields'));
        self::assertSame(EngineeringExecutionCoverage::MODE_OBSERVE, data_get($coverage->payload, 'mode'));

        $replayed = $this->app->make(EliteExecutorKernel::class)->execute(
            (new EngineeringModeExecutionOrderFactory)->make([
                'run_id' => 'quality-foundry-exactly-once',
                'delivery_id' => 'quality-foundry-exactly-once',
                'mode' => 'dev',
                'risk_class' => 'R3',
                'complexity_band' => 'C3',
                'duration_regime' => 'interactive',
                'work_topology' => 'single',
                'product_intent_verdict_hash' => hash('sha256', 'qf-exactly-once-intent'),
                'spec_hash' => hash('sha256', 'qf-exactly-once-spec'),
                'world_model_snapshot_hash' => hash('sha256', 'qf-exactly-once-world'),
                'market_decision_hash' => hash('sha256', 'qf-exactly-once-market'),
                'workspace' => base_path(),
                'base_commit' => str_repeat('a', 40),
                'allowed_scope' => $scope,
                'forbidden_scope' => ['.env'],
                'authority_envelope' => [
                    'kind' => 'quality_foundry_exactly_once',
                    'authority_hash' => hash('sha256', 'qf-exactly-once-authority'),
                ],
                'operator_contract' => ['presence' => 'confirmed'],
                'provider_route' => ['provider' => 'fixture', 'model' => 'fixture'],
                'decision_event_id' => 'quality-foundry-exactly-once-decision',
                'mutate' => true,
                'experiment_ref' => 'quality-foundry-exactly-once',
                'idempotency_key' => 'quality-foundry:exactly-once',
            ]),
        );

        self::assertSame($outcome->outcomeHash, $replayed->outcomeHash);
        self::assertSame(1, AtlasLedgerEvent::query()
            ->where('event_type', EngineeringExecutionCoverage::EVENT_TYPE)
            ->count());
    }
}
