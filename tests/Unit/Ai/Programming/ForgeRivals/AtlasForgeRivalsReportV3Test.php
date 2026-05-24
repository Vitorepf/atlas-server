<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsAdjudicatorService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsCollectEvidenceService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsDoctorService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEventStream;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEvidencePolicy;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsReplayService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsReportService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunPathResolver;
use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use App\Services\Ai\Programming\WorkspaceHygieneService;
use Tests\TestCase;

/**
 * Atlas Forge Rivals · Report v3 contract.
 *
 * Locks down the human-first JSON envelope + Markdown report v3:
 * winner por categoria, confidence ladder, suspicious detection, claim
 * separation, atlas_decide_recommendations advisory_only, deterministic hash,
 * pt-BR markdown, multi-case aggregation when the cases/* layout exists.
 *
 * Never invokes provider, never unlocks external_rivals_certification.
 */
final class AtlasForgeRivalsReportV3Test extends TestCase
{
    private string $tmpRoot;

    private AtlasForgeRivalsRunPathResolver $paths;

    private AtlasForgeRivalsCollectEvidenceService $collect;

    private AtlasForgeRivalsReplayService $replay;

    private AtlasForgeRivalsAdjudicatorService $adjudicator;

    private AtlasForgeRivalsReportService $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fr-report-v3-'.bin2hex(random_bytes(6));
        @mkdir($this->tmpRoot, 0o755, true);
        config(['atlas_rivals.runs_root' => $this->tmpRoot]);

        $this->paths = new AtlasForgeRivalsRunPathResolver;
        $events = new AtlasForgeRivalsEventStream($this->paths);
        $this->collect = new AtlasForgeRivalsCollectEvidenceService($this->paths);
        $this->replay = new AtlasForgeRivalsReplayService($this->paths, $events);
        $this->adjudicator = new AtlasForgeRivalsAdjudicatorService($this->paths, $this->replay);
        $this->report = new AtlasForgeRivalsReportService($this->paths, $this->replay);
    }

    protected function tearDown(): void
    {
        $this->purge($this->tmpRoot);
        parent::tearDown();
    }

    public function test_1_valid_adjudication_yields_clear_headline(): void
    {
        $runId = $this->newRunId('valid-headline');
        $this->seedComparableQualityWinner($runId, atlas: 86.5, rival: 79.2);

        $report = $this->report->render(['run_id' => $runId]);

        $this->assertSame('ok', $report['status']);
        $this->assertSame('atlas.forge.rivals.report.v3', $report['schema_version']);
        $this->assertNotEmpty($report['headline']);
        $this->assertStringContainsString('caso', strtolower($report['headline']));
    }

    public function test_provider_signal_category_fit_uses_category_bucket_name(): void
    {
        $reflection = new \ReflectionMethod($this->report, 'buildAxisFit');
        $reflection->setAccessible(true);

        $fit = $reflection->invoke($this->report, [[
            'category' => 'bugfix',
            'cases' => 1,
            'winner' => AtlasForgeRivalsAdjudicatorService::WINNER_TIE,
            'validity' => 'insufficient',
            'validity_reason' => 'small_sample_less_than_three',
            'margin' => 0.0,
        ]], 'category');

        $this->assertSame('bugfix', $fit[0]['key']);
        $this->assertSame('none', $fit[0]['routing_effect']);
    }

    public function test_capability_results_expand_complexity_dimensions_and_measurement_tags(): void
    {
        $reflection = new \ReflectionMethod($this->report, 'buildCapabilityResults');
        $reflection->setAccessible(true);

        $results = $reflection->invoke($this->report, [[
            'case_id' => 'l5-hard-case',
            'atlas_score' => 91.0,
            'rival_score' => 84.0,
            'winner' => AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS,
            'suspicious' => false,
            'replay_passes' => true,
            'hard_failures' => [],
            'evidence_status' => 'evidence_ok',
            'complexity_profile' => [
                'schema_version' => 'atlas.forge.rivals.case_complexity_profile.v1',
                'requires_rollback_plan' => true,
                'measured_dimensions' => [
                    'long_context_retention',
                ],
            ],
            'measurement_tags' => [
                'ambiguity_handling',
                'rollback_safety',
            ],
            'measured_capabilities' => [
                'multi_step_execution',
                'evidence_replay_completeness',
                'assumption_quality',
            ],
        ]], 5.0);

        $keys = array_column($results, 'key');
        $this->assertContains('long_context_retention', $keys);
        $this->assertContains('rollback_safety', $keys);
        $this->assertContains('ambiguous_human_prompt_handling', $keys);
        $this->assertContains('multi_step_reasoning', $keys);
        $this->assertContains('replayable_evidence_quality', $keys);
        foreach ($results as $row) {
            $this->assertSame('capability', $row['axis']);
            $this->assertSame('none', $row['routing_effect']);
        }
    }

    public function test_2_inconclusive_when_evidence_invalid(): void
    {
        $runId = $this->newRunId('invalid-evidence');
        $this->seedInvalidEvidenceRun($runId);

        $report = $this->report->render(['run_id' => $runId]);

        $this->assertNull($report['winner']);
        $this->assertSame('inconclusive', $report['confidence']['level']);
        $this->assertStringContainsString('inconclusivo', strtolower($report['headline']));
    }

    public function test_3_inconclusive_when_replay_failing(): void
    {
        $runId = $this->newRunId('replay-failing');
        $this->seedReplayFailedRun($runId);

        $report = $this->report->render(['run_id' => $runId]);

        $this->assertFalse($report['replay_passes']);
        $this->assertSame('inconclusive', $report['confidence']['level']);
        $this->assertStringContainsString('replay', strtolower($report['headline']));
    }

    public function test_4_winner_per_category_in_release(): void
    {
        $runId = $this->newRunId('multi-case-release');
        $this->seedMultiCaseRelease($runId);

        $report = $this->report->render(['run_id' => $runId]);

        $this->assertSame('release', $report['preset']);
        $this->assertGreaterThanOrEqual(8, count($report['category_results']));
        $cats = array_column($report['category_results'], 'category');
        $this->assertContains('backend_logic', $cats);
        $this->assertContains('frontend_ui', $cats);
        $byCat = [];
        foreach ($report['category_results'] as $entry) {
            $byCat[$entry['category']] = $entry['winner'];
        }
        $this->assertSame('atlas', $byCat['backend_logic']);
        $this->assertSame('rival', $byCat['frontend_ui']);
    }

    public function test_5_statistical_tie_surfaces_as_human_review(): void
    {
        $runId = $this->newRunId('tie');
        $this->seedComparableTie($runId);

        $report = $this->report->render(['run_id' => $runId]);

        $this->assertSame(AtlasForgeRivalsAdjudicatorService::WINNER_TIE, $report['winner']);
        $this->assertTrue($report['human_review_required']);
        $this->assertTrue($report['human_review']['required']);
    }

    public function test_6_suspicious_low_claude_score_limits_confidence(): void
    {
        $runId = $this->newRunId('suspicious-claude');
        $this->seedSuspiciousLowRival($runId);

        $report = $this->report->render(['run_id' => $runId]);

        $this->assertNotEmpty($report['suspicious_results']);
        $this->assertSame('inconclusive', $report['confidence']['level']);
        $this->assertFalse($report['claim_status']['claim_ready']);
    }

    public function test_7_hard_failures_block_claim_and_appear(): void
    {
        $runId = $this->newRunId('hard-fail');
        $this->seedHardFailureScorecard($runId);

        $report = $this->report->render(['run_id' => $runId]);

        $this->assertNotEmpty($report['hard_failures']);
        $this->assertNull($report['winner']);
        $this->assertFalse($report['claim_status']['claim_ready']);
    }

    public function test_8_battery_result_valid_is_separated_from_external_claim(): void
    {
        $runId = $this->newRunId('separation');
        $this->seedMultiCaseRelease($runId);

        $report = $this->report->render(['run_id' => $runId]);

        $this->assertSame(
            'blocked_requires_operator_approval',
            $report['claim_status']['external_rivals_certification_status'],
        );
        $this->assertFalse($report['unlocks_external_rivals_certification']);
    }

    public function test_9_external_rivals_certification_stays_blocked_even_on_trusted(): void
    {
        $runId = $this->newRunId('trusted-still-blocked');
        $this->seedMultiCaseRelease($runId);

        $report = $this->report->render(['run_id' => $runId]);

        $this->assertSame(
            'blocked_requires_operator_approval',
            $report['safety']['external_rivals_certification_status'],
        );
    }

    public function test_10_claim_ready_false_when_confidence_insufficient(): void
    {
        $runId = $this->newRunId('quick-low-confidence');
        $this->seedComparableQualityWinner($runId, atlas: 86.5, rival: 79.2, preset: 'quick');

        $report = $this->report->render(['run_id' => $runId]);

        $this->assertSame('flow_validated', $report['confidence']['level']);
        $this->assertFalse($report['claim_status']['claim_ready']);
        $this->assertFalse($report['claim_ready']);
    }

    public function test_11_claim_ready_false_when_human_review_required(): void
    {
        $runId = $this->newRunId('tie-no-claim');
        $this->seedComparableTie($runId);

        $report = $this->report->render(['run_id' => $runId]);

        $this->assertFalse($report['claim_status']['claim_ready']);
    }

    public function test_12_can_feed_ledger_requires_valid_evidence_and_no_measurement_blockers(): void
    {
        $runId = $this->newRunId('feed-ledger');
        $this->seedMultiCaseRelease($runId);

        $report = $this->report->render(['run_id' => $runId]);

        $this->assertFalse($report['claim_status']['can_feed_ledger']);
        $this->assertContains('complexity_profile_coverage_incomplete', $report['claim_status']['ledger_blockers']);
        $this->assertContains('resolve_ledger_blockers', array_column($report['next_actions'], 'kind'));
        $this->assertNotContains('feed_ledger', array_column($report['next_actions'], 'kind'));

        $runIdBad = $this->newRunId('feed-ledger-bad');
        $this->seedInvalidEvidenceRun($runIdBad);
        $bad = $this->report->render(['run_id' => $runIdBad]);
        $this->assertFalse($bad['claim_status']['can_feed_ledger']);
    }

    public function test_13_atlas_decide_recommendations_are_advisory_only(): void
    {
        $runId = $this->newRunId('decide-advisory');
        $this->seedMultiCaseRelease($runId);

        $report = $this->report->render(['run_id' => $runId]);

        $this->assertTrue($report['atlas_decide_recommendations']['advisory_only']);
        $this->assertFalse($report['atlas_decide_recommendations']['should_update_provider_topology']);
        $this->assertTrue($report['atlas_decide_recommendations']['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $report['atlas_decide_recommendations']['owner_of_model_routing']);
        $this->assertSame([], $report['atlas_decide_recommendations']['primary_builder_by_category']);
        $this->assertSame([], $report['atlas_decide_recommendations']['reviewer_by_category']);
        $this->assertNotEmpty($report['atlas_decide_recommendations']['measured_signal_by_category']);
        $this->assertSame('none', $report['atlas_decide_recommendations']['measured_signal_by_category'][0]['routing_effect']);
    }

    public function test_14_markdown_report_is_generated_in_portuguese(): void
    {
        $runId = $this->newRunId('markdown-pt');
        $this->seedComparableQualityWinner($runId, atlas: 86.5, rival: 79.2);

        $report = $this->report->render(['run_id' => $runId]);

        $this->assertFileExists($report['report_path']);
        $body = (string) file_get_contents($report['report_path']);
        $this->assertStringContainsString('Resumo Executivo', $body);
        $this->assertStringContainsString('Resultado por Categoria', $body);
        $this->assertStringContainsString('Próximas Ações', $body);
    }

    public function test_15_json_report_has_schema_version_v3(): void
    {
        $runId = $this->newRunId('schema-v3');
        $this->seedComparableQualityWinner($runId, atlas: 86.5, rival: 79.2);

        $report = $this->report->render(['run_id' => $runId]);

        $this->assertSame('atlas.forge.rivals.report.v3', $report['schema_version']);
    }

    public function test_report_exposes_capability_results_and_signal_fit(): void
    {
        $runId = $this->newRunId('capability-fit');
        $complexity = [
            'schema_version' => 'atlas.forge.rivals.case_complexity_profile.v1',
            'difficulty_level' => 'L5',
            'difficulty_score' => 5,
            'estimated_context_tokens' => 6400,
            'reasoning_depth' => 5,
            'ambiguity_score' => 3,
            'risk_score' => 4,
            'pressure_level' => 'L5+',
            'long_context_required' => true,
            'requires_multi_step_plan' => true,
            'requires_rollback_plan' => true,
            'requires_evidence_matrix' => true,
            'requires_adversarial_constraints' => true,
            'requires_non_obvious_regression_probe' => true,
            'requires_honest_uncertainty_boundary' => true,
            'measured_dimensions' => [
                'long_context_retention',
                'rollback_safety',
            ],
        ];
        $this->seedSingleCaseManifest($runId, [
            'verdict' => 'comparable',
            'mode' => 'fair',
            'preset' => 'release',
            'case_id' => 'l5-capability-case',
            'task_category' => 'bugfix',
            'difficulty_band' => 'L5',
            'difficulty_level' => 'L5',
            'difficulty_score' => 5.0,
            'measurement_tags' => ['ambiguity_handling'],
            'measured_capabilities' => [
                'multi_step_execution',
                'evidence_replay_completeness',
                'assumption_quality',
            ],
            'context_profile' => [
                'schema_version' => 'atlas.forge.rivals.context_profile.v1',
                'prompt_style' => 'human_ambiguous_operator_ticket',
                'long_context_required' => true,
                'requires_assumption_log' => true,
                'requires_scope_boundary_reasoning' => true,
                'requires_replayable_evidence' => true,
                'complexity_profile' => $complexity,
            ],
            'human_prompt_probe' => [
                'schema_version' => 'atlas.forge.rivals.human_prompt_probe.v1',
                'complexity_profile' => $complexity,
                'requires_sections' => [
                    'facts_observed',
                    'assumptions',
                    'reversible_decisions',
                    'scope_boundaries',
                    'evidence_plan',
                    'replay_matrix',
                    'tradeoffs',
                    'honest_blockers',
                ],
            ],
        ]);
        $this->seedScorecard($runId, [
            'winner' => AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS,
            'atlas_score' => 91.0,
            'rival_score' => 84.0,
            'score_source' => 'quality_dimensions',
            'quality_score_available' => true,
            'hard_failures' => [],
            'tie_threshold' => AtlasForgeRivalsAdjudicatorService::DEFAULT_TIE_THRESHOLD,
            'winner_reason' => ['atlas_better_on_l5_capability_probe'],
            'hard_gates' => $this->okGates(),
            'quality_dimensions' => [
                'objective_alignment' => ['atlas' => 91.0, 'rival' => 84.0, 'explanation' => 'capability probe'],
                'ceiling_360_contract' => [
                    'atlas' => 100.0,
                    'rival' => 85.714,
                    'explanation' => 'ceiling markers',
                    'markers' => [
                        'required' => true,
                        'atlas' => [
                            'facts_assumptions_decisions_split' => true,
                            'tradeoff_matrix' => true,
                        ],
                        'rival' => [
                            'facts_assumptions_decisions_split' => false,
                            'tradeoff_matrix' => true,
                        ],
                    ],
                ],
            ],
        ]);
        $this->runCollectFinal($runId);

        $report = $this->report->render(['run_id' => $runId]);
        $capabilities = array_column($report['capability_results'], 'key');
        $signalCapabilities = array_column($report['provider_performance_signal']['capability_fit'], 'key');
        $body = (string) file_get_contents($report['report_path']);

        $this->assertContains('long_context_retention', $capabilities);
        $this->assertContains('rollback_safety', $capabilities);
        $this->assertContains('ambiguous_human_prompt_handling', $capabilities);
        $this->assertContains('multi_step_reasoning', $capabilities);
        $this->assertContains('replayable_evidence_quality', $capabilities);
        $this->assertContains('long_context_retention', $signalCapabilities);
        $complexityCoverage = $report['provider_performance_signal']['complexity_profile_coverage'];
        $this->assertSame(1, $complexityCoverage['high_ambiguity_cases']);
        $this->assertSame(1, $complexityCoverage['l5_plus_pressure_cases']);
        $this->assertSame(1, $complexityCoverage['adversarial_constraint_cases']);
        $this->assertSame(1, $complexityCoverage['non_obvious_regression_probe_cases']);
        $this->assertSame(1, $complexityCoverage['honest_uncertainty_boundary_cases']);
        $this->assertFalse($report['provider_performance_signal']['capability_coverage']['floor_met']);
        $this->assertContains('capability_floor_not_met', $report['provider_performance_signal']['ledger_blockers']);
        $this->assertContains('capability_floor_not_met', $report['claim_status']['ledger_blockers']);
        $separation = $report['provider_performance_signal']['capability_coverage']['separation'];
        $this->assertSame('atlas.forge.rivals.capability_separation.v1', $separation['schema_version']);
        $this->assertTrue($separation['tie_is_diagnostic_not_claim']);
        $this->assertContains('long_context_retention', $separation['insufficient_sample_capabilities']);
        $this->assertSame('insufficient_sample', $separation['capabilities']['long_context_retention']['state']);
        $this->assertSame('none', $separation['capabilities']['long_context_retention']['routing_effect']);
        $plan = $report['provider_performance_signal']['capability_coverage']['next_measurement_plan'];
        $ceilingSignal = $report['provider_performance_signal']['ceiling_360_contract_signal'];
        $this->assertSame('atlas.forge.rivals.ceiling_360_contract_signal.v1', $ceilingSignal['schema_version']);
        $this->assertSame('differentiated_with_contract_floor_gap', $ceilingSignal['status']);
        $this->assertSame(1, $ceilingSignal['observed_cases']);
        $this->assertSame(1, $ceilingSignal['differentiated_cases']);
        $this->assertSame(1, $ceilingSignal['atlas_ahead_cases']);
        $this->assertSame(100.0, $ceilingSignal['contract_floor_score']);
        $this->assertSame(1, $ceilingSignal['below_contract_floor_cases']);
        $this->assertSame(1, $ceilingSignal['any_missing_markers']['facts_assumptions_decisions_split']);
        $this->assertSame(1.0, $ceilingSignal['separation_ratio']);
        $this->assertTrue($ceilingSignal['tie_is_diagnostic_not_claim']);
        $this->assertSame('none', $ceilingSignal['routing_effect']);
        $this->assertFalse($ceilingSignal['cases'][0]['floor_met']);
        $this->assertTrue($ceilingSignal['cases'][0]['marker_delta']['facts_assumptions_decisions_split']['atlas']);
        $this->assertFalse($ceilingSignal['cases'][0]['marker_delta']['facts_assumptions_decisions_split']['rival']);
        $this->assertSame('needs_more_cases', $plan['status']);
        $this->assertNotEmpty($plan['requirements']);
        $firstRequirement = $plan['requirements'][0];
        $this->assertArrayHasKey('additional_cases_needed', $firstRequirement);
        $this->assertContains('extreme-differentiator', $report['provider_performance_signal']['capability_coverage']['recommended_case_sets']);
        $commands = $plan['recommended_commands'];
        $this->assertNotEmpty($commands);
        $this->assertSame('none', $commands[0]['routing_effect']);
        $this->assertFalse($commands[0]['external_provider_call']);
        $this->assertFalse($commands[0]['provider_tokens_spent']);
        $commandIds = array_column($commands, 'id');
        $this->assertContains('dry_run_atlas_forge_vs_claude_sonnet', $commandIds);
        $this->assertContains('dry_run_composer_2_5_vs_codex_gpt_5_5', $commandIds);
        $this->assertContains('dry_run_cursor_default_vs_claude_sonnet', $commandIds);
        $composerDryRun = collect($commands)->firstWhere('id', 'dry_run_composer_2_5_vs_codex_gpt_5_5');
        $this->assertIsArray($composerDryRun);
        $this->assertSame('provider_arena', $composerDryRun['mode']);
        $this->assertSame('composer_2_5', $composerDryRun['arm_a']);
        $this->assertStringContainsString('--case-set=ceiling-360', $composerDryRun['command']);
        $this->assertFalse($composerDryRun['external_provider_call']);
        $realCommands = array_values(array_filter(
            $commands,
            static fn (array $command): bool => (bool) ($command['external_provider_call'] ?? false),
        ));
        $this->assertNotEmpty($realCommands);
        $this->assertTrue($realCommands[0]['requires_confirmations']);
        $this->assertStringContainsString('--confirm-real-provider-call', $realCommands[0]['command']);
        $composerRealRun = collect($realCommands)->firstWhere('id', 'real_confirmed_composer_2_5_vs_codex_gpt_5_5');
        $this->assertIsArray($composerRealRun);
        $this->assertTrue($composerRealRun['requires_confirmations']);
        $this->assertStringContainsString('--confirm-provider-cost', $composerRealRun['command']);
        $this->assertSame($commands, $report['provider_performance_signal']['next_measurement_commands']);
        $this->assertStringContainsString('Capacidade Medida (360)', $body);
        $this->assertStringContainsString('capability_floor_met = **false**', $body);
        $this->assertStringContainsString('capability_separation_ratio = `0`', $body);
        $this->assertStringContainsString('tie_is_diagnostic_not_claim = **true**', $body);
        $this->assertStringContainsString('next_measurement_plan: `needs_more_cases`', $body);
        $this->assertStringContainsString('command `dry_run_extreme_differentiator`', $body);
        $pressure = $report['provider_performance_signal']['difficulty_pressure'];
        $this->assertSame('atlas.forge.rivals.difficulty_pressure.v1', $pressure['schema_version']);
        $this->assertSame('needs_valid_difficulty_sample', $pressure['status']);
        $this->assertSame('L5', $pressure['highest_observed_difficulty_band']);
        $this->assertNull($pressure['highest_valid_difficulty_band']);
        $this->assertTrue($pressure['requires_harder_followup']);
        $this->assertFalse($pressure['difficulty_ceiling_reached']);
        $this->assertSame('none', $pressure['routing_effect']);
        $this->assertFalse($pressure['should_update_provider_topology']);
        $this->assertStringContainsString('difficulty_pressure_status = `needs_valid_difficulty_sample`', $body);
        $this->assertStringContainsString('requires_harder_followup = **true**', $body);
        $this->assertSame('none', $report['provider_performance_signal']['capability_fit'][0]['routing_effect']);
    }

    public function test_difficulty_pressure_marks_l5_tie_as_diagnostic_not_claim(): void
    {
        $reflection = new \ReflectionMethod($this->report, 'buildDifficultyPressure');
        $reflection->setAccessible(true);

        $pressure = $reflection->invoke($this->report, [[
            'axis' => 'difficulty',
            'key' => 'L5',
            'cases' => 40,
            'winner' => AtlasForgeRivalsAdjudicatorService::WINNER_TIE,
            'margin' => 0.0,
            'validity' => AtlasForgeRivalsReportService::VALIDITY_VALID,
            'validity_reason' => 'sample_size_and_evidence_ok',
            'routing_effect' => 'none',
        ]]);

        $this->assertSame('l5_tied_needs_extreme_pressure', $pressure['status']);
        $this->assertSame('L5', $pressure['highest_valid_difficulty_band']);
        $this->assertSame(40, $pressure['l5_cases']);
        $this->assertTrue($pressure['l5_tie_is_diagnostic_not_claim']);
        $this->assertTrue($pressure['all_valid_bands_tied']);
        $this->assertTrue($pressure['requires_harder_followup']);
        $this->assertFalse($pressure['difficulty_ceiling_reached']);
        $this->assertContains('extreme-differentiator', $pressure['recommended_case_sets']);
        $this->assertSame('none', $pressure['routing_effect']);
        $this->assertTrue($pressure['advisory_only']);
        $this->assertFalse($pressure['should_update_provider_topology']);
        $this->assertSame('atlas_decide', $pressure['owner_of_model_routing']);
    }

    public function test_difficulty_pressure_keeps_single_l5_tie_diagnostic_even_when_under_sampled(): void
    {
        $reflection = new \ReflectionMethod($this->report, 'buildDifficultyPressure');
        $reflection->setAccessible(true);

        $pressure = $reflection->invoke($this->report, [[
            'axis' => 'difficulty',
            'key' => 'L5',
            'cases' => 1,
            'winner' => AtlasForgeRivalsAdjudicatorService::WINNER_TIE,
            'margin' => 4.0,
            'validity' => AtlasForgeRivalsReportService::VALIDITY_INSUFFICIENT,
            'validity_reason' => 'small_sample_less_than_three',
            'routing_effect' => 'none',
        ]]);

        $this->assertSame('needs_valid_difficulty_sample', $pressure['status']);
        $this->assertSame('L5', $pressure['highest_observed_difficulty_band']);
        $this->assertNull($pressure['highest_valid_difficulty_band']);
        $this->assertTrue($pressure['l5_tie_is_diagnostic_not_claim']);
        $this->assertTrue($pressure['requires_harder_followup']);
        $this->assertFalse($pressure['difficulty_ceiling_reached']);
        $this->assertSame('none', $pressure['routing_effect']);
    }

    public function test_16_artifacts_paths_appear_in_envelope(): void
    {
        $runId = $this->newRunId('artifacts');
        $this->seedComparableQualityWinner($runId, atlas: 86.5, rival: 79.2);

        $report = $this->report->render(['run_id' => $runId]);

        $this->assertNotEmpty($report['artifacts']);
        $foundManifest = false;
        foreach ($report['artifacts'] as $path) {
            if (str_ends_with($path, 'manifest.json')) {
                $foundManifest = true;
            }
        }
        $this->assertTrue($foundManifest);
    }

    public function test_17_next_action_when_evidence_missing(): void
    {
        $runId = $this->newRunId('next-evidence');
        $this->seedAdjudicationMissing($runId);

        $report = $this->report->render(['run_id' => $runId]);

        $kinds = array_column($report['next_actions'], 'kind');
        $this->assertContains('run_adjudicate', $kinds);
    }

    public function test_18_next_action_when_replay_missing(): void
    {
        $runId = $this->newRunId('next-replay');
        $this->seedReplayFailedRun($runId);

        $report = $this->report->render(['run_id' => $runId]);

        $kinds = array_column($report['next_actions'], 'kind');
        $this->assertTrue(in_array('run_replay', $kinds, true) || in_array('triage_suspicious', $kinds, true));
    }

    public function test_19_doctor_exposes_corpus_registry_field_in_both_paths(): void
    {
        $hygiene = new WorkspaceHygieneService;

        // Path A: corpus dependency not injected (unit-test seam).
        $doctorNull = new AtlasForgeRivalsDoctorService(
            $this->paths,
            $hygiene,
            null,
        );
        $resultNull = $doctorNull->check();
        $this->assertArrayHasKey('corpus_registry_loadable', $resultNull['checks']);
        $this->assertTrue($resultNull['checks']['corpus_registry_loadable']['ok']);
        $this->assertStringContainsString('not injected', $resultNull['checks']['corpus_registry_loadable']['value']);

        // Path B: real corpus resolved through the container. Doctor must
        // surface the real state honestly — either ok=true with the case
        // count when the corpus loads, or ok=false with a non-empty error
        // when an in-flight corpus change has left the registry broken.
        $realCorpus = app(AtlasForgeRivalsProviderArenaCorpusService::class);
        $doctorReal = new AtlasForgeRivalsDoctorService(
            $this->paths,
            $hygiene,
            $realCorpus,
        );
        $resultReal = $doctorReal->check();
        $real = $resultReal['checks']['corpus_registry_loadable'];
        if ($real['ok'] === true) {
            $this->assertStringContainsString('case(s) registered', $real['value']);
        } else {
            $this->assertSame('corpus_registry_threw', $real['value']);
            $this->assertNotEmpty($real['error'] ?? '');
        }
    }

    public function test_20_next_suggests_single_correct_command(): void
    {
        $runId = $this->newRunId('next-single');
        $this->seedMultiCaseRelease($runId);

        $report = $this->report->render(['run_id' => $runId]);

        $this->assertIsArray($report['next_actions']);
        $this->assertNotEmpty($report['next_actions']);
        $this->assertNotEmpty($report['next_actions'][0]['command']);
        $this->assertStringStartsWith('php artisan atlas:forge:rivals', $report['next_actions'][0]['command']);
    }

    public function test_21_report_deterministic_hash_for_same_input(): void
    {
        $runId = $this->newRunId('determinism');
        $this->seedComparableQualityWinner($runId, atlas: 86.5, rival: 79.2);

        $first = $this->report->render(['run_id' => $runId]);
        $second = $this->report->render(['run_id' => $runId]);

        $this->assertSame($first['deterministic_hash'], $second['deterministic_hash']);
    }

    public function test_22_executive_summary_explains_why_not_full_score(): void
    {
        $runId = $this->newRunId('explain-not-100');
        $this->seedComparableQualityWinner($runId, atlas: 86.5, rival: 79.2, preset: 'quick');

        $report = $this->report->render(['run_id' => $runId]);

        $this->assertStringContainsString('quick', strtolower($report['executive_summary']));
    }

    public function test_23_report_does_not_use_synthetic_score(): void
    {
        $runId = $this->newRunId('no-synthetic');
        $this->seedAdjudicationMissing($runId);

        $report = $this->report->render(['run_id' => $runId]);

        $this->assertNull($report['atlas_score']);
        $this->assertNull($report['rival_score']);
        $this->assertNull($report['winner']);
    }

    public function test_24_report_does_not_declare_global_superiority_in_quick(): void
    {
        $runId = $this->newRunId('quick-no-global');
        $this->seedComparableQualityWinner($runId, atlas: 86.5, rival: 79.2, preset: 'quick');

        $report = $this->report->render(['run_id' => $runId]);

        $headline = strtolower($report['headline']);
        $this->assertStringNotContainsString('trusted', $headline);
        $this->assertFalse($report['claim_status']['claim_ready']);
    }

    public function test_25_release_trusted_requires_12_cases_8_categories(): void
    {
        $runIdTrusted = $this->newRunId('release-trusted');
        $this->seedMultiCaseRelease($runIdTrusted);
        $trusted = $this->report->render(['run_id' => $runIdTrusted]);
        $this->assertSame('trusted_battery', $trusted['confidence']['level']);
        $this->assertTrue($trusted['confidence']['is_trusted']);

        $runIdSmall = $this->newRunId('release-too-small');
        $this->seedSingleReleaseCase($runIdSmall);
        $small = $this->report->render(['run_id' => $runIdSmall]);
        $this->assertNotSame('trusted_battery', $small['confidence']['level']);
    }

    public function test_26_arms_block_describes_atlas_and_rival(): void
    {
        $runId = $this->newRunId('arms');
        $this->seedComparableQualityWinner($runId, atlas: 86.5, rival: 79.2);

        $report = $this->report->render(['run_id' => $runId]);

        $this->assertCount(2, $report['arms']);
        $this->assertSame('atlas', $report['arms'][0]['id']);
        $this->assertSame('rival', $report['arms'][1]['id']);
    }

    public function test_27_replay_state_summarised_in_v3(): void
    {
        $runId = $this->newRunId('replay-state');
        $this->seedComparableQualityWinner($runId, atlas: 86.5, rival: 79.2);

        $report = $this->report->render(['run_id' => $runId]);

        $this->assertArrayHasKey('passes', $report['replay']);
        $this->assertTrue($report['replay']['passes']);
    }

    // ---------- fixtures ----------

    private function seedComparableQualityWinner(string $runId, float $atlas, float $rival, string $preset = 'release', string $caseId = 'arena-backend-pagination-cursor'): void
    {
        $this->seedSingleCaseManifest($runId, [
            'verdict' => 'comparable',
            'mode' => 'fair',
            'preset' => $preset,
            'case_id' => $caseId,
            'task_category' => 'backend',
        ]);
        $this->seedScorecard($runId, [
            'winner' => $atlas - $rival >= AtlasForgeRivalsAdjudicatorService::DEFAULT_TIE_THRESHOLD
                ? AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS
                : ($rival - $atlas >= AtlasForgeRivalsAdjudicatorService::DEFAULT_TIE_THRESHOLD
                    ? AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL
                    : AtlasForgeRivalsAdjudicatorService::WINNER_TIE),
            'atlas_score' => $atlas,
            'rival_score' => $rival,
            'score_source' => 'quality_dimensions',
            'quality_score_available' => true,
            'hard_failures' => [],
            'tie_threshold' => AtlasForgeRivalsAdjudicatorService::DEFAULT_TIE_THRESHOLD,
            'winner_reason' => ['atlas_better_in_scope_discipline'],
            'hard_gates' => $this->okGates(),
            'quality_dimensions' => [
                'objective_alignment' => ['atlas' => 100.0, 'rival' => 100.0, 'explanation' => 'both arms exit zero'],
            ],
        ]);
        $this->runCollectFinal($runId);
    }

    private function seedComparableTie(string $runId): void
    {
        $this->seedSingleCaseManifest($runId, [
            'verdict' => 'comparable',
            'mode' => 'fair',
            'preset' => 'release',
            'case_id' => 'arena-backend-pagination-cursor',
            'task_category' => 'backend',
        ]);
        $this->seedScorecard($runId, [
            'winner' => AtlasForgeRivalsAdjudicatorService::WINNER_TIE,
            'atlas_score' => 80.0,
            'rival_score' => 80.1,
            'score_source' => 'quality_dimensions',
            'quality_score_available' => true,
            'hard_failures' => [],
            'tie_threshold' => AtlasForgeRivalsAdjudicatorService::DEFAULT_TIE_THRESHOLD,
            'winner_reason' => ['statistical_tie'],
            'hard_gates' => $this->okGates(),
            'quality_dimensions' => ['objective_alignment' => ['atlas' => 80.0, 'rival' => 80.1, 'explanation' => 'tie']],
        ]);
        $this->runCollectFinal($runId);
    }

    private function seedSuspiciousLowRival(string $runId): void
    {
        $this->seedSingleCaseManifest($runId, [
            'verdict' => 'comparable',
            'mode' => 'fair',
            'preset' => 'quick',
            'case_id' => 'arena-backend-pagination-cursor',
            'task_category' => 'backend',
            'rival_model' => 'claude_sonnet',
        ]);
        $this->seedScorecard($runId, [
            'winner' => AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS,
            'atlas_score' => 92.0,
            'rival_score' => 58.0,
            'score_source' => 'quality_dimensions',
            'quality_score_available' => true,
            'hard_failures' => [],
            'tie_threshold' => AtlasForgeRivalsAdjudicatorService::DEFAULT_TIE_THRESHOLD,
            'winner_reason' => ['atlas_dominated'],
            'hard_gates' => $this->okGates(),
            'quality_dimensions' => ['objective_alignment' => ['atlas' => 92.0, 'rival' => 58.0, 'explanation' => 'rival underperformed']],
        ]);
        $this->runCollectFinal($runId);
    }

    private function seedHardFailureScorecard(string $runId): void
    {
        $this->seedSingleCaseManifest($runId, [
            'verdict' => 'comparable',
            'mode' => 'fair',
            'preset' => 'quick',
            'case_id' => 'arena-backend-pagination-cursor',
            'task_category' => 'backend',
        ]);
        $this->seedScorecard($runId, [
            'winner' => null,
            'atlas_score' => null,
            'rival_score' => null,
            'score_source' => 'hard_fail',
            'quality_score_available' => false,
            'quality_score_reason' => 'dirty_workspace_after_run',
            'hard_failures' => ['dirty_after_run', 'no_out_of_scope_files_atlas'],
            'tie_threshold' => AtlasForgeRivalsAdjudicatorService::DEFAULT_TIE_THRESHOLD,
            'winner_reason' => [],
            'hard_gates' => $this->failGates(),
            'quality_dimensions' => null,
        ]);
    }

    private function seedInvalidEvidenceRun(string $runId): void
    {
        $this->seedSingleCaseManifest($runId, [
            'verdict' => 'invalid_no_patch_diff',
            'mode' => 'fair',
            'preset' => 'quick',
            'case_id' => 'arena-backend-pagination-cursor',
            'task_category' => 'backend',
        ]);
    }

    private function seedReplayFailedRun(string $runId): void
    {
        $this->seedSingleCaseManifest($runId, [
            'verdict' => 'comparable',
            'mode' => 'fair',
            'preset' => 'quick',
            'case_id' => 'arena-backend-pagination-cursor',
            'task_category' => 'backend',
        ]);
        // Generate evidence pack with original hashes, then drift the manifest.
        $this->runCollectFinal($runId);
        $paths = $this->paths->paths($runId);
        $manifest = json_decode((string) file_get_contents($paths['manifest_json']), true);
        $manifest['atlas_receipt_hash'] = hash('sha256', 'tampered');
        file_put_contents($paths['manifest_json'], json_encode($manifest));
    }

    private function seedAdjudicationMissing(string $runId): void
    {
        $this->seedSingleCaseManifest($runId, [
            'verdict' => 'comparable',
            'mode' => 'fair',
            'preset' => 'quick',
            'case_id' => 'arena-backend-pagination-cursor',
            'task_category' => 'backend',
        ]);
        // No scorecard written.
    }

    private function seedSingleReleaseCase(string $runId): void
    {
        $this->seedComparableQualityWinner($runId, 86.5, 79.2, preset: 'release');
    }

    private function seedMultiCaseRelease(string $runId): void
    {
        $paths = $this->paths->paths($runId);
        @mkdir($paths['base'].'/cases', 0o755, true);
        @mkdir($paths['evidence'], 0o755, true);
        // Top-level manifest captures aggregate metadata.
        $manifest = $this->baseManifest($paths['run_id'], [
            'verdict' => 'comparable',
            'mode' => 'fair',
            'preset' => 'release',
            'case_id' => 'multi',
            'task_category' => 'aggregate',
        ]);
        file_put_contents($paths['manifest_json'], $this->jsonEncode($manifest));
        file_put_contents($paths['events_jsonl'], json_encode(['kind' => 'battery_started']).PHP_EOL);
        file_put_contents($paths['base'].'/intent.json', json_encode(['kind' => 'battery']));

        $catCases = [
            'backend' => ['atlas' => 88.0, 'rival' => 75.0, 'winner' => 'atlas'],
            'frontend' => ['atlas' => 70.0, 'rival' => 84.0, 'winner' => 'rival'],
            'bugfix' => ['atlas' => 90.0, 'rival' => 76.0, 'winner' => 'atlas'],
            'tests' => ['atlas' => 82.0, 'rival' => 76.0, 'winner' => 'atlas'],
            'refactor' => ['atlas' => 80.0, 'rival' => 80.0, 'winner' => 'human_review_required_tie'],
            'architecture' => ['atlas' => 85.0, 'rival' => 78.0, 'winner' => 'atlas'],
            'docs' => ['atlas' => 77.0, 'rival' => 85.0, 'winner' => 'rival'],
            'performance' => ['atlas' => 86.0, 'rival' => 79.0, 'winner' => 'atlas'],
        ];
        $idx = 0;
        foreach ($catCases as $cat => $data) {
            $sub1 = $this->writeSubCase($paths['base'].'/cases/case-'.($idx + 1), $cat, $data);
            $sub2 = $this->writeSubCase($paths['base'].'/cases/case-'.($idx + 2), $cat, $data);
            $idx += 2;
            // Each category needs >=1 case, but we add 2 to push case_count to 16 (>=12).
            unset($sub1, $sub2);
        }
        // Also drop a top-level scorecard so single-case fall-through stays satisfied.
        $this->seedScorecard($runId, [
            'winner' => AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS,
            'atlas_score' => 82.5,
            'rival_score' => 79.0,
            'score_source' => 'quality_dimensions',
            'quality_score_available' => true,
            'hard_failures' => [],
            'tie_threshold' => AtlasForgeRivalsAdjudicatorService::DEFAULT_TIE_THRESHOLD,
            'winner_reason' => ['multi_case_aggregate'],
            'hard_gates' => $this->okGates(),
            'quality_dimensions' => ['objective_alignment' => ['atlas' => 82.5, 'rival' => 79.0, 'explanation' => 'aggregate']],
        ]);
        // Top-level receipts so the primary-case path stays valid.
        $this->writeReceipts($paths['evidence']);
        $this->runCollectFinal($runId);
    }

    private function runCollectFinal(string $runId): void
    {
        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
        ]);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function writeSubCase(string $caseBase, string $cat, array $data): string
    {
        @mkdir($caseBase.'/evidence', 0o755, true);
        $caseId = 'release-'.$cat.'-'.bin2hex(random_bytes(2));
        $manifest = $this->baseManifest($caseId, [
            'verdict' => 'comparable',
            'mode' => 'fair',
            'preset' => 'release',
            'case_id' => $caseId,
            'task_category' => $cat,
            // Matrix evidence lock requires L1-L5 declared per case.
            'difficulty_band' => 'L3',
        ]);
        file_put_contents($caseBase.'/evidence/manifest.json', $this->jsonEncode($manifest));
        $score = [
            'schema_version' => AtlasForgeRivalsAdjudicatorService::SCHEMA_VERSION,
            'winner' => $data['winner'],
            'atlas_score' => $data['atlas'],
            'rival_score' => $data['rival'],
            'score_source' => 'quality_dimensions',
            'quality_score_available' => true,
            'hard_failures' => [],
            'tie_threshold' => AtlasForgeRivalsAdjudicatorService::DEFAULT_TIE_THRESHOLD,
            'winner_reason' => ['cat_'.$cat],
            'hard_gates' => $this->okGates(),
            'quality_dimensions' => null,
        ];
        file_put_contents($caseBase.'/evidence/scorecard.json', $this->jsonEncode($score));
        $this->writeReceipts($caseBase.'/evidence');

        return $caseBase;
    }

    private function writeReceipts(string $evidenceDir): void
    {
        $atlas = $this->receipt('atlas');
        $rival = $this->receipt('rival');
        file_put_contents($evidenceDir.'/atlas_receipt.json', $this->jsonEncode($atlas));
        file_put_contents($evidenceDir.'/rival_receipt.json', $this->jsonEncode($rival));
        file_put_contents($evidenceDir.'/workspace_hashes.json', $this->jsonEncode([
            'before' => ['atlas' => 'h1', 'rival' => 'h1'],
            'after' => ['atlas' => 'h2', 'rival' => 'h2'],
            'dirty_after_run' => false,
            'workspace_blockers' => [],
        ]));
        file_put_contents($evidenceDir.'/atlas_patch.diff', '--- atlas patch ---');
        file_put_contents($evidenceDir.'/rival_patch.diff', '--- rival patch ---');
        file_put_contents($evidenceDir.'/atlas_test.log', '(50 tests, 120 assertions)');
        file_put_contents($evidenceDir.'/rival_test.log', '(50 tests, 120 assertions)');
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function seedSingleCaseManifest(string $runId, array $overrides): void
    {
        $paths = $this->paths->paths($runId);
        @mkdir($paths['base'], 0o755, true);
        @mkdir($paths['evidence'], 0o755, true);
        $manifest = $this->baseManifest($paths['run_id'], $overrides);
        file_put_contents($paths['manifest_json'], $this->jsonEncode($manifest));
        file_put_contents($paths['events_jsonl'], json_encode(['kind' => 'run_started']).PHP_EOL);
        file_put_contents($paths['base'].'/intent.json', json_encode(['kind' => 'synthetic']));
        $this->writeReceipts($paths['evidence']);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function baseManifest(string $runId, array $overrides): array
    {
        $atlasReceipt = $this->receipt('atlas');
        $rivalReceipt = $this->receipt('rival');

        return array_merge([
            'schema_version' => 'atlas.forge.rivals.run_real.v1',
            'run_id' => $runId,
            'mode' => 'fair',
            'atlas_model' => 'claude_sonnet',
            'rival_model' => 'claude_sonnet',
            'preset' => 'release',
            'case_id' => 'synthetic-case',
            'task_category' => 'backend',
            'verdict' => 'comparable',
            'score' => null,
            'claim_ready' => true,
            'atlas_receipt_hash' => hash('sha256', $this->jsonEncode($atlasReceipt)),
            'rival_receipt_hash' => hash('sha256', $this->jsonEncode($rivalReceipt)),
            'workspace_hash_before' => ['atlas' => 'h1', 'rival' => 'h1'],
            'workspace_hash_after' => ['atlas' => 'h2', 'rival' => 'h2'],
            'dirty_after_run' => false,
            'workspace_blockers' => [],
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'separated_from_external_rivals_certification' => true,
            'confirmations' => [
                'runbook_reviewed' => true,
                'provider_cost' => true,
                'real_provider_call' => true,
            ],
        ], $overrides);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function seedScorecard(string $runId, array $payload): void
    {
        $paths = $this->paths->paths($runId);
        $payload['schema_version'] = AtlasForgeRivalsAdjudicatorService::SCHEMA_VERSION;
        file_put_contents($paths['scorecard_json'], $this->jsonEncode($payload));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function okGates(): array
    {
        return [
            ['code' => 'verdict_comparable', 'ok' => true, 'detail' => 'verdict ok'],
            ['code' => 'tests_passed_atlas', 'ok' => true, 'detail' => 'exit 0'],
            ['code' => 'tests_passed_rival', 'ok' => true, 'detail' => 'exit 0'],
            ['code' => 'replay_passes', 'ok' => true, 'detail' => 'hash match'],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function failGates(): array
    {
        return [
            ['code' => 'dirty_after_run_false', 'ok' => false, 'detail' => 'workspace dirty'],
            ['code' => 'no_out_of_scope_files_atlas', 'ok' => false, 'detail' => 'atlas touched out-of-scope'],
        ];
    }

    public function test_markdown_verdict_distinguishes_measured_multi_case_winner_from_invalid_harness(): void
    {
        $marker = new \ReflectionMethod($this->report, 'buildVerdictMarker');
        $marker->setAccessible(true);

        $line = $marker->invoke(
            $this->report,
            'invalid_scope_violation',
            true,
            [],
            AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL,
            [
                'score_source' => 'multi_case_deterministic_gate_rollup',
                'atlas_score' => 66.88,
                'rival_score' => 73.13,
            ],
        );

        $this->assertStringContainsString('MEASURED WINNER = Rival baseline', $line);
        $this->assertStringContainsString('CASE FAILURES PRESENT', $line);
        $this->assertStringContainsString('ZERO external claim', $line);
        $this->assertStringNotContainsString('INVALID', $line);
        $this->assertStringNotContainsString('score=null', $line);
    }

    /**
     * @return array<string,mixed>
     */
    private function receipt(string $arm): array
    {
        return [
            'arm' => $arm,
            'mode' => 'fair',
            'model' => 'claude_sonnet',
            'command_hash' => hash('sha256', $arm),
            'prompt_hash' => hash('sha256', $arm.'p'),
            'started_at' => '2026-05-15T12:00:00+00:00',
            'finished_at' => '2026-05-15T12:01:00+00:00',
            'exit_code' => 0,
            'killed' => false,
            'timeout' => false,
            'timeout_reason' => null,
            'stdout_hash' => hash('sha256', $arm.'so'),
            'stderr_hash' => hash('sha256', $arm.'se'),
            'stdout_bytes' => 4_000,
            'stderr_bytes' => 0,
            'stdout_tail' => 'tail',
            'stderr_tail' => '',
            'stdout_path' => '/tmp/stdout',
            'stderr_path' => '/tmp/stderr',
            'token_cost' => 0.01,
            'tokens_used' => 100,
            'worktree' => '/tmp/work',
            'case_id' => 'synthetic-case',
            'changed_files' => ['tests/Feature/Synthetic.php', 'app/Synthetic.php'],
            'out_of_scope_files' => [],
            'bytecode_artifacts' => [],
            'workspace_blockers' => [],
            'workspace_has_blocking_changes' => false,
            'patch_diff_path' => '/tmp/patch.diff',
            'patch_diff_hash' => hash('sha256', $arm.'pd'),
            'patch_diff_bytes' => 3_000,
            'test_command' => 'phpunit',
            'test_exit_code' => 0,
            'test_log_path' => '/tmp/test.log',
            'test_log_hash' => hash('sha256', $arm.'tl'),
            'test_log_tail' => '(50 tests, 120 assertions)',
        ];
    }

    private function jsonEncode(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function newRunId(string $suffix): string
    {
        return 'report-v3-'.bin2hex(random_bytes(4)).'-'.$suffix;
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
