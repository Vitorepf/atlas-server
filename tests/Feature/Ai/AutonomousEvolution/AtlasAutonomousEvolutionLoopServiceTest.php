<?php

namespace Tests\Feature\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasAutonomousEvolutionLoopService;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesAaelTables;
use Tests\TestCase;

class AtlasAutonomousEvolutionLoopServiceTest extends TestCase
{
    use CreatesAaelTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAaelTables();
    }

    protected function tearDown(): void
    {
        $this->dropAaelTables();

        parent::tearDown();
    }

    public function test_cycle_creates_portfolio_experiment_decision_and_audit(): void
    {
        $payload = app(AtlasAutonomousEvolutionLoopService::class)->runCycle([
            'objective' => 'Improve Atlas Dev and Forge quality with verified evidence and rollback',
            'evidence_refs' => ['test:aael'],
        ]);

        $this->assertSame(AtlasAutonomousEvolutionLoopService::CYCLE_SCHEMA, $payload['schema_version']);
        $this->assertSame(AtlasAutonomousEvolutionLoopService::LEVEL_MAX, data_get($payload, 'portfolio_snapshot.maturity_level'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'portfolio_snapshot.selected_count'));
        $this->assertSame('passed', data_get($payload, 'strategic_alignment_gate.status'));
        $this->assertSame('passed', data_get($payload, 'anti_drift_doctrine_gate.status'));
        $this->assertSame(AtlasAutonomousEvolutionLoopService::ASSISTED_EXECUTION_BRIDGE_SCHEMA, data_get($payload, 'experiments.0.assisted_execution_quality.schema_version'));
        $this->assertSame(AtlasAutonomousEvolutionLoopService::STATUS_READY, data_get($payload, 'experiments.0.assisted_execution_quality.status'));
        $this->assertSame('passed', data_get($payload, 'experiments.0.assisted_execution_quality.aedpds_gate_status'));
        $this->assertNotEmpty(data_get($payload, 'experiments.0.assisted_execution_quality.selected_drivers'));
        $this->assertSame('recorded', data_get($payload, 'experiments.0.assisted_execution_quality.outcome_feedback_status'));
        $this->assertSame('ready_to_record', data_get($payload, 'experiments.0.assisted_execution_quality.aemor_feedback_status'));
        $this->assertSame('passed', data_get($payload, 'audit_report.audit_court.assisted_execution_critic'));
        $this->assertSame(AtlasAutonomousEvolutionLoopService::AUDIT_SCHEMA, data_get($payload, 'audit_report.schema_version'));
        $this->assertDatabaseCount('atlas_aael_opportunities', 1);
        $this->assertDatabaseCount('atlas_aael_portfolio_cycles', 1);
        $this->assertDatabaseCount('atlas_aael_evolution_experiments', 1);
        $this->assertDatabaseCount('atlas_aael_promotion_decisions', 1);
        $this->assertDatabaseCount('atlas_aael_audit_reports', 1);
    }

    public function test_high_risk_evolution_requires_human_signature(): void
    {
        $payload = app(AtlasAutonomousEvolutionLoopService::class)->runCycle([
            'opportunities' => [[
                'objective' => 'Change provider topology for Atlas router',
                'risk_level' => 'high',
                'strategic_alignment_score' => 0.95,
                'impact' => 0.95,
                'frequency' => 0.9,
                'effort' => 0.1,
            ]],
            'evidence_refs' => ['test:high_risk'],
        ]);

        $this->assertSame('human_signature_required', data_get($payload, 'operator_queue.0.action'));
        $this->assertSame(AtlasAutonomousEvolutionLoopService::STATUS_WATCH, data_get($payload, 'experiments.0.assisted_execution_quality.status'));
        $this->assertSame('signature_required', data_get($payload, 'promotion_decisions.0.trust_level'));
        $this->assertSame(AtlasAutonomousEvolutionLoopService::STATUS_WATCH, data_get($payload, 'promotion_decisions.0.promotion_gate.assisted_execution_quality_status'));
        $this->assertSame('operator_review_required', data_get($payload, 'promotion_decisions.0.status'));
    }

    public function test_doctrine_gate_blocks_parallel_runtime_duplication(): void
    {
        $payload = app(AtlasAutonomousEvolutionLoopService::class)->runCycle([
            'opportunities' => [[
                'objective' => 'Create parallel self construction runtime and bypass evidence',
                'strategic_alignment_score' => 0.99,
                'impact' => 1.0,
                'frequency' => 1.0,
                'effort' => 0.1,
            ]],
        ]);

        $this->assertSame(AtlasAutonomousEvolutionLoopService::STATUS_BLOCKED, data_get($payload, 'anti_drift_doctrine_gate.status'));
        $this->assertSame(0, data_get($payload, 'portfolio_snapshot.selected_count'));
        $this->assertSame([], $payload['experiments']);
    }

    public function test_control_plane_summarizes_aael_without_raw_objective(): void
    {
        app(AtlasAutonomousEvolutionLoopService::class)->runCycle([
            'objective' => 'objetivo sensivel de evolucao aael',
            'evidence_refs' => ['test:control'],
        ]);

        $report = app(AtlasAutonomousEvolutionLoopService::class)->controlPlane();
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);

        $this->assertSame(AtlasAutonomousEvolutionLoopService::CONTROL_PLANE_SCHEMA, $report['schema_version']);
        $this->assertSame(1, data_get($report, 'summary.cycles_total'));
        $this->assertStringNotContainsString('objetivo sensivel', $encoded);
    }

    public function test_cli_cycle_and_control_plane_emit_json(): void
    {
        Artisan::call('atlas:aael', ['action' => 'cycle', '--objective' => 'Improve AAEL CLI', '--evidence' => ['test:cli'], '--json' => true]);
        $cycle = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(AtlasAutonomousEvolutionLoopService::CYCLE_SCHEMA, $cycle['schema_version']);

        Artisan::call('atlas:aael', ['action' => 'control-plane', '--json' => true]);
        $control = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(AtlasAutonomousEvolutionLoopService::CONTROL_PLANE_SCHEMA, $control['schema_version']);
        $this->assertGreaterThanOrEqual(1, data_get($control, 'summary.cycles_total'));
    }
}
