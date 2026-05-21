<?php

namespace Tests\Feature\Ai\AutonomousWorkExecution;

use App\Models\AtlasAweosExecution;
use App\Services\Ai\AutonomousWorkExecution\AtlasAutonomousWorkExecutionService;
use App\Services\Ai\ControlPlane\AtlasAiControlPlaneService;
use Tests\Concerns\CreatesAemorTables;
use Tests\Concerns\CreatesAgenticWorkcellTables;
use Tests\Concerns\CreatesAweosTables;
use Tests\Concerns\CreatesPersistentContextTables;
use Tests\Concerns\CreatesRuntimeEfficiencyTables;
use Tests\TestCase;

class AtlasAutonomousWorkExecutionServiceTest extends TestCase
{
    use CreatesAemorTables;
    use CreatesAgenticWorkcellTables;
    use CreatesAweosTables;
    use CreatesPersistentContextTables;
    use CreatesRuntimeEfficiencyTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPersistentContextTables();
        $this->createRuntimeEfficiencyTables();
        $this->createAgenticWorkcellTables();
        $this->createAemorTables(false);
        $this->createAweosTables();
    }

    protected function tearDown(): void
    {
        $this->dropAweosTables();
        $this->dropAemorTables();
        $this->dropAgenticWorkcellTables();
        $this->dropRuntimeEfficiencyTables();
        $this->dropPersistentContextTables();

        parent::tearDown();
    }

    public function test_run_creates_maximum_aweos_cycle(): void
    {
        $payload = app(AtlasAutonomousWorkExecutionService::class)->run([
            'objective' => 'implemente um fluxo de programacao com contexto, testes e certificacao',
            'domain' => 'programming',
            'flow_id' => 'atlas_dev',
            'evidence_refs' => ['doc:aweos'],
            'context_refs' => ['file:app/Services/Ai'],
        ]);

        $this->assertSame(AtlasAutonomousWorkExecutionService::EXECUTION_SCHEMA, $payload['schema_version']);
        $this->assertSame(AtlasAutonomousWorkExecutionService::LEVEL_MAX, $payload['maturity_level']);
        $this->assertSame('atlas_dev', data_get($payload, 'execution_plan.runtime_target'));
        $this->assertNotEmpty($payload['persistent_context']['persistent_context_hash']);
        $this->assertNotEmpty($payload['runtime_efficiency']['decision_hash']);
        $this->assertNotEmpty($payload['agentic_workcell']['workcell_hash']);
        $this->assertNotEmpty($payload['aemor_episode']['episode_hash']);
        $this->assertTrue(data_get($payload, 'claim_policy.completion_requires_certified_outcome'));
        $this->assertDatabaseCount('atlas_aweos_executions', 1);
        $this->assertDatabaseCount('atlas_aweos_events', 1);
    }

    public function test_forge_objective_targets_forge_and_operator_review(): void
    {
        $payload = app(AtlasAutonomousWorkExecutionService::class)->run([
            'objective' => 'implemente uma Obra grande de multiplos milestones no Atlas Forge',
            'domain' => 'programming',
            'flow_id' => 'atlas_forge',
            'evidence_refs' => ['doc:forge'],
        ]);

        $this->assertSame('atlas_forge', data_get($payload, 'execution_plan.runtime_target'));
        $this->assertSame('forge_milestone_crew', data_get($payload, 'agentic_workcell.topology'));
        $this->assertContains('forge_promotion_review', collect(data_get($payload, 'operator_decision_economy.decisions'))->pluck('id')->all());
    }

    public function test_certified_outcome_requires_evidence(): void
    {
        $runtime = app(AtlasAutonomousWorkExecutionService::class);
        $execution = $runtime->run(['objective' => 'pesquisa sem evidencia', 'domain' => 'research']);
        $outcome = $runtime->certifyOutcome([
            'execution_id' => $execution['execution_id'],
            'claim' => 'resultado sem evidencia',
        ]);

        $this->assertSame(AtlasAutonomousWorkExecutionService::STATUS_BLOCKED, $outcome['status']);
        $this->assertSame('blocked', $outcome['certification_level']);
        $this->assertSame('quarantined', data_get($outcome, 'learning_decision.status'));
    }

    public function test_certified_outcome_gold_closes_feedback_loops(): void
    {
        $runtime = app(AtlasAutonomousWorkExecutionService::class);
        $execution = $runtime->run([
            'objective' => 'corrija bug com testes e evidencias',
            'domain' => 'programming',
            'flow_id' => 'atlas_dev',
            'evidence_refs' => ['test:initial'],
        ]);
        $outcome = $runtime->certifyOutcome([
            'execution_id' => $execution['execution_id'],
            'claim' => 'Bug corrigido com testes focados.',
            'evidence_refs' => ['test:focused_green', 'diff:reviewed'],
            'command_ledger' => [['command' => 'php artisan test --filter=BugFix', 'exit_code' => 0]],
            'test_impact' => [['test' => 'BugFixTest', 'status' => 'passed']],
            'quality_score' => 0.94,
        ]);

        $this->assertSame(AtlasAutonomousWorkExecutionService::STATUS_CERTIFIED, $outcome['status']);
        $this->assertSame('gold', $outcome['certification_level']);
        $this->assertSame('candidate', data_get($outcome, 'learning_decision.status'));
        $this->assertNotEmpty(data_get($outcome, 'aemor_outcome.outcome_hash'));
        $this->assertNotEmpty(data_get($outcome, 'areg_outcome.outcome_hash'));
        $this->assertNotEmpty(data_get($outcome, 'aawr_outcome.outcome_hash'));
        $this->assertDatabaseCount('atlas_aweos_certified_outcomes', 1);
        $this->assertSame(AtlasAutonomousWorkExecutionService::STATUS_CERTIFIED, AtlasAweosExecution::query()->find($execution['execution_id'])->status);
    }

    public function test_control_plane_summarizes_without_raw_objective(): void
    {
        $runtime = app(AtlasAutonomousWorkExecutionService::class);
        $runtime->run(['objective' => 'objetivo sensivel que nao deve aparecer no control plane', 'domain' => 'strategy']);
        $controlPlane = $runtime->controlPlane();
        $encoded = json_encode($controlPlane, JSON_THROW_ON_ERROR);

        $this->assertSame(AtlasAutonomousWorkExecutionService::CONTROL_PLANE_SCHEMA, $controlPlane['schema_version']);
        $this->assertSame(1, data_get($controlPlane, 'summary.executions_total'));
        $this->assertStringNotContainsString('objetivo sensivel', $encoded);
        $this->assertNotEmpty($controlPlane['control_plane_hash']);
    }

    public function test_control_plane_exposes_aweos_runtime_section(): void
    {
        app(AtlasAutonomousWorkExecutionService::class)->run([
            'objective' => 'garanta que AWEOS aparece no Atlas AI Control Plane sem texto cru',
            'domain' => 'programming',
            'flow_id' => 'atlas_dev',
            'evidence_refs' => ['test:aweos_control_plane'],
        ]);

        $report = app(AtlasAiControlPlaneService::class)->report();
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);

        $this->assertSame('ready', data_get($report, 'autonomous_work_execution.status'));
        $this->assertSame(1, data_get($report, 'autonomous_work_execution.summary.executions_total'));
        $this->assertSame(1, data_get($report, 'summary.aweos_executions_total'));
        $this->assertStringNotContainsString('garanta que AWEOS aparece', $encoded);
    }
}
