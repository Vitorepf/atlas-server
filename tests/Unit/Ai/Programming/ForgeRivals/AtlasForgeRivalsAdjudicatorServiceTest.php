<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsAdjudicatorService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEventStream;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsReplayService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunPathResolver;
use Tests\TestCase;

/**
 * Atlas Forge Rivals · Adjudicator (deterministic, local-only) contract tests.
 *
 * Builds synthetic evidence packs on disk and exercises the heuristics:
 *   - infrastructure/evidence hard-fail forces score=null, winner=null
 *   - one-sided deterministic test failure produces gate_winner with score=null and claim_ready=false
 *   - quality scoring fires only when every hard gate is green
 *   - statistical tie ⇒ winner=human_review_required_tie
 *   - clear quality lead ⇒ winner=atlas|rival with structured reason
 *   - every code path stays read-only and never invokes a provider
 */
final class AtlasForgeRivalsAdjudicatorServiceTest extends TestCase
{
    private string $tmpRoot;

    private AtlasForgeRivalsRunPathResolver $paths;

    private AtlasForgeRivalsAdjudicatorService $adjudicator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fr-adjudicator-'.bin2hex(random_bytes(6));
        @mkdir($this->tmpRoot, 0o755, true);
        config(['atlas_rivals.runs_root' => $this->tmpRoot]);

        $this->paths = new AtlasForgeRivalsRunPathResolver;
        $replay = new AtlasForgeRivalsReplayService($this->paths, new AtlasForgeRivalsEventStream($this->paths));
        $this->adjudicator = new AtlasForgeRivalsAdjudicatorService($this->paths, $replay);
    }

    protected function tearDown(): void
    {
        $this->purge($this->tmpRoot);
        parent::tearDown();
    }

    public function test_blocks_when_run_id_missing(): void
    {
        $result = $this->adjudicator->adjudicate([]);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('run_id_required', $result['blockers']);
    }

    public function test_blocks_when_run_not_found(): void
    {
        $result = $this->adjudicator->adjudicate(['run_id' => 'nonexistent-run']);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('run_not_found:nonexistent-run', $result['blockers']);
    }

    public function test_hard_fail_when_replay_fails_forces_null_score_and_null_winner(): void
    {
        $runId = $this->newRunId('hard-fail-replay');
        $paths = $this->paths->paths($runId);
        $this->seedComparableRun($paths, atlasOverrides: [], rivalOverrides: []);
        // Corrupt the manifest so replay's hash check fails on a known artifact.
        file_put_contents($paths['evidence'].'/atlas_receipt.json', '{"corrupted":true}');

        // Rebuild evidence_pack to a stale hash so replay mismatches.
        $packPath = $paths['evidence'].'/evidence_pack.json';
        $pack = json_decode((string) file_get_contents($packPath), true);
        $pack['artifacts']['atlas_receipt']['sha256'] = str_repeat('0', 64);
        file_put_contents($packPath, json_encode($pack, JSON_PRETTY_PRINT));

        $result = $this->adjudicator->adjudicate(['run_id' => $runId]);

        $this->assertSame('ok', $result['status']);
        $scorecard = $result['scorecard'];
        $this->assertNull($scorecard['winner']);
        $this->assertNull($scorecard['atlas_score']);
        $this->assertNull($scorecard['rival_score']);
        $this->assertNotEmpty($scorecard['hard_failures']);
        $this->assertFalse($scorecard['claim_ready']);
        $this->assertTrue($scorecard['separated_from_external_rivals_certification']);
    }

    public function test_hard_fail_when_out_of_scope_files_present(): void
    {
        $runId = $this->newRunId('hard-fail-oos');
        $paths = $this->paths->paths($runId);
        $this->seedComparableRun(
            $paths,
            atlasOverrides: ['out_of_scope_files' => ['src/sneaky.php']],
            rivalOverrides: [],
        );

        $result = $this->adjudicator->adjudicate(['run_id' => $runId]);
        $scorecard = $result['scorecard'];

        $this->assertNull($scorecard['winner']);
        $this->assertSame('rival', $scorecard['gate_winner']);
        $this->assertSame('atlas', $scorecard['gate_loser']);
        $this->assertSame('one_sided_scope_failure', $scorecard['gate_result']['kind']);
        $this->assertContains('no_out_of_scope_files_atlas', $scorecard['hard_failures']);
    }

    public function test_no_gate_winner_when_both_arms_fail_scope(): void
    {
        $runId = $this->newRunId('hard-fail-both-oos');
        $paths = $this->paths->paths($runId);
        $this->seedComparableRun(
            $paths,
            atlasOverrides: ['out_of_scope_files' => ['src/sneaky-atlas.php']],
            rivalOverrides: ['out_of_scope_files' => ['src/sneaky-rival.php']],
        );

        $scorecard = $this->adjudicator->adjudicate(['run_id' => $runId])['scorecard'];

        $this->assertNull($scorecard['winner']);
        $this->assertArrayNotHasKey('gate_winner', $scorecard);
        $this->assertContains('no_out_of_scope_files_atlas', $scorecard['hard_failures']);
        $this->assertContains('no_out_of_scope_files_rival', $scorecard['hard_failures']);
    }

    public function test_hard_fail_when_bytecode_artifacts_present(): void
    {
        $runId = $this->newRunId('hard-fail-pyc');
        $paths = $this->paths->paths($runId);
        $this->seedComparableRun(
            $paths,
            atlasOverrides: [],
            rivalOverrides: ['bytecode_artifacts' => ['foo/__pycache__/bar.pyc']],
        );

        $scorecard = $this->adjudicator->adjudicate(['run_id' => $runId])['scorecard'];
        $this->assertNull($scorecard['winner']);
        $this->assertContains('no_bytecode_artifacts_rival', $scorecard['hard_failures']);
    }

    public function test_hard_fail_when_dirty_after_run(): void
    {
        $runId = $this->newRunId('hard-fail-dirty');
        $paths = $this->paths->paths($runId);
        $this->seedComparableRun($paths, [], [], manifestOverrides: ['dirty_after_run' => true]);

        $scorecard = $this->adjudicator->adjudicate(['run_id' => $runId])['scorecard'];
        $this->assertNull($scorecard['winner']);
        $this->assertContains('dirty_after_run_false', $scorecard['hard_failures']);
    }

    public function test_hard_fail_when_patch_diff_missing(): void
    {
        $runId = $this->newRunId('hard-fail-nopatch');
        $paths = $this->paths->paths($runId);
        $this->seedComparableRun(
            $paths,
            atlasOverrides: ['patch_diff_bytes' => 0],
            rivalOverrides: [],
        );

        $scorecard = $this->adjudicator->adjudicate(['run_id' => $runId])['scorecard'];
        $this->assertNull($scorecard['winner']);
        $this->assertContains('patch_diff_present_atlas', $scorecard['hard_failures']);
    }

    public function test_one_sided_test_failure_produces_gate_winner_without_quality_score_or_external_claim(): void
    {
        $runId = $this->newRunId('rival-test-fails');
        $paths = $this->paths->paths($runId);
        $this->seedComparableRun(
            $paths,
            atlasOverrides: ['test_exit_code' => 0],
            rivalOverrides: ['test_exit_code' => 2, 'test_log_tail' => 'FAILED Tests\\Feature\\SyntheticTest'],
            manifestOverrides: ['verdict' => 'invalid_tests_failed', 'claim_ready' => false],
        );

        $scorecard = $this->adjudicator->adjudicate(['run_id' => $runId])['scorecard'];

        $this->assertNull($scorecard['winner']);
        $this->assertSame(AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS, $scorecard['gate_winner']);
        $this->assertSame('gate_outcome', $scorecard['score_source']);
        $this->assertNull($scorecard['atlas_score']);
        $this->assertNull($scorecard['rival_score']);
        $this->assertFalse($scorecard['quality_score_available']);
        $this->assertSame('one_sided_test_failure', $scorecard['gate_result']['kind']);
        $this->assertContains('tests_passed_rival', $scorecard['hard_failures']);
        $this->assertFalse($scorecard['claim_ready']);
        $this->assertTrue($scorecard['separated_from_external_rivals_certification']);
    }

    public function test_atlas_wins_when_patch_focus_and_scope_clearly_better(): void
    {
        $runId = $this->newRunId('atlas-wins');
        $paths = $this->paths->paths($runId);
        $this->seedComparableRun(
            $paths,
            atlasOverrides: [
                'patch_diff_bytes' => 1_500,
                'changed_files' => ['tests/Feature/Foo.php', 'app/Foo.php'],
                'test_log_tail' => '(150 tests, 320 assertions)',
            ],
            rivalOverrides: [
                'patch_diff_bytes' => 120_000,
                'changed_files' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n'],
                'test_log_tail' => '(5 tests, 10 assertions)',
            ],
        );

        $scorecard = $this->adjudicator->adjudicate(['run_id' => $runId])['scorecard'];
        $this->assertSame(AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS, $scorecard['winner']);
        $this->assertGreaterThan($scorecard['rival_score'], $scorecard['atlas_score']);
        $this->assertNotEmpty($scorecard['winner_reason']);
        // local_fake mode never produces a real claim, even on a clear win.
        // The fairness gate flips claim_ready to false with an explicit
        // validity_class so the operator sees the harness signal vs a real
        // superiority claim.
        $this->assertFalse($scorecard['claim_ready']);
        $this->assertSame(
            AtlasForgeRivalsAdjudicatorService::VALIDITY_INVALID_LOCAL_FAKE,
            $scorecard['fairness']['validity_class'],
        );
        $this->assertFalse($scorecard['human_review_required']);
    }

    public function test_rival_wins_when_test_quality_and_focus_clearly_better(): void
    {
        $runId = $this->newRunId('rival-wins');
        $paths = $this->paths->paths($runId);
        $this->seedComparableRun(
            $paths,
            atlasOverrides: [
                'patch_diff_bytes' => 180_000,
                'changed_files' => array_map(static fn (int $i): string => 'src/big_'.$i.'.php', range(1, 15)),
                'test_log_tail' => '(2 tests, 4 assertions)',
            ],
            rivalOverrides: [
                'patch_diff_bytes' => 1_400,
                'changed_files' => ['tests/Feature/Bar.php', 'app/Bar.php'],
                'test_log_tail' => '(200 tests, 800 assertions)',
            ],
        );

        $scorecard = $this->adjudicator->adjudicate(['run_id' => $runId])['scorecard'];
        $this->assertSame(AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL, $scorecard['winner']);
        $this->assertGreaterThan($scorecard['atlas_score'], $scorecard['rival_score']);
        // local_fake never claims real superiority — see fairness canon.
        $this->assertFalse($scorecard['claim_ready']);
        $this->assertSame(
            AtlasForgeRivalsAdjudicatorService::VALIDITY_INVALID_LOCAL_FAKE,
            $scorecard['fairness']['validity_class'],
        );
    }

    public function test_statistical_tie_when_arms_equivalent(): void
    {
        $runId = $this->newRunId('tie');
        $paths = $this->paths->paths($runId);
        $this->seedComparableRun(
            $paths,
            atlasOverrides: ['patch_diff_bytes' => 2_500, 'test_log_tail' => '(20 tests, 50 assertions)'],
            rivalOverrides: ['patch_diff_bytes' => 2_500, 'test_log_tail' => '(20 tests, 50 assertions)'],
        );

        $scorecard = $this->adjudicator->adjudicate(['run_id' => $runId])['scorecard'];
        $this->assertSame(AtlasForgeRivalsAdjudicatorService::WINNER_TIE, $scorecard['winner']);
        $this->assertTrue($scorecard['human_review_required']);
        $this->assertFalse($scorecard['claim_ready']);
    }

    public function test_scorecard_persists_to_evidence_directory(): void
    {
        $runId = $this->newRunId('persist');
        $paths = $this->paths->paths($runId);
        $this->seedComparableRun($paths, [], []);

        $result = $this->adjudicator->adjudicate(['run_id' => $runId]);

        $this->assertFileExists($paths['scorecard_json']);
        $persisted = json_decode((string) file_get_contents($paths['scorecard_json']), true);
        $this->assertSame(AtlasForgeRivalsAdjudicatorService::SCHEMA_VERSION, $persisted['schema_version']);
        $this->assertSame($paths['scorecard_json'], $result['scorecard_path']);
    }

    public function test_ceiling_360_contract_dimension_separates_semantic_evidence_under_l5_pressure(): void
    {
        $runId = $this->newRunId('ceiling-contract');
        $paths = $this->paths->paths($runId);
        @mkdir($paths['evidence'], 0o755, true);
        $atlasPatch = $paths['evidence'].'/atlas_patch.diff';
        $rivalPatch = $paths['evidence'].'/rival_patch.diff';
        file_put_contents($atlasPatch, implode("\n", [
            '## Facts Observed',
            '## Assumptions',
            '## Reversible Decisions',
            '| Decision | Alternative | Reason chosen |',
            'Rollback plan uses rollout undo and restore traffic ramp.',
            'Replay matrix includes negative test and reconstruct scorecard.',
            'Uncertainties: telemetry not available; root cause unconfirmed.',
            'Production invariant: prevent data loss, replica lag > 5s, circuit breaker, p99 guard.',
            'Evidence pack includes scorecard, workspace hashes, audit trail and postmortem.',
            '## Contradiction Resolution',
            'Conflicting constraints are resolved by preserving data safety before delivery speed.',
            '## Hidden Oracle Hypotheses',
            'Hidden oracle hypothesis: replay must catch scorecard hash drift and dirty_after_run.',
            '## Failure Mode Matrix',
            '| Failure mode | Severity | Blast radius |',
            '## Stop/Block Criteria',
            'Stop/block criteria: auto-halt if replay_passes=false or test_exit_code != 0.',
            '## Telemetry Delta',
            'Telemetry delta before: p99=420ms after: p99=240ms and 0 data loss events.',
            '## Counterfactual Check',
            'Counterfactual: the alternative outcome would fail first under 15% retry traffic.',
            '## Blast Radius',
            'Blast radius: 2% affected users, severity=high, customer impact bounded by feature flag.',
            '## Confidence Calibration',
            'Confidence: 82% because replay_passes=true but hidden oracle remains partially unconfirmed.',
        ]));
        file_put_contents($rivalPatch, implode("\n", [
            'Rollback plan.',
            'Replay smoke test passes.',
            'Postmortem draft.',
        ]));

        $this->seedComparableRun(
            $paths,
            atlasOverrides: ['patch_diff_path' => $atlasPatch, 'patch_diff_bytes' => 3_000],
            rivalOverrides: ['patch_diff_path' => $rivalPatch, 'patch_diff_bytes' => 3_000],
            manifestOverrides: [
                'case_set' => 'ceiling-360',
                'case_id' => 'ceiling-360-001-industrial-005-incident_rollback',
                'ceiling_pressure_profile' => [
                    'schema_version' => 'atlas.forge.rivals.ceiling_pressure_profile.v1',
                    'pressure_level' => 'L5++',
                ],
            ],
        );

        $scorecard = $this->adjudicator->adjudicate(['run_id' => $runId])['scorecard'];

        $this->assertSame(AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS, $scorecard['winner']);
        $this->assertArrayHasKey('ceiling_360_contract', $scorecard['quality_dimensions']);
        $dimension = $scorecard['quality_dimensions']['ceiling_360_contract'];
        $this->assertGreaterThan(85.0, $dimension['atlas']);
        $this->assertLessThan(50.0, $dimension['rival']);
        $this->assertTrue($dimension['markers']['required']);
        $this->assertTrue($dimension['markers']['atlas']['uncertainty_boundary']);
        $this->assertTrue($dimension['markers']['atlas']['hidden_oracle_hypotheses']);
        $this->assertTrue($dimension['markers']['atlas']['telemetry_delta']);
        $this->assertTrue($dimension['markers']['atlas']['counterfactual_check']);
        $this->assertTrue($dimension['markers']['atlas']['blast_radius_quantification']);
        $this->assertTrue($dimension['markers']['atlas']['confidence_calibration']);
        $this->assertFalse($dimension['markers']['rival']['uncertainty_boundary']);
        $this->assertGreaterThan($dimension['markers']['rival_depth']['section_hits'], $dimension['markers']['atlas_depth']['section_hits']);
        $this->assertGreaterThan($dimension['markers']['rival_depth']['specificity_hits'], $dimension['markers']['atlas_depth']['specificity_hits']);
        $this->assertGreaterThan($dimension['markers']['rival_depth']['adversarial_hits'], $dimension['markers']['atlas_depth']['adversarial_hits']);
    }

    public function test_ceiling_360_contract_recognizes_operational_l5_plus_plus_evidence_variants(): void
    {
        $runId = $this->newRunId('ceiling-operational-variants');
        $paths = $this->paths->paths($runId);
        @mkdir($paths['evidence'], 0o755, true);
        $atlasPatch = $paths['evidence'].'/atlas_patch.diff';
        $rivalPatch = $paths['evidence'].'/rival_patch.diff';

        file_put_contents($atlasPatch, implode("\n", [
            '## Rollback Procedure',
            'Rollback uses restore traffic and health checks.',
            '## Evidence Requirements',
            'Evidence pack includes scorecard and workspace hashes.',
        ]));
        file_put_contents($rivalPatch, implode("\n", [
            '## Root Cause',
            'Deployment introduced a null dereference under production traffic.',
            '## Contributing Factors',
            'Canary skipped; staging traffic shape diverged.',
            '## Tradeoffs and Uncertainties',
            'Feature flag kill is accepted for speed but degrades UX.',
            '## Safe Rollback',
            'Restore traffic gradually and keep data loss at zero.',
            '## Evidence Requirements',
            'Evidence pack, scorecard, workspace hashes and oracle_adjudication_notes reference the oracle hash.',
            '## Abort Criteria',
            'Halt rollback if smoke test fails or replay_passes=false.',
            '## Impact',
            'TTD: 0m; TTM: 8m; TTR: 35m; peak error rate: 18%; affected users: 2%.',
            '## Counterfactual Check',
            'Counterfactual: an alternate outcome fails first when 20% of traffic bypasses cache.',
            '## Confidence Calibration',
            'Confidence level: medium because oracle hash is still hidden.',
        ]));

        $this->seedComparableRun(
            $paths,
            atlasOverrides: ['patch_diff_path' => $atlasPatch, 'patch_diff_bytes' => 4_000],
            rivalOverrides: ['patch_diff_path' => $rivalPatch, 'patch_diff_bytes' => 4_000],
            manifestOverrides: [
                'case_set' => 'ceiling-360',
                'case_id' => 'ceiling-360-001-industrial-005-incident_rollback',
                'ceiling_pressure_profile' => [
                    'schema_version' => 'atlas.forge.rivals.ceiling_pressure_profile.v1',
                    'pressure_level' => 'L5++',
                ],
            ],
        );

        $dimension = $this->adjudicator
            ->adjudicate(['run_id' => $runId])['scorecard']['quality_dimensions']['ceiling_360_contract'];

        $this->assertGreaterThan($dimension['atlas'], $dimension['rival']);
        $this->assertTrue($dimension['markers']['rival']['hidden_oracle_hypotheses']);
        $this->assertTrue($dimension['markers']['rival']['failure_mode_matrix']);
        $this->assertTrue($dimension['markers']['rival']['stop_block_criteria']);
        $this->assertTrue($dimension['markers']['rival']['telemetry_delta']);
        $this->assertTrue($dimension['markers']['rival']['counterfactual_check']);
        $this->assertTrue($dimension['markers']['rival']['blast_radius_quantification']);
        $this->assertTrue($dimension['markers']['rival']['confidence_calibration']);
        $this->assertGreaterThanOrEqual(4, $dimension['markers']['rival_depth']['adversarial_hits']);
    }

    public function test_ceiling_360_contract_scores_provider_stdout_evidence_when_patch_is_code_only(): void
    {
        $runId = $this->newRunId('ceiling-stdout-evidence');
        $paths = $this->paths->paths($runId);
        @mkdir($paths['evidence'], 0o755, true);
        $atlasPatch = $paths['evidence'].'/atlas_patch.diff';
        $rivalPatch = $paths['evidence'].'/rival_patch.diff';
        $atlasStdout = $paths['evidence'].'/atlas_stdout.log';
        $rivalStdout = $paths['evidence'].'/rival_stdout.log';

        file_put_contents($atlasPatch, 'code-only change with no operational prose');
        file_put_contents($rivalPatch, 'code-only change with no operational prose');
        file_put_contents($atlasStdout, implode("\n", [
            '## Facts Observed',
            '## Assumptions',
            '## Reversible Decisions',
            '## Tradeoff Matrix',
            '## Rollback Plan',
            '## Replay/Negative Regression Probe',
            '## Production Invariants',
            '## Uncertainty Boundary',
            '## Capability-Specific Evidence',
            '## Contradiction Resolution',
            '## Hidden Oracle Hypotheses',
            'Oracle hash remains hidden, so confidence is bounded.',
            '## Failure Mode Matrix',
            '## Stop/Block Criteria',
            'Auto-halt if replay_passes=false or p99 > 500ms.',
            '## Telemetry Delta',
            'Before: p99=480ms after: p99=220ms; TTD=1m TTM=6m TTR=20m.',
            '## Counterfactual Check',
            'Counterfactual: the alternate outcome would fail first under 20% traffic.',
            '## Blast Radius',
            'Blast radius: affected users=2%, severity=high.',
            '## Confidence Calibration',
            'Confidence: 78% because hidden oracle evidence is unavailable.',
        ]));
        file_put_contents($rivalStdout, 'Short final answer without L5++ evidence sections.');

        $this->seedComparableRun(
            $paths,
            atlasOverrides: [
                'patch_diff_path' => $atlasPatch,
                'stdout_path' => $atlasStdout,
                'patch_diff_bytes' => 3_000,
            ],
            rivalOverrides: [
                'patch_diff_path' => $rivalPatch,
                'stdout_path' => $rivalStdout,
                'patch_diff_bytes' => 3_000,
            ],
            manifestOverrides: [
                'case_set' => 'ceiling-360',
                'case_id' => 'ceiling-360-002-industrial-010-product',
                'ceiling_pressure_profile' => [
                    'schema_version' => 'atlas.forge.rivals.ceiling_pressure_profile.v1',
                    'pressure_level' => 'L5++',
                ],
            ],
        );

        $dimension = $this->adjudicator
            ->adjudicate(['run_id' => $runId])['scorecard']['quality_dimensions']['ceiling_360_contract'];

        $this->assertGreaterThan(90.0, $dimension['atlas']);
        $this->assertLessThan(30.0, $dimension['rival']);
        $this->assertTrue($dimension['markers']['atlas']['counterfactual_check']);
        $this->assertTrue($dimension['markers']['atlas']['blast_radius_quantification']);
        $this->assertTrue($dimension['markers']['atlas']['confidence_calibration']);
        $this->assertGreaterThanOrEqual(7, $dimension['markers']['atlas_depth']['adversarial_hits']);
    }

    public function test_ceiling_360_contract_recognizes_machine_readable_confidence_calibration_section(): void
    {
        $runId = $this->newRunId('ceiling-confidence-underscore');
        $paths = $this->paths->paths($runId);
        @mkdir($paths['evidence'], 0o755, true);
        $atlasPatch = $paths['evidence'].'/atlas_patch.diff';
        $rivalPatch = $paths['evidence'].'/rival_patch.diff';

        file_put_contents($atlasPatch, implode("\n", [
            '## confidence_calibration',
            '| Decisao | Confidence | Nivel | Razao |',
            '| Test passara | 0.97 | Alta | Evidencia local e replay_passes=true |',
        ]));
        file_put_contents($rivalPatch, 'implementation summary with local tests and replay notes only');

        $this->seedComparableRun(
            $paths,
            atlasOverrides: [
                'patch_diff_path' => $atlasPatch,
                'patch_diff_bytes' => 3_000,
            ],
            rivalOverrides: [
                'patch_diff_path' => $rivalPatch,
                'patch_diff_bytes' => 3_000,
            ],
            manifestOverrides: [
                'case_set' => 'ceiling-360',
                'case_id' => 'ceiling-360-001-industrial-005-incident_rollback',
                'ceiling_pressure_profile' => [
                    'schema_version' => 'atlas.forge.rivals.ceiling_pressure_profile.v1',
                    'pressure_level' => 'L5++',
                ],
            ],
        );

        $dimension = $this->adjudicator
            ->adjudicate(['run_id' => $runId])['scorecard']['quality_dimensions']['ceiling_360_contract'];

        $this->assertTrue($dimension['markers']['atlas']['confidence_calibration']);
        $this->assertFalse($dimension['markers']['rival']['confidence_calibration']);
        $this->assertGreaterThanOrEqual(1, $dimension['markers']['atlas_depth']['section_hits']);
    }

    public function test_ceiling_360_contract_recognizes_machine_readable_stop_and_failure_sections(): void
    {
        $runId = $this->newRunId('ceiling-stop-failure-underscore');
        $paths = $this->paths->paths($runId);
        @mkdir($paths['evidence'], 0o755, true);
        $atlasPatch = $paths['evidence'].'/atlas_patch.diff';
        $rivalPatch = $paths['evidence'].'/rival_patch.diff';

        file_put_contents($atlasPatch, implode("\n", [
            '## failure_mode_matrix',
            '| Mode | Escape | Detection |',
            '| checkpoint drift | stale replay hash | replay_passes=false |',
            '## stop_block_criteria',
            '- Block if replay_passes=false.',
            '- Abort if p99 > 500ms after rollout.',
        ]));
        file_put_contents($rivalPatch, 'implementation summary with rollback and replay notes only');

        $this->seedComparableRun(
            $paths,
            atlasOverrides: [
                'patch_diff_path' => $atlasPatch,
                'patch_diff_bytes' => 3_000,
            ],
            rivalOverrides: [
                'patch_diff_path' => $rivalPatch,
                'patch_diff_bytes' => 3_000,
            ],
            manifestOverrides: [
                'case_set' => 'ceiling-360',
                'case_id' => 'ceiling-360-006-industrial-030-multi_day_task',
                'ceiling_pressure_profile' => [
                    'schema_version' => 'atlas.forge.rivals.ceiling_pressure_profile.v1',
                    'pressure_level' => 'L5++',
                ],
            ],
        );

        $dimension = $this->adjudicator
            ->adjudicate(['run_id' => $runId])['scorecard']['quality_dimensions']['ceiling_360_contract'];

        $this->assertTrue($dimension['markers']['atlas']['failure_mode_matrix']);
        $this->assertTrue($dimension['markers']['atlas']['stop_block_criteria']);
        $this->assertFalse($dimension['markers']['rival']['failure_mode_matrix']);
        $this->assertFalse($dimension['markers']['rival']['stop_block_criteria']);
        $this->assertGreaterThanOrEqual(2, $dimension['markers']['atlas_depth']['section_hits']);
    }

    public function test_weights_explicit_and_sum_to_one(): void
    {
        $total = 0.0;
        foreach (AtlasForgeRivalsAdjudicatorService::WEIGHTS as $w) {
            $total += $w;
        }
        $this->assertEqualsWithDelta(1.0, $total, 0.0001, 'Adjudicator weights must sum to 1.0.');
    }

    /**
     * @param  array<string,mixed>  $paths
     * @param  array<string,mixed>  $atlasOverrides
     * @param  array<string,mixed>  $rivalOverrides
     * @param  array<string,mixed>  $manifestOverrides
     */
    private function seedComparableRun(
        array $paths,
        array $atlasOverrides,
        array $rivalOverrides,
        array $manifestOverrides = [],
    ): void {
        @mkdir($paths['base'], 0o755, true);
        @mkdir($paths['evidence'], 0o755, true);

        $atlasReceipt = $this->baseReceipt('atlas', $atlasOverrides);
        $rivalReceipt = $this->baseReceipt('rival', $rivalOverrides);

        file_put_contents($paths['evidence'].'/atlas_receipt.json', $this->jsonEncode($atlasReceipt));
        file_put_contents($paths['evidence'].'/rival_receipt.json', $this->jsonEncode($rivalReceipt));
        file_put_contents($paths['evidence'].'/workspace_hashes.json', $this->jsonEncode([
            'before' => ['atlas' => 'h1', 'rival' => 'h1'],
            'after' => ['atlas' => 'h2', 'rival' => 'h2'],
            'dirty_after_run' => false,
            'workspace_blockers' => [],
        ]));

        $manifest = array_replace([
            'schema_version' => 'atlas.forge.rivals.run_real.v1',
            'run_id' => $paths['run_id'],
            'mode' => 'local_fake',
            'atlas_model' => 'claude_sonnet',
            'rival_model' => 'claude_sonnet',
            'preset' => 'quick',
            'case_id' => 'synthetic-case',
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
        ], $manifestOverrides);
        file_put_contents($paths['manifest_json'], $this->jsonEncode($manifest));

        // Minimal events.jsonl
        file_put_contents($paths['events_jsonl'], json_encode(['kind' => 'run_started']).PHP_EOL);
        file_put_contents($paths['base'].'/intent.json', json_encode(['kind' => 'synthetic']));

        // Build the evidence_pack with valid hashes for replay.
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
        $pack = [
            'schema_version' => 'atlas.forge.rivals.evidence_pack.v1',
            'run_id' => $paths['run_id'],
            'collected_at' => date('c'),
            'paths' => $paths,
            'artifacts' => $artifacts,
            'missing_evidence' => [],
            'verdict' => 'comparable',
            'claim_ready' => true,
        ];
        file_put_contents($paths['evidence'].'/evidence_pack.json', $this->jsonEncode($pack));
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function baseReceipt(string $arm, array $overrides): array
    {
        $base = [
            'arm' => $arm,
            'mode' => 'local_fake',
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

        return array_replace($base, $overrides);
    }

    private function jsonEncode(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function newRunId(string $suffix): string
    {
        return 'adj-test-'.bin2hex(random_bytes(4)).'-'.$suffix;
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
