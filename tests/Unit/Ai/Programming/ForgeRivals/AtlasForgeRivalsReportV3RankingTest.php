<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsAdjudicatorService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsCollectEvidenceService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEventStream;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEvidencePolicy;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsReplayService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsReportService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunPathResolver;
use Tests\TestCase;

/**
 * Atlas Forge Rivals · Report v3 ranking + signal v1 contract.
 *
 * Locks down:
 *   - difficulty_results[] aggregated over L1..L5 canon (unknown reserved).
 *   - planning_vs_execution_results split by manifest.work_kind / category.
 *   - provider_results[] grouped by atlas_model::rival_model pair.
 *   - mode_results[] split fair vs full_power vs local_fake.
 *   - provider_performance_signal.v1 with provider_measurement, fits, do_not_use_when, fallback_hint.
 *   - filters: --category, --difficulty, --provider, --mode collapse case_results before aggregation.
 *   - validity status valid/suspect/insufficient surfaced on every bucket.
 *   - rankings stay advisory_only — never_changes_atlas_decide_topology=true.
 *
 * Never invokes provider.
 */
final class AtlasForgeRivalsReportV3RankingTest extends TestCase
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
        $this->tmpRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fr-report-rank-'.bin2hex(random_bytes(6));
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

    public function test_difficulty_results_split_l1_to_l5_canon(): void
    {
        $runId = $this->newRunId('difficulty-canon');
        $this->seedMultiCaseBattery($runId);

        $report = $this->report->render(['run_id' => $runId]);

        $this->assertNotEmpty($report['difficulty_results']);
        $bands = array_column($report['difficulty_results'], 'key');
        foreach (['L1', 'L2', 'L3', 'L4', 'L5'] as $band) {
            $this->assertContains($band, $bands, "Bateria deveria cobrir banda {$band}");
        }
        foreach ($report['difficulty_results'] as $entry) {
            $this->assertContains($entry['key'], ['L1', 'L2', 'L3', 'L4', 'L5', 'unknown']);
            $this->assertContains($entry['validity'], ['valid', 'suspect', 'insufficient']);
        }
    }

    public function test_planning_vs_execution_results_present(): void
    {
        $runId = $this->newRunId('planning-execution');
        $this->seedMultiCaseBattery($runId);

        $report = $this->report->render(['run_id' => $runId]);

        $this->assertNotEmpty($report['planning_vs_execution_results']);
        $kinds = array_column($report['planning_vs_execution_results'], 'key');
        $this->assertContains('planning', $kinds);
        $this->assertContains('execution', $kinds);
    }

    public function test_provider_results_group_by_model_pair(): void
    {
        $runId = $this->newRunId('provider-pair');
        $this->seedMultiCaseBattery($runId);

        $report = $this->report->render(['run_id' => $runId]);

        $this->assertNotEmpty($report['provider_results']);
        $first = $report['provider_results'][0];
        $this->assertStringContainsString('_vs_', (string) $first['key']);
    }

    public function test_mode_results_split_fair_and_full_power(): void
    {
        $runId = $this->newRunId('mode-split');
        $this->seedMultiCaseBattery($runId);

        $report = $this->report->render(['run_id' => $runId]);

        $modes = array_column($report['mode_results'], 'key');
        $this->assertContains('fair', $modes);
        $this->assertContains('full_power', $modes);
    }

    public function test_signal_v1_schema_and_advisory_invariants(): void
    {
        $runId = $this->newRunId('signal-shape');
        $this->seedMultiCaseBattery($runId);

        $report = $this->report->render(['run_id' => $runId]);
        $signal = $report['provider_performance_signal'];

        $this->assertSame(
            'atlas.forge.rivals.provider_performance_signal.v1',
            $signal['schema_version'],
        );
        $this->assertTrue($signal['advisory_only']);
        $this->assertTrue($signal['never_changes_atlas_decide_topology']);
        foreach (['provider_measurement', 'provider_recommendation', 'category_fit', 'difficulty_fit', 'mode_fit', 'provider_pair_fit', 'human_prompt_contract_coverage', 'complexity_profile_coverage', 'do_not_use_when', 'fallback_hint', 'confidence', 'rows'] as $key) {
            $this->assertArrayHasKey($key, $signal, "signal missing key {$key}");
        }
        $this->assertFalse($signal['should_update_provider_topology']);
        $this->assertSame('atlas_decide', $signal['owner_of_model_routing']);
        $this->assertSame('none', $signal['routing_effect']);
        $this->assertSame('atlas.forge.rivals.human_prompt_contract_coverage.v1', $signal['human_prompt_contract_coverage']['schema_version']);
        $this->assertTrue($signal['human_prompt_contract_coverage']['advisory_only']);
        $this->assertSame('none', $signal['human_prompt_contract_coverage']['routing_effect']);
        $this->assertSame('atlas.forge.rivals.complexity_profile_coverage.v1', $signal['complexity_profile_coverage']['schema_version']);
        $this->assertTrue($signal['complexity_profile_coverage']['advisory_only']);
        $this->assertSame('none', $signal['complexity_profile_coverage']['routing_effect']);
        $this->assertArrayHasKey('meta_provider_claim_floor_met', $signal['complexity_profile_coverage']);
        $this->assertArrayHasKey('critical_or_high_risk_cases', $signal['complexity_profile_coverage']);
        $this->assertArrayHasKey('high_ambiguity_cases', $signal['complexity_profile_coverage']);
    }

    public function test_signal_provider_measurement_has_both_arms(): void
    {
        $runId = $this->newRunId('signal-arms');
        $this->seedMultiCaseBattery($runId);

        $report = $this->report->render(['run_id' => $runId]);
        $signal = $report['provider_performance_signal'];
        $arms = array_column($signal['provider_measurement'], 'arm');
        $this->assertContains('atlas', $arms);
        $this->assertContains('rival', $arms);
    }

    public function test_signal_do_not_use_when_surfaces_suspicious(): void
    {
        $runId = $this->newRunId('signal-do-not-use');
        $this->seedSuspiciousBattery($runId);

        $report = $this->report->render(['run_id' => $runId]);
        $signal = $report['provider_performance_signal'];
        $conditions = array_column($signal['do_not_use_when'], 'condition');
        $this->assertContains('suspicious_results_present', $conditions);
    }

    public function test_signal_do_not_use_when_blocks_missing_complexity_measurement(): void
    {
        $runId = $this->newRunId('signal-missing-complexity');
        $paths = $this->paths->paths($runId);
        @mkdir($paths['base'].'/cases', 0o755, true);
        @mkdir($paths['evidence'], 0o755, true);
        file_put_contents($paths['manifest_json'], $this->jsonEncode($this->baseManifest($paths['run_id'], ['preset' => 'release', 'case_id' => 'multi'])));
        file_put_contents($paths['events_jsonl'], json_encode(['kind' => 'battery_started']).PHP_EOL);
        file_put_contents($paths['base'].'/intent.json', json_encode(['kind' => 'battery']));
        $this->writeSubCase(
            $paths['base'].'/cases/case-1',
            cat: 'backend',
            data: ['atlas' => 88.0, 'rival' => 75.0, 'winner' => 'atlas'],
            difficulty: 'L3',
            manifestOverrides: [
                'context_profile' => [
                    'schema_version' => 'atlas.forge.rivals.context_profile.v1',
                    'prompt_style' => 'human_ambiguous_operator_ticket',
                    'long_context_required' => false,
                    'requires_assumption_log' => true,
                    'requires_tradeoff_notes' => true,
                    'requires_scope_boundary_reasoning' => true,
                    'requires_replayable_evidence' => true,
                ],
                'human_prompt_probe' => [
                    'schema_version' => 'atlas.forge.rivals.human_prompt_probe.v1',
                    'requires_sections' => [
                        'facts_observed',
                        'assumptions',
                        'reversible_decisions',
                        'scope_boundaries',
                        'evidence_plan',
                        'tradeoffs',
                        'honest_blockers',
                    ],
                ],
            ],
        );
        $this->seedTopScorecard($runId, winner: 'atlas');
        $this->writeReceipts($paths['evidence']);
        $this->runCollectFinal($runId);

        $signal = $this->report->render(['run_id' => $runId])['provider_performance_signal'];
        $conditions = array_column($signal['do_not_use_when'], 'condition');

        $this->assertSame(0, $signal['complexity_profile_coverage']['cases_with_complexity_profile']);
        $this->assertContains('complexity_profile_coverage_incomplete', $conditions);
        $this->assertContains('long_context_not_measured', $conditions);
        $this->assertContains('evidence_matrix_not_measured', $conditions);
        $this->assertContains('multi_step_plan_not_measured', $conditions);
        $this->assertFalse($signal['should_update_provider_topology']);
        $this->assertSame('none', $signal['routing_effect']);
    }

    public function test_signal_meta_provider_claim_floor_passes_only_with_diverse_risky_ambiguous_cases(): void
    {
        $runId = $this->newRunId('signal-meta-provider-floor');
        $paths = $this->paths->paths($runId);
        @mkdir($paths['base'].'/cases', 0o755, true);
        @mkdir($paths['evidence'], 0o755, true);
        file_put_contents($paths['manifest_json'], $this->jsonEncode($this->baseManifest($paths['run_id'], ['preset' => 'release', 'case_id' => 'multi'])));
        file_put_contents($paths['events_jsonl'], json_encode(['kind' => 'battery_started']).PHP_EOL);
        file_put_contents($paths['base'].'/intent.json', json_encode(['kind' => 'battery']));

        $categories = ['backend', 'frontend', 'bugfix', 'tests', 'refactor', 'architecture', 'docs', 'performance'];
        for ($i = 0; $i < 16; $i++) {
            $profile = $this->complexityProfile([
                'ambiguity_score' => $i === 0 ? 5 : 3,
                'risk_score' => $i === 1 ? 5 : 2,
                'requires_rollback_plan' => $i < 8,
            ]);
            $this->writeSubCase(
                $paths['base'].'/cases/case-'.($i + 1),
                cat: $categories[$i % count($categories)],
                data: ['atlas' => 88.0, 'rival' => 75.0, 'winner' => 'atlas'],
                difficulty: 'L4',
                manifestOverrides: $this->humanComplexityManifestOverrides($profile),
            );
        }
        $this->seedTopScorecard($runId, winner: 'atlas');
        $this->writeReceipts($paths['evidence']);
        $this->runCollectFinal($runId);

        $report = $this->report->render(['run_id' => $runId]);
        $signal = $report['provider_performance_signal'];
        $coverage = $signal['complexity_profile_coverage'];
        $conditions = array_column($signal['do_not_use_when'], 'condition');

        $this->assertTrue($coverage['meta_provider_claim_floor_met']);
        $this->assertTrue($signal['can_feed_ledger']);
        $this->assertSame([], $signal['ledger_blockers']);
        $this->assertTrue($report['claim_status']['can_feed_ledger']);
        $this->assertSame(8, $coverage['domain_count']);
        $this->assertSame(1, $coverage['high_ambiguity_cases']);
        $this->assertSame(1, $coverage['critical_or_high_risk_cases']);
        $this->assertSame(8, $coverage['rollback_plan_required_cases']);
        $this->assertNotContains('meta_provider_stress_floor_not_met', $conditions);
        $this->assertFalse($signal['should_update_provider_topology']);
        $this->assertSame('none', $signal['routing_effect']);
    }

    public function test_signal_fallback_hint_when_replay_fails(): void
    {
        $runId = $this->newRunId('signal-fallback');
        $this->seedReplayFailedBattery($runId);

        $signal = $this->report->render(['run_id' => $runId])['provider_performance_signal'];
        $this->assertSame('rivals_signal_unusable_until_replay_passes', $signal['fallback_hint']);
    }

    public function test_filter_by_category_collapses_aggregation(): void
    {
        $runId = $this->newRunId('filter-category');
        $this->seedMultiCaseBattery($runId);

        $report = $this->report->render(['run_id' => $runId, 'category' => 'backend']);

        foreach ($report['case_results'] as $case) {
            $this->assertSame('backend', $case['task_category']);
        }
        $this->assertSame('backend', $report['filters_applied']['category']);
    }

    public function test_filter_by_difficulty_collapses_aggregation(): void
    {
        $runId = $this->newRunId('filter-difficulty');
        $this->seedMultiCaseBattery($runId);

        $report = $this->report->render(['run_id' => $runId, 'difficulty' => 'L3']);

        foreach ($report['case_results'] as $case) {
            $this->assertSame('L3', $case['difficulty_band']);
        }
        $this->assertSame('L3', $report['filters_applied']['difficulty']);
    }

    public function test_filter_by_provider_collapses_aggregation(): void
    {
        $runId = $this->newRunId('filter-provider');
        $this->seedMultiCaseBattery($runId);

        $report = $this->report->render(['run_id' => $runId, 'provider' => 'opus']);

        foreach ($report['case_results'] as $case) {
            $combined = strtolower((string) $case['atlas_model']).'|'.strtolower((string) $case['rival_model']);
            $this->assertStringContainsString('opus', $combined);
        }
    }

    public function test_filter_by_mode_collapses_aggregation(): void
    {
        $runId = $this->newRunId('filter-mode');
        $this->seedMultiCaseBattery($runId);

        $report = $this->report->render(['run_id' => $runId, 'mode_filter' => 'fair']);

        foreach ($report['case_results'] as $case) {
            $this->assertSame('fair', $case['mode']);
        }
    }

    public function test_case_results_expose_human_prompt_probe_contract(): void
    {
        $runId = $this->newRunId('human-prompt-probe-report');
        $this->seedMultiCaseBattery($runId);

        $report = $this->report->render(['run_id' => $runId]);
        $first = $report['case_results'][0];

        $this->assertSame('atlas.forge.rivals.report_human_prompt_contract.v1', $first['human_prompt_contract']['schema_version']);
        $this->assertTrue($first['human_prompt_contract']['complete']);
        $this->assertSame([], $first['human_prompt_contract']['missing_sections']);
        $this->assertTrue($first['human_prompt_contract']['requires_assumption_log']);
        $this->assertTrue($first['human_prompt_contract']['requires_scope_boundary_reasoning']);
        $this->assertTrue($first['human_prompt_contract']['has_complexity_profile']);
        $this->assertGreaterThanOrEqual(4200, $first['human_prompt_contract']['estimated_context_tokens']);
        $this->assertGreaterThanOrEqual(3, $first['human_prompt_contract']['reasoning_depth']);
        $this->assertContains('assumption_probe', $first['measurement_tags']);
        $this->assertContains('replay_matrix', $first['human_prompt_contract']['required_sections']);
        $this->assertContains('honest_blockers', $first['human_prompt_contract']['required_sections']);

        $coverage = $report['provider_performance_signal']['human_prompt_contract_coverage'];
        $this->assertSame(16, $coverage['case_count']);
        $this->assertSame(16, $coverage['cases_with_contract']);
        $this->assertSame(16, $coverage['complete_contract_cases']);
        $this->assertSame(1.0, $coverage['coverage_ratio']);
        $this->assertSame(1.0, $coverage['complete_ratio']);

        $complexityCoverage = $report['provider_performance_signal']['complexity_profile_coverage'];
        $this->assertSame(16, $complexityCoverage['case_count']);
        $this->assertSame(16, $complexityCoverage['cases_with_complexity_profile']);
        $this->assertSame(1.0, $complexityCoverage['coverage_ratio']);
        $this->assertSame(16, $complexityCoverage['long_context_required_cases']);
        $this->assertSame(16, $complexityCoverage['evidence_matrix_required_cases']);
        $this->assertSame(16, $complexityCoverage['multi_step_plan_required_cases']);
        $this->assertSame(8, $complexityCoverage['domain_count']);
        $this->assertFalse($complexityCoverage['meta_provider_claim_floor_met']);
        $this->assertGreaterThanOrEqual(4200, $complexityCoverage['min_estimated_context_tokens']);
        $this->assertGreaterThanOrEqual(3, $complexityCoverage['max_reasoning_depth']);
        $this->assertArrayHasKey('replayable_evidence_quality', $complexityCoverage['measured_dimensions']);

        $conditions = array_column($report['provider_performance_signal']['do_not_use_when'], 'condition');
        $this->assertContains('meta_provider_stress_floor_not_met', $conditions);
        $this->assertFalse($report['provider_performance_signal']['can_feed_ledger']);
        $this->assertContains('meta_provider_stress_floor_not_met', $report['provider_performance_signal']['ledger_blockers']);
        $this->assertFalse($report['claim_status']['can_feed_ledger']);
        $this->assertContains('meta_provider_stress_floor_not_met', $report['claim_status']['ledger_blockers']);

        $nextActionKinds = array_column($report['next_actions'], 'kind');
        $this->assertNotContains('feed_ledger', $nextActionKinds);
        $this->assertContains('resolve_ledger_blockers', $nextActionKinds);
    }

    public function test_case_results_flag_incomplete_human_prompt_probe_contract(): void
    {
        $runId = $this->newRunId('human-prompt-probe-incomplete-report');
        $paths = $this->paths->paths($runId);
        @mkdir($paths['base'].'/cases', 0o755, true);
        @mkdir($paths['evidence'], 0o755, true);
        file_put_contents($paths['manifest_json'], $this->jsonEncode($this->baseManifest($paths['run_id'], ['preset' => 'release', 'case_id' => 'multi'])));
        file_put_contents($paths['events_jsonl'], json_encode(['kind' => 'battery_started']).PHP_EOL);
        file_put_contents($paths['base'].'/intent.json', json_encode(['kind' => 'battery']));
        $this->writeSubCase(
            $paths['base'].'/cases/case-1',
            cat: 'backend',
            data: ['atlas' => 88.0, 'rival' => 75.0, 'winner' => 'atlas'],
            difficulty: 'L3',
            manifestOverrides: [
                'human_prompt_probe' => [
                    'schema_version' => 'atlas.forge.rivals.human_prompt_probe.v1',
                    'requires_sections' => ['facts_observed'],
                ],
            ],
        );
        $this->seedTopScorecard($runId, winner: 'atlas');
        $this->writeReceipts($paths['evidence']);
        $this->runCollectFinal($runId);

        $report = $this->report->render(['run_id' => $runId]);
        $contract = $report['case_results'][0]['human_prompt_contract'];

        $this->assertFalse($contract['complete']);
        $this->assertContains('honest_blockers', $contract['missing_sections']);
        $this->assertContains('replay_matrix', $contract['missing_sections']);
        $coverage = $report['provider_performance_signal']['human_prompt_contract_coverage'];
        $this->assertSame(1, $coverage['incomplete_contract_cases']);
        $this->assertSame(0.0, $coverage['complete_ratio']);
        $this->assertSame(1, $coverage['missing_sections']['honest_blockers']);
    }

    public function test_validity_bucket_status_marks_insufficient_for_small_samples(): void
    {
        $runId = $this->newRunId('validity-insufficient');
        $this->seedMultiCaseBattery($runId);

        $report = $this->report->render(['run_id' => $runId]);

        // Each band has 2 cases — should be insufficient (cases < 3).
        foreach ($report['difficulty_results'] as $entry) {
            if ($entry['key'] === 'unknown' || $entry['cases'] < 3) {
                $this->assertSame('insufficient', $entry['validity']);
            }
        }
    }

    public function test_markdown_includes_new_ranking_sections(): void
    {
        $runId = $this->newRunId('markdown-rankings');
        $this->seedMultiCaseBattery($runId);

        $report = $this->report->render(['run_id' => $runId]);
        $body = (string) file_get_contents($report['report_path']);

        $this->assertStringContainsString('Resultado por Dificuldade', $body);
        $this->assertStringContainsString('Planejamento vs Execução', $body);
        $this->assertStringContainsString('Provider / Modelo', $body);
        $this->assertStringContainsString('Atlas Forge fair vs full_power', $body);
        $this->assertStringContainsString('Medição por Arm', $body);
    }

    public function test_signal_confidence_block_present(): void
    {
        $runId = $this->newRunId('signal-confidence');
        $this->seedMultiCaseBattery($runId);

        $signal = $this->report->render(['run_id' => $runId])['provider_performance_signal'];

        $this->assertArrayHasKey('confidence', $signal);
        $this->assertArrayHasKey('level', $signal['confidence']);
        $this->assertArrayHasKey('is_trusted', $signal['confidence']);
    }

    public function test_unknown_difficulty_does_not_poison_rankings(): void
    {
        $runId = $this->newRunId('difficulty-unknown');
        $paths = $this->paths->paths($runId);
        @mkdir($paths['base'].'/cases', 0o755, true);
        @mkdir($paths['evidence'], 0o755, true);
        $manifest = $this->baseManifest($paths['run_id'], ['preset' => 'release', 'case_id' => 'multi']);
        // Deliberately omit difficulty_band.
        file_put_contents($paths['manifest_json'], $this->jsonEncode($manifest));
        file_put_contents($paths['events_jsonl'], json_encode(['kind' => 'started']).PHP_EOL);
        file_put_contents($paths['base'].'/intent.json', json_encode(['kind' => 'battery']));
        $this->writeReceipts($paths['evidence']);
        $this->writeSubCase(
            $paths['base'].'/cases/case-1',
            cat: 'backend',
            data: ['atlas' => 88.0, 'rival' => 75.0, 'winner' => 'atlas'],
            difficulty: 'banana',
        );
        $this->seedTopScorecard($runId, winner: 'atlas');
        $this->runCollectFinal($runId);

        $report = $this->report->render(['run_id' => $runId]);

        foreach ($report['difficulty_results'] as $entry) {
            $this->assertContains($entry['key'], ['L1', 'L2', 'L3', 'L4', 'L5', 'unknown']);
        }
    }

    // ---------- fixtures ----------

    private function seedMultiCaseBattery(string $runId): void
    {
        $paths = $this->paths->paths($runId);
        @mkdir($paths['base'].'/cases', 0o755, true);
        @mkdir($paths['evidence'], 0o755, true);
        $manifest = $this->baseManifest($paths['run_id'], [
            'preset' => 'release',
            'case_id' => 'multi',
            'task_category' => 'aggregate',
        ]);
        file_put_contents($paths['manifest_json'], $this->jsonEncode($manifest));
        file_put_contents($paths['events_jsonl'], json_encode(['kind' => 'battery_started']).PHP_EOL);
        file_put_contents($paths['base'].'/intent.json', json_encode(['kind' => 'battery']));

        // 16 cases × 8 categories × distribute L1-L5, fair/full_power, sonnet/opus.
        $blueprint = [
            ['cat' => 'backend', 'L' => 'L3', 'atlas' => 88.0, 'rival' => 75.0, 'winner' => 'atlas', 'mode' => 'fair', 'atlas_model' => 'claude_sonnet', 'rival_model' => 'claude_sonnet'],
            ['cat' => 'backend', 'L' => 'L4', 'atlas' => 90.0, 'rival' => 78.0, 'winner' => 'atlas', 'mode' => 'fair', 'atlas_model' => 'claude_sonnet', 'rival_model' => 'claude_sonnet'],
            ['cat' => 'frontend', 'L' => 'L2', 'atlas' => 70.0, 'rival' => 84.0, 'winner' => 'rival', 'mode' => 'fair', 'atlas_model' => 'claude_sonnet', 'rival_model' => 'claude_sonnet'],
            ['cat' => 'frontend', 'L' => 'L3', 'atlas' => 72.0, 'rival' => 82.0, 'winner' => 'rival', 'mode' => 'fair', 'atlas_model' => 'claude_sonnet', 'rival_model' => 'claude_sonnet'],
            ['cat' => 'bugfix', 'L' => 'L1', 'atlas' => 90.0, 'rival' => 80.0, 'winner' => 'atlas', 'mode' => 'fair', 'atlas_model' => 'claude_sonnet', 'rival_model' => 'claude_sonnet'],
            ['cat' => 'bugfix', 'L' => 'L2', 'atlas' => 91.0, 'rival' => 81.0, 'winner' => 'atlas', 'mode' => 'fair', 'atlas_model' => 'claude_sonnet', 'rival_model' => 'claude_sonnet'],
            ['cat' => 'tests', 'L' => 'L1', 'atlas' => 82.0, 'rival' => 80.0, 'winner' => 'tie', 'mode' => 'fair', 'atlas_model' => 'claude_sonnet', 'rival_model' => 'claude_sonnet'],
            ['cat' => 'tests', 'L' => 'L2', 'atlas' => 84.0, 'rival' => 79.0, 'winner' => 'atlas', 'mode' => 'fair', 'atlas_model' => 'claude_sonnet', 'rival_model' => 'claude_sonnet'],
            ['cat' => 'refactor', 'L' => 'L4', 'atlas' => 80.0, 'rival' => 80.0, 'winner' => 'tie', 'mode' => 'fair', 'atlas_model' => 'claude_sonnet', 'rival_model' => 'claude_sonnet'],
            ['cat' => 'refactor', 'L' => 'L5', 'atlas' => 78.0, 'rival' => 81.0, 'winner' => 'tie', 'mode' => 'fair', 'atlas_model' => 'claude_sonnet', 'rival_model' => 'claude_sonnet'],
            ['cat' => 'architecture', 'L' => 'L5', 'atlas' => 85.0, 'rival' => 78.0, 'winner' => 'atlas', 'mode' => 'full_power', 'atlas_model' => 'claude_opus', 'rival_model' => 'claude_opus'],
            ['cat' => 'architecture', 'L' => 'L4', 'atlas' => 86.0, 'rival' => 76.0, 'winner' => 'atlas', 'mode' => 'full_power', 'atlas_model' => 'claude_opus', 'rival_model' => 'claude_opus'],
            ['cat' => 'docs', 'L' => 'L1', 'atlas' => 77.0, 'rival' => 85.0, 'winner' => 'rival', 'mode' => 'fair', 'atlas_model' => 'claude_sonnet', 'rival_model' => 'claude_sonnet'],
            ['cat' => 'docs', 'L' => 'L2', 'atlas' => 79.0, 'rival' => 84.0, 'winner' => 'tie', 'mode' => 'fair', 'atlas_model' => 'claude_sonnet', 'rival_model' => 'claude_sonnet'],
            ['cat' => 'performance', 'L' => 'L3', 'atlas' => 86.0, 'rival' => 79.0, 'winner' => 'atlas', 'mode' => 'full_power', 'atlas_model' => 'claude_opus', 'rival_model' => 'claude_opus'],
            ['cat' => 'performance', 'L' => 'L4', 'atlas' => 88.0, 'rival' => 80.0, 'winner' => 'atlas', 'mode' => 'full_power', 'atlas_model' => 'claude_opus', 'rival_model' => 'claude_opus'],
        ];
        foreach ($blueprint as $idx => $b) {
            $this->writeSubCase(
                $paths['base'].'/cases/case-'.($idx + 1),
                cat: $b['cat'],
                data: ['atlas' => $b['atlas'], 'rival' => $b['rival'], 'winner' => $b['winner'] === 'tie' ? 'human_review_required_tie' : $b['winner']],
                difficulty: $b['L'],
                mode: $b['mode'],
                atlasModel: $b['atlas_model'],
                rivalModel: $b['rival_model'],
            );
        }
        $this->seedTopScorecard($runId, winner: 'atlas');
        $this->writeReceipts($paths['evidence']);
        $this->runCollectFinal($runId);
    }

    private function seedSuspiciousBattery(string $runId): void
    {
        $paths = $this->paths->paths($runId);
        @mkdir($paths['base'].'/cases', 0o755, true);
        @mkdir($paths['evidence'], 0o755, true);
        $manifest = $this->baseManifest($paths['run_id'], ['preset' => 'release', 'case_id' => 'multi', 'rival_model' => 'claude_sonnet']);
        file_put_contents($paths['manifest_json'], $this->jsonEncode($manifest));
        file_put_contents($paths['events_jsonl'], json_encode(['kind' => 'battery_started']).PHP_EOL);
        file_put_contents($paths['base'].'/intent.json', json_encode(['kind' => 'battery']));
        $this->writeSubCase($paths['base'].'/cases/case-1', cat: 'backend', data: ['atlas' => 92.0, 'rival' => 50.0, 'winner' => 'atlas'], difficulty: 'L3');
        $this->writeSubCase($paths['base'].'/cases/case-2', cat: 'backend', data: ['atlas' => 90.0, 'rival' => 88.0, 'winner' => 'human_review_required_tie'], difficulty: 'L2');
        $this->seedTopScorecard($runId, winner: 'atlas');
        $this->writeReceipts($paths['evidence']);
        $this->runCollectFinal($runId);
    }

    private function seedReplayFailedBattery(string $runId): void
    {
        $paths = $this->paths->paths($runId);
        @mkdir($paths['base'].'/cases', 0o755, true);
        @mkdir($paths['evidence'], 0o755, true);
        $manifest = $this->baseManifest($paths['run_id'], ['preset' => 'release', 'case_id' => 'multi']);
        file_put_contents($paths['manifest_json'], $this->jsonEncode($manifest));
        file_put_contents($paths['events_jsonl'], json_encode(['kind' => 'battery_started']).PHP_EOL);
        file_put_contents($paths['base'].'/intent.json', json_encode(['kind' => 'battery']));
        $sub = $this->writeSubCase($paths['base'].'/cases/case-1', cat: 'backend', data: ['atlas' => 88.0, 'rival' => 75.0, 'winner' => 'atlas'], difficulty: 'L3');
        // Remove a required sub-case replay artifact so aggregate replay fails.
        @unlink($sub.'/evidence/scorecard.json');
        $this->writeReceipts($paths['evidence']);
        $this->runCollectFinal($runId);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function writeSubCase(
        string $caseBase,
        string $cat,
        array $data,
        string $difficulty,
        string $mode = 'fair',
        string $atlasModel = 'claude_sonnet',
        string $rivalModel = 'claude_sonnet',
        array $manifestOverrides = [],
    ): string {
        @mkdir($caseBase.'/evidence', 0o755, true);
        $caseId = 'release-'.$cat.'-'.bin2hex(random_bytes(2));
        $manifest = $this->baseManifest($caseId, array_merge([
            'verdict' => 'comparable',
            'mode' => $mode,
            'preset' => 'release',
            'case_id' => $caseId,
            'task_category' => $cat,
            'difficulty_band' => $difficulty,
            'atlas_model' => $atlasModel,
            'rival_model' => $rivalModel,
        ], $manifestOverrides));
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
            'hard_gates' => [['code' => 'verdict_comparable', 'ok' => true, 'detail' => 'ok']],
            'quality_dimensions' => null,
        ];
        file_put_contents($caseBase.'/evidence/scorecard.json', $this->jsonEncode($score));
        $this->writeReceipts($caseBase.'/evidence');

        return $caseBase;
    }

    private function seedTopScorecard(string $runId, string $winner): void
    {
        $paths = $this->paths->paths($runId);
        $score = [
            'schema_version' => AtlasForgeRivalsAdjudicatorService::SCHEMA_VERSION,
            'winner' => $winner,
            'atlas_score' => 82.5,
            'rival_score' => 79.0,
            'score_source' => 'quality_dimensions',
            'quality_score_available' => true,
            'hard_failures' => [],
            'tie_threshold' => AtlasForgeRivalsAdjudicatorService::DEFAULT_TIE_THRESHOLD,
            'winner_reason' => ['aggregate'],
            'hard_gates' => [['code' => 'verdict_comparable', 'ok' => true, 'detail' => 'ok']],
            'quality_dimensions' => ['objective_alignment' => ['atlas' => 82.5, 'rival' => 79.0, 'explanation' => 'aggregate']],
        ];
        file_put_contents($paths['scorecard_json'], $this->jsonEncode($score));
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

    private function runCollectFinal(string $runId): void
    {
        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
        ]);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function baseManifest(string $runId, array $overrides): array
    {
        $atlasReceipt = $this->receipt('atlas');
        $rivalReceipt = $this->receipt('rival');
        $complexityProfile = $this->complexityProfile();

        return array_merge([
            'schema_version' => 'atlas.forge.rivals.run_real.v1',
            'run_id' => $runId,
            'mode' => 'fair',
            'atlas_model' => 'claude_sonnet',
            'rival_model' => 'claude_sonnet',
            'preset' => 'release',
            'case_id' => 'synthetic-case',
            'task_category' => 'backend',
            'difficulty_band' => 'L3',
            'role_focus' => 'builder',
            'verdict' => 'comparable',
            'atlas_receipt_hash' => hash('sha256', $this->jsonEncode($atlasReceipt)),
            'rival_receipt_hash' => hash('sha256', $this->jsonEncode($rivalReceipt)),
            'workspace_hash_before' => ['atlas' => 'h1', 'rival' => 'h1'],
            'workspace_hash_after' => ['atlas' => 'h2', 'rival' => 'h2'],
            'dirty_after_run' => false,
            'workspace_blockers' => [],
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'separated_from_external_rivals_certification' => true,
            'confirmations' => ['runbook_reviewed' => true, 'provider_cost' => true, 'real_provider_call' => true],
            'context_profile' => [
                'schema_version' => 'atlas.forge.rivals.context_profile.v1',
                'prompt_style' => 'human_ambiguous_operator_ticket',
                'long_context_required' => true,
                'requires_assumption_log' => true,
                'requires_tradeoff_notes' => true,
                'requires_scope_boundary_reasoning' => true,
                'requires_replayable_evidence' => true,
                'requires_evidence_matrix' => true,
                'complexity_profile' => $complexityProfile,
            ],
            'measurement_tags' => ['human_prompt', 'long_context', 'ambiguity_handling', 'assumption_probe', 'scope_boundary_probe'],
            'human_prompt_probe' => [
                'schema_version' => 'atlas.forge.rivals.human_prompt_probe.v1',
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
                'complexity_profile' => $complexityProfile,
            ],
        ], $overrides);
    }

    /**
     * @return array<string,mixed>
     */
    private function complexityProfile(array $overrides = []): array
    {
        return array_merge([
            'schema_version' => 'atlas.forge.rivals.case_complexity_profile.v1',
            'difficulty_level' => 'L3',
            'difficulty_score' => 3,
            'domain_count' => 2,
            'scope_surface_count' => 5,
            'estimated_context_tokens' => 5400,
            'reasoning_depth' => 4,
            'ambiguity_score' => 3,
            'risk_score' => 2,
            'long_context_required' => true,
            'requires_multi_step_plan' => true,
            'requires_rollback_plan' => false,
            'requires_evidence_matrix' => true,
            'measured_dimensions' => [
                'long_context_retention',
                'ambiguous_human_prompt_handling',
                'multi_step_reasoning',
                'scope_boundary_discipline',
                'replayable_evidence_quality',
                'honest_blocker_behavior',
            ],
        ], $overrides);
    }

    /**
     * @param  array<string,mixed>  $complexityProfile
     * @return array<string,mixed>
     */
    private function humanComplexityManifestOverrides(array $complexityProfile): array
    {
        return [
            'context_profile' => [
                'schema_version' => 'atlas.forge.rivals.context_profile.v1',
                'prompt_style' => 'human_ambiguous_operator_ticket',
                'long_context_required' => true,
                'requires_assumption_log' => true,
                'requires_tradeoff_notes' => true,
                'requires_scope_boundary_reasoning' => true,
                'requires_replayable_evidence' => true,
                'requires_evidence_matrix' => true,
                'complexity_profile' => $complexityProfile,
            ],
            'human_prompt_probe' => [
                'schema_version' => 'atlas.forge.rivals.human_prompt_probe.v1',
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
                'complexity_profile' => $complexityProfile,
            ],
        ];
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
            'changed_files' => ['tests/Feature/Synthetic.php'],
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
        return 'rank-'.bin2hex(random_bytes(4)).'-'.$suffix;
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
