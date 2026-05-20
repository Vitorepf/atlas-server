<?php

namespace Tests\Feature\Ai\AgenticWorkcell;

use App\Services\Ai\AgenticWorkcell\AtlasAgenticWorkcellRuntimeService;
use Tests\Concerns\CreatesAgenticWorkcellTables;
use Tests\Concerns\CreatesRuntimeEfficiencyTables;
use Tests\TestCase;

class AtlasAgenticWorkcellRuntimeServiceTest extends TestCase
{
    use CreatesAgenticWorkcellTables;
    use CreatesRuntimeEfficiencyTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRuntimeEfficiencyTables();
        $this->createAgenticWorkcellTables();
    }

    protected function tearDown(): void
    {
        $this->dropAgenticWorkcellTables();
        $this->dropRuntimeEfficiencyTables();
        parent::tearDown();
    }

    public function test_simple_task_uses_solo_workcell_but_still_requires_verification(): void
    {
        $workcell = app(AtlasAgenticWorkcellRuntimeService::class)->design([
            'objective' => 'Responda uma duvida simples.',
            'domain' => 'conversation',
        ]);

        $this->assertSame(AtlasAgenticWorkcellRuntimeService::WORKCELL_SCHEMA, $workcell['schema_version']);
        $this->assertSame('solo_agent', $workcell['topology']);
        $this->assertSame(AtlasAgenticWorkcellRuntimeService::LEVEL_L5, $workcell['maturity_level']);
        $this->assertTrue(data_get($workcell, 'verification_plan.independent_verifier_required'));
        $this->assertFalse(data_get($workcell, 'claim_policy.provider_invoked'));
        $this->assertFalse(data_get($workcell, 'claim_policy.agents_spawned'));
        $this->assertDatabaseCount('atlas_agentic_workcells', 1);
    }

    public function test_forge_objective_creates_milestone_crew_with_context_isolation(): void
    {
        $workcell = app(AtlasAgenticWorkcellRuntimeService::class)->design([
            'objective' => 'Implemente uma Obra enterprise completa por meses com milestones e certificacao.',
            'domain' => 'programming',
            'flow_id' => 'atlas_forge',
            'evidence_refs' => ['doc:forge'],
            'context_refs' => ['ctx:forge', 'ctx:teos'],
        ]);
        $roles = collect($workcell['role_roster'])->pluck('role_id')->all();

        $this->assertSame('forge_milestone_crew', $workcell['topology']);
        $this->assertContains('lead_architect', $roles);
        $this->assertContains('final_certifier', $roles);
        $this->assertGreaterThanOrEqual(8, count($roles));
        $this->assertSame(count($roles), data_get($workcell, 'context_packs.pack_count'));
        $this->assertTrue(data_get($workcell, 'context_packs.context_isolation_required'));
        $this->assertStringContainsString('milestones', json_encode($workcell, JSON_THROW_ON_ERROR));
    }

    public function test_research_uses_mapreduce_with_evidence_auditor(): void
    {
        $workcell = app(AtlasAgenticWorkcellRuntimeService::class)->design([
            'objective' => 'Faça pesquisa profunda sobre oportunidades de mercado global com fontes contrárias.',
            'domain' => 'research',
            'evidence_refs' => ['source:seed'],
        ]);
        $roles = collect($workcell['role_roster'])->pluck('role_id')->all();

        $this->assertSame('mapreduce_research', $workcell['topology']);
        $this->assertContains('source_scout', $roles);
        $this->assertContains('counter_source_scout', $roles);
        $this->assertContains('evidence_auditor', $roles);
        $this->assertTrue(data_get($workcell, 'verification_plan.evidence_auditor_required'));
    }

    public function test_strategy_uses_red_blue_team_and_adjudicator(): void
    {
        $workcell = app(AtlasAgenticWorkcellRuntimeService::class)->design([
            'objective' => 'Decida a melhor prioridade estratégica da empresa.',
            'domain' => 'strategy',
            'evidence_refs' => ['ctx:strategy'],
        ]);
        $roles = collect($workcell['role_roster'])->pluck('role_id')->all();

        $this->assertSame('red_blue_team', $workcell['topology']);
        $this->assertContains('red_team_critic', $roles);
        $this->assertContains('blue_team_builder', $roles);
        $this->assertContains('final_adjudicator', $roles);
    }

    public function test_capability_gap_uses_tool_builder_loop(): void
    {
        $workcell = app(AtlasAgenticWorkcellRuntimeService::class)->design([
            'objective' => 'Crie uma ferramenta nova para processar PDFs gigantes.',
            'domain' => 'programming',
            'evidence_refs' => ['capability:gap'],
        ]);
        $roles = collect($workcell['role_roster'])->pluck('role_id')->all();

        $this->assertSame('tool_builder_loop', $workcell['topology']);
        $this->assertContains('tool_designer', $roles);
        $this->assertContains('simulation_verifier', $roles);
        $this->assertContains('release_certifier', $roles);
    }

    public function test_external_side_effect_is_blocked_before_agent_execution(): void
    {
        $workcell = app(AtlasAgenticWorkcellRuntimeService::class)->design([
            'objective' => 'Executar compra e venda externa automaticamente agora.',
            'domain' => 'finance',
            'external_execution_requested' => true,
        ]);

        $this->assertSame('blocked', $workcell['status']);
        $this->assertSame('critic_chain', $workcell['topology']);
        $this->assertFalse(data_get($workcell, 'claim_policy.external_execution_performed'));
        $this->assertFalse(data_get($workcell, 'claim_policy.agents_spawned'));
    }

    public function test_close_outcome_compiles_org_pattern_and_control_plane_hides_objective(): void
    {
        $runtime = app(AtlasAgenticWorkcellRuntimeService::class);
        $objective = 'Objetivo confidencial para validar que o control plane nao vaza texto cru.';
        $workcell = $runtime->design([
            'objective' => $objective,
            'domain' => 'programming',
            'evidence_refs' => ['test:aawr'],
        ]);
        $outcome = $runtime->closeOutcome([
            'workcell_id' => $workcell['workcell_id'],
            'quality_score' => 0.91,
            'coordination_roi_score' => 0.74,
            'evidence_refs' => ['outcome:aawr'],
        ]);
        $controlPlane = $runtime->controlPlane();
        $encoded = json_encode($controlPlane, JSON_THROW_ON_ERROR);

        $this->assertSame(AtlasAgenticWorkcellRuntimeService::OUTCOME_SCHEMA, $outcome['schema_version']);
        $this->assertSame(AtlasAgenticWorkcellRuntimeService::ORG_PATTERN_SCHEMA, data_get($outcome, 'compiled_org_pattern.schema_version'));
        $this->assertDatabaseCount('atlas_agentic_workcell_org_patterns', 1);
        $this->assertStringNotContainsString($objective, $encoded);
        $this->assertStringContainsString('objective_hash', $encoded);
    }
}
