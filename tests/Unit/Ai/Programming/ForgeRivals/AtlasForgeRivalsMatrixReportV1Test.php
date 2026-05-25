<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals;

use App\Console\Commands\AtlasForgeRivalsCommand;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsBatteryEvidenceService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsCollectEvidenceService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEvidencePolicy;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsMatrixReportService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsReportService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunPathResolver;
use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use Tests\TestCase;

/**
 * Atlas Forge Rivals · Matrix Report v1.
 *
 * Locks down the human-facing aggregate report contract:
 *
 *   - winner geral computed from comparable+scored cases,
 *   - per-category and per-L1-L5 rankings,
 *   - heatmap categoria × dificuldade,
 *   - planning_score vs execution_score split over strong quality evidence
 *     plus diagnostic-only patch-shape dimensions,
 *   - invalid/suspicious cases surfaced separately,
 *   - "onde Atlas é melhor / onde Rival é melhor",
 *   - Atlas Decide advisory signal per category + global,
 *   - markdown + JSON written to disk,
 *   - `insufficient_evidence` when zero comparable+scored cases exist,
 *   - `claim_ready=false` always; `external_rivals_certification='blocked'` always.
 */
final class AtlasForgeRivalsMatrixReportV1Test extends TestCase
{
    private string $tmpRoot;

    private AtlasForgeRivalsRunPathResolver $paths;

    private AtlasForgeRivalsCollectEvidenceService $collect;

    private AtlasForgeRivalsBatteryEvidenceService $battery;

    private AtlasForgeRivalsMatrixReportService $matrix;

    private mixed $oldEvidenceFloor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fr-matrix-'.bin2hex(random_bytes(6));
        @mkdir($this->tmpRoot, 0o755, true);
        $this->oldEvidenceFloor = config('atlas_rivals.min_free_bytes_before_provider_evidence');
        config(['atlas_rivals.runs_root' => $this->tmpRoot]);
        config(['atlas_rivals.min_free_bytes_before_provider_evidence' => 0]);

        $this->paths = new AtlasForgeRivalsRunPathResolver;
        $this->collect = new AtlasForgeRivalsCollectEvidenceService($this->paths);
        $this->battery = new AtlasForgeRivalsBatteryEvidenceService($this->paths, $this->collect);
        $this->matrix = new AtlasForgeRivalsMatrixReportService($this->paths, $this->battery);
    }

    protected function tearDown(): void
    {
        config(['atlas_rivals.min_free_bytes_before_provider_evidence' => $this->oldEvidenceFloor]);
        $this->purge($this->tmpRoot);
        parent::tearDown();
    }

    public function test_matrix_report_emits_overall_winner_from_comparable_scored_cases(): void
    {
        $runId = $this->seedBattery([
            $this->case('case-1', 'backend_logic', 'medium', 'comparable', atlas: 80, rival: 60),
            $this->case('case-2', 'backend_logic', 'medium', 'comparable', atlas: 78, rival: 62),
            $this->case('case-3', 'frontend_ui', 'easy', 'comparable', atlas: 65, rival: 70),
            $this->case('case-4', 'frontend_ui', 'hard', 'comparable', atlas: 90, rival: 85),
        ]);

        $result = $this->matrix->render([
            'run_ids' => [$runId],
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertSame('ok', $result['status']);
        $report = $result['matrix_report'];
        $this->assertSame(AtlasForgeRivalsMatrixReportService::SCHEMA_VERSION, $report['schema_version']);
        $this->assertSame(4, $report['comparable_scored_count']);
        $this->assertSame(AtlasForgeRivalsMatrixReportService::WINNER_ATLAS, $report['overall']['winner']);
        $this->assertSame(3, $report['overall']['atlas_wins']);
        $this->assertSame(1, $report['overall']['rival_wins']);
    }

    public function test_matrix_report_emits_category_ranking(): void
    {
        $runId = $this->seedBattery([
            $this->case('c1', 'backend_logic', 'medium', 'comparable', atlas: 90, rival: 60),
            $this->case('c2', 'backend_logic', 'medium', 'comparable', atlas: 85, rival: 65),
            $this->case('c3', 'frontend_ui', 'easy', 'comparable', atlas: 50, rival: 78),
        ]);

        $result = $this->matrix->render(['run_ids' => [$runId]]);
        $ranking = $result['matrix_report']['category_ranking'];
        $byCat = [];
        foreach ($ranking as $r) {
            $byCat[$r['category']] = $r;
        }

        $this->assertSame(2, $byCat['backend_logic']['atlas_wins']);
        $this->assertSame(0, $byCat['backend_logic']['rival_wins']);
        $this->assertSame('atlas', $byCat['backend_logic']['leader']);
        $this->assertSame('rival', $byCat['frontend_ui']['leader']);
    }

    public function test_matrix_report_emits_difficulty_l5_ranking(): void
    {
        $runId = $this->seedBattery([
            $this->case('c1', 'backend_logic', 'easy', 'comparable', atlas: 90, rival: 60),    // L1
            $this->case('c2', 'frontend_ui', 'medium', 'comparable', atlas: 80, rival: 60),    // L3
            $this->case('c3', 'architecture', 'hard', 'comparable', atlas: 95, rival: 50),     // L5
        ]);

        $result = $this->matrix->render(['run_ids' => [$runId]]);
        $ranking = $result['matrix_report']['difficulty_ranking'];
        $byLevel = [];
        foreach ($ranking as $r) {
            $byLevel[$r['level']] = $r;
        }
        $this->assertSame(['L1', 'L2', 'L3', 'L4', 'L5'], array_keys($byLevel));
        $this->assertSame(1, $byLevel['L1']['atlas_wins']);
        $this->assertSame(1, $byLevel['L3']['atlas_wins']);
        $this->assertSame(1, $byLevel['L5']['atlas_wins']);
        $this->assertSame(0, $byLevel['L2']['cases']);
        $this->assertSame(0, $byLevel['L4']['cases']);
        $this->assertNull($byLevel['L2']['leader']);
    }

    public function test_matrix_report_emits_heatmap_category_times_difficulty(): void
    {
        $runId = $this->seedBattery([
            $this->case('c1', 'backend_logic', 'easy', 'comparable', atlas: 90, rival: 60),
            $this->case('c2', 'backend_logic', 'hard', 'comparable', atlas: 60, rival: 90),
            $this->case('c3', 'frontend_ui', 'medium', 'comparable', atlas: 75, rival: 75),
        ]);

        $result = $this->matrix->render(['run_ids' => [$runId]]);
        $heatmap = $result['matrix_report']['heatmap'];
        $this->assertContains('backend_logic', $heatmap['categories']);
        $this->assertContains('frontend_ui', $heatmap['categories']);
        $this->assertSame(['L1', 'L2', 'L3', 'L4', 'L5'], $heatmap['levels']);
        $this->assertSame('atlas', $heatmap['cells']['backend_logic']['L1']['leader']);
        $this->assertSame('rival', $heatmap['cells']['backend_logic']['L5']['leader']);
        // frontend_ui/L3 is a clean tie (scores 75 vs 75 — within tie threshold)
        $this->assertSame('tie', $heatmap['cells']['frontend_ui']['L3']['leader']);
    }

    public function test_matrix_report_emits_planning_vs_execution_split(): void
    {
        $runId = $this->seedBattery([
            $this->case('c1', 'backend_logic', 'medium', 'comparable', atlas: 80, rival: 60, planningAtlasOverride: 85, planningRivalOverride: 70, executionAtlasOverride: 75, executionRivalOverride: 55),
        ]);

        $result = $this->matrix->render(['run_ids' => [$runId]]);
        $split = $result['matrix_report']['planning_vs_execution'];
        $this->assertSame(85.0, $split['planning']['atlas']);
        $this->assertSame(70.0, $split['planning']['rival']);
        $this->assertSame('atlas', $split['planning']['leader']);
        $this->assertSame(75.0, $split['execution']['atlas']);
        $this->assertSame(55.0, $split['execution']['rival']);
        $this->assertSame('atlas', $split['execution']['leader']);
        $this->assertSame(AtlasForgeRivalsMatrixReportService::PLANNING_DIMENSIONS, $split['planning']['dimensions']);
        $this->assertSame(AtlasForgeRivalsMatrixReportService::EXECUTION_DIMENSIONS, $split['execution']['dimensions']);
        $this->assertSame(75.0, $split['diagnostic_only']['atlas']);
        $this->assertSame(55.0, $split['diagnostic_only']['rival']);
        $this->assertSame(AtlasForgeRivalsMatrixReportService::DIAGNOSTIC_ONLY_DIMENSIONS, $split['diagnostic_only']['dimensions']);
        $this->assertTrue($split['diagnostic_only']['excluded_from_winner']);
        $this->assertSame(['cost_time_efficiency'], $split['telemetry_only']['dimensions']);
        $this->assertTrue($split['telemetry_only']['excluded_from_winner']);
        $this->assertFalse($result['matrix_report']['score_decision_policy']['winner_uses_cost_time_efficiency']);
        $this->assertFalse($result['matrix_report']['score_decision_policy']['winner_uses_patch_shape_heuristics']);
        $this->assertSame(['cost_time_efficiency'], $result['matrix_report']['score_decision_policy']['telemetry_only_dimensions']);
        $this->assertSame(AtlasForgeRivalsMatrixReportService::DIAGNOSTIC_ONLY_DIMENSIONS, $result['matrix_report']['score_decision_policy']['diagnostic_only_dimensions']);
        $this->assertSame('winner_uses_only_strong_evidence_dimensions', $result['matrix_report']['score_decision_policy']['strong_quality_decision_policy']);
    }

    public function test_matrix_report_emits_capability_ranking_for_360_diagnosis(): void
    {
        $runId = $this->seedBattery([
            $this->case('security-l5', 'security', 'hard', 'comparable', atlas: 91, rival: 87, capabilities: [
                'security_fail_closed',
                'threat_model_quality',
                'assumption_quality',
                'scope_boundary_probe',
            ]),
            $this->case('rollback-l5', 'architecture', 'hard', 'comparable', atlas: 84, rival: 84, capabilities: [
                'rollback_safety',
                'evidence_replay_completeness',
            ]),
        ]);

        $result = $this->matrix->render(['run_ids' => [$runId]]);
        $ranking = $result['matrix_report']['capability_ranking'];
        $this->assertSame('advisory', $ranking['status']);
        $this->assertFalse($ranking['floor_met']);
        $this->assertTrue($ranking['advisory_only']);
        $this->assertFalse($ranking['should_update_provider_topology']);
        $this->assertTrue($ranking['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $ranking['owner_of_model_routing']);
        $this->assertSame('none', $ranking['routing_effect']);

        $byCapability = [];
        foreach ($ranking['rows'] as $row) {
            $byCapability[$row['capability']] = $row;
        }

        $this->assertArrayHasKey('security_fail_closed', $byCapability);
        $this->assertArrayHasKey('threat_model_quality', $byCapability);
        $this->assertArrayHasKey('ambiguous_human_prompt_handling', $byCapability);
        $this->assertArrayHasKey('scope_boundary_discipline', $byCapability);
        $this->assertArrayHasKey('rollback_safety', $byCapability);
        $this->assertArrayHasKey('replayable_evidence_quality', $byCapability);
        $this->assertSame('tie', $byCapability['rollback_safety']['leader']);
        $this->assertSame('insufficient', $byCapability['security_fail_closed']['validity']);
        $this->assertSame('insufficient', $byCapability['scope_boundary_discipline']['validity']);
        $plan = $ranking['next_measurement_plan'];
        $this->assertSame('needs_more_measurement', $plan['status']);
        $this->assertTrue($plan['advisory_only']);
        $this->assertFalse($plan['should_update_provider_topology']);
        $this->assertTrue($plan['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $plan['owner_of_model_routing']);
        $this->assertSame('none', $plan['routing_effect']);
        $this->assertFalse($plan['provider_call']);
        $this->assertFalse($plan['tokens_spent']);

        $requirementsByCapability = [];
        foreach ($plan['requirements'] as $requirement) {
            $requirementsByCapability[$requirement['capability']] = $requirement;
        }
        $this->assertArrayHasKey('scope_boundary_discipline', $requirementsByCapability);
        $this->assertNotEmpty($requirementsByCapability['scope_boundary_discipline']['candidate_cases']);
        $this->assertStringContainsString('--dry-run --json', $requirementsByCapability['scope_boundary_discipline']['recommended_dry_run_commands'][0]);
        $this->assertStringContainsString('--arm-a=atlas_dev', $requirementsByCapability['scope_boundary_discipline']['recommended_dry_run_commands'][0]);
        $this->assertStringContainsString('--arm-b=claude_code', $requirementsByCapability['scope_boundary_discipline']['recommended_dry_run_commands'][0]);
        $this->assertStringNotContainsString('--task-category=architecture', $requirementsByCapability['scope_boundary_discipline']['recommended_dry_run_commands'][0]);
        $this->assertStringContainsString('--case=', $requirementsByCapability['scope_boundary_discipline']['recommended_dry_run_commands'][0]);
        $battleMatrix = $requirementsByCapability['scope_boundary_discipline']['battle_matrix'];
        $battleIds = array_column($battleMatrix, 'battle_id');
        $this->assertContains('atlas_forge_vs_claude_sonnet', $battleIds);
        $this->assertContains('atlas_dev_vs_atlas_forge', $battleIds);
        $this->assertContains('composer_2_5_vs_codex_gpt_5_5', $battleIds);
        $this->assertContains('cursor_default_vs_claude_sonnet', $battleIds);
        $this->assertContains('claude_sonnet_vs_codex_gpt_5_5', $battleIds);
        $this->assertContains('codex_gpt_5_5_vs_gemini_pro', $battleIds);
        $this->assertContains('claude_sonnet_vs_claude_opus', $battleIds);
        $this->assertContains('atlas_forge_full_power_vs_claude_opus', $battleIds);
        foreach ($battleMatrix as $battle) {
            $this->assertFalse($battle['provider_call']);
            $this->assertFalse($battle['tokens_spent']);
            $this->assertSame('none', $battle['routing_effect']);
            $this->assertStringContainsString('--dry-run --json', $battle['dry_run_command']);
            $this->assertStringContainsString('--case=', $battle['dry_run_command']);
            $this->assertSame(['runbook_reviewed', 'provider_cost', 'real_provider_call'], $battle['real_run_requires_confirmations']);
        }

        $paths = $this->paths->paths($runId);
        $md = (string) file_get_contents($paths['evidence'].'/'.AtlasForgeRivalsMatrixReportService::MATRIX_REPORT_MD_FILE);
        $this->assertStringContainsString('Ranking por capacidade (360)', $md);
    }

    public function test_matrix_report_marks_full_floor_ties_as_low_differentiation_not_strength_claim(): void
    {
        $required = AtlasForgeRivalsReportService::REQUIRED_360_CAPABILITIES;
        $runId = $this->seedBattery([
            $this->case('tie-l5-1', 'architecture', 'hard', 'comparable', atlas: 88, rival: 87, capabilities: $required),
            $this->case('tie-l5-2', 'refactor', 'hard', 'comparable', atlas: 86, rival: 86, capabilities: $required),
            $this->case('tie-l5-3', 'security', 'hard', 'comparable', atlas: 89, rival: 88, capabilities: $required),
        ]);

        $result = $this->matrix->render(['run_ids' => [$runId]]);
        $report = $result['matrix_report'];

        $this->assertSame('ok', $result['status']);
        $this->assertSame('tie', $report['overall']['winner']);
        $this->assertTrue($report['capability_ranking']['floor_met']);
        $this->assertSame('low_differentiation', $report['differentiation']['status']);
        $this->assertSame([], $report['differentiation']['required_differentiated_capabilities']);
        $this->assertSame($required, $report['differentiation']['required_tied_capabilities']);
        $this->assertTrue($report['differentiation']['tie_is_diagnostic_not_claim']);
        $this->assertFalse($report['differentiation']['should_update_provider_topology']);
        $this->assertSame('none', $report['differentiation']['routing_effect']);
        $this->assertSame('run_extreme_differentiator_cases_targeting_required_tied_or_insufficient_capabilities', $report['differentiation']['next_action']);
        $this->assertSame('atlas.forge.rivals.tie_pressure_diagnosis.v1', $report['tie_pressure_diagnosis']['schema_version']);
        $this->assertSame('per_level_tie_escalation_cancel_and_increase_complexity', $report['tie_pressure_diagnosis']['status']);
        $this->assertSame(1.0, $report['tie_pressure_diagnosis']['tie_rate']);
        $this->assertSame(1.0, $report['tie_pressure_diagnosis']['l5_tie_rate']);
        $this->assertTrue($report['tie_pressure_diagnosis']['requires_harder_followup']);
        $this->assertTrue($report['tie_pressure_diagnosis']['tie_escalation_policy']['should_cancel_current_battery']);
        $this->assertSame(10, $report['tie_pressure_diagnosis']['tie_escalation_policy']['cancel_after_tie_count']);
        $this->assertSame(0.55, $report['tie_pressure_diagnosis']['tie_escalation_policy']['max_technical_tie_rate_per_difficulty_level']);
        $this->assertSame(['L5'], $report['tie_pressure_diagnosis']['tie_escalation_policy']['levels_to_reinforce']);
        $this->assertSame(['L5'], $report['tie_pressure_diagnosis']['per_level_tie_escalation']['levels_exceeding_tie_budget']);
        $this->assertTrue($report['tie_pressure_diagnosis']['per_level_tie_escalation']['should_cancel_current_battery']);
        $this->assertSame('telemetry_only_excluded_from_winner', $report['tie_pressure_diagnosis']['tie_escalation_policy']['cost_efficiency_role']);
        $this->assertSame($required, $report['tie_pressure_diagnosis']['target_capabilities']);
        $this->assertFalse($report['tie_pressure_diagnosis']['provider_call']);
        $this->assertFalse($report['tie_pressure_diagnosis']['tokens_spent']);
        $this->assertTrue($report['tie_pressure_diagnosis']['advisory_only']);
        $this->assertFalse($report['tie_pressure_diagnosis']['should_update_provider_topology']);
        $this->assertTrue($report['tie_pressure_diagnosis']['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $report['tie_pressure_diagnosis']['owner_of_model_routing']);
        $this->assertSame('none', $report['tie_pressure_diagnosis']['routing_effect']);
        $this->assertContains('tie_pressure_requires_harder_followup', $report['ceiling_360_completion_gap']['blockers']);
        $this->assertFalse($report['ceiling_360_completion_gap']['tie_pressure_resolved']);

        $paths = $this->paths->paths($runId);
        $md = (string) file_get_contents($paths['evidence'].'/'.AtlasForgeRivalsMatrixReportService::MATRIX_REPORT_MD_FILE);
        $this->assertStringContainsString('Diagnóstico de diferenciação', $md);
        $this->assertStringContainsString('Pressao de empate', $md);
        $this->assertStringContainsString('low_differentiation', $md);
    }

    public function test_matrix_report_cancels_battery_after_ten_ties_and_demands_harder_baseline(): void
    {
        $required = AtlasForgeRivalsReportService::REQUIRED_360_CAPABILITIES;
        $cases = [];
        foreach (range(1, 10) as $i) {
            $cases[] = $this->case(
                'tie-cancel-'.$i,
                'security',
                'hard',
                'comparable',
                atlas: 88,
                rival: 88,
                capabilities: $required,
            );
        }

        $runId = $this->seedBattery($cases);
        $report = $this->matrix->render(['run_ids' => [$runId]])['matrix_report'];
        $policy = $report['tie_pressure_diagnosis']['tie_escalation_policy'];

        $this->assertSame('per_level_tie_escalation_cancel_and_increase_complexity', $report['tie_pressure_diagnosis']['status']);
        $this->assertSame(10, $report['tie_pressure_diagnosis']['tie_count']);
        $this->assertTrue($report['tie_pressure_diagnosis']['requires_harder_followup']);
        $this->assertTrue($policy['should_cancel_current_battery']);
        $this->assertSame('cancel_current_battery_and_raise_complexity_for_over_tied_levels', $policy['required_action']);
        $this->assertSame('multi_capability_composite_l5_plus_plus', $policy['next_baseline_complexity']);
        $this->assertSame(['L5'], $policy['levels_to_reinforce']);
        $this->assertTrue($policy['must_metric_more_capabilities']);
        $this->assertSame('telemetry_only_excluded_from_winner', $policy['cost_efficiency_role']);
        $this->assertFalse($report['tie_pressure_diagnosis']['provider_call']);
        $this->assertFalse($report['tie_pressure_diagnosis']['tokens_spent']);
        $this->assertTrue($report['tie_pressure_diagnosis']['advisory_only']);
        $this->assertSame('none', $report['tie_pressure_diagnosis']['routing_effect']);
    }

    public function test_matrix_report_stops_any_difficulty_level_above_fifty_five_percent_technical_ties(): void
    {
        $runId = $this->seedBattery([
            $this->case('l1-tie-1', 'docs', 'easy', 'comparable', atlas: 80, rival: 80),
            $this->case('l1-tie-2', 'docs', 'easy', 'comparable', atlas: 81, rival: 81),
            $this->case('l1-atlas-1', 'docs', 'easy', 'comparable', atlas: 90, rival: 75),
            $this->case('l2-atlas-1', 'bugfix', 'medium', 'comparable', atlas: 90, rival: 75),
            $this->case('l2-rival-1', 'bugfix', 'medium', 'comparable', atlas: 75, rival: 90),
        ]);

        $report = $this->matrix->render(['run_ids' => [$runId]])['matrix_report'];
        $perLevel = $report['tie_pressure_diagnosis']['per_level_tie_escalation'];

        $this->assertSame('per_level_tie_escalation_cancel_and_increase_complexity', $report['tie_pressure_diagnosis']['status']);
        $this->assertSame(['L1'], $perLevel['levels_exceeding_tie_budget']);
        $this->assertTrue($perLevel['should_cancel_current_battery']);
        $this->assertSame(0.55, $perLevel['max_allowed_technical_tie_rate']);
        $l1 = collect($perLevel['rows'])->firstWhere('level', 'L1');
        $this->assertSame(3, $l1['case_count']);
        $this->assertSame(2, $l1['technical_tie_count']);
        $this->assertSame(0.6667, $l1['technical_tie_rate']);
        $this->assertTrue($l1['exceeds_tie_budget']);
        $this->assertSame('stop_this_level_and_increase_complexity_functions_and_capability_measurement', $l1['required_action']);
        $this->assertFalse($perLevel['provider_call']);
        $this->assertFalse($perLevel['tokens_spent']);
        $this->assertTrue($perLevel['advisory_only']);
        $this->assertSame('none', $perLevel['routing_effect']);
    }

    public function test_matrix_report_does_not_call_one_win_inside_many_ties_differentiated(): void
    {
        $required = AtlasForgeRivalsReportService::REQUIRED_360_CAPABILITIES;
        $runId = $this->seedBattery([
            $this->case('atlas-l5-one', 'bugfix', 'hard', 'comparable', atlas: 91, rival: 84, capabilities: $required),
            $this->case('tie-l5-one', 'architecture', 'hard', 'comparable', atlas: 88, rival: 87, capabilities: $required),
            $this->case('tie-l5-two', 'backend', 'hard', 'comparable', atlas: 86, rival: 86, capabilities: $required),
            $this->case('tie-l5-three', 'docs', 'hard', 'comparable', atlas: 89, rival: 88, capabilities: $required),
        ]);

        $report = $this->matrix->render(['run_ids' => [$runId]])['matrix_report'];
        $firstCapability = $report['capability_ranking']['rows'][0];

        $this->assertSame('atlas', $report['overall']['winner']);
        $this->assertSame(0.75, $firstCapability['tie_rate']);
        $this->assertSame('tied_high_tie_rate', $firstCapability['separation_state']);
        $this->assertSame('low_differentiation', $report['differentiation']['status']);
        $this->assertSame([], $report['differentiation']['required_differentiated_capabilities']);
        $this->assertTrue($report['tie_pressure_diagnosis']['requires_harder_followup']);
        $this->assertSame('none', $report['differentiation']['routing_effect']);
    }

    public function test_matrix_report_marks_repeated_l5_capability_wins_as_differentiated_signal(): void
    {
        $required = AtlasForgeRivalsReportService::REQUIRED_360_CAPABILITIES;
        $runId = $this->seedBattery([
            $this->case('atlas-l5-1', 'architecture', 'hard', 'comparable', atlas: 96, rival: 80, capabilities: $required),
            $this->case('atlas-l5-2', 'refactor', 'hard', 'comparable', atlas: 94, rival: 81, capabilities: $required),
            $this->case('atlas-l5-3', 'security', 'hard', 'comparable', atlas: 95, rival: 82, capabilities: $required),
        ]);

        $report = $this->matrix->render(['run_ids' => [$runId]])['matrix_report'];

        $this->assertSame('atlas', $report['overall']['winner']);
        $this->assertSame('differentiated', $report['differentiation']['status']);
        $this->assertSame($required, $report['differentiation']['required_differentiated_capabilities']);
        $this->assertSame([], $report['differentiation']['required_tied_capabilities']);
        $this->assertSame(1.0, $report['differentiation']['separation_ratio']);
        $this->assertSame('continue_repetition_for_confidence_and_cost_receipts', $report['differentiation']['next_action']);
        $this->assertSame('differentiating_enough_for_current_sample', $report['tie_pressure_diagnosis']['status']);
        $this->assertFalse($report['tie_pressure_diagnosis']['requires_harder_followup']);
    }

    public function test_matrix_report_aggregates_ceiling_360_contract_signal_across_l5_cases(): void
    {
        $runId = $this->seedBattery([
            $this->case('ceiling-atlas-ahead', 'refactor', 'hard', 'comparable', atlas: 86, rival: 84, ceilingContract: [
                'atlas' => 100.0,
                'rival' => 85.714,
                'markers' => [
                    'required' => true,
                    'atlas' => [
                        'facts_assumptions_decisions_split' => true,
                        'rollback_plan' => true,
                        'production_invariant_reasoning' => true,
                    ],
                    'rival' => [
                        'facts_assumptions_decisions_split' => false,
                        'rollback_plan' => true,
                        'production_invariant_reasoning' => true,
                    ],
                ],
            ]),
            $this->case('ceiling-shared-gap', 'security', 'hard', 'comparable', atlas: 83, rival: 83, ceilingContract: [
                'atlas' => 57.143,
                'rival' => 57.143,
                'markers' => [
                    'required' => true,
                    'atlas' => [
                        'facts_assumptions_decisions_split' => false,
                        'rollback_plan' => false,
                        'production_invariant_reasoning' => false,
                    ],
                    'rival' => [
                        'facts_assumptions_decisions_split' => false,
                        'rollback_plan' => false,
                        'production_invariant_reasoning' => false,
                    ],
                ],
            ]),
        ]);

        $report = $this->matrix->render(['run_ids' => [$runId]])['matrix_report'];
        $matrix = $report['ceiling_360_contract_matrix'];

        $this->assertSame(AtlasForgeRivalsMatrixReportService::CEILING_360_CONTRACT_MATRIX_SCHEMA_VERSION, $matrix['schema_version']);
        $this->assertSame('differentiated_with_contract_floor_gap', $matrix['status']);
        $this->assertSame(2, $matrix['observed_cases']);
        $this->assertSame(0, $matrix['missing_contract_cases']);
        $this->assertSame(1, $matrix['differentiated_cases']);
        $this->assertSame(1, $matrix['atlas_ahead_cases']);
        $this->assertSame(0, $matrix['rival_ahead_cases']);
        $this->assertSame(1, $matrix['tie_cases']);
        $this->assertSame(2, $matrix['below_contract_floor_cases']);
        $this->assertSame(100.0, $matrix['contract_floor_score']);
        $this->assertSame(0.5, $matrix['separation_ratio']);
        $this->assertSame(1, $matrix['shared_missing_markers']['rollback_plan']);
        $this->assertSame(2, $matrix['any_missing_markers']['facts_assumptions_decisions_split']);
        $plan = $matrix['next_measurement_plan'];
        $this->assertSame('atlas.forge.rivals.ceiling_360_marker_next_measurement_plan.v1', $plan['schema_version']);
        $this->assertSame('needs_more_marker_pressure', $plan['status']);
        $this->assertSame('multi_capability_composite_pressure', $plan['planning_mode']);
        $this->assertTrue($plan['single_marker_plan_is_advisory']);
        $this->assertTrue($plan['dry_run_ready']);
        $this->assertContains($plan['real_run_ready'], [true, false], 'real readiness depends on local disk guard');
        $this->assertArrayHasKey('evidence_disk_status', $plan);
        $this->assertSame(['runbook_reviewed', 'provider_cost', 'real_provider_call'], $plan['required_confirmations_for_real_run']);
        $this->assertFalse($plan['provider_call']);
        $this->assertFalse($plan['tokens_spent']);
        $this->assertTrue($plan['advisory_only']);
        $this->assertFalse($plan['should_update_provider_topology']);
        $this->assertTrue($plan['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $plan['owner_of_model_routing']);
        $this->assertSame('none', $plan['routing_effect']);
        $requirementsByMarker = [];
        foreach ($plan['requirements'] as $requirement) {
            $requirementsByMarker[$requirement['marker']] = $requirement;
        }
        $this->assertArrayHasKey('facts_assumptions_decisions_split', $requirementsByMarker);
        $this->assertArrayHasKey('rollback_plan', $requirementsByMarker);
        $this->assertContains('ambiguous_human_prompt_handling', $requirementsByMarker['facts_assumptions_decisions_split']['target_capabilities']);
        $this->assertContains('rollback_safety', $requirementsByMarker['rollback_plan']['target_capabilities']);
        $this->assertNotEmpty($requirementsByMarker['facts_assumptions_decisions_split']['candidate_cases']);
        $this->assertStringContainsString('--dry-run --json', $requirementsByMarker['facts_assumptions_decisions_split']['recommended_dry_run_commands'][0]);
        $this->assertStringContainsString('--case=', $requirementsByMarker['facts_assumptions_decisions_split']['recommended_dry_run_commands'][0]);
        $this->assertSame('facts_assumptions_decisions_split', $requirementsByMarker['facts_assumptions_decisions_split']['battle_matrix'][0]['marker']);
        $this->assertFalse($requirementsByMarker['facts_assumptions_decisions_split']['battle_matrix'][0]['provider_call']);
        $this->assertFalse($requirementsByMarker['facts_assumptions_decisions_split']['battle_matrix'][0]['tokens_spent']);
        $this->assertSame('none', $requirementsByMarker['facts_assumptions_decisions_split']['battle_matrix'][0]['routing_effect']);
        $compositePlan = $plan['composite_next_measurement_plan'];
        $this->assertSame('atlas.forge.rivals.ceiling_360_composite_next_measurement_plan.v1', $compositePlan['schema_version']);
        $this->assertSame('needs_multi_capability_pressure', $compositePlan['status']);
        $this->assertNotEmpty($compositePlan['candidate_cases']);
        $this->assertNotEmpty($compositePlan['recommended_dry_run_commands']['atlas_dev_vs_claude_sonnet']);
        $this->assertStringContainsString('--arm-a=atlas_dev', $compositePlan['recommended_dry_run_commands']['atlas_dev_vs_claude_sonnet'][0]);
        $this->assertStringContainsString('--arm-b=claude_code', $compositePlan['recommended_dry_run_commands']['atlas_dev_vs_claude_sonnet'][0]);
        $this->assertFalse($compositePlan['provider_call']);
        $this->assertFalse($compositePlan['tokens_spent']);
        $this->assertTrue($compositePlan['advisory_only']);
        $this->assertFalse($compositePlan['should_update_provider_topology']);
        $this->assertTrue($compositePlan['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $compositePlan['owner_of_model_routing']);
        $this->assertSame('none', $compositePlan['routing_effect']);
        $this->assertTrue($matrix['advisory_only']);
        $this->assertFalse($matrix['should_update_provider_topology']);
        $this->assertTrue($matrix['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $matrix['owner_of_model_routing']);
        $this->assertSame('none', $matrix['routing_effect']);

        $byCase = [];
        foreach ($matrix['cases'] as $case) {
            $byCase[$case['case_id']] = $case;
        }
        $this->assertSame('atlas', $byCase['ceiling-atlas-ahead']['leader']);
        $this->assertFalse($byCase['ceiling-atlas-ahead']['floor_met']);
        $this->assertTrue($byCase['ceiling-atlas-ahead']['marker_delta']['facts_assumptions_decisions_split']['atlas']);
        $this->assertFalse($byCase['ceiling-atlas-ahead']['marker_delta']['facts_assumptions_decisions_split']['rival']);
        $this->assertSame('tie', $byCase['ceiling-shared-gap']['leader']);

        $paths = $this->paths->paths($runId);
        $md = (string) file_get_contents($paths['evidence'].'/'.AtlasForgeRivalsMatrixReportService::MATRIX_REPORT_MD_FILE);
        $this->assertStringContainsString('Matriz ceiling_360_contract', $md);
        $this->assertStringContainsString('differentiated_with_contract_floor_gap', $md);
        $this->assertStringContainsString('Marker next measurement', $md);
    }

    public function test_matrix_report_requires_battle_diversity_before_claiming_360_model_coverage(): void
    {
        $runId = $this->seedBattery([
            $this->case('same-battle-1', 'architecture', 'hard', 'comparable', atlas: 88, rival: 87, capabilities: AtlasForgeRivalsReportService::REQUIRED_360_CAPABILITIES),
            $this->case('same-battle-2', 'security', 'hard', 'comparable', atlas: 86, rival: 86, capabilities: AtlasForgeRivalsReportService::REQUIRED_360_CAPABILITIES),
            $this->case('same-battle-3', 'frontend_ui', 'hard', 'comparable', atlas: 89, rival: 88, capabilities: AtlasForgeRivalsReportService::REQUIRED_360_CAPABILITIES),
        ]);

        $report = $this->matrix->render(['run_ids' => [$runId]])['matrix_report'];
        $coverage = $report['battle_coverage'];
        $completionGap = $report['ceiling_360_completion_gap'];

        $this->assertSame('atlas.forge.rivals.matrix_battle_coverage.v1', $coverage['schema_version']);
        $this->assertSame('needs_more_battle_diversity', $coverage['status']);
        $this->assertFalse($coverage['floor_met']);
        $this->assertSame(9, $coverage['canonical_battle_count']);
        $this->assertSame(1, $coverage['observed_battle_count']);
        $this->assertSame(8, $coverage['missing_battle_count']);
        $this->assertSame(0.1111, $coverage['coverage_ratio']);
        $this->assertSame('atlas_forge_vs_claude_sonnet', $coverage['observed_battles'][0]['battle_id']);
        $this->assertSame(3, $coverage['observed_battles'][0]['cases']);
        $this->assertSame('needs_missing_canonical_battles', $coverage['next_measurement_plan']['status']);
        $this->assertSame(8, $coverage['next_measurement_plan']['required_real_runs_remaining']);
        $this->assertTrue($coverage['next_measurement_plan']['dry_run_ready']);
        $this->assertContains($coverage['next_measurement_plan']['real_run_ready'], [true, false], 'real readiness depends on local disk guard');
        $this->assertArrayHasKey('evidence_disk_status', $coverage['next_measurement_plan']);
        $this->assertNotEmpty($coverage['next_measurement_plan']['dry_run_commands']);
        $this->assertNotEmpty($coverage['next_measurement_plan']['real_run_commands_when_ready']);
        $this->assertSame(['runbook_reviewed', 'provider_cost', 'real_provider_call'], $coverage['next_measurement_plan']['required_confirmations_for_real_run']);
        $this->assertFalse($coverage['next_measurement_plan']['provider_call']);
        $this->assertFalse($coverage['next_measurement_plan']['tokens_spent']);
        $this->assertTrue($coverage['next_measurement_plan']['advisory_only']);
        $this->assertFalse($coverage['next_measurement_plan']['should_update_provider_topology']);
        $this->assertTrue($coverage['next_measurement_plan']['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $coverage['next_measurement_plan']['owner_of_model_routing']);
        $this->assertSame('none', $coverage['next_measurement_plan']['routing_effect']);
        $missingIds = array_column($coverage['missing_canonical_battles'], 'id');
        $this->assertContains('atlas_dev_architecture_escalated_vs_claude_sonnet', $missingIds);
        $this->assertContains('atlas_dev_vs_atlas_forge', $missingIds);
        $this->assertContains('composer_2_5_vs_codex_gpt_5_5', $missingIds);
        $this->assertStringContainsString('--dry-run --json', $coverage['missing_canonical_battles'][0]['dry_run_command']);

        $this->assertSame('atlas.forge.rivals.ceiling_360_completion_gap.v1', $completionGap['schema_version']);
        $this->assertFalse($completionGap['ready_for_360_claim']);
        $this->assertSame(0.1111, $completionGap['battle_coverage_ratio']);
        $this->assertSame(8, $completionGap['required_real_runs_remaining']);
        $this->assertSame('atlas_dev_architecture_escalated_vs_claude_sonnet', $completionGap['next_missing_battle']['id']);
        $this->assertStringContainsString('--confirm-runbook-reviewed', $completionGap['next_real_run_command_when_ready']);
        $this->assertContains('canonical_battle_floor_not_met', $completionGap['blockers']);
        $this->assertFalse($completionGap['provider_call']);
        $this->assertFalse($completionGap['tokens_spent']);
        $this->assertTrue($completionGap['advisory_only']);
        $this->assertFalse($completionGap['should_update_provider_topology']);
        $this->assertTrue($completionGap['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $completionGap['owner_of_model_routing']);
        $this->assertSame('none', $completionGap['routing_effect']);

        $missingById = [];
        foreach ($coverage['missing_canonical_battles'] as $battle) {
            $missingById[$battle['id']] = $battle;
        }

        $this->assertSame('architecture', $missingById['atlas_dev_architecture_escalated_vs_claude_sonnet']['task_category']);
        $this->assertSame('ceiling-360-003-industrial-015-incomplete_requirements', $missingById['atlas_dev_architecture_escalated_vs_claude_sonnet']['case_id']);
        $this->assertStringContainsString('--arm-a=atlas_dev', $missingById['atlas_dev_architecture_escalated_vs_claude_sonnet']['dry_run_command']);
        $this->assertStringContainsString('--arm-b=claude_code', $missingById['atlas_dev_architecture_escalated_vs_claude_sonnet']['dry_run_command']);
        $this->assertStringContainsString('--task-category=architecture', $missingById['atlas_dev_architecture_escalated_vs_claude_sonnet']['dry_run_command']);

        $this->assertSame('refactor', $missingById['atlas_dev_vs_atlas_forge']['task_category']);
        $this->assertSame('ceiling-360-001-industrial-005-incident_rollback', $missingById['atlas_dev_vs_atlas_forge']['case_id']);
        $this->assertStringContainsString('--task-category=refactor', $missingById['atlas_dev_vs_atlas_forge']['dry_run_command']);
        $this->assertStringContainsString('--case=ceiling-360-001-industrial-005-incident_rollback', $missingById['atlas_dev_vs_atlas_forge']['dry_run_command']);
        $this->assertStringNotContainsString('--task-category=architecture', $missingById['atlas_dev_vs_atlas_forge']['dry_run_command']);
        $this->assertStringContainsString('--confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call --json', $missingById['atlas_dev_vs_atlas_forge']['real_run_command_when_ready']);
        $this->assertStringNotContainsString('--dry-run', $missingById['atlas_dev_vs_atlas_forge']['real_run_command_when_ready']);
        $this->assertSame($missingById['atlas_dev_architecture_escalated_vs_claude_sonnet'], $coverage['next_measurement_plan']['next_missing_battle']);

        $paths = $this->paths->paths($runId);
        $md = (string) file_get_contents($paths['evidence'].'/'.AtlasForgeRivalsMatrixReportService::MATRIX_REPORT_MD_FILE);
        $this->assertStringContainsString('Cobertura de batalhas 360', $md);
        $this->assertStringContainsString('needs_more_battle_diversity', $md);
    }

    public function test_matrix_report_battle_plan_blocks_real_run_when_evidence_disk_floor_is_not_met(): void
    {
        config(['atlas_rivals.min_free_bytes_before_provider_evidence' => PHP_INT_MAX]);

        $runId = $this->seedBattery([
            $this->case('same-battle-disk-blocked', 'architecture', 'hard', 'comparable', atlas: 88, rival: 87, capabilities: AtlasForgeRivalsReportService::REQUIRED_360_CAPABILITIES),
        ]);

        $plan = $this->matrix->render(['run_ids' => [$runId]])['matrix_report']['battle_coverage']['next_measurement_plan'];

        $this->assertSame('needs_missing_canonical_battles', $plan['status']);
        $this->assertTrue($plan['dry_run_ready']);
        $this->assertFalse($plan['real_run_ready']);
        $this->assertSame('blocked', $plan['evidence_disk_status']['status']);
        $this->assertNotEmpty(array_filter(
            $plan['real_run_blockers'],
            static fn (string $blocker): bool => str_starts_with($blocker, 'provider_evidence_disk_space_insufficient:'),
        ));
        $this->assertFalse($plan['provider_call']);
        $this->assertFalse($plan['tokens_spent']);
        $this->assertSame('none', $plan['routing_effect']);
    }

    public function test_matrix_report_marker_plan_blocks_real_run_when_evidence_disk_floor_is_not_met(): void
    {
        $oldEvidenceFloor = config('atlas_rivals.min_free_bytes_before_provider_evidence');

        try {
            config(['atlas_rivals.min_free_bytes_before_provider_evidence' => PHP_INT_MAX]);

            $runId = $this->seedBattery([
                $this->case('ceiling-disk-blocked', 'architecture', 'hard', 'comparable', atlas: 84, rival: 84, ceilingContract: [
                    'atlas' => 57.143,
                    'rival' => 57.143,
                    'markers' => [
                        'required' => true,
                        'atlas' => [
                            'facts_assumptions_decisions_split' => false,
                            'failure_mode_matrix' => false,
                            'production_invariant_reasoning' => false,
                            'stop_block_criteria' => false,
                            'counterfactual_check' => false,
                            'blast_radius_quantification' => false,
                            'confidence_calibration' => false,
                        ],
                        'rival' => [
                            'facts_assumptions_decisions_split' => false,
                            'failure_mode_matrix' => false,
                            'production_invariant_reasoning' => false,
                            'stop_block_criteria' => false,
                            'counterfactual_check' => false,
                            'blast_radius_quantification' => false,
                            'confidence_calibration' => false,
                        ],
                    ],
                ]),
            ]);

            $plan = $this->matrix->render(['run_ids' => [$runId]])['matrix_report']['ceiling_360_contract_matrix']['next_measurement_plan'];

            $this->assertSame('needs_more_marker_pressure', $plan['status']);
            $this->assertTrue($plan['dry_run_ready']);
            $this->assertFalse($plan['real_run_ready']);
            $this->assertSame('blocked', $plan['evidence_disk_status']['status']);
            $this->assertNotEmpty(array_filter(
                $plan['real_run_blockers'],
                static fn (string $blocker): bool => str_starts_with($blocker, 'provider_evidence_disk_space_insufficient:'),
            ));
            $this->assertFalse($plan['provider_call']);
            $this->assertFalse($plan['tokens_spent']);
            $this->assertSame('none', $plan['routing_effect']);

            $byMarker = [];
            foreach ($plan['requirements'] as $requirement) {
                $byMarker[(string) $requirement['marker']] = $requirement;
            }
            $this->assertContains(
                'failure_mode_analysis',
                $byMarker['failure_mode_matrix']['target_capabilities'] ?? [],
            );
            $this->assertContains(
                'stop_block_criteria_quality',
                $byMarker['stop_block_criteria']['target_capabilities'] ?? [],
            );
            $this->assertContains(
                'counterfactual_reasoning',
                $byMarker['counterfactual_check']['target_capabilities'] ?? [],
            );
            $this->assertContains(
                'blast_radius_quantification',
                $byMarker['blast_radius_quantification']['target_capabilities'] ?? [],
            );
            $this->assertContains(
                'confidence_calibration',
                $byMarker['confidence_calibration']['target_capabilities'] ?? [],
            );
        } finally {
            config(['atlas_rivals.min_free_bytes_before_provider_evidence' => $oldEvidenceFloor]);
        }
    }

    public function test_matrix_report_separates_invalid_and_suspicious_cases(): void
    {
        $runId = $this->seedBattery([
            $this->case('valid', 'backend_logic', 'medium', 'comparable', atlas: 80, rival: 60),
            $this->case('inv', 'frontend_ui', 'easy', 'invalid_no_patch_diff', atlas: null, rival: null),
            $this->case('susp', 'architecture', 'hard', 'comparable', atlas: 80, rival: 60, hardFailures: ['verdict_comparable']),
        ]);

        $result = $this->matrix->render(['run_ids' => [$runId]]);
        $report = $result['matrix_report'];

        $invalidIds = array_map(static fn (array $c): string => $c['case_id'], $report['invalid_cases']);
        $suspiciousIds = array_map(static fn (array $c): string => $c['case_id'], $report['suspicious_cases']);
        $this->assertSame(['inv'], $invalidIds);
        $this->assertSame(['susp'], $suspiciousIds);
        $this->assertSame(1, $report['comparable_scored_count']);
    }

    public function test_matrix_report_emits_atlas_better_and_rival_better_lists(): void
    {
        $runId = $this->seedBattery([
            $this->case('c1', 'backend_logic', 'medium', 'comparable', atlas: 90, rival: 50),
            $this->case('c2', 'backend_logic', 'medium', 'comparable', atlas: 85, rival: 55),
            $this->case('c3', 'frontend_ui', 'easy', 'comparable', atlas: 40, rival: 80),
        ]);

        $result = $this->matrix->render(['run_ids' => [$runId]]);
        $atlasBetter = $result['matrix_report']['atlas_better_in']['categories'];
        $rivalBetter = $result['matrix_report']['rival_better_in']['categories'];
        $this->assertContains('backend_logic', $atlasBetter);
        $this->assertContains('frontend_ui', $rivalBetter);
    }

    public function test_matrix_report_emits_atlas_decide_advisory_signal(): void
    {
        $runId = $this->seedBattery([
            $this->case('c1', 'backend_logic', 'medium', 'comparable', atlas: 90, rival: 50),
            $this->case('c2', 'backend_logic', 'medium', 'comparable', atlas: 85, rival: 55),
            $this->case('c3', 'frontend_ui', 'easy', 'comparable', atlas: 50, rival: 78),
            $this->case('c4', 'frontend_ui', 'easy', 'comparable', atlas: 55, rival: 80),
        ]);

        $result = $this->matrix->render(['run_ids' => [$runId]]);
        $advice = $result['matrix_report']['atlas_decide_recommendation'];
        $entries = [];
        foreach ($advice['entries'] as $entry) {
            $entries[$entry['category']] = $entry;
        }
        $this->assertSame('advisory', $advice['status']);
        $this->assertTrue($advice['advisory_only']);
        $this->assertFalse($advice['should_update_provider_topology']);
        $this->assertTrue($advice['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $advice['owner_of_model_routing']);
        $this->assertSame('atlas_forge_measured_ahead', $entries['backend_logic']['signal']);
        $this->assertSame('rival_measured_ahead', $entries['frontend_ui']['signal']);
        $this->assertSame('split_measured_evidence_by_category', $advice['global_recommendation']);
    }

    public function test_matrix_report_writes_markdown_and_json_on_disk(): void
    {
        $runId = $this->seedBattery([
            $this->case('c1', 'backend_logic', 'medium', 'comparable', atlas: 80, rival: 60),
        ]);
        $result = $this->matrix->render(['run_ids' => [$runId]]);
        $paths = $this->paths->paths($runId);
        $jsonPath = $paths['evidence'].'/'.AtlasForgeRivalsMatrixReportService::MATRIX_REPORT_JSON_FILE;
        $mdPath = $paths['evidence'].'/'.AtlasForgeRivalsMatrixReportService::MATRIX_REPORT_MD_FILE;
        $this->assertFileExists($jsonPath);
        $this->assertFileExists($mdPath);
        $md = (string) file_get_contents($mdPath);
        $this->assertStringContainsString('# Atlas Forge Rivals · Matrix Report', $md);
        $this->assertStringContainsString('## TL;DR', $md);
        $this->assertStringContainsString('## Ranking por categoria', $md);
        $this->assertStringContainsString('## Heatmap', $md);
        $this->assertStringContainsString('planning_score vs execution_score', $md);
        $this->assertStringContainsString('Sinal medido para Atlas Decide', $md);
        $this->assertStringContainsString('claim_ready', $md);
    }

    public function test_matrix_report_returns_insufficient_evidence_when_no_comparable_cases(): void
    {
        $runId = $this->seedBattery([
            $this->case('c1', 'backend_logic', 'medium', 'invalid_no_patch_diff', atlas: null, rival: null),
        ]);

        $result = $this->matrix->render(['run_ids' => [$runId]]);
        $this->assertSame('insufficient_evidence', $result['status']);
        $report = $result['matrix_report'];
        $this->assertNull($report['overall']['winner']);
        $this->assertSame('insufficient_evidence', $report['atlas_decide_recommendation']['status']);
        $this->assertSame([], $report['atlas_decide_recommendation']['entries']);
    }

    public function test_matrix_report_keeps_claim_ready_false_and_external_rivals_blocked(): void
    {
        $runId = $this->seedBattery([
            $this->case('c1', 'backend_logic', 'medium', 'comparable', atlas: 90, rival: 50),
            $this->case('c2', 'frontend_ui', 'easy', 'comparable', atlas: 50, rival: 80),
        ]);
        $result = $this->matrix->render(['run_ids' => [$runId]]);
        $this->assertFalse($result['matrix_report']['claim_ready']);
        $this->assertSame('blocked', $result['matrix_report']['external_rivals_certification_status']);
        $this->assertTrue($result['matrix_report']['separated_from_external_rivals_certification']);
    }

    public function test_cli_matrix_report_writes_files_and_returns_strict_exit_on_insufficient(): void
    {
        $runId = $this->seedBattery([
            $this->case('c1', 'backend_logic', 'medium', 'invalid_no_patch_diff', atlas: null, rival: null),
        ]);
        $exit = $this->artisan('atlas:forge:rivals', [
            'action' => 'matrix-report',
            '--run-id' => $runId,
            '--stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
            '--json' => true,
            '--strict' => true,
        ])->run();
        $this->assertSame(1, $exit);
    }

    public function test_cli_matrix_report_writes_files_when_battery_is_valid(): void
    {
        $runId = $this->seedBattery([
            $this->case('c1', 'backend_logic', 'medium', 'comparable', atlas: 80, rival: 60),
        ]);
        $exit = $this->artisan('atlas:forge:rivals', [
            'action' => 'matrix-report',
            '--run-id' => $runId,
            '--stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
            '--json' => true,
        ])->run();
        $this->assertSame(0, $exit);
        $paths = $this->paths->paths($runId);
        $this->assertFileExists($paths['evidence'].'/'.AtlasForgeRivalsMatrixReportService::MATRIX_REPORT_JSON_FILE);
        $this->assertFileExists($paths['evidence'].'/'.AtlasForgeRivalsMatrixReportService::MATRIX_REPORT_MD_FILE);
    }

    public function test_cli_matrix_report_honors_repeated_run_id_values(): void
    {
        $firstRunId = $this->seedBattery([
            $this->case('c1', 'backend_logic', 'medium', 'comparable', atlas: 80, rival: 60),
        ]);
        $secondRunId = $this->seedBattery([
            $this->case('c2', 'frontend_ui', 'hard', 'comparable', atlas: 70, rival: 90),
        ]);

        $exit = $this->artisan('atlas:forge:rivals', [
            'action' => 'matrix-report',
            '--run-id' => [$firstRunId, $secondRunId],
            '--stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
            '--json' => true,
        ])->run();

        $this->assertSame(0, $exit);
        $paths = $this->paths->paths($firstRunId);
        $report = json_decode(
            (string) file_get_contents($paths['evidence'].'/'.AtlasForgeRivalsMatrixReportService::MATRIX_REPORT_JSON_FILE),
            true,
        );

        $this->assertSame([$firstRunId, $secondRunId], $report['run_ids'] ?? null);
        $this->assertSame(2, $report['case_count'] ?? null);
    }

    public function test_matrix_report_action_is_registered_in_cli(): void
    {
        $this->assertContains('matrix-report', AtlasForgeRivalsCommand::ACTIONS);
    }

    // --- helpers ---

    /**
     * @param  list<array<string,mixed>>  $caseSpecs
     */
    private function seedBattery(array $caseSpecs): string
    {
        $runId = 'matrix-multi-'.bin2hex(random_bytes(4));
        $paths = $this->paths->paths($runId);
        @mkdir($paths['base'], 0o755, true);
        @mkdir($paths['evidence'], 0o755, true);
        @mkdir($paths['evidence'].'/cases', 0o755, true);

        $manifestCases = [];
        $scorecardCases = [];

        foreach ($caseSpecs as $index => $spec) {
            $caseSubdir = 'case-'.($index + 1);
            $caseDir = $paths['evidence'].'/cases/'.$caseSubdir;
            @mkdir($caseDir, 0o755, true);
            file_put_contents($caseDir.'/atlas_receipt.json', $this->jsonEncode($this->makeReceipt('atlas')));
            file_put_contents($caseDir.'/rival_receipt.json', $this->jsonEncode($this->makeReceipt('rival')));
            file_put_contents($caseDir.'/atlas_patch.diff', "--- atlas patch {$spec['case_id']} ---\n");
            file_put_contents($caseDir.'/rival_patch.diff', "--- rival patch {$spec['case_id']} ---\n");
            file_put_contents($caseDir.'/atlas_test.log', "(case={$spec['case_id']})\n");
            file_put_contents($caseDir.'/rival_test.log', "(case={$spec['case_id']})\n");
            file_put_contents($caseDir.'/workspace_hashes.json', $this->jsonEncode([
                'before' => ['atlas' => 'h1', 'rival' => 'h1'],
                'after' => ['atlas' => 'h2', 'rival' => 'h2'],
                'dirty_after_run' => false,
                'workspace_blockers' => [],
            ]));

            $manifestCases[] = [
                'case_id' => $spec['case_id'],
                'case_index' => $index,
                'case_source' => 'provider_arena_corpus',
                'task_category' => $spec['task_category'],
                'case_set' => 'quick',
                'context_profile' => $spec['context_profile'] ?? null,
                'measurement_tags' => $spec['measurement_tags'] ?? [],
                'human_prompt_probe' => $spec['human_prompt_probe'] ?? null,
                'meta_provider_stress' => $spec['meta_provider_stress'] ?? null,
                'extreme_differentiator' => $spec['extreme_differentiator'] ?? null,
                'measured_capabilities' => $spec['capabilities'] ?? [],
                'difficulty' => $spec['difficulty'],
                'difficulty_level' => AtlasForgeRivalsProviderArenaCorpusService::difficultyToLevel($spec['difficulty']),
                'difficulty_weight' => AtlasForgeRivalsProviderArenaCorpusService::difficultyLevelWeight(
                    AtlasForgeRivalsProviderArenaCorpusService::difficultyToLevel($spec['difficulty']),
                ),
                'verdict' => $spec['verdict'],
                'evidence_subdir' => 'cases/'.$caseSubdir,
                'workspace_hash_before' => ['atlas' => 'h1', 'rival' => 'h1'],
                'workspace_hash_after' => ['atlas' => 'h2', 'rival' => 'h2'],
                'workspace_blockers' => [],
                'fixture_stage' => ['atlas' => ['status' => 'not_required'], 'rival' => ['status' => 'not_required']],
                'atlas_arm' => $this->makeArmEntry('atlas', $caseDir),
                'rival_arm' => $this->makeArmEntry('rival', $caseDir),
            ];

            if ($spec['verdict'] === 'comparable' && $spec['atlas_score'] !== null && $spec['rival_score'] !== null) {
                $scorecardCases[$spec['case_id']] = [
                    'atlas_score' => (float) $spec['atlas_score'],
                    'rival_score' => (float) $spec['rival_score'],
                    'planning_atlas' => $spec['planning_atlas'] ?? (float) $spec['atlas_score'],
                    'planning_rival' => $spec['planning_rival'] ?? (float) $spec['rival_score'],
                    'execution_atlas' => $spec['execution_atlas'] ?? (float) $spec['atlas_score'],
                    'execution_rival' => $spec['execution_rival'] ?? (float) $spec['rival_score'],
                    'hard_failures' => $spec['hard_failures'] ?? [],
                ];
            } elseif (! empty($spec['hard_failures'])) {
                // suspicious: hard failures but verdict still recorded
                $scorecardCases[$spec['case_id']] = [
                    'atlas_score' => null,
                    'rival_score' => null,
                    'hard_failures' => $spec['hard_failures'],
                    'score_source' => 'hard_failure',
                ];
            }
        }

        $manifest = [
            'schema_version' => 'atlas.forge.rivals.run_real.v1',
            'run_id' => $paths['run_id'],
            'mode' => 'provider_arena',
            'atlas_model' => 'claude_sonnet',
            'rival_model' => 'claude_sonnet',
            'arena_contracts' => [
                'arm_a' => [
                    'arm_id' => 'atlas_forge',
                    'provider' => 'claude',
                    'model_alias' => 'sonnet',
                    'requested_model' => 'sonnet',
                    'resolved_model_id' => 'claude-sonnet-4-6',
                ],
                'arm_b' => [
                    'arm_id' => 'claude_code',
                    'provider' => 'claude',
                    'model_alias' => 'sonnet',
                    'requested_model' => 'sonnet',
                    'resolved_model_id' => 'claude-sonnet-4-6',
                ],
                'advisory_only' => true,
                'should_update_provider_topology' => false,
                'never_changes_atlas_decide_topology' => true,
                'owner_of_model_routing' => 'atlas_decide',
                'routing_effect' => 'none',
            ],
            'preset' => 'quick',
            'case_id' => 'multi_case_aggregate',
            'case_source' => 'provider_arena_corpus',
            'case_set' => 'quick',
            'task_category' => $manifestCases[0]['task_category'] ?? 'backend_logic',
            'case_count' => count($manifestCases),
            'is_multi_case' => count($manifestCases) > 1,
            'cases' => $manifestCases,
            'fixture_stage' => ['atlas' => ['status' => 'not_required'], 'rival' => ['status' => 'not_required']],
            'started_at' => '2026-05-15T12:00:00+00:00',
            'finished_at' => '2026-05-15T12:30:00+00:00',
            'verdict' => $this->aggregateVerdict($caseSpecs),
            'score' => null,
            'claim_ready' => false,
            'atlas_receipt_hash' => hash('sha256', 'atlas'),
            'rival_receipt_hash' => hash('sha256', 'rival'),
            'workspace_hash_before' => ['atlas' => 'h1', 'rival' => 'h1'],
            'workspace_hash_after' => ['atlas' => 'h2', 'rival' => 'h2'],
            'dirty_after_run' => false,
            'workspace_blockers' => [],
            'workspace_changes_after_run' => ['atlas' => [], 'rival' => []],
            'external_provider_call' => true,
            'provider_tokens_spent' => true,
            'separated_from_external_rivals_certification' => true,
        ];
        file_put_contents($paths['manifest_json'], $this->jsonEncode($manifest));

        file_put_contents($paths['evidence'].'/atlas_receipt.json', $this->jsonEncode($this->makeReceipt('atlas')));
        file_put_contents($paths['evidence'].'/rival_receipt.json', $this->jsonEncode($this->makeReceipt('rival')));
        file_put_contents($paths['evidence'].'/workspace_hashes.json', $this->jsonEncode([
            'before' => ['atlas' => 'h1', 'rival' => 'h1'],
            'after' => ['atlas' => 'h2', 'rival' => 'h2'],
            'dirty_after_run' => false,
            'workspace_blockers' => [],
            'case_count' => count($manifestCases),
        ]));
        file_put_contents($paths['evidence'].'/atlas_patch.diff', "--- atlas top-level patch ---\n");
        file_put_contents($paths['evidence'].'/rival_patch.diff', "--- rival top-level patch ---\n");
        file_put_contents($paths['evidence'].'/atlas_test.log', "(top-level)\n");
        file_put_contents($paths['evidence'].'/rival_test.log', "(top-level)\n");
        file_put_contents(
            $paths['events_jsonl'],
            json_encode(['kind' => 'run_started']).PHP_EOL.
            json_encode(['kind' => 'heartbeat']).PHP_EOL.
            json_encode(['kind' => 'final_report']).PHP_EOL,
        );
        file_put_contents($paths['base'].'/intent.json', json_encode(['kind' => 'synthetic_matrix_battery']));

        // Synthesize a scorecard for the run aggregating the per-case scores.
        // The matrix report reads the run-level scorecard (per-case scorecard
        // detail lives inside `cases_breakdown`). For multi-case synthesis we
        // pick the first comparable+scored case as the aggregate scorecard
        // anchor; per-case breakdown is in the manifest's cases[] which our
        // matrix consumer re-reads case-by-case via the scorecard pointer.
        $firstComparable = null;
        foreach ($caseSpecs as $spec) {
            if ($spec['verdict'] === 'comparable' && $spec['atlas_score'] !== null) {
                $firstComparable = $spec;

                break;
            }
        }
        if ($firstComparable !== null) {
            $atlas = (float) $firstComparable['atlas_score'];
            $rival = (float) $firstComparable['rival_score'];
            $diff = $atlas - $rival;
            $winner = abs($diff) < 5.0 ? 'tie' : ($diff > 0 ? 'atlas' : 'rival');
            file_put_contents($paths['scorecard_json'], $this->jsonEncode([
                'schema_version' => 'atlas.forge.rivals.adjudication.v1',
                'winner' => $winner,
                'atlas_score' => $atlas,
                'rival_score' => $rival,
                'tie_threshold' => 5.0,
                'score_source' => 'quality_dimensions',
                'hard_failures' => [],
                'quality_dimensions' => $this->dimsForScores($firstComparable),
                'claim_ready' => $winner !== 'tie',
                'cases_breakdown' => $scorecardCases,
            ]));
        }

        // Write per-case scorecards adjacent to per-case subdirs so the matrix
        // service can read per-case quality_dimensions deterministically.
        foreach ($caseSpecs as $index => $spec) {
            if (! isset($scorecardCases[$spec['case_id']])) {
                continue;
            }
            $caseDir = $paths['evidence'].'/cases/case-'.($index + 1);
            $atlas = (float) ($spec['atlas_score'] ?? 0);
            $rival = (float) ($spec['rival_score'] ?? 0);
            $diff = $atlas - $rival;
            $winner = abs($diff) < 5.0 ? 'tie' : ($diff > 0 ? 'atlas' : 'rival');
            file_put_contents($caseDir.'/scorecard.json', $this->jsonEncode([
                'winner' => $winner,
                'atlas_score' => $atlas,
                'rival_score' => $rival,
                'tie_threshold' => 5.0,
                'score_source' => $spec['atlas_score'] === null ? 'hard_failure' : 'quality_dimensions',
                'hard_failures' => $spec['hard_failures'] ?? [],
                'quality_dimensions' => $spec['atlas_score'] === null ? null : $this->dimsForScores($spec),
            ]));
        }

        // For the matrix service, the read happens at the RUN scorecard. We
        // therefore stash the per-case scorecard summary under a key the
        // matrix service consumes via its battery aggregate. The matrix
        // service today reads `paths['scorecard_json']` once per run; multi
        // -case scoring is consumed through the per-case quality_dimensions
        // we embed in the run-level scorecard's `cases_breakdown` AND through
        // the per-case scorecard.json files we just wrote, both of which the
        // matrix service projects via the case digest's hard_failures + the
        // per-case scorecard lookup.

        return $runId;
    }

    /**
     * @param  array<string,mixed>  $spec
     */
    private function dimsForScores(array $spec): array
    {
        $atlas = (float) ($spec['atlas_score'] ?? 0);
        $rival = (float) ($spec['rival_score'] ?? 0);
        $planningAtlas = (float) ($spec['planning_atlas'] ?? $atlas);
        $planningRival = (float) ($spec['planning_rival'] ?? $rival);
        $executionAtlas = (float) ($spec['execution_atlas'] ?? $atlas);
        $executionRival = (float) ($spec['execution_rival'] ?? $rival);

        $dims = [];
        foreach (AtlasForgeRivalsMatrixReportService::PLANNING_DIMENSIONS as $key) {
            $dims[$key] = ['atlas' => $planningAtlas, 'rival' => $planningRival, 'explanation' => 'synthetic'];
        }
        foreach (AtlasForgeRivalsMatrixReportService::EXECUTION_DIMENSIONS as $key) {
            $dims[$key] = ['atlas' => $executionAtlas, 'rival' => $executionRival, 'explanation' => 'synthetic'];
        }
        foreach (AtlasForgeRivalsMatrixReportService::DIAGNOSTIC_ONLY_DIMENSIONS as $key) {
            $dims[$key] = ['atlas' => $executionAtlas, 'rival' => $executionRival, 'explanation' => 'diagnostic synthetic'];
        }
        if (is_array($spec['ceiling_contract'] ?? null)) {
            $dims['ceiling_360_contract'] = array_merge(
                ['explanation' => 'synthetic ceiling contract'],
                $spec['ceiling_contract'],
            );
        }

        return $dims;
    }

    /**
     * @param  list<array<string,mixed>>  $caseSpecs
     */
    private function aggregateVerdict(array $caseSpecs): string
    {
        foreach ($caseSpecs as $spec) {
            if (str_starts_with((string) $spec['verdict'], 'invalid')) {
                return 'comparable'; // The run-level verdict can still be comparable; per-case verdicts drive the matrix.
            }
        }

        return 'comparable';
    }

    /**
     * @return array<string,mixed>
     */
    private function case(
        string $caseId,
        string $taskCategory,
        string $difficulty,
        string $verdict,
        ?int $atlas,
        ?int $rival,
        ?int $planningAtlasOverride = null,
        ?int $planningRivalOverride = null,
        ?int $executionAtlasOverride = null,
        ?int $executionRivalOverride = null,
        array $hardFailures = [],
        array $capabilities = [],
        ?array $ceilingContract = null,
    ): array {
        return [
            'case_id' => $caseId,
            'task_category' => $taskCategory,
            'difficulty' => $difficulty,
            'verdict' => $verdict,
            'atlas_score' => $atlas,
            'rival_score' => $rival,
            'planning_atlas' => $planningAtlasOverride,
            'planning_rival' => $planningRivalOverride,
            'execution_atlas' => $executionAtlasOverride,
            'execution_rival' => $executionRivalOverride,
            'hard_failures' => $hardFailures,
            'capabilities' => $capabilities,
            'ceiling_contract' => $ceilingContract,
            'measurement_tags' => $capabilities,
            'extreme_differentiator' => $capabilities === [] ? null : [
                'capability_axes' => $capabilities,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function makeReceipt(string $arm): array
    {
        return [
            'arm' => $arm,
            'mode' => 'fair',
            'model' => 'claude_sonnet',
            'command_hash' => hash('sha256', $arm.'-cmd'),
            'prompt_hash' => hash('sha256', $arm.'-prompt'),
            'started_at' => '2026-05-15T12:00:00+00:00',
            'finished_at' => '2026-05-15T12:01:00+00:00',
            'exit_code' => 0,
            'killed' => false,
            'timeout' => false,
            'timeout_reason' => null,
            'stdout_hash' => hash('sha256', $arm.'-so'),
            'stderr_hash' => hash('sha256', $arm.'-se'),
            'stdout_bytes' => 4_000,
            'stderr_bytes' => 0,
            'stdout_tail' => 'tail',
            'stderr_tail' => '',
            'stdout_path' => '/tmp/stdout',
            'stderr_path' => '/tmp/stderr',
            'token_cost' => 0.01,
            'tokens_used' => 100,
            'worktree' => '/tmp/work',
            'case_id' => 'multi-case',
            'changed_files' => ['app/A.php'],
            'out_of_scope_files' => [],
            'bytecode_artifacts' => [],
            'workspace_blockers' => [],
            'workspace_has_blocking_changes' => false,
            'patch_diff_path' => '/tmp/patch.diff',
            'patch_diff_hash' => hash('sha256', $arm.'-pd'),
            'patch_diff_bytes' => 3_000,
            'test_command' => 'phpunit',
            'test_exit_code' => 0,
            'test_log_path' => '/tmp/test.log',
            'test_log_hash' => hash('sha256', $arm.'-tl'),
            'test_log_tail' => '(50 tests, 120 assertions)',
            'fake' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function makeArmEntry(string $arm, string $caseDir): array
    {
        $patchSha = hash_file('sha256', $caseDir.'/'.$arm.'_patch.diff') ?: null;
        $testSha = hash_file('sha256', $caseDir.'/'.$arm.'_test.log') ?: null;

        return [
            'exit_code' => 0,
            'test_exit_code' => 0,
            'killed' => false,
            'timeout_reason' => null,
            'patch_diff_bytes' => (int) (filesize($caseDir.'/'.$arm.'_patch.diff') ?: 0),
            'patch_diff_hash' => $patchSha,
            'patch_diff_path' => $caseDir.'/'.$arm.'_patch.diff',
            'test_log_path' => $caseDir.'/'.$arm.'_test.log',
            'test_log_hash' => $testSha,
            'changed_files' => ['app/'.ucfirst($arm).'.php'],
            'out_of_scope_files' => [],
            'bytecode_artifacts' => [],
        ];
    }

    private function jsonEncode(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function purge(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->purge($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
