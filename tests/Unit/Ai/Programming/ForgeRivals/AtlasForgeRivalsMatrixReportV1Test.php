<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsBatteryEvidenceService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsCollectEvidenceService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEvidencePolicy;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsMatrixReportService;
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
 *   - planning_score vs execution_score split over the canonical 9 quality
 *     dimensions,
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fr-matrix-'.bin2hex(random_bytes(6));
        @mkdir($this->tmpRoot, 0o755, true);
        config(['atlas_rivals.runs_root' => $this->tmpRoot]);

        $this->paths = new AtlasForgeRivalsRunPathResolver;
        $this->collect = new AtlasForgeRivalsCollectEvidenceService($this->paths);
        $this->battery = new AtlasForgeRivalsBatteryEvidenceService($this->paths, $this->collect);
        $this->matrix = new AtlasForgeRivalsMatrixReportService($this->paths, $this->battery);
    }

    protected function tearDown(): void
    {
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

    public function test_matrix_report_action_is_registered_in_cli(): void
    {
        $this->assertContains('matrix-report', \App\Console\Commands\AtlasForgeRivalsCommand::ACTIONS);
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
            'mode' => 'fair',
            'atlas_model' => 'claude_sonnet',
            'rival_model' => 'claude_sonnet',
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
