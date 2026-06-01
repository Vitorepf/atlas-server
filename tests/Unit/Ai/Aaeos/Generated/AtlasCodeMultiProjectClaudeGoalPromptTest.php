<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCodeMultiProjectClaudeGoalPromptService;
use Tests\TestCase;

/**
 * Pins the documented contract of the Atlas Code Multi-Project `/goal` prompt:
 * the "Regras para IA" hard rejection rules, the "Obrigatorio" blocking clauses
 * and the seven-item "Definition of done".
 *
 * @see docs/engineering-knowledge-base/atlas-code-multi-project-claude-goal-prompt.md
 */
final class AtlasCodeMultiProjectClaudeGoalPromptTest extends TestCase
{
    private function service(): AtlasCodeMultiProjectClaudeGoalPromptService
    {
        return new AtlasCodeMultiProjectClaudeGoalPromptService();
    }

    /** A fully compliant report (every gate satisfied) for use as a baseline. */
    private function compliantReport(): array
    {
        return [
            'changed_files' => ['app/Models/Workspace.php'],
            'validations_run' => ['php artisan test --filter Workspace'],
            'residual_risks' => ['Blackink project not yet seeded.'],
            'next_steps' => ['Seed Blackink workspace.'],
            'commands_ran' => [['command' => 'php artisan migrate', 'workspace_resolved' => true]],
            'has_unmarked_scaffold' => false,
            'open_obvious_regressions' => [],
            'design_system_preserved' => true,
            'project_obra_separation_held' => true,
            'definition_of_done' => [
                'project_read_model_exists' => true,
                'desktop_shows_active_project' => true,
                'existing_obras_still_work' => true,
                'path_to_other_projects_clear' => true,
                'visual_design_consistent' => true,
                'validations_executed' => true,
                'final_answer_complete' => true,
            ],
        ];
    }

    /** An empty report must be rejected: it fails all four "Regras para IA". */
    public function test_empty_report_is_rejected_on_all_four_hard_rules(): void
    {
        $out = $this->service()->evaluateGoalReport([]);

        $this->assertSame(AtlasCodeMultiProjectClaudeGoalPromptService::DECISION_REJECT, $out['decision']);
        $this->assertFalse($out['accepted']);
        // no_changed_files, no_validations_run, no_residual_risks, no_next_steps.
        $this->assertCount(4, $out['rejection_rules_failed']);
        $this->assertSame(['satisfied' => 0, 'total' => 7], $out['definition_of_done_ratio']);
    }

    /** A fully compliant report is accepted with a clean tally. */
    public function test_fully_compliant_report_is_accepted(): void
    {
        $out = $this->service()->evaluateGoalReport($this->compliantReport());

        $this->assertSame(AtlasCodeMultiProjectClaudeGoalPromptService::DECISION_ACCEPT, $out['decision']);
        $this->assertTrue($out['accepted']);
        $this->assertSame([], $out['rejection_rules_failed']);
        $this->assertSame([], $out['blocking_violations']);
        $this->assertSame([], $out['missing_definition_of_done']);
        $this->assertSame(['satisfied' => 7, 'total' => 7], $out['definition_of_done_ratio']);
    }

    /** "Nao crie runtime fake" — unflagged scaffold blocks an otherwise-clean report. */
    public function test_unmarked_scaffold_blocks_acceptance(): void
    {
        $report = $this->compliantReport();
        $report['has_unmarked_scaffold'] = true;

        $out = $this->service()->evaluateGoalReport($report);

        $this->assertFalse($out['accepted']);
        $this->assertSame(AtlasCodeMultiProjectClaudeGoalPromptService::DECISION_REJECT, $out['decision']);
        $this->assertContains(
            'fake_runtime: scaffold nao marcado honestamente (doc proibe runtime fake).',
            $out['blocking_violations']
        );
    }

    /** "Nenhum comando roda sem workspace/repo resolvido" — unscoped execution blocks. */
    public function test_command_without_resolved_workspace_blocks_acceptance(): void
    {
        $report = $this->compliantReport();
        $report['commands_ran'] = [['command' => 'php artisan db:wipe', 'workspace_resolved' => false]];

        $out = $this->service()->evaluateGoalReport($report);

        $this->assertFalse($out['accepted']);
        $this->assertContains(
            'unscoped_execution: comando rodou sem workspace/repo resolvido (php artisan db:wipe).',
            $out['blocking_violations']
        );
    }

    /** Identity guard: design-system drift and Project/Obra confusion both block. */
    public function test_identity_drift_blocks_acceptance(): void
    {
        $report = $this->compliantReport();
        $report['design_system_preserved'] = false;
        $report['project_obra_separation_held'] = false;

        $out = $this->service()->evaluateGoalReport($report);

        $this->assertFalse($out['accepted']);
        $this->assertContains(
            'design_system_drift: tela Atlas Code virou chat/IDE/landing/arvore generica.',
            $out['blocking_violations']
        );
        $this->assertContains(
            'project_obra_confusion: separacao Projeto(software) vs Obra(trabalho) quebrada.',
            $out['blocking_violations']
        );
    }

    /** A single missing Definition-of-Done item rejects even with all hard rules passing. */
    public function test_missing_one_definition_of_done_item_rejects(): void
    {
        $report = $this->compliantReport();
        $report['definition_of_done']['desktop_shows_active_project'] = false;

        $out = $this->service()->evaluateGoalReport($report);

        $this->assertFalse($out['accepted']);
        $this->assertSame(['satisfied' => 6, 'total' => 7], $out['definition_of_done_ratio']);
        $this->assertContains(
            'desktop_shows_active_project: Desktop mostra Projeto ativo.',
            $out['missing_definition_of_done']
        );
        // The seven DoD keys are stable and in documented order.
        $this->assertSame('project_read_model_exists', $this->service()->definitionOfDoneKeys()[0]);
        $this->assertCount(7, $this->service()->definitionOfDoneKeys());
    }
}
