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
 * Atlas Forge Rivals · Matrix Evidence & Replay Lock v1 contract.
 *
 * Locks down the multi-case matrix:
 *   - 40-case battery with L1-L5 distribution across categories.
 *   - per-case manifest, scorecard, atlas+rival receipts, atlas+rival patches,
 *     atlas+rival test logs, workspace hashes, difficulty_band declared.
 *   - missing receipt / patch / test log per case → case invalid.
 *   - missing difficulty_band (or non-L1..L5 value) → case invalid + matrix
 *     blocks claim final.
 *   - too many invalid cases (>10%) → claim_final blocked.
 *   - replay must traverse every case.
 *   - difficulty preserved through case → scorecard → evidence → replay → report.
 *
 * Never invokes provider, never destrava external_rivals_certification.
 */
final class AtlasForgeRivalsMatrixEvidenceLockTest extends TestCase
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
        $this->tmpRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fr-matrix-'.bin2hex(random_bytes(6));
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

    public function test_40_case_battery_with_full_evidence_is_matrix_ok(): void
    {
        $runId = $this->newRunId('40-case-ok');
        $this->seedBattery($runId, $this->fortyCaseBlueprint(invalidateBy: []));

        $report = $this->report->render(['run_id' => $runId]);

        $lock = $report['matrix_evidence_lock'];
        $this->assertSame('atlas.forge.rivals.matrix_evidence_lock.v1', $lock['schema_version']);
        $this->assertTrue($lock['is_multi_case']);
        $this->assertSame(40, $lock['total_cases']);
        $this->assertSame(40, $lock['valid_cases']);
        $this->assertSame(0, $lock['invalid_cases_count']);
        $this->assertTrue($lock['matrix_ok']);
        $this->assertFalse($lock['blocks_claim_final']);
    }

    public function test_required_artifacts_per_case_canon_is_declared(): void
    {
        $runId = $this->newRunId('canon-artifacts');
        $this->seedBattery($runId, $this->fortyCaseBlueprint(invalidateBy: []));

        $report = $this->report->render(['run_id' => $runId]);

        $required = $report['matrix_evidence_lock']['required_artifacts_per_case'];
        foreach (['manifest', 'scorecard', 'atlas_receipt', 'rival_receipt', 'atlas_patch', 'rival_patch', 'atlas_test_log', 'rival_test_log', 'workspace_hashes', 'difficulty_band'] as $artifact) {
            $this->assertContains($artifact, $required, "canon must include {$artifact}");
        }
    }

    public function test_difficulty_distribution_covers_l1_through_l5(): void
    {
        $runId = $this->newRunId('difficulty-distribution');
        $this->seedBattery($runId, $this->fortyCaseBlueprint(invalidateBy: []));

        $report = $this->report->render(['run_id' => $runId]);

        $distribution = $report['matrix_evidence_lock']['difficulty_distribution'];
        foreach (['L1', 'L2', 'L3', 'L4', 'L5'] as $band) {
            $this->assertGreaterThan(0, $distribution[$band], "missing band {$band}");
        }
        $this->assertSame(0, $distribution['unknown']);
    }

    public function test_missing_atlas_receipt_marks_case_invalid(): void
    {
        $runId = $this->newRunId('missing-atlas-receipt');
        $this->seedBattery($runId, $this->fortyCaseBlueprint(invalidateBy: ['atlas_receipt' => [3]]));

        $report = $this->report->render(['run_id' => $runId]);
        $lock = $report['matrix_evidence_lock'];

        $this->assertSame(1, $lock['invalid_cases_count']);
        $reasons = $lock['invalid_cases'][0]['reasons'];
        $this->assertContains('missing_atlas_receipt', $reasons);
    }

    public function test_missing_rival_patch_marks_case_invalid(): void
    {
        $runId = $this->newRunId('missing-rival-patch');
        $this->seedBattery($runId, $this->fortyCaseBlueprint(invalidateBy: ['rival_patch' => [7]]));

        $report = $this->report->render(['run_id' => $runId]);
        $lock = $report['matrix_evidence_lock'];

        $this->assertSame(1, $lock['invalid_cases_count']);
        $this->assertContains('missing_rival_patch', $lock['invalid_cases'][0]['reasons']);
    }

    public function test_missing_test_log_marks_case_invalid(): void
    {
        $runId = $this->newRunId('missing-test-log');
        $this->seedBattery($runId, $this->fortyCaseBlueprint(invalidateBy: ['atlas_test_log' => [11]]));

        $report = $this->report->render(['run_id' => $runId]);
        $lock = $report['matrix_evidence_lock'];

        $this->assertContains('missing_atlas_test_log', $lock['invalid_cases'][0]['reasons']);
    }

    public function test_missing_workspace_hashes_marks_case_invalid(): void
    {
        $runId = $this->newRunId('missing-ws-hashes');
        $this->seedBattery($runId, $this->fortyCaseBlueprint(invalidateBy: ['workspace_hashes' => [15]]));

        $report = $this->report->render(['run_id' => $runId]);
        $lock = $report['matrix_evidence_lock'];

        $this->assertContains('missing_workspace_hashes', $lock['invalid_cases'][0]['reasons']);
    }

    public function test_missing_difficulty_band_marks_case_invalid_and_blocks_claim(): void
    {
        $runId = $this->newRunId('missing-difficulty');
        $this->seedBattery($runId, $this->fortyCaseBlueprint(invalidateBy: ['difficulty_band' => [20]]));

        $report = $this->report->render(['run_id' => $runId]);
        $lock = $report['matrix_evidence_lock'];

        $this->assertGreaterThanOrEqual(1, count($lock['missing_difficulty_cases']));
        $this->assertContains('missing_difficulty_band', $lock['invalid_cases'][0]['reasons']);
        $this->assertTrue($lock['blocks_claim_final']);
        $this->assertFalse($report['claim_status']['claim_ready']);
        $this->assertFalse($report['claim_status']['battery_result_valid']);
    }

    public function test_invalid_difficulty_value_is_coerced_to_unknown_and_blocks(): void
    {
        $runId = $this->newRunId('invalid-difficulty');
        $this->seedBattery($runId, $this->fortyCaseBlueprint(invalidateBy: ['difficulty_band' => [25], 'difficulty_band_value' => 'banana']));

        $report = $this->report->render(['run_id' => $runId]);
        $lock = $report['matrix_evidence_lock'];

        $this->assertTrue($lock['blocks_claim_final']);
        $unknownEntry = null;
        foreach ($lock['per_case'] as $entry) {
            if ($entry['difficulty_band'] === 'unknown') {
                $unknownEntry = $entry;
                break;
            }
        }
        $this->assertNotNull($unknownEntry, 'invalid difficulty must coerce to unknown');
    }

    public function test_any_single_invalid_case_blocks_claim_in_hard_floor(): void
    {
        $runId = $this->newRunId('hard-floor');
        $this->seedBattery($runId, $this->fortyCaseBlueprint(invalidateBy: ['atlas_patch' => [4]]));

        $report = $this->report->render(['run_id' => $runId]);

        $this->assertFalse($report['matrix_evidence_lock']['matrix_ok']);
        $this->assertTrue($report['matrix_evidence_lock']['blocks_claim_final']);
        $this->assertFalse($report['claim_status']['claim_ready']);
    }

    public function test_invalid_ratio_above_threshold_blocks_claim(): void
    {
        $runId = $this->newRunId('ratio-block');
        // Invalidate 5/40 = 12.5% > 10% threshold.
        $this->seedBattery($runId, $this->fortyCaseBlueprint(invalidateBy: ['rival_test_log' => [1, 2, 3, 4, 5]]));

        $report = $this->report->render(['run_id' => $runId]);
        $lock = $report['matrix_evidence_lock'];

        $this->assertSame(5, $lock['invalid_cases_count']);
        $this->assertGreaterThan(0.10, $lock['invalid_ratio']);
        $this->assertTrue($lock['blocks_claim_final']);
    }

    public function test_difficulty_preserved_in_case_results_after_replay(): void
    {
        $runId = $this->newRunId('difficulty-preserved');
        $this->seedBattery($runId, $this->fortyCaseBlueprint(invalidateBy: []));

        $report = $this->report->render(['run_id' => $runId]);
        $cases = $report['case_results'];
        foreach ($cases as $case) {
            $this->assertContains($case['difficulty_band'], ['L1', 'L2', 'L3', 'L4', 'L5']);
        }
    }

    public function test_matrix_per_case_inventory_lists_required_artifact_presence(): void
    {
        $runId = $this->newRunId('per-case-inventory');
        $this->seedBattery($runId, $this->fortyCaseBlueprint(invalidateBy: []));

        $report = $this->report->render(['run_id' => $runId]);
        $perCase = $report['matrix_evidence_lock']['per_case'];

        $this->assertCount(40, $perCase);
        foreach ($perCase as $entry) {
            $required = $entry['required'];
            foreach (['manifest', 'scorecard', 'atlas_receipt', 'rival_receipt', 'atlas_patch', 'rival_patch', 'atlas_test_log', 'rival_test_log', 'workspace_hashes', 'difficulty_band'] as $k) {
                $this->assertArrayHasKey($k, $required);
            }
            $this->assertTrue($entry['valid']);
        }
    }

    public function test_matrix_blocks_can_feed_ledger_when_invalid_present(): void
    {
        $runId = $this->newRunId('block-ledger');
        $this->seedBattery($runId, $this->fortyCaseBlueprint(invalidateBy: ['atlas_test_log' => [9]]));

        $report = $this->report->render(['run_id' => $runId]);

        $this->assertFalse($report['claim_status']['can_feed_ledger']);
        $this->assertFalse($report['claim_status']['can_feed_decide_signal']);
        $this->assertTrue($report['claim_status']['matrix_blocks_claim_final']);
    }

    public function test_single_case_run_does_not_apply_matrix_hard_floor(): void
    {
        $runId = $this->newRunId('single-case-advisory');
        $this->seedSingleCase($runId);

        $report = $this->report->render(['run_id' => $runId]);

        $this->assertFalse($report['matrix_evidence_lock']['is_multi_case']);
        $this->assertTrue($report['matrix_evidence_lock']['matrix_ok']);
        $this->assertFalse($report['matrix_evidence_lock']['blocks_claim_final']);
    }

    public function test_replay_drift_case_marked_invalid(): void
    {
        $runId = $this->newRunId('replay-drift');
        $blueprint = $this->fortyCaseBlueprint(invalidateBy: ['hard_failures' => [13]]);
        $this->seedBattery($runId, $blueprint);

        $report = $this->report->render(['run_id' => $runId]);
        $lock = $report['matrix_evidence_lock'];

        $this->assertContains('replay_did_not_pass', $lock['invalid_cases'][0]['reasons']);
        $this->assertNotEmpty($lock['replay_drift_cases']);
    }

    public function test_external_rivals_status_remains_blocked(): void
    {
        $runId = $this->newRunId('external-blocked');
        $this->seedBattery($runId, $this->fortyCaseBlueprint(invalidateBy: []));

        $report = $this->report->render(['run_id' => $runId]);

        $this->assertSame('blocked_requires_operator_approval', $report['claim_status']['external_rivals_certification_status']);
        $this->assertFalse($report['unlocks_external_rivals_certification']);
    }

    public function test_rule_summary_mentions_required_artifacts(): void
    {
        $runId = $this->newRunId('rule-summary');
        $this->seedBattery($runId, $this->fortyCaseBlueprint(invalidateBy: []));

        $report = $this->report->render(['run_id' => $runId]);

        $summary = (string) $report['matrix_evidence_lock']['rule_summary'];
        $this->assertStringContainsString('manifest', $summary);
        $this->assertStringContainsString('difficulty_band', $summary);
        $this->assertStringContainsString('L1-L5', $summary);
    }

    // ---------- fixtures ----------

    /**
     * 40-case blueprint: 8 categories × 5 difficulty bands (L1..L5).
     *
     * @param  array<string,mixed>  $invalidateBy  Keys are artifact names; values are list<int> of case indexes (1..40) to skip writing that artifact. Special key `difficulty_band_value` overrides the difficulty for invalidate slots.
     * @return list<array<string,mixed>>
     */
    private function fortyCaseBlueprint(array $invalidateBy): array
    {
        $categories = ['backend', 'frontend', 'bugfix', 'tests', 'refactor', 'architecture', 'docs', 'performance'];
        $bands = ['L1', 'L2', 'L3', 'L4', 'L5'];
        $bp = [];
        $idx = 1;
        foreach ($categories as $cat) {
            foreach ($bands as $band) {
                $skip = [];
                foreach ($invalidateBy as $artifact => $slots) {
                    if (is_array($slots) && in_array($idx, $slots, true)) {
                        $skip[] = $artifact;
                    }
                }
                if (in_array('difficulty_band', $skip, true)) {
                    $diffOverride = isset($invalidateBy['difficulty_band_value'])
                        ? (string) $invalidateBy['difficulty_band_value']
                        : ''; // empty → manifest omits a valid L1-L5 value
                } else {
                    $diffOverride = null;
                }
                $bp[] = [
                    'idx' => $idx,
                    'category' => $cat,
                    'difficulty' => $band,
                    'difficulty_override' => $diffOverride,
                    'skip' => $skip,
                    'atlas_score' => 82.0 + ($idx % 7),
                    'rival_score' => 75.0 + ($idx % 5),
                    'winner' => 'atlas',
                ];
                $idx++;
            }
        }

        return $bp;
    }

    /**
     * @param  list<array<string,mixed>>  $blueprint
     */
    private function seedBattery(string $runId, array $blueprint): void
    {
        $paths = $this->paths->paths($runId);
        @mkdir($paths['base'].'/cases', 0o755, true);
        @mkdir($paths['evidence'], 0o755, true);

        $manifest = $this->baseManifest($paths['run_id'], [
            'preset' => 'release', 'case_id' => 'multi',
            'task_category' => 'aggregate', 'difficulty_band' => 'L3',
        ]);
        file_put_contents($paths['manifest_json'], $this->jsonEncode($manifest));
        file_put_contents($paths['events_jsonl'], json_encode(['kind' => 'battery_started']).PHP_EOL);
        file_put_contents($paths['base'].'/intent.json', json_encode(['kind' => 'battery']));
        $this->writeReceipts($paths['evidence']);

        foreach ($blueprint as $entry) {
            $caseBase = $paths['base'].'/cases/case-'.$entry['idx'];
            @mkdir($caseBase.'/evidence', 0o755, true);
            $cat = (string) $entry['category'];
            $skip = (array) $entry['skip'];
            $diff = $entry['difficulty_override'] ?? (string) $entry['difficulty'];

            // Manifest declares the difficulty (canon L1-L5).
            $cmf = $this->baseManifest('case-'.$entry['idx'].'-'.$cat, [
                'case_id' => 'case-'.$entry['idx'].'-'.$cat,
                'task_category' => $cat,
                'difficulty_band' => $diff,
                'preset' => 'release',
                'mode' => 'fair',
            ]);
            if (! in_array('manifest', $skip, true)) {
                file_put_contents($caseBase.'/evidence/manifest.json', $this->jsonEncode($cmf));
            }

            $hardFailures = in_array('hard_failures', $skip, true) ? ['fake_hard_fail_for_test'] : [];
            $score = [
                'schema_version' => AtlasForgeRivalsAdjudicatorService::SCHEMA_VERSION,
                'winner' => $entry['winner'] === 'tie' ? AtlasForgeRivalsAdjudicatorService::WINNER_TIE : ($entry['winner'] === 'rival' ? AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL : AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS),
                'atlas_score' => $entry['atlas_score'],
                'rival_score' => $entry['rival_score'],
                'score_source' => 'quality_dimensions',
                'quality_score_available' => true,
                'hard_failures' => $hardFailures,
                'tie_threshold' => AtlasForgeRivalsAdjudicatorService::DEFAULT_TIE_THRESHOLD,
                'winner_reason' => ['demo'],
                'hard_gates' => [['code' => 'verdict_comparable', 'ok' => true, 'detail' => 'ok']],
                'quality_dimensions' => null,
            ];
            if (! in_array('scorecard', $skip, true)) {
                file_put_contents($caseBase.'/evidence/scorecard.json', $this->jsonEncode($score));
            }
            $this->writeReceiptsSelective($caseBase.'/evidence', $skip);
        }

        // Top-level scorecard with no hard failures so single-case logic doesn't get tripped.
        file_put_contents($paths['scorecard_json'], $this->jsonEncode([
            'schema_version' => AtlasForgeRivalsAdjudicatorService::SCHEMA_VERSION,
            'winner' => AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS,
            'atlas_score' => 85.0, 'rival_score' => 78.0,
            'score_source' => 'quality_dimensions', 'quality_score_available' => true,
            'hard_failures' => [], 'tie_threshold' => AtlasForgeRivalsAdjudicatorService::DEFAULT_TIE_THRESHOLD,
            'winner_reason' => ['aggregate'],
            'hard_gates' => [['code' => 'verdict_comparable', 'ok' => true, 'detail' => 'ok']],
            'quality_dimensions' => ['objective_alignment' => ['atlas' => 85.0, 'rival' => 78.0, 'explanation' => 'agg']],
        ]));
        $this->collect->collect(['run_id' => $runId, 'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_FINAL]);
    }

    private function seedSingleCase(string $runId): void
    {
        $paths = $this->paths->paths($runId);
        @mkdir($paths['base'], 0o755, true);
        @mkdir($paths['evidence'], 0o755, true);
        $manifest = $this->baseManifest($paths['run_id'], [
            'verdict' => 'comparable', 'mode' => 'fair', 'preset' => 'release',
            'case_id' => 'single', 'task_category' => 'backend', 'difficulty_band' => 'L3',
        ]);
        file_put_contents($paths['manifest_json'], $this->jsonEncode($manifest));
        file_put_contents($paths['events_jsonl'], json_encode(['kind' => 'run_started']).PHP_EOL);
        file_put_contents($paths['base'].'/intent.json', json_encode(['kind' => 'single']));
        $this->writeReceipts($paths['evidence']);
        file_put_contents($paths['scorecard_json'], $this->jsonEncode([
            'schema_version' => AtlasForgeRivalsAdjudicatorService::SCHEMA_VERSION,
            'winner' => AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS,
            'atlas_score' => 86.0, 'rival_score' => 79.0,
            'score_source' => 'quality_dimensions', 'quality_score_available' => true,
            'hard_failures' => [], 'tie_threshold' => AtlasForgeRivalsAdjudicatorService::DEFAULT_TIE_THRESHOLD,
            'winner_reason' => ['single'],
            'hard_gates' => [['code' => 'verdict_comparable', 'ok' => true, 'detail' => 'ok']],
            'quality_dimensions' => ['objective_alignment' => ['atlas' => 86.0, 'rival' => 79.0, 'explanation' => 'single']],
        ]));
        $this->collect->collect(['run_id' => $runId, 'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_FINAL]);
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
        file_put_contents($evidenceDir.'/atlas_patch.diff', '--- atlas ---');
        file_put_contents($evidenceDir.'/rival_patch.diff', '--- rival ---');
        file_put_contents($evidenceDir.'/atlas_test.log', '(50 tests, 120 assertions)');
        file_put_contents($evidenceDir.'/rival_test.log', '(50 tests, 120 assertions)');
    }

    /**
     * @param  list<string>  $skip
     */
    private function writeReceiptsSelective(string $evidenceDir, array $skip): void
    {
        $atlas = $this->receipt('atlas');
        $rival = $this->receipt('rival');
        if (! in_array('atlas_receipt', $skip, true)) {
            file_put_contents($evidenceDir.'/atlas_receipt.json', $this->jsonEncode($atlas));
        }
        if (! in_array('rival_receipt', $skip, true)) {
            file_put_contents($evidenceDir.'/rival_receipt.json', $this->jsonEncode($rival));
        }
        if (! in_array('workspace_hashes', $skip, true)) {
            file_put_contents($evidenceDir.'/workspace_hashes.json', $this->jsonEncode([
                'before' => ['atlas' => 'h1', 'rival' => 'h1'],
                'after' => ['atlas' => 'h2', 'rival' => 'h2'],
                'dirty_after_run' => false,
                'workspace_blockers' => [],
            ]));
        }
        if (! in_array('atlas_patch', $skip, true)) {
            file_put_contents($evidenceDir.'/atlas_patch.diff', '--- atlas ---');
        }
        if (! in_array('rival_patch', $skip, true)) {
            file_put_contents($evidenceDir.'/rival_patch.diff', '--- rival ---');
        }
        if (! in_array('atlas_test_log', $skip, true)) {
            file_put_contents($evidenceDir.'/atlas_test.log', '(50 tests, 120 assertions)');
        }
        if (! in_array('rival_test_log', $skip, true)) {
            file_put_contents($evidenceDir.'/rival_test.log', '(50 tests, 120 assertions)');
        }
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
            'arm' => $arm, 'mode' => 'fair', 'model' => 'claude_sonnet',
            'command_hash' => hash('sha256', $arm), 'prompt_hash' => hash('sha256', $arm.'p'),
            'started_at' => '2026-05-15T12:00:00+00:00', 'finished_at' => '2026-05-15T12:01:00+00:00',
            'exit_code' => 0, 'killed' => false, 'timeout' => false, 'timeout_reason' => null,
            'stdout_hash' => hash('sha256', $arm.'so'), 'stderr_hash' => hash('sha256', $arm.'se'),
            'stdout_bytes' => 4000, 'stderr_bytes' => 0,
            'stdout_tail' => '', 'stderr_tail' => '',
            'stdout_path' => '/tmp/s', 'stderr_path' => '/tmp/e',
            'token_cost' => 0.01, 'tokens_used' => 100, 'worktree' => '/tmp/w', 'case_id' => 'demo',
            'changed_files' => [], 'out_of_scope_files' => [],
            'bytecode_artifacts' => [], 'workspace_blockers' => [], 'workspace_has_blocking_changes' => false,
            'patch_diff_path' => '/tmp/p.diff', 'patch_diff_hash' => hash('sha256', $arm.'pd'),
            'patch_diff_bytes' => 3000,
            'test_command' => 'phpunit', 'test_exit_code' => 0,
            'test_log_path' => '/tmp/t.log', 'test_log_hash' => hash('sha256', $arm.'tl'),
            'test_log_tail' => '',
        ];
    }

    private function jsonEncode(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function newRunId(string $suffix): string
    {
        return 'matrix-'.bin2hex(random_bytes(4)).'-'.$suffix;
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
