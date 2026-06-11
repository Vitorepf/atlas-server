<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\StrategicOperatingSystem;

use App\Services\Ai\StrategicOperatingSystem\AtlasStrategicOperatingSystemRuntimeService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasStrategicOperatingSystemRuntimeServiceTest extends TestCase
{
    public function test_strategic_operating_system_composes_all_five_advanced_loops(): void
    {
        $payload = app(AtlasStrategicOperatingSystemRuntimeService::class)->operatingSystem([
            'workspace' => base_path(),
            'logs' => ['checkout p95 stable after release'],
            'incidents' => [],
            'regressions' => ['signup regression recovered after rollback'],
            'metrics' => ['activation_rate' => 0.42, 'checkout_conversion' => 0.31],
            'revenue_usd' => 12500,
            'cost_usd' => 420,
            'human_feedback' => ['onboarding feels too long'],
            'capital_budget_usd' => 10000,
            'teams' => [['id' => 'atlas-product', 'capacity' => 2]],
            'ventures' => [['id' => 'blackink', 'expected_roi' => 2.1]],
            'features' => [['id' => 'shorter_onboarding', 'expected_roi' => 1.4]],
            'capital_items' => [['id' => 'provider_budget', 'expected_roi' => 0.8]],
        ]);

        $this->assertSame(AtlasStrategicOperatingSystemRuntimeService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(AtlasStrategicOperatingSystemRuntimeService::RUNTIME_FEEDBACK_GRAPH_SCHEMA_VERSION, data_get($payload, 'runtime_feedback_graph.schema_version'));
        $this->assertSame(AtlasStrategicOperatingSystemRuntimeService::EXPERIMENT_STRATEGY_LOOP_SCHEMA_VERSION, data_get($payload, 'autonomous_experiment_strategy_loop.schema_version'));
        $this->assertSame(AtlasStrategicOperatingSystemRuntimeService::ORGANIZATION_TWIN_SCHEMA_VERSION, data_get($payload, 'organization_twin.schema_version'));
        $this->assertSame(AtlasStrategicOperatingSystemRuntimeService::PORTFOLIO_CAPITAL_BRAIN_SCHEMA_VERSION, data_get($payload, 'portfolio_capital_allocation_brain.schema_version'));
        $this->assertSame(AtlasStrategicOperatingSystemRuntimeService::GOVERNANCE_POLICY_EVOLUTION_SCHEMA_VERSION, data_get($payload, 'autonomous_governance_policy_evolution.schema_version'));
        $this->assertSame('ready', data_get($payload, 'autonomous_experiment_strategy_loop.verified_execution_sidecar.status'));
        $this->assertNotEmpty(data_get($payload, 'autonomous_experiment_strategy_loop.hypotheses'));
        $this->assertNotEmpty(data_get($payload, 'organization_twin.recommended_sequence'));
        $this->assertNotEmpty(data_get($payload, 'organization_twin.risk_by_area'));
        $this->assertNotEmpty(data_get($payload, 'portfolio_capital_allocation_brain.ranked_candidates'));
        $this->assertContains('feature', array_column(data_get($payload, 'portfolio_capital_allocation_brain.ranked_candidates'), 'type'));
        $this->assertSame(1, data_get($payload, 'runtime_feedback_graph.coverage.regressions'));
        $this->assertFalse(data_get($payload, 'autonomous_governance_policy_evolution.review_gate.auto_apply_allowed'));
        $this->assertFalse(data_get($payload, 'claim_policy.provider_invoked'));
        $this->assertFalse(data_get($payload, 'claim_policy.auto_spends_capital'));
        $this->assertSame(64, strlen((string) $payload['strategic_operating_system_hash']));
    }

    public function test_runtime_feedback_graph_stays_honest_when_only_watch_signals_exist(): void
    {
        $payload = app(AtlasStrategicOperatingSystemRuntimeService::class)->runtimeFeedbackGraph();

        $this->assertSame(AtlasStrategicOperatingSystemRuntimeService::RUNTIME_FEEDBACK_GRAPH_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertContains($payload['status'], ['watch', 'ready']);
        $this->assertFalse($payload['claim_policy']['writes']);
        $this->assertFalse($payload['claim_policy']['provider_invoked']);
    }

    public function test_certification_proves_all_strategic_os_sections(): void
    {
        $payload = app(AtlasStrategicOperatingSystemRuntimeService::class)->certify();

        $this->assertSame(AtlasStrategicOperatingSystemRuntimeService::CERTIFICATION_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(11, $payload['summary']['total']);
        $this->assertSame(11, $payload['summary']['passed']);
        $this->assertSame(0, $payload['summary']['failed']);
        $this->assertSame([], $payload['remaining_blockers']);
    }

    public function test_strategic_os_command_outputs_snapshot_and_certification_json(): void
    {
        $exit = Artisan::call('atlas:strategic-os', [
            'action' => 'snapshot',
            '--metric' => ['activation_rate=0.42'],
            '--regression' => ['signup regression recovered after rollback'],
            '--feedback' => ['onboarding feels too long'],
            '--revenue' => '12500',
            '--cost' => '420',
            '--capital-budget' => '10000',
            '--json' => true,
            '--strict' => true,
        ]);
        $snapshot = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ready', $snapshot['status']);
        $this->assertSame(AtlasStrategicOperatingSystemRuntimeService::SCHEMA_VERSION, $snapshot['schema_version']);

        $exit = Artisan::call('atlas:strategic-os', [
            'action' => 'certify',
            '--json' => true,
            '--strict' => true,
        ]);
        $certification = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ready', $certification['status']);
        $this->assertSame(11, $certification['summary']['passed']);
    }
}
