<?php

namespace Tests\Feature\Ai\AgenticWorkcell;

use App\Services\Ai\AgenticWorkcell\AtlasAgenticWorkcellRuntimeService;
use App\Services\Ai\EngineeringKernel\EngineeringRoleRoster;
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

    public function test_every_topology_keeps_the_official_22_role_membership_and_only_depth_changes_with_risk(): void
    {
        $topologies = ['solo_agent', 'lead_workers', 'parallel_scouts', 'debate_council', 'tournament', 'red_blue_team', 'mapreduce_research', 'forge_milestone_crew', 'critic_chain', 'tool_builder_loop'];
        foreach ($topologies as $topology) {
            $workcell = app(AtlasAgenticWorkcellRuntimeService::class)->design([
                'objective' => 'Executar uma entrega governada com evidence e rollback.',
                'domain' => 'conversation', 'topology' => $topology, 'evidence_refs' => ['fixture:quality'],
            ]);
            $roles = collect($workcell['role_roster'] ?? [])->pluck('role_id')->all();
            self::assertSame(EngineeringRoleRoster::OFFICIAL_ROLES, $roles, $topology);
            self::assertCount(22, array_unique($roles), $topology);
            self::assertSame(['EngineeringRoleRoster::OFFICIAL_ROLES'], array_values(array_unique(collect($workcell['role_roster'])->pluck('membership_source')->all())), $topology);
        }

        $low = app(AtlasAgenticWorkcellRuntimeService::class)->design([
            'objective' => 'Executar uma entrega governada.', 'domain' => 'conversation', 'topology' => 'solo_agent', 'evidence_refs' => ['fixture:quality'],
        ]);
        $high = app(AtlasAgenticWorkcellRuntimeService::class)->design([
            'objective' => 'Executar uma entrega governada.', 'domain' => 'finance', 'topology' => 'solo_agent', 'evidence_refs' => ['fixture:quality'],
        ]);
        self::assertNotSame($low['role_roster'][0]['depth'], $high['role_roster'][0]['depth']);
        self::assertSame(EngineeringRoleRoster::OFFICIAL_ROLES, collect($high['role_roster'])->pluck('role_id')->all());
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
        $this->assertSame(EngineeringRoleRoster::OFFICIAL_ROLES, $roles);
        $this->assertContains('architecture', $roles);
        $this->assertContains('final_certification', $roles);
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
        $this->assertContains('domain_research', $roles);
        $this->assertContains('evidence_audit', $roles);
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
        $this->assertContains('product_strategy', $roles);
        $this->assertContains('final_certification', $roles);
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
        $this->assertContains('architecture', $roles);
        $this->assertContains('qa_testing', $roles);
        $this->assertContains('release', $roles);
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
