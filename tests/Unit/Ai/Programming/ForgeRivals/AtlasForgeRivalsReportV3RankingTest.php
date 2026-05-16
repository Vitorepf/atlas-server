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
        foreach (['provider_measurement', 'provider_recommendation', 'category_fit', 'difficulty_fit', 'mode_fit', 'provider_pair_fit', 'do_not_use_when', 'fallback_hint', 'confidence', 'rows'] as $key) {
            $this->assertArrayHasKey($key, $signal, "signal missing key {$key}");
        }
        $this->assertFalse($signal['should_update_provider_topology']);
        $this->assertSame('atlas_decide', $signal['owner_of_model_routing']);
        $this->assertSame('none', $signal['routing_effect']);
    }

    public function test_signal_provider_measurement_has_both_arms(): void
    {
        $runId = $this->newRunId('signal-arms');
        $this->seedMultiCaseBattery($runId);

        $signal = $this->report->render(['run_id' => $runId])['provider_performance_signal'];
        $arms = array_column($signal['provider_measurement'], 'arm');
        $this->assertContains('atlas', $arms);
        $this->assertContains('rival', $arms);
    }

    public function test_signal_do_not_use_when_surfaces_suspicious(): void
    {
        $runId = $this->newRunId('signal-do-not-use');
        $this->seedSuspiciousBattery($runId);

        $signal = $this->report->render(['run_id' => $runId])['provider_performance_signal'];
        $conditions = array_column($signal['do_not_use_when'], 'condition');
        $this->assertContains('suspicious_results_present', $conditions);
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
    ): string {
        @mkdir($caseBase.'/evidence', 0o755, true);
        $caseId = 'release-'.$cat.'-'.bin2hex(random_bytes(2));
        $manifest = $this->baseManifest($caseId, [
            'verdict' => 'comparable',
            'mode' => $mode,
            'preset' => 'release',
            'case_id' => $caseId,
            'task_category' => $cat,
            'difficulty_band' => $difficulty,
            'atlas_model' => $atlasModel,
            'rival_model' => $rivalModel,
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
        ], $overrides);
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
