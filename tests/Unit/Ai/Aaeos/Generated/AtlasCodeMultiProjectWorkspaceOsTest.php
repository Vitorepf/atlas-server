<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCodeMultiProjectWorkspaceOsService;
use Tests\TestCase;

/**
 * Pins the documented contract of the Atlas Code Multi-Project Workspace OS:
 * the active-project gate, the Project-vs-Obra invariant, the "Regras para IA"
 * work classification, the production risk floor, the execution gate and the
 * minimal-profile guard.
 *
 * @see docs/engineering-knowledge-base/atlas-code-multi-project-workspace-os.md
 */
final class AtlasCodeMultiProjectWorkspaceOsTest extends TestCase
{
    private function service(): AtlasCodeMultiProjectWorkspaceOsService
    {
        return new AtlasCodeMultiProjectWorkspaceOsService();
    }

    /** A docs-complete, non-production Atlas project with a resolved workspace. */
    private function atlasProject(): array
    {
        return [
            'project_id' => 'atlas',
            'name' => 'Atlas',
            'repo_root' => '/repos/atlas',
            'workspace_path' => '/repos/atlas',
            'production_status' => 'development',
            'docs_status' => 'complete',
            'default_risk' => 'medium',
        ];
    }

    /** No active project => the router refuses to classify any code work. */
    public function test_no_active_project_refuses_to_route(): void
    {
        $out = $this->service()->routeWork([], ['is_small' => true, 'is_clear' => true]);

        $this->assertSame(AtlasCodeMultiProjectWorkspaceOsService::TIER_NONE, $out['tier']);
        $this->assertFalse($out['active_project']);
        $this->assertFalse($out['execution_allowed']);
        $this->assertSame('Selecionar Projeto/Workspace ativo no Atlas Desktop.', $out['required_next_action']);
    }

    /** A small + clear + reversible single-file change is an Intervencao Rapida — never Obra. */
    public function test_small_clear_reversible_is_quick_intervention_not_obra(): void
    {
        $out = $this->service()->routeWork($this->atlasProject(), [
            'is_small' => true,
            'is_clear' => true,
            'is_reversible' => true,
            'files_touched' => 1,
        ]);

        $this->assertSame(AtlasCodeMultiProjectWorkspaceOsService::TIER_INTERVENCAO_RAPIDA, $out['tier']);
        $this->assertNotSame(AtlasCodeMultiProjectWorkspaceOsService::TIER_OBRA, $out['tier']);
        $this->assertTrue($out['execution_allowed']);
        $this->assertSame('medium', $out['effective_risk']);
    }

    /** Structural / multi-file work routes to Obra. */
    public function test_structural_multifile_routes_to_obra(): void
    {
        $out = $this->service()->routeWork($this->atlasProject(), [
            'is_structural' => true,
            'files_touched' => 7,
        ]);

        $this->assertSame(AtlasCodeMultiProjectWorkspaceOsService::TIER_OBRA, $out['tier']);
        $this->assertSame('Promover para Obra de Programacao governada (gates + evidence).', $out['required_next_action']);
    }

    /** Ambiguous work routes to Candidato de Obra (structured discovery first). */
    public function test_ambiguous_routes_to_obra_candidate(): void
    {
        $out = $this->service()->routeWork($this->atlasProject(), [
            'is_ambiguous' => true,
            'files_touched' => 2,
        ]);

        $this->assertSame(AtlasCodeMultiProjectWorkspaceOsService::TIER_CANDIDATO_DE_OBRA, $out['tier']);
    }

    /**
     * Project-vs-Obra invariant: framing the whole product/repo as one Obra is
     * a violation and is NEVER routed to Obra — it lands on Candidato de Obra.
     */
    public function test_whole_project_as_obra_is_rejected_invariant(): void
    {
        $out = $this->service()->routeWork($this->atlasProject(), [
            'targets_whole_project' => true,
            'is_structural' => true,
            'files_touched' => 50,
        ]);

        $this->assertNotSame(AtlasCodeMultiProjectWorkspaceOsService::TIER_OBRA, $out['tier']);
        $this->assertSame(AtlasCodeMultiProjectWorkspaceOsService::TIER_CANDIDATO_DE_OBRA, $out['tier']);
        $this->assertContains(
            'project_not_obra: "Hierarquia proibida: Obra -> Projeto inteiro". Atlas/Blackink sao Projetos, nao Obras.',
            $out['invariant_violations']
        );
    }

    /**
     * Production risk floor: a small/clear/reversible change on a production
     * project stays an Intervencao Rapida (not auto-Obra) but the risk is floored
     * to >= high and explicit intervention review is required.
     */
    public function test_production_floors_risk_but_small_change_stays_intervention(): void
    {
        $blackink = [
            'project_id' => 'blackink',
            'name' => 'Blackink',
            'repo_root' => '/repos/blackink',
            'workspace_path' => '/repos/blackink',
            'production_status' => 'production',
            'docs_status' => 'complete',
            'default_risk' => 'low',
        ];

        $out = $this->service()->routeWork($blackink, [
            'is_small' => true,
            'is_clear' => true,
            'is_reversible' => true,
            'files_touched' => 1,
        ]);

        $this->assertSame(AtlasCodeMultiProjectWorkspaceOsService::TIER_INTERVENCAO_RAPIDA, $out['tier']);
        $this->assertSame('high', $out['effective_risk']);
        $this->assertTrue($out['requires_explicit_intervention_review']);
    }

    /** Execution gate: no resolved workspace/repo => execution blocked. */
    public function test_unresolved_workspace_blocks_execution(): void
    {
        $project = $this->atlasProject();
        unset($project['workspace_path'], $project['repo_root']);

        $out = $this->service()->routeWork($project, [
            'is_small' => true,
            'is_clear' => true,
            'is_reversible' => true,
            'files_touched' => 1,
        ]);

        $this->assertFalse($out['execution_allowed']);
        $this->assertNotNull($out['execution_blocked_reason']);
        $this->assertSame('Resolver workspace/repo do Projeto antes de qualquer execucao.', $out['required_next_action']);
    }

    /** Minimal-profile guard: risky work on a docs-incomplete project needs the profile first. */
    public function test_risky_work_on_incomplete_docs_requires_minimal_profile(): void
    {
        $project = [
            'project_id' => 'blackink',
            'name' => 'Blackink',
            'repo_root' => '/repos/blackink',
            // workspace_path + production_status intentionally missing.
            'docs_status' => 'incomplete',
        ];

        $out = $this->service()->routeWork($project, [
            'is_structural' => true,
            'files_touched' => 5,
        ]);

        $this->assertSame(AtlasCodeMultiProjectWorkspaceOsService::TIER_OBRA, $out['tier']);
        $this->assertTrue($out['requires_minimal_profile']);
        $this->assertContains('workspace_path', $out['missing_profile_fields']);
        $this->assertContains('production_status', $out['missing_profile_fields']);
        $this->assertSame('Criar Project Profile minimo antes de iniciar trabalho arriscado.', $out['required_next_action']);
    }

    /** The four documented work tiers are stable and in escalation order. */
    public function test_work_tiers_are_stable_and_ordered(): void
    {
        $this->assertSame(
            ['consulta', 'intervencao_rapida', 'candidato_de_obra', 'obra'],
            $this->service()->workTiers()
        );
    }
}
