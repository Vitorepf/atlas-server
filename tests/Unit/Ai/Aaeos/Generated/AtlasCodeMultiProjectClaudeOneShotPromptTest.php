<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCodeMultiProjectClaudeOneShotPromptService;
use Tests\TestCase;

/**
 * Pins the documented contract of the Atlas Code Multi-Project one-shot prompt:
 * the ten "Critérios de aceite", the section-6 "Safety/risk" admission rules and
 * the Project-is-not-Obra / no-fake-runtime invariants.
 *
 * @see docs/engineering-knowledge-base/atlas-code-multi-project-claude-one-shot-prompt.md
 */
final class AtlasCodeMultiProjectClaudeOneShotPromptTest extends TestCase
{
    private function service(): AtlasCodeMultiProjectClaudeOneShotPromptService
    {
        return new AtlasCodeMultiProjectClaudeOneShotPromptService();
    }

    /** A resolved, production-capable Atlas project profile. */
    private function atlasProject(): array
    {
        return [
            'workspace_path' => '/Users/vitorepf/develop/Atlas',
            'repo_root' => '/Users/vitorepf/develop/Atlas/atlas-server',
            'production_status' => 'production',
            'default_risk' => 'medium',
        ];
    }

    /** A plan that asserts every one of the ten acceptance criteria. */
    private function compliantPlan(): array
    {
        return [
            'work_type' => 'intervencao_rapida',
            'planned_commands' => [['command' => 'php artisan test', 'workspace_resolved' => true]],
            'treats_project_as_obra' => false,
            'claims_done_but_scaffold' => false,
            'acceptance_criteria' => [
                'project_read_model_exists' => true,
                'desktop_shows_active_project' => true,
                'existing_obras_still_work' => true,
                'create_select_obra_unbroken' => true,
                'no_giant_side_tree' => true,
                'design_system_preserved' => true,
                'atlas_blackink_are_projects' => true,
                'path_to_other_work_types_clear' => true,
                'no_fake_runtime' => true,
                'validations_reported' => true,
            ],
        ];
    }

    /** Empty plan against no workspace: rejected, all ten criteria missing. */
    public function test_empty_plan_is_rejected_with_all_criteria_missing(): void
    {
        $out = $this->service()->admitPlan([], []);

        $this->assertSame(AtlasCodeMultiProjectClaudeOneShotPromptService::DECISION_REJECT, $out['decision']);
        $this->assertFalse($out['admitted']);
        $this->assertSame(['satisfied' => 0, 'total' => 10], $out['acceptance_ratio']);
        // Default work type is the safest: consulta.
        $this->assertSame(AtlasCodeMultiProjectClaudeOneShotPromptService::WORK_CONSULTA, $out['work_type']);
    }

    /** A fully compliant plan against a resolved workspace is admitted. */
    public function test_fully_compliant_plan_is_admitted(): void
    {
        $out = $this->service()->admitPlan($this->atlasProject(), $this->compliantPlan());

        $this->assertSame(AtlasCodeMultiProjectClaudeOneShotPromptService::DECISION_ADMIT, $out['decision']);
        $this->assertTrue($out['admitted']);
        $this->assertSame([], $out['blocking_violations']);
        $this->assertSame([], $out['missing_acceptance_criteria']);
        $this->assertSame(['satisfied' => 10, 'total' => 10], $out['acceptance_ratio']);
    }

    /**
     * Section 6: with no resolved workspace, only Consulta/Descoberta is admissible.
     * Planning an Intervencao Rapida is blocked and execution is not allowed.
     */
    public function test_change_work_without_resolved_workspace_is_blocked(): void
    {
        $plan = $this->compliantPlan();
        // Remove the unscoped-command risk so the workspace rule is isolated.
        $plan['planned_commands'] = [];

        $out = $this->service()->admitPlan([], $plan); // empty project => no workspace_path

        $this->assertFalse($out['admitted']);
        $this->assertFalse($out['execution_allowed']);
        $this->assertContains(
            'execution_without_workspace: plano "intervencao_rapida" exige workspace resolvido; sem ele so Consulta/Descoberta e admissivel.',
            $out['blocking_violations']
        );
    }

    /** Section 6: a planned command without a resolved workspace is blocked. */
    public function test_unscoped_planned_command_is_blocked(): void
    {
        $plan = $this->compliantPlan();
        $plan['planned_commands'] = [['command' => 'php artisan db:wipe', 'workspace_resolved' => false]];

        $out = $this->service()->admitPlan($this->atlasProject(), $plan);

        $this->assertFalse($out['admitted']);
        $this->assertContains(
            'unscoped_command: comando planejado sem workspace/repo resolvido (php artisan db:wipe).',
            $out['blocking_violations']
        );
    }

    /** "Atlas/Blackink sao Projetos, nao Obras": framing a Project as an Obra is blocked. */
    public function test_treating_project_as_obra_is_blocked(): void
    {
        $plan = $this->compliantPlan();
        $plan['treats_project_as_obra'] = true;

        $out = $this->service()->admitPlan($this->atlasProject(), $plan);

        $this->assertFalse($out['admitted']);
        $this->assertContains(
            'project_treated_as_obra: plano trata o Projeto/Workspace inteiro como Obra (Atlas/Blackink sao Projetos, nao Obras).',
            $out['blocking_violations']
        );
    }

    /**
     * Section 6: a production project floors change-work risk to >= high, and a
     * single missing criterion still rejects even when nothing else blocks.
     */
    public function test_production_floors_risk_and_missing_criterion_rejects(): void
    {
        $plan = $this->compliantPlan(); // work_type = intervencao_rapida (change work)
        $plan['acceptance_criteria']['design_system_preserved'] = false;

        $out = $this->service()->admitPlan($this->atlasProject(), $plan);

        // Production + change work => effective risk elevated to high.
        $this->assertSame('high', $out['effective_risk']);
        $this->assertTrue($out['risk_elevated_for_production']);
        // One criterion missing => rejected with no blocking violation.
        $this->assertFalse($out['admitted']);
        $this->assertSame([], $out['blocking_violations']);
        $this->assertSame(['satisfied' => 9, 'total' => 10], $out['acceptance_ratio']);
        $this->assertContains(
            'design_system_preserved: Design system atual foi preservado.',
            $out['missing_acceptance_criteria']
        );
        // The ten criteria keys are stable and in documented order.
        $this->assertCount(10, $this->service()->acceptanceCriteriaKeys());
        $this->assertSame('project_read_model_exists', $this->service()->acceptanceCriteriaKeys()[0]);
    }
}
