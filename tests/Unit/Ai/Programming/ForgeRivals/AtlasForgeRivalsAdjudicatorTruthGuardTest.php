<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsAdjudicatorCalibrationService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsAdjudicatorV2Service;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunPathResolver;
use Tests\TestCase;

/**
 * Atlas Forge Rivals · Adjudicator Truth Guard v1 + Calibration contract.
 *
 * Golden fixtures (6) covering empate, vitória justa, hard fail, timeout,
 * missing evidence and absurd 100-vs-0 score. Each fixture pairs with a test
 * asserting the canonical outcome described by
 * `atlas-forge-rivals-benchmark-strategy-v1.md` §Taxonomia de dificuldade
 * and §Triage de resultado suspeito.
 *
 * The Truth Guard NEVER promotes claim_ready, NEVER unlocks
 * external_rivals_certification, NEVER trusts a score that lacks a structural
 * explanation. Difficulty changes weight but never masks failure.
 */
final class AtlasForgeRivalsAdjudicatorTruthGuardTest extends TestCase
{
    private AtlasForgeRivalsAdjudicatorV2Service $svc;

    private AtlasForgeRivalsAdjudicatorCalibrationService $calibration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calibration = new AtlasForgeRivalsAdjudicatorCalibrationService;
        $this->svc = new AtlasForgeRivalsAdjudicatorV2Service(new AtlasForgeRivalsRunPathResolver, $this->calibration);
    }

    public function test_calibration_table_snapshot_is_self_consistent(): void
    {
        $snap = $this->calibration->tableSnapshot();
        $this->assertSame(AtlasForgeRivalsAdjudicatorCalibrationService::SCHEMA_VERSION, $snap['schema_version']);
        $this->assertSame(
            [
                'L1' => 1.00,
                'L2' => 1.20,
                'L3' => 1.50,
                'L4' => 2.00,
                'L5' => 2.50,
            ],
            $snap['difficulty_multipliers'],
        );

        foreach ($snap['difficulty_plan_exec'] as $level => $row) {
            $this->assertEqualsWithDelta(1.0, $row['planning'] + $row['execution'], 0.0001, "plan/exec sum for {$level}");
            $this->assertGreaterThanOrEqual(0.0, $row['planning']);
            $this->assertGreaterThanOrEqual(0.0, $row['execution']);
        }

        foreach ($snap['category_weights'] as $cat => $weights) {
            $this->assertEqualsWithDelta(1.0, array_sum($weights), 0.01, "weights for {$cat} must sum to 1.0");
        }

        $this->assertContains('planning', AtlasForgeRivalsAdjudicatorV2Service::TASK_CATEGORIES);
    }

    public function test_difficulty_resolver_falls_back_to_l3(): void
    {
        $d = $this->calibration->resolveDifficulty(null, null);
        $this->assertSame('L3', $d['level']);
        $this->assertSame(1.50, $d['multiplier']);
    }

    public function test_difficulty_multiplier_never_masks_hard_fail(): void
    {
        // Even at L5, an invalid case keeps difficulty_weighted_score=null.
        $weighted = $this->calibration->applyDifficultyMultiplier(80.0, 'L5', valid: false);
        $this->assertNull($weighted);

        // Valid L5 score caps at 100.
        $weighted = $this->calibration->applyDifficultyMultiplier(80.0, 'L5', valid: true);
        $this->assertSame(100.0, $weighted);
    }

    public function test_golden_tie_yields_no_claim(): void
    {
        $env = $this->svc->adjudicateBatch(['payload' => $this->goldenTie()]);
        $case = $env['cases'][0];

        $this->assertSame(AtlasForgeRivalsAdjudicatorV2Service::WINNER_TIE, $case['winner']);
        $this->assertSame(AtlasForgeRivalsAdjudicatorV2Service::OUTCOME_TIE, $case['outcome']);
        $this->assertTrue($case['human_review_required']);
        $this->assertFalse($case['claim_ready']);
        $this->assertFalse($env['claim_ready']);
    }

    public function test_golden_fair_win_explains_why_not_100(): void
    {
        $env = $this->svc->adjudicateBatch(['payload' => $this->goldenFairWin()]);
        $case = $env['cases'][0];

        $this->assertContains($case['winner'], [
            AtlasForgeRivalsAdjudicatorV2Service::WINNER_ATLAS,
            AtlasForgeRivalsAdjudicatorV2Service::WINNER_RIVAL,
        ]);
        $this->assertSame(AtlasForgeRivalsAdjudicatorV2Service::OUTCOME_WINNER, $case['outcome']);
        $winnerArm = $case['winner'];
        $breakdown = $case[$winnerArm.'_breakdown'];
        $this->assertFalse($breakdown['hard_fail']);
        $this->assertNotNull($breakdown['quality_score']);
        $this->assertNotEmpty($breakdown['score_explanation']);
        // Must surface the "not_100" reason and a top loss dimension.
        $joined = implode("\n", $breakdown['score_explanation']);
        $this->assertStringContainsString('not_100_because', $joined);
        $this->assertStringContainsString('top_loss_dimension', $joined);
        $this->assertStringContainsString('difficulty=', $joined);
    }

    public function test_golden_hard_fail_keeps_score_null_and_outcome_invalid(): void
    {
        $env = $this->svc->adjudicateBatch(['payload' => $this->goldenHardFail()]);
        $case = $env['cases'][0];

        $this->assertNotEmpty($case['hard_failures']);
        $this->assertNull($case['scores']['atlas']);
        $this->assertNull($case['scores']['rival']);
        $this->assertNull($case['winner']);
        $this->assertSame(AtlasForgeRivalsAdjudicatorV2Service::OUTCOME_INVALID, $case['outcome']);
        $this->assertTrue($case['atlas_breakdown']['hard_fail']);
        $this->assertTrue($case['rival_breakdown']['hard_fail']);
        $this->assertNull($case['atlas_breakdown']['difficulty_weighted_score']);
        $this->assertNull($case['rival_breakdown']['difficulty_weighted_score']);
        $this->assertFalse($env['claim_ready']);
    }

    public function test_golden_timeout_is_marked_as_provider_timeout_not_quality(): void
    {
        $env = $this->svc->adjudicateBatch(['payload' => $this->goldenTimeout()]);
        $case = $env['cases'][0];

        // The timeout produces timeout_without_result + stalled_runner_no_heartbeat
        // hard gates, so the case is invalid.
        $this->assertNotEmpty($case['hard_failures']);
        $hasTimeoutGate = false;
        foreach ($case['hard_failures'] as $code) {
            if (str_contains((string) $code, 'timeout_without_result') || str_contains((string) $code, 'stalled_runner')) {
                $hasTimeoutGate = true;
                break;
            }
        }
        $this->assertTrue($hasTimeoutGate, 'timeout must surface as hard gate');
        $this->assertSame(AtlasForgeRivalsAdjudicatorV2Service::OUTCOME_INVALID, $case['outcome']);
        $this->assertFalse($env['claim_ready']);
    }

    public function test_golden_missing_evidence_blocks_claim(): void
    {
        $env = $this->svc->adjudicateBatch(['payload' => $this->goldenMissingEvidence()]);
        $case = $env['cases'][0];

        $this->assertContains('evidence_complete', $case['hard_failures']);
        $this->assertNull($case['winner']);
        $this->assertSame(AtlasForgeRivalsAdjudicatorV2Service::OUTCOME_INVALID, $case['outcome']);
        $this->assertFalse($env['claim_ready']);
    }

    public function test_golden_100_vs_0_is_needs_triage_never_claim(): void
    {
        $env = $this->svc->adjudicateBatch(['payload' => $this->golden100vs0()]);
        $case = $env['cases'][0];

        $codes = array_column($case['suspicious_results'], 'code');
        $this->assertContains('score_100_vs_0', $codes);
        $affects = array_filter($case['suspicious_results'], static fn (array $s): bool => ! empty($s['affects_winner']));
        $this->assertNotEmpty($affects);
        $this->assertSame(AtlasForgeRivalsAdjudicatorV2Service::OUTCOME_NEEDS_TRIAGE, $case['outcome']);
        $this->assertTrue($case['human_review_required']);
        $this->assertFalse($env['claim_ready']);
        $this->assertSame('BLOCKED', $env['external_rivals_certification_status']);
    }

    public function test_difficulty_level_l5_planning_weight_is_high(): void
    {
        $payload = $this->goldenFairWin();
        $payload['runs'][0]['task_category'] = 'planning';
        $payload['runs'][0]['case_manifest'] = [
            'role_focus' => 'system_design',
            'difficulty_level' => 'L5',
            'difficulty_reason' => 'multi-module schema redesign with backwards-compat constraints',
            'quality_gates' => [
                'dimensions' => ['architecture_fit', 'correctness', 'evidence_quality'],
                'weights' => ['architecture_fit' => 0.5, 'correctness' => 0.3, 'evidence_quality' => 0.2],
            ],
        ];

        $env = $this->svc->adjudicateBatch(['payload' => $payload]);
        $case = $env['cases'][0];

        $this->assertSame('L5', $case['difficulty']['level']);
        $this->assertSame(2.5, $case['difficulty']['multiplier']);
        $this->assertSame('planning', $case['task_category']);
        $this->assertGreaterThan(0.5, $case['difficulty']['planning_weight']);
        $this->assertLessThan(0.5, $case['difficulty']['execution_weight']);
        // difficulty_weighted_score must be capped at 100, never above.
        $this->assertLessThanOrEqual(100.0, $case['atlas_breakdown']['difficulty_weighted_score']);
        $this->assertLessThanOrEqual(100.0, $case['rival_breakdown']['difficulty_weighted_score']);
    }

    public function test_patch_almost_empty_surfaces_as_suspicious(): void
    {
        $payload = $this->goldenFairWin();
        // Truth Guard threshold is 64 bytes; configure rival just below it but
        // > 0 (so it does not trip the patch_diff_present hard gate).
        $payload['runs'][0]['rival_receipt']['patch_diff_bytes'] = 40;

        $env = $this->svc->adjudicateBatch(['payload' => $payload]);
        $codes = array_column($env['cases'][0]['suspicious_results'], 'code');

        $this->assertContains('rival_patch_almost_empty', $codes);
    }

    public function test_provider_timeout_low_score_is_flagged_not_judged_as_low_quality(): void
    {
        $payload = $this->goldenFairWin();
        // Pin weights so rival score lands below the canon Truth Guard
        // threshold (50). architecture_fit (30 files ⇒ 35) and minimality
        // (200KB ⇒ 25) keep rival quality_score in the [25, 35] band, which
        // is exactly what the timeout-vs-quality detector must catch.
        $payload['runs'][0]['case_manifest'] = [
            'role_focus' => 'api_correctness',
            'difficulty_level' => 'L3',
            'difficulty_reason' => 'long-running integration with timeout-prone branches',
            'quality_gates' => [
                'dimensions' => ['minimality', 'architecture_fit'],
                'weights' => ['minimality' => 0.60, 'architecture_fit' => 0.40],
            ],
        ];
        $payload['runs'][0]['rival_receipt']['timeout'] = true;
        $payload['runs'][0]['rival_receipt']['killed'] = false;
        $payload['runs'][0]['rival_receipt']['test_log_tail'] = '(2 tests, 4 assertions)';
        $payload['runs'][0]['rival_receipt']['patch_diff_bytes'] = 200_000;
        $payload['runs'][0]['rival_receipt']['changed_files'] = array_map(static fn (int $i) => 'src/x_'.$i.'.php', range(1, 30));

        $env = $this->svc->adjudicateBatch(['payload' => $payload]);
        $codes = array_column($env['cases'][0]['suspicious_results'], 'code');

        $this->assertContains('provider_timeout_mistaken_for_low_quality', $codes);
    }

    // ---- golden fixtures ---------------------------------------------------

    /** @return array<string,mixed> */
    private function goldenTie(): array
    {
        return [
            'schema_version' => AtlasForgeRivalsAdjudicatorV2Service::BATCH_INPUT_SCHEMA_VERSION,
            'preset' => 'release',
            'mode' => 'fair',
            'arms' => $this->arms(),
            'runs' => [$this->baseRun('golden-tie', 'backend_logic')],
        ];
    }

    /** @return array<string,mixed> */
    private function goldenFairWin(): array
    {
        $run = $this->baseRun('golden-fair-win', 'backend_logic');
        $run['atlas_receipt']['test_log_tail'] = '(60 tests, 200 assertions)';
        $run['rival_receipt']['test_log_tail'] = '(30 tests, 40 assertions)';
        $run['case_manifest'] = [
            'role_focus' => 'api_correctness',
            'difficulty_level' => 'L3',
            'difficulty_reason' => 'multi-file integration with cursor pagination',
            'quality_gates' => [
                'dimensions' => ['test_coverage', 'scope_discipline', 'minimality', 'correctness'],
                'weights' => [
                    'test_coverage' => 0.40,
                    'scope_discipline' => 0.25,
                    'minimality' => 0.15,
                    'correctness' => 0.20,
                ],
            ],
        ];

        return [
            'schema_version' => AtlasForgeRivalsAdjudicatorV2Service::BATCH_INPUT_SCHEMA_VERSION,
            'preset' => 'release',
            'mode' => 'fair',
            'arms' => $this->arms(),
            'runs' => [$run],
        ];
    }

    /** @return array<string,mixed> */
    private function goldenHardFail(): array
    {
        $run = $this->baseRun('golden-hard-fail', 'backend_logic');
        $run['rival_receipt']['out_of_scope_files'] = ['src/sneaky.php'];

        return [
            'schema_version' => AtlasForgeRivalsAdjudicatorV2Service::BATCH_INPUT_SCHEMA_VERSION,
            'preset' => 'release',
            'mode' => 'fair',
            'arms' => $this->arms(),
            'runs' => [$run],
        ];
    }

    /** @return array<string,mixed> */
    private function goldenTimeout(): array
    {
        $run = $this->baseRun('golden-timeout', 'backend_logic');
        $run['rival_receipt']['timeout'] = true;
        $run['rival_receipt']['killed'] = true;
        $run['rival_receipt']['exit_code'] = 124;
        $run['rival_receipt']['patch_diff_bytes'] = 0;
        $run['rival_receipt']['test_log_path'] = '';

        return [
            'schema_version' => AtlasForgeRivalsAdjudicatorV2Service::BATCH_INPUT_SCHEMA_VERSION,
            'preset' => 'release',
            'mode' => 'fair',
            'arms' => $this->arms(),
            'runs' => [$run],
        ];
    }

    /** @return array<string,mixed> */
    private function goldenMissingEvidence(): array
    {
        $run = $this->baseRun('golden-missing-evidence', 'backend_logic');
        $run['evidence_pack']['missing_evidence'] = ['atlas_receipt', 'workspace_hashes'];
        $run['evidence_pack']['artifacts'] = [
            'manifest' => ['present' => true, 'sha256' => str_repeat('a', 64)],
            'events_jsonl' => ['present' => true, 'sha256' => str_repeat('b', 64)],
        ];

        return [
            'schema_version' => AtlasForgeRivalsAdjudicatorV2Service::BATCH_INPUT_SCHEMA_VERSION,
            'preset' => 'release',
            'mode' => 'fair',
            'arms' => $this->arms(),
            'runs' => [$run],
        ];
    }

    /** @return array<string,mixed> */
    private function golden100vs0(): array
    {
        $run = $this->baseRun('golden-100-vs-0', 'backend_logic');
        $run['atlas_receipt']['test_log_tail'] = '(300 tests, 900 assertions)';
        $run['atlas_receipt']['patch_diff_bytes'] = 1500;
        $run['atlas_receipt']['changed_files'] = ['app/Foo.php'];
        $run['rival_receipt']['test_log_tail'] = '(1 tests, 1 assertions)';
        $run['rival_receipt']['patch_diff_bytes'] = 250000;
        $run['rival_receipt']['changed_files'] = array_map(static fn (int $i): string => 'src/big_'.$i.'.php', range(1, 30));
        // Pin weights to dimensions that can drive a 100 vs 0 score (the
        // canon Truth Guard threshold is margin >= 95 OR atlas>=99 / rival<=1).
        $run['case_manifest'] = [
            'role_focus' => 'minimal_diff',
            'difficulty_level' => 'L1',
            'difficulty_reason' => 'mechanical_off_by_one',
            'quality_gates' => [
                'dimensions' => ['test_coverage', 'minimality'],
                'weights' => ['test_coverage' => 0.5, 'minimality' => 0.5],
            ],
        ];

        return [
            'schema_version' => AtlasForgeRivalsAdjudicatorV2Service::BATCH_INPUT_SCHEMA_VERSION,
            'preset' => 'release',
            'mode' => 'fair',
            'arms' => $this->arms(),
            'runs' => [$run],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function arms(): array
    {
        return [
            [
                'arm_id' => 'atlas',
                'runner_type' => 'atlas_forge',
                'provider' => 'anthropic_claude',
                'model' => 'claude_sonnet',
                'role' => 'builder',
            ],
            [
                'arm_id' => 'rival',
                'runner_type' => 'raw_provider',
                'provider' => 'anthropic_claude',
                'model' => 'claude_sonnet',
                'role' => 'builder',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function baseRun(string $runId, string $taskCategory): array
    {
        $receipt = [
            'arm' => 'atlas',
            'mode' => 'fair',
            'model' => 'claude_sonnet',
            'exit_code' => 0,
            'killed' => false,
            'timeout' => false,
            'stdout_bytes' => 4_000,
            'stderr_bytes' => 0,
            'started_at' => '2026-05-15T12:00:00+00:00',
            'finished_at' => '2026-05-15T12:01:00+00:00',
            'token_cost' => 0.01,
            'tokens_used' => 100,
            'changed_files' => ['tests/Feature/Foo.php', 'app/Foo.php'],
            'out_of_scope_files' => [],
            'bytecode_artifacts' => [],
            'workspace_blockers' => [],
            'patch_diff_bytes' => 3_000,
            'test_command' => 'phpunit',
            'test_exit_code' => 0,
            'test_log_path' => '/tmp/test.log',
            'test_log_tail' => '(50 tests, 120 assertions)',
            'provider_policy_violation' => false,
            'forbidden_paths_touched' => [],
        ];

        return [
            'run_id' => $runId,
            'task_category' => $taskCategory,
            'case_id' => 'golden-'.$runId,
            'replay_passes' => true,
            'manifest' => [
                'run_id' => $runId,
                'mode' => 'fair',
                'atlas_model' => 'claude_sonnet',
                'rival_model' => 'claude_sonnet',
                'preset' => 'release',
                'verdict' => 'comparable',
                'task_category' => $taskCategory,
                'dirty_after_run' => false,
                'workspace_dirty_before' => false,
                'claim_ready' => false,
            ],
            'atlas_receipt' => $receipt,
            'rival_receipt' => array_replace($receipt, ['arm' => 'rival']),
            'workspace_hashes' => [
                'before' => ['atlas' => 'h1', 'rival' => 'h1'],
                'after' => ['atlas' => 'h2', 'rival' => 'h2'],
                'dirty_after_run' => false,
                'workspace_blockers' => [],
            ],
            'evidence_pack' => [
                'schema_version' => 'atlas.forge.rivals.evidence_pack.v1',
                'run_id' => $runId,
                'missing_evidence' => [],
                'verdict' => 'comparable',
                'artifacts' => [
                    'manifest' => ['present' => true, 'sha256' => str_repeat('a', 64)],
                    'events_jsonl' => ['present' => true, 'sha256' => str_repeat('b', 64)],
                    'atlas_receipt' => ['present' => true, 'sha256' => str_repeat('c', 64)],
                    'rival_receipt' => ['present' => true, 'sha256' => str_repeat('d', 64)],
                    'workspace_hashes' => ['present' => true, 'sha256' => str_repeat('e', 64)],
                    'replay_manifest' => ['present' => true, 'sha256' => str_repeat('f', 64)],
                ],
            ],
        ];
    }
}
