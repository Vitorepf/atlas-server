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
 * Atlas Forge Rivals · Evidence/Replay/Adjudicator Hardening v2 contract.
 *
 * Locks down:
 *   - `pre_adjudication` collect never requires `scorecard`.
 *   - `pre_adjudication` replay never blocks on `scorecard:not_present_at_replay`.
 *   - `final` collect demands `scorecard` when adjudication has been run.
 *   - `final` replay re-hashes the scorecard.
 *   - `comparable` real-provider run requires patch diffs + test logs as
 *     blocking required artifacts.
 *   - `invalid` / `local_fake` run treats per-arm artifacts as optional and
 *     surfaces them in `optional_missing` rather than as blockers.
 *   - Invalid run never produces `claim_ready=true`.
 *   - Infrastructure hard-gate failure keeps `atlas_score=null`, `rival_score=null`, no winner.
 *   - One-sided deterministic test failure produces `gate_winner` with `score=null` and `claim_ready=false`.
 *   - Report renders ZERO claim for invalid, replay-failed, hard-fail, and
 *     blocked-evidence outcomes — and always explains why.
 *   - The report writes `unlocks_external_rivals_certification=false`.
 */
final class AtlasForgeRivalsEvidenceReplayAdjudicatorHardeningV2Test extends TestCase
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

        $this->tmpRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fr-hardening-v2-'.bin2hex(random_bytes(6));
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

    public function test_policy_pre_adjudication_does_not_require_scorecard_for_comparable_real_run(): void
    {
        $plan = AtlasForgeRivalsEvidencePolicy::plan(
            AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
            ['verdict' => 'comparable', 'mode' => 'fair'],
        );

        $this->assertNotContains('scorecard', $plan['required']);
        $this->assertNotContains('scorecard', $plan['optional']);
        $this->assertContains('atlas_patch', $plan['required']);
        $this->assertContains('rival_patch', $plan['required']);
        $this->assertContains('atlas_test_log', $plan['required']);
        $this->assertContains('rival_test_log', $plan['required']);
        $this->assertTrue($plan['is_comparable_real_run']);
    }

    public function test_policy_final_requires_scorecard_when_comparable(): void
    {
        $plan = AtlasForgeRivalsEvidencePolicy::plan(
            AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
            ['verdict' => 'comparable', 'mode' => 'fair'],
        );

        $this->assertContains('scorecard', $plan['required']);
    }

    public function test_policy_invalid_local_fake_run_treats_per_arm_artifacts_as_optional(): void
    {
        $plan = AtlasForgeRivalsEvidencePolicy::plan(
            AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
            ['verdict' => 'invalid_no_patch_diff', 'mode' => 'local_fake'],
        );

        $this->assertContains('atlas_patch', $plan['optional']);
        $this->assertContains('rival_patch', $plan['optional']);
        $this->assertContains('atlas_test_log', $plan['optional']);
        $this->assertContains('rival_test_log', $plan['optional']);
        $this->assertContains('atlas_receipt', $plan['optional']);
        $this->assertNotContains('atlas_patch', $plan['required']);
        $this->assertNotContains('scorecard', $plan['required']);
        $this->assertFalse($plan['is_comparable_real_run']);
    }

    public function test_collect_pre_adjudication_does_not_require_scorecard(): void
    {
        $runId = $this->newRunId('collect-pre');
        $this->seedRunArtifacts($runId, ['verdict' => 'comparable', 'mode' => 'fair'], includePatchesAndLogs: true);

        $result = $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertSame('ok', $result['status'], 'pre_adjudication collect must not block on scorecard');
        $this->assertSame(AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION, $result['evidence_stage']);
        $this->assertNotContains('scorecard', $result['missing_required']);
        $this->assertArrayNotHasKey('scorecard', $result['evidence_pack']['artifacts']);
    }

    public function test_collect_final_requires_scorecard_and_blocks_when_absent(): void
    {
        $runId = $this->newRunId('collect-final-no-scorecard');
        $this->seedRunArtifacts($runId, ['verdict' => 'comparable', 'mode' => 'fair'], includePatchesAndLogs: true);

        $result = $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('scorecard', $result['missing_required']);
        $this->assertContains('missing_evidence:scorecard', $result['blockers']);
    }

    public function test_collect_final_passes_when_scorecard_exists(): void
    {
        $runId = $this->newRunId('collect-final-with-scorecard');
        $this->seedRunArtifacts($runId, ['verdict' => 'comparable', 'mode' => 'fair'], includePatchesAndLogs: true);
        $paths = $this->paths->paths($runId);
        file_put_contents($paths['scorecard_json'], json_encode(['winner' => null]));

        $result = $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
        ]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame([], $result['missing_required']);
        $this->assertTrue($result['evidence_pack']['artifacts']['scorecard']['present']);
        $this->assertNotEmpty($result['evidence_pack']['artifacts']['scorecard']['sha256']);
    }

    public function test_replay_pre_adjudication_does_not_block_on_scorecard_absence(): void
    {
        $runId = $this->newRunId('replay-pre');
        $this->seedRunArtifacts($runId, ['verdict' => 'comparable', 'mode' => 'fair'], includePatchesAndLogs: true);
        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $result = $this->replay->replay([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertTrue($result['replay_passes']);
        $this->assertSame([], $result['required_mismatches']);
        $this->assertSame([], $result['hash_mismatches']);
    }

    public function test_replay_final_validates_scorecard_hash(): void
    {
        $runId = $this->newRunId('replay-final-hash');
        $this->seedRunArtifacts($runId, ['verdict' => 'comparable', 'mode' => 'fair'], includePatchesAndLogs: true);
        $paths = $this->paths->paths($runId);
        file_put_contents($paths['scorecard_json'], '{"winner":null}');

        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
        ]);

        // Drift the scorecard after collect captured its hash.
        file_put_contents($paths['scorecard_json'], '{"winner":"tampered"}');

        $result = $this->replay->replay([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
        ]);

        $this->assertFalse($result['replay_passes']);
        $this->assertContains('scorecard:hash_mismatch', $result['hash_mismatches']);
    }

    public function test_final_evidence_and_replay_do_not_override_scorecard_claim_gate(): void
    {
        $runId = $this->newRunId('replay-final-scorecard-claim');
        $this->seedRunArtifacts($runId, ['verdict' => 'comparable', 'mode' => 'fair'], includePatchesAndLogs: true);
        $paths = $this->paths->paths($runId);
        file_put_contents($paths['scorecard_json'], $this->jsonEncode([
            'winner' => AtlasForgeRivalsAdjudicatorService::WINNER_TIE,
            'claim_ready' => false,
            'hard_failures' => [],
        ]));

        $collect = $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
        ]);
        $this->assertSame('ok', $collect['status']);
        $this->assertFalse($collect['evidence_pack']['claim_ready']);

        $result = $this->replay->replay([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
        ]);

        $this->assertTrue($result['replay_passes']);
        $this->assertFalse($result['decision']['claim_ready']);
    }

    public function test_replay_local_fake_invalid_run_lists_optional_missing_without_blocking(): void
    {
        $runId = $this->newRunId('replay-localfake-optional');
        $this->seedRunArtifacts(
            $runId,
            ['verdict' => 'invalid_no_patch_diff', 'mode' => 'local_fake'],
            includePatchesAndLogs: false,
        );

        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $result = $this->replay->replay([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertTrue($result['replay_passes']);
        $this->assertSame([], $result['required_mismatches']);
        $this->assertNotEmpty($result['optional_missing']);
        foreach ($result['optional_missing'] as $entry) {
            $this->assertStringContainsString('optional_missing', $entry);
        }
    }

    public function test_replay_comparable_real_run_requires_patch_and_test_logs(): void
    {
        $runId = $this->newRunId('replay-real-required');
        $this->seedRunArtifacts(
            $runId,
            ['verdict' => 'comparable', 'mode' => 'fair'],
            includePatchesAndLogs: false,
        );
        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $result = $this->replay->replay([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertFalse($result['replay_passes']);
        $blockerCodes = implode('|', $result['required_mismatches']);
        $this->assertStringContainsString('atlas_patch', $blockerCodes);
        $this->assertStringContainsString('rival_patch', $blockerCodes);
        $this->assertStringContainsString('atlas_test_log', $blockerCodes);
        $this->assertStringContainsString('rival_test_log', $blockerCodes);
    }

    public function test_invalid_run_never_claims_ready(): void
    {
        $runId = $this->newRunId('invalid-no-claim');
        $this->seedRunArtifacts(
            $runId,
            ['verdict' => 'invalid_no_patch_diff', 'mode' => 'local_fake'],
            includePatchesAndLogs: false,
        );

        $packResult = $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertFalse($packResult['evidence_pack']['claim_ready']);

        $adjudication = $this->adjudicator->adjudicate(['run_id' => $runId]);
        $this->assertNull($adjudication['scorecard']['winner']);
        $this->assertNull($adjudication['scorecard']['atlas_score']);
        $this->assertNull($adjudication['scorecard']['rival_score']);
        $this->assertFalse($adjudication['scorecard']['claim_ready']);
    }

    public function test_hard_failure_keeps_score_null_and_winner_null(): void
    {
        $runId = $this->newRunId('hard-fail');
        $this->seedRunArtifacts(
            $runId,
            ['verdict' => 'invalid_tests_failed', 'mode' => 'fair'],
            includePatchesAndLogs: true,
        );

        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $result = $this->adjudicator->adjudicate(['run_id' => $runId]);
        $scorecard = $result['scorecard'];

        $this->assertNull($scorecard['winner']);
        $this->assertNull($scorecard['atlas_score']);
        $this->assertNull($scorecard['rival_score']);
        $this->assertFalse($scorecard['claim_ready']);
        $this->assertNotEmpty($scorecard['hard_failures']);
        $this->assertContains('verdict_comparable', $scorecard['hard_failures']);
    }

    public function test_one_sided_test_failure_renders_gate_winner_without_quality_score_or_claim(): void
    {
        $runId = $this->newRunId('gate-winner');
        $this->seedRunArtifacts(
            $runId,
            ['verdict' => 'invalid_tests_failed', 'mode' => 'fair'],
            includePatchesAndLogs: true,
        );
        $paths = $this->paths->paths($runId);

        $rivalReceipt = json_decode((string) file_get_contents($paths['evidence'].'/rival_receipt.json'), true);
        $rivalReceipt['test_exit_code'] = 2;
        $rivalReceipt['test_log_tail'] = 'FAILED Tests\\Feature\\SyntheticTest';
        file_put_contents($paths['evidence'].'/rival_receipt.json', $this->jsonEncode($rivalReceipt));

        $manifest = json_decode((string) file_get_contents($paths['manifest_json']), true);
        $manifest['claim_ready'] = false;
        $manifest['rival_receipt_hash'] = hash('sha256', $this->jsonEncode($rivalReceipt));
        file_put_contents($paths['manifest_json'], $this->jsonEncode($manifest));

        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);
        $adjudication = $this->adjudicator->adjudicate(['run_id' => $runId]);
        $scorecard = $adjudication['scorecard'];

        $this->assertNull($scorecard['winner']);
        $this->assertSame('atlas', $scorecard['gate_winner']);
        $this->assertSame('gate_outcome', $scorecard['score_source']);
        $this->assertNull($scorecard['atlas_score']);
        $this->assertNull($scorecard['rival_score']);
        $this->assertFalse($scorecard['quality_score_available']);
        $this->assertFalse($scorecard['claim_ready']);

        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
        ]);
        $report = $this->report->render(['run_id' => $runId]);

        $this->assertNull($report['winner']);
        $this->assertSame('atlas', $report['gate_winner']);
        $this->assertSame('gate_winner:atlas_no_quality_score', $report['declared_why']);
        $this->assertNull($report['atlas_score']);
        $this->assertNull($report['rival_score']);
        $this->assertFalse($report['quality_score_available']);
        $this->assertFalse($report['claim_ready']);
        $body = (string) file_get_contents($report['report_path']);
        $this->assertStringContainsString('GATE WINNER = Atlas Forge', $body);
        $this->assertStringContainsString('QUALITY SCORE = N/A', $body);
        $this->assertStringContainsString('external claim blocked', $body);
    }

    public function test_report_renders_invalid_run_without_winner_and_without_claim(): void
    {
        $runId = $this->newRunId('report-invalid');
        $this->seedRunArtifacts(
            $runId,
            ['verdict' => 'invalid_no_patch_diff', 'mode' => 'local_fake'],
            includePatchesAndLogs: false,
        );
        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);
        $this->adjudicator->adjudicate(['run_id' => $runId]);
        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
        ]);

        $report = $this->report->render(['run_id' => $runId]);

        $this->assertSame('ok', $report['status']);
        $this->assertNull($report['winner']);
        $this->assertFalse($report['claim_ready']);
        $this->assertStringStartsWith('invalid:', (string) $report['declared_why']);
        $this->assertFalse($report['unlocks_external_rivals_certification']);
        $this->assertFileExists($report['report_path']);
        $body = (string) file_get_contents($report['report_path']);
        $this->assertStringContainsString('Verdict:', $body);
        $this->assertStringContainsString('INVALID', $body);
        $this->assertStringContainsString('ZERO claim', $body);
    }

    public function test_full_local_fake_chain_reaches_report_without_circular_block(): void
    {
        $runId = $this->newRunId('localfake-end-to-end');
        $this->seedRunArtifacts(
            $runId,
            ['verdict' => 'invalid_no_patch_diff', 'mode' => 'local_fake'],
            includePatchesAndLogs: false,
        );

        $collectPre = $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);
        $this->assertSame('ok', $collectPre['status']);
        $replayPre = $this->replay->replay([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);
        $this->assertSame('ok', $replayPre['status'], 'pre_adjudication replay must not fail on scorecard absence');

        $adjudication = $this->adjudicator->adjudicate(['run_id' => $runId]);
        $this->assertSame('ok', $adjudication['status']);
        $this->assertNull($adjudication['scorecard']['winner']);

        $collectFinal = $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
        ]);
        $this->assertSame('ok', $collectFinal['status']);
        $replayFinal = $this->replay->replay([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
        ]);
        $this->assertSame('ok', $replayFinal['status']);

        $report = $this->report->render(['run_id' => $runId]);
        $this->assertSame('ok', $report['status']);
        $this->assertNull($report['winner']);
        $this->assertFalse($report['claim_ready']);
    }

    public function test_legacy_v1_pack_without_evidence_stage_still_replays(): void
    {
        $runId = $this->newRunId('legacy-pack');
        $this->seedRunArtifacts(
            $runId,
            ['verdict' => 'comparable', 'mode' => 'fair'],
            includePatchesAndLogs: false,
        );
        $paths = $this->paths->paths($runId);

        // Build a legacy v1-style pack (no evidence_stage, no required_artifacts).
        $artifacts = [];
        foreach ([
            'manifest' => $paths['manifest_json'],
            'events_jsonl' => $paths['events_jsonl'],
            'intent_json' => $paths['base'].'/intent.json',
            'atlas_receipt' => $paths['evidence'].'/atlas_receipt.json',
            'rival_receipt' => $paths['evidence'].'/rival_receipt.json',
            'workspace_hashes' => $paths['evidence'].'/workspace_hashes.json',
        ] as $key => $path) {
            $artifacts[$key] = [
                'path' => $path,
                'present' => is_file($path),
                'bytes' => is_file($path) ? filesize($path) : 0,
                'sha256' => is_file($path) ? hash_file('sha256', $path) : null,
            ];
        }
        @mkdir($paths['evidence'], 0o755, true);
        file_put_contents(
            $paths['evidence'].'/evidence_pack.json',
            (string) json_encode([
                'schema_version' => 'atlas.forge.rivals.evidence_pack.v1',
                'artifacts' => $artifacts,
                'missing_evidence' => [],
                'verdict' => 'comparable',
                'claim_ready' => true,
            ], JSON_PRETTY_PRINT),
        );

        $result = $this->replay->replay(['run_id' => $runId]);

        $this->assertSame('ok', $result['status'], 'legacy v1 packs must keep replaying without v2 markers');
        $this->assertTrue($result['replay_passes']);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function seedRunArtifacts(string $runId, array $overrides, bool $includePatchesAndLogs): void
    {
        $paths = $this->paths->paths($runId);
        @mkdir($paths['base'], 0o755, true);
        @mkdir($paths['evidence'], 0o755, true);

        $verdict = (string) ($overrides['verdict'] ?? 'comparable');
        $mode = (string) ($overrides['mode'] ?? 'fair');

        $atlasReceipt = $this->receipt('atlas');
        $rivalReceipt = $this->receipt('rival');
        if ($mode === 'local_fake') {
            $atlasReceipt['patch_diff_bytes'] = 0;
            $rivalReceipt['patch_diff_bytes'] = 0;
            $atlasReceipt['test_command'] = 'not_run_local_fake';
            $rivalReceipt['test_command'] = 'not_run_local_fake';
        }

        file_put_contents($paths['evidence'].'/atlas_receipt.json', $this->jsonEncode($atlasReceipt));
        file_put_contents($paths['evidence'].'/rival_receipt.json', $this->jsonEncode($rivalReceipt));
        file_put_contents($paths['evidence'].'/workspace_hashes.json', $this->jsonEncode([
            'before' => ['atlas' => 'h1', 'rival' => 'h1'],
            'after' => ['atlas' => 'h2', 'rival' => 'h2'],
            'dirty_after_run' => false,
            'workspace_blockers' => [],
        ]));

        if ($includePatchesAndLogs) {
            file_put_contents($paths['evidence'].'/atlas_patch.diff', '--- atlas patch ---');
            file_put_contents($paths['evidence'].'/rival_patch.diff', '--- rival patch ---');
            file_put_contents($paths['evidence'].'/atlas_test.log', '(50 tests, 120 assertions)');
            file_put_contents($paths['evidence'].'/rival_test.log', '(50 tests, 120 assertions)');
        }

        $manifest = [
            'schema_version' => 'atlas.forge.rivals.run_real.v1',
            'run_id' => $paths['run_id'],
            'mode' => $mode,
            'atlas_model' => 'claude_sonnet',
            'rival_model' => 'claude_sonnet',
            'preset' => 'quick',
            'case_id' => 'synthetic-case',
            'verdict' => $verdict,
            'score' => null,
            'claim_ready' => $verdict === 'comparable' && $mode !== 'local_fake',
            'atlas_receipt_hash' => hash('sha256', $this->jsonEncode($atlasReceipt)),
            'rival_receipt_hash' => hash('sha256', $this->jsonEncode($rivalReceipt)),
            'workspace_hash_before' => ['atlas' => 'h1', 'rival' => 'h1'],
            'workspace_hash_after' => ['atlas' => 'h2', 'rival' => 'h2'],
            'dirty_after_run' => false,
            'workspace_blockers' => [],
            'external_provider_call' => $mode === 'fair' || $mode === 'full_power',
            'provider_tokens_spent' => $mode === 'fair' || $mode === 'full_power',
            'separated_from_external_rivals_certification' => true,
        ];
        file_put_contents($paths['manifest_json'], $this->jsonEncode($manifest));
        file_put_contents($paths['events_jsonl'], json_encode(['kind' => 'run_started']).PHP_EOL);
        file_put_contents($paths['base'].'/intent.json', json_encode(['kind' => 'synthetic']));
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
        return 'hardening-v2-'.bin2hex(random_bytes(4)).'-'.$suffix;
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
