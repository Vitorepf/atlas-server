<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsAdjudicatorV2Service;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunPathResolver;
use Tests\TestCase;

/**
 * Atlas Forge Rivals · Adjudicator v2 contract tests.
 *
 * Covers the 25 obligatory invariants from
 * `atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md` §v2:
 *
 *   - per-case / per-category / overall scoring is deterministic
 *   - hard gates fail closed to score=null (missing receipt, dirty workspace,
 *     scope violation, tests failed, synthetic score, provider policy, model
 *     lock, atlas arm not forge)
 *   - case-manifest weights are consumed when present; default weights
 *     otherwise; weights_source recorded
 *   - confidence ladder honours `release` (12 cases, 8 categories ⇒
 *     trusted_battery) and `quick` (3 cases ⇒ no global superiority claim)
 *   - low Claude score without hard fail ⇒ suspicious_result with affects_winner
 *   - absurd Atlas margin on simple case ⇒ suspicious_result
 *   - tie margin <3 ⇒ tie (and human_review_required)
 *   - narrow margin ⇒ human review recommended
 *   - category winner is computed
 *   - overall not emitted when valid cases insufficient
 *   - external_rivals_certification stays BLOCKED in every code path
 *   - completion claim never promoted (claim_ready=false everywhere)
 *   - ledger_projection_ready has fields per provider/model/category
 *   - adjudication_hash is deterministic for the same input
 *   - invalid input fails closed with explicit blockers
 *
 * No provider call. No token spend. No external_rivals unlock.
 */
final class AtlasForgeRivalsAdjudicatorV2ServiceTest extends TestCase
{
    private AtlasForgeRivalsAdjudicatorV2Service $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new AtlasForgeRivalsAdjudicatorV2Service(new AtlasForgeRivalsRunPathResolver);
    }

    // 1
    public function test_score_deterministic_for_valid_run(): void
    {
        $payload = $this->releasePayload(12);
        $envelopeA = $this->svc->adjudicateBatch(['payload' => $payload]);
        $envelopeB = $this->svc->adjudicateBatch(['payload' => $payload]);

        $this->assertSame($envelopeA['adjudication_hash'], $envelopeB['adjudication_hash']);
        $this->assertSame($envelopeA['overall']['atlas_score'], $envelopeB['overall']['atlas_score']);
        $this->assertSame($envelopeA['overall']['rival_score'], $envelopeB['overall']['rival_score']);
    }

    // 2
    public function test_hard_fail_missing_provider_receipt_forces_score_null(): void
    {
        $payload = $this->releasePayload(1);
        $payload['runs'][0]['atlas_receipt'] = [];

        $env = $this->svc->adjudicateBatch(['payload' => $payload]);

        $this->assertCount(1, $env['cases']);
        $this->assertContains('missing_provider_receipt:atlas', $env['cases'][0]['hard_failures']);
        $this->assertNull($env['cases'][0]['scores']['atlas']);
        $this->assertNull($env['cases'][0]['scores']['rival']);
        $this->assertNull($env['cases'][0]['winner']);
        $this->assertFalse($env['cases'][0]['valid']);
        $this->assertFalse($env['claim_ready']);
        $this->assertSame('BLOCKED', $env['external_rivals_certification_status']);
    }

    // 3
    public function test_hard_fail_dirty_after_run_forces_score_null(): void
    {
        $payload = $this->releasePayload(1);
        $payload['runs'][0]['manifest']['dirty_after_run'] = true;

        $env = $this->svc->adjudicateBatch(['payload' => $payload]);

        $this->assertContains('dirty_after_run_false', $env['cases'][0]['hard_failures']);
        $this->assertNull($env['cases'][0]['scores']['atlas']);
        $this->assertNull($env['cases'][0]['winner']);
    }

    // 4
    public function test_hard_fail_scope_violation_forces_score_null(): void
    {
        $payload = $this->releasePayload(1);
        $payload['runs'][0]['atlas_receipt']['out_of_scope_files'] = ['app/sneaky.php'];

        $env = $this->svc->adjudicateBatch(['payload' => $payload]);

        $this->assertContains('no_out_of_scope_files_atlas', $env['cases'][0]['hard_failures']);
        $this->assertContains('scope_violation:atlas', $env['cases'][0]['hard_failures']);
        $this->assertNull($env['cases'][0]['scores']['atlas']);
    }

    // 5
    public function test_tests_failed_blocks_case_winner(): void
    {
        $payload = $this->releasePayload(1);
        $payload['runs'][0]['rival_receipt']['test_exit_code'] = 2;

        $env = $this->svc->adjudicateBatch(['payload' => $payload]);

        $this->assertContains('tests_failed:rival', $env['cases'][0]['hard_failures']);
        $this->assertNull($env['cases'][0]['winner']);
        $this->assertFalse($env['cases'][0]['valid']);
    }

    // 6
    public function test_weights_from_case_manifest_are_consumed(): void
    {
        $payload = $this->releasePayload(1);
        $payload['runs'][0]['case_manifest'] = [
            'role_focus' => 'ui_correctness',
            'quality_gates' => [
                'dimensions' => ['correctness', 'test_coverage', 'ux_quality'],
                'weights' => [
                    'correctness' => 0.50,
                    'test_coverage' => 0.30,
                    'ux_quality' => 0.20,
                ],
            ],
        ];

        $env = $this->svc->adjudicateBatch(['payload' => $payload]);

        $this->assertSame('case_manifest', $env['cases'][0]['weights_source']);
        $this->assertArrayHasKey('correctness', $env['cases'][0]['weights']);
        $this->assertEqualsWithDelta(1.0, array_sum($env['cases'][0]['weights']), 0.01);
    }

    // 7
    public function test_default_weights_used_when_manifest_does_not_declare(): void
    {
        $payload = $this->releasePayload(1);
        unset($payload['runs'][0]['case_manifest']);

        $env = $this->svc->adjudicateBatch(['payload' => $payload]);

        $this->assertSame('default_policy', $env['cases'][0]['weights_source']);
        $this->assertSame(AtlasForgeRivalsAdjudicatorV2Service::DEFAULT_WEIGHTS, $env['cases'][0]['weights']);
    }

    public function test_cost_time_is_telemetry_only_even_when_case_manifest_weights_it(): void
    {
        $payload = $this->releasePayload(1);
        $payload['runs'][0]['case_manifest'] = [
            'role_focus' => 'telemetry_only_guard',
            'quality_gates' => [
                'dimensions' => ['cost_time', 'test_coverage'],
                'weights' => [
                    'cost_time' => 0.99,
                    'test_coverage' => 0.01,
                ],
            ],
        ];
        $payload['runs'][0]['atlas_receipt']['stdout_bytes'] = 700_000;
        $payload['runs'][0]['atlas_receipt']['finished_at'] = '2026-05-15T12:20:00+00:00';
        $payload['runs'][0]['atlas_receipt']['test_log_tail'] = '(120 tests, 300 assertions)';
        $payload['runs'][0]['rival_receipt']['stdout_bytes'] = 100;
        $payload['runs'][0]['rival_receipt']['finished_at'] = '2026-05-15T12:01:00+00:00';
        $payload['runs'][0]['rival_receipt']['test_log_tail'] = '(10 tests, 20 assertions)';

        $env = $this->svc->adjudicateBatch(['payload' => $payload]);
        $case = $env['cases'][0];

        $this->assertSame(0.0, $case['weights']['cost_time']);
        $this->assertSame(1.0, $case['weights']['test_coverage']);
        $this->assertSame(0.0, $case['dimensions']['cost_time']['weight']);
        $this->assertLessThan($case['dimensions']['cost_time']['rival'], $case['dimensions']['cost_time']['atlas']);
        $this->assertSame(AtlasForgeRivalsAdjudicatorV2Service::WINNER_ATLAS, $case['winner']);
        $this->assertSame('telemetry_only_excluded_from_winner', $case['score_decision_policy']['cost_token_efficiency_role']);
        $this->assertSame(0.0, $case['score_decision_policy']['winner_decision_weights']['cost_time']);
        $this->assertSame(1.0, $case['score_decision_policy']['winner_decision_weights']['test_coverage']);
        $this->assertFalse($env['score_decision_policy']['winner_uses_cost_time']);
    }

    public function test_patch_shape_dimensions_are_diagnostic_only_even_when_case_manifest_weights_them(): void
    {
        $payload = $this->releasePayload(1);
        $payload['runs'][0]['case_manifest'] = [
            'role_focus' => 'strong_quality_guard',
            'quality_gates' => [
                'dimensions' => ['minimality', 'maintainability', 'architecture_fit', 'test_coverage'],
                'weights' => [
                    'minimality' => 0.30,
                    'maintainability' => 0.30,
                    'architecture_fit' => 0.30,
                    'test_coverage' => 0.10,
                ],
            ],
        ];
        $payload['runs'][0]['atlas_receipt']['patch_diff_bytes'] = 1_400;
        $payload['runs'][0]['atlas_receipt']['changed_files'] = ['app/Product.php'];
        $payload['runs'][0]['atlas_receipt']['test_log_tail'] = '(10 tests, 20 assertions)';
        $payload['runs'][0]['rival_receipt']['patch_diff_bytes'] = 250_000;
        $payload['runs'][0]['rival_receipt']['changed_files'] = array_map(static fn (int $i) => 'src/big_'.$i.'.php', range(1, 20));
        $payload['runs'][0]['rival_receipt']['test_log_tail'] = '(10 tests, 20 assertions)';

        $env = $this->svc->adjudicateBatch(['payload' => $payload]);
        $case = $env['cases'][0];

        $this->assertSame(0.0, $case['weights']['minimality']);
        $this->assertSame(0.0, $case['weights']['maintainability']);
        $this->assertSame(0.0, $case['weights']['architecture_fit']);
        $this->assertSame(1.0, $case['weights']['test_coverage']);
        $this->assertSame(AtlasForgeRivalsAdjudicatorV2Service::WINNER_TIE, $case['winner']);
        $this->assertSame(
            ['minimality', 'maintainability', 'architecture_fit'],
            $case['score_decision_policy']['diagnostic_only_dimensions'],
        );
    }

    // 8
    public function test_release_12_cases_8_categories_reaches_trusted_battery(): void
    {
        $payload = $this->releasePayload(12);
        $env = $this->svc->adjudicateBatch(['payload' => $payload]);

        $this->assertSame(12, $env['case_count']);
        $distinctCats = count(array_unique(array_column($env['cases'], 'task_category')));
        $this->assertGreaterThanOrEqual(8, $distinctCats);
        $this->assertSame(AtlasForgeRivalsAdjudicatorV2Service::CONFIDENCE_TRUSTED_BATTERY, $env['overall']['confidence']);
    }

    // 9
    public function test_quick_3_cases_never_declares_global_superiority(): void
    {
        $payload = $this->releasePayload(3);
        $payload['preset'] = 'quick';
        $env = $this->svc->adjudicateBatch(['payload' => $payload]);

        $this->assertNotSame(AtlasForgeRivalsAdjudicatorV2Service::CONFIDENCE_TRUSTED_BATTERY, $env['overall']['confidence']);
        $this->assertFalse($env['overall']['claim_allowed']);
    }

    // 10
    public function test_claude_low_without_hard_fail_triggers_suspicious(): void
    {
        $payload = $this->releasePayload(1);
        // Force Claude (rival) below 70 using a strong scored signal
        // (semantic test coverage), not patch shape.
        $payload['runs'][0]['case_manifest'] = [
            'role_focus' => 'ui_correctness',
            'quality_gates' => [
                'dimensions' => ['test_coverage'],
                'weights' => ['test_coverage' => 1.0],
            ],
        ];
        $payload['runs'][0]['rival_receipt']['test_log_tail'] = '(2 tests, 4 assertions)';

        $env = $this->svc->adjudicateBatch(['payload' => $payload]);
        $sus = array_column($env['cases'][0]['suspicious_results'], 'code');

        $this->assertContains('rival_underperformed_unexpectedly', $sus);
    }

    // 11
    public function test_atlas_absurd_margin_on_simple_case_triggers_suspicious(): void
    {
        $payload = $this->releasePayload(1);
        // Mark the case as a simple ui_correctness focus and make Atlas crush
        // rival through strong semantic test evidence only.
        $payload['runs'][0]['case_manifest'] = [
            'role_focus' => 'ui_correctness',
            'quality_gates' => [
                'dimensions' => ['test_coverage'],
                'weights' => [
                    'test_coverage' => 1.0,
                ],
            ],
        ];
        $payload['runs'][0]['atlas_receipt']['test_log_tail'] = '(300 tests, 900 assertions)';
        $payload['runs'][0]['rival_receipt']['test_log_tail'] = '(1 tests, 1 assertions)';

        $env = $this->svc->adjudicateBatch(['payload' => $payload]);
        $codes = array_column($env['cases'][0]['suspicious_results'], 'code');

        $this->assertContains('atlas_won_easy_case_by_huge_margin', $codes);
    }

    // 12
    public function test_margin_below_3_yields_tie(): void
    {
        $payload = $this->releasePayload(1);
        // Equal receipts ⇒ margin == 0 ⇒ tie.
        $env = $this->svc->adjudicateBatch(['payload' => $payload]);

        $this->assertSame(AtlasForgeRivalsAdjudicatorV2Service::WINNER_TIE, $env['cases'][0]['winner']);
        $this->assertTrue($env['cases'][0]['human_review_required']);
    }

    // 13
    public function test_narrow_margin_recommends_human_review(): void
    {
        $payload = $this->releasePayload(1);
        // Pin weights so the margin lands deterministically in the [tie, narrow]
        // band. test_coverage gives atlas a 10-point lead; scope_discipline ties
        // out so the weighted aggregate margin is 0.6*10 = 6.0 (∈ [5, 7)).
        $payload['runs'][0]['case_manifest'] = [
            'role_focus' => 'unknown',
            'quality_gates' => [
                'dimensions' => ['test_coverage', 'scope_discipline'],
                'weights' => [
                    'test_coverage' => 0.60,
                    'scope_discipline' => 0.40,
                ],
            ],
        ];
        $payload['runs'][0]['atlas_receipt']['test_log_tail'] = '(20 tests, 50 assertions)';
        $payload['runs'][0]['rival_receipt']['test_log_tail'] = '(20 tests, 11 assertions)';

        $env = $this->svc->adjudicateBatch(['payload' => $payload]);
        $winner = $env['cases'][0]['winner'];
        $margin = (float) ($env['cases'][0]['scores']['margin'] ?? 0);

        $this->assertSame(AtlasForgeRivalsAdjudicatorV2Service::WINNER_ATLAS, $winner);
        $this->assertGreaterThanOrEqual(AtlasForgeRivalsAdjudicatorV2Service::DEFAULT_TIE_THRESHOLD, $margin);
        $this->assertLessThan(AtlasForgeRivalsAdjudicatorV2Service::NARROW_WIN_THRESHOLD, $margin);
        $this->assertTrue($env['cases'][0]['human_review_required']);
    }

    // 14
    public function test_category_winner_is_calculated(): void
    {
        $payload = $this->releasePayload(12);
        $env = $this->svc->adjudicateBatch(['payload' => $payload]);
        $this->assertNotEmpty($env['categories']);
        foreach ($env['categories'] as $cat) {
            $this->assertArrayHasKey('atlas_score_avg', $cat);
            $this->assertArrayHasKey('rival_score_avg', $cat);
            $this->assertArrayHasKey('winner', $cat);
            $this->assertArrayHasKey('confidence', $cat);
        }
    }

    // 15
    public function test_overall_not_emitted_when_zero_valid_cases(): void
    {
        $payload = $this->releasePayload(2);
        foreach ($payload['runs'] as &$run) {
            $run['atlas_receipt']['out_of_scope_files'] = ['app/sneaky.php'];
        }
        unset($run);

        $env = $this->svc->adjudicateBatch(['payload' => $payload]);
        $this->assertSame(AtlasForgeRivalsAdjudicatorV2Service::CONFIDENCE_INSUFFICIENT, $env['overall']['confidence']);
        $this->assertNull($env['overall']['atlas_score']);
        $this->assertNull($env['overall']['rival_score']);
        $this->assertFalse($env['overall']['claim_allowed']);
    }

    // 16
    public function test_synthetic_score_is_hard_fail(): void
    {
        $payload = $this->releasePayload(1);
        $payload['runs'][0]['assertions'] = ['synthetic_score' => true];

        $env = $this->svc->adjudicateBatch(['payload' => $payload]);

        $this->assertContains('synthetic_score_detected', $env['cases'][0]['hard_failures']);
        $this->assertNull($env['cases'][0]['winner']);
        $this->assertFalse($env['cases'][0]['valid']);
    }

    // 17
    public function test_provider_policy_violation_is_hard_fail(): void
    {
        $payload = $this->releasePayload(1);
        $payload['runs'][0]['rival_receipt']['provider_policy_violation'] = true;

        $env = $this->svc->adjudicateBatch(['payload' => $payload]);

        $this->assertContains('provider_policy_violation:rival', $env['cases'][0]['hard_failures']);
    }

    // 18
    public function test_model_lock_violation_in_fair_mode_is_hard_fail(): void
    {
        $payload = $this->releasePayload(1);
        $payload['mode'] = 'fair';
        $payload['arms'][1]['model'] = 'claude_opus'; // atlas is sonnet, rival opus → fair violation

        $env = $this->svc->adjudicateBatch(['payload' => $payload]);

        $this->assertContains('model_lock_violation', $env['cases'][0]['hard_failures']);
    }

    // 19
    public function test_external_rivals_certification_remains_blocked(): void
    {
        $payload = $this->releasePayload(12);
        $env = $this->svc->adjudicateBatch(['payload' => $payload]);

        $this->assertSame('BLOCKED', $env['external_rivals_certification_status']);
        $this->assertTrue($env['safety']['never_unlocks_external_rivals_certification']);
        $this->assertTrue($env['safety']['separated_from_external_rivals_certification']);
        $this->assertFalse($env['external_provider_call']);
    }

    // 20
    public function test_completion_claim_never_promoted(): void
    {
        $payload = $this->releasePayload(12);
        $env = $this->svc->adjudicateBatch(['payload' => $payload]);

        $this->assertFalse($env['claim_ready']);
        $this->assertFalse($env['safety']['claim_ready']);
        foreach ($env['cases'] as $c) {
            $this->assertFalse($c['claim_ready']);
        }
    }

    // 21
    public function test_ledger_projection_ready_has_per_provider_model_category_fields(): void
    {
        $payload = $this->releasePayload(2);
        $env = $this->svc->adjudicateBatch(['payload' => $payload]);

        $this->assertNotEmpty($env['ledger_projection_ready']);
        foreach ($env['ledger_projection_ready'] as $row) {
            $this->assertArrayHasKey('arm', $row);
            $this->assertArrayHasKey('provider', $row);
            $this->assertArrayHasKey('model', $row);
            $this->assertArrayHasKey('role', $row);
            $this->assertArrayHasKey('task_category', $row);
            $this->assertArrayHasKey('mode', $row);
            $this->assertArrayHasKey('score', $row);
            $this->assertArrayHasKey('confidence', $row);
            $this->assertArrayHasKey('valid', $row);
            $this->assertArrayHasKey('hard_failure_reason', $row);
            $this->assertFalse($row['claim_ready']);
        }
    }

    // 22
    public function test_adjudication_hash_is_deterministic_for_same_input(): void
    {
        $payload = $this->releasePayload(4);
        $env1 = $this->svc->adjudicateBatch(['payload' => $payload]);
        $env2 = $this->svc->adjudicateBatch(['payload' => $payload]);

        $this->assertSame($env1['adjudication_hash'], $env2['adjudication_hash']);
        $this->assertNotEmpty($env1['adjudication_hash']);
        $this->assertSame(64, strlen($env1['adjudication_hash']));
    }

    // 23
    public function test_invalid_input_fails_closed_with_explicit_blockers(): void
    {
        $env = $this->svc->adjudicateBatch([]);
        $this->assertSame('blocked', $env['status']);
        $this->assertContains('batch_input_path_required', $env['blockers']);

        $env = $this->svc->adjudicateBatch(['input' => '/nonexistent/path.json']);
        $this->assertSame('blocked', $env['status']);
        $this->assertTrue(count(array_filter(
            $env['blockers'],
            static fn (string $b): bool => str_starts_with($b, 'batch_input_file_not_found:')
        )) > 0);

        $env = $this->svc->adjudicateBatch(['payload' => ['runs' => []]]);
        $this->assertSame('blocked', $env['status']);
        $this->assertContains('batch_input_runs_empty', $env['blockers']);
    }

    // 24
    public function test_input_file_is_consumed_when_provided(): void
    {
        $payload = $this->releasePayload(2);
        $tmp = tempnam(sys_get_temp_dir(), 'adj-v2-input-');
        if ($tmp === false) {
            $this->fail('Failed to create tmp file for input fixture');
        }
        file_put_contents($tmp, (string) json_encode($payload));

        $env = $this->svc->adjudicateBatch(['input' => $tmp]);

        $this->assertSame(AtlasForgeRivalsAdjudicatorV2Service::SCHEMA_VERSION, $env['schema_version']);
        $this->assertSame(2, $env['case_count']);
        $this->assertSame($tmp, $env['input_path']);

        @unlink($tmp);
    }

    // 25
    public function test_atlas_arm_not_forge_is_hard_fail_in_full_power(): void
    {
        $payload = $this->releasePayload(1);
        $payload['mode'] = 'full_power';
        $payload['arms'][0]['runner_type'] = 'raw_provider'; // atlas arm must be forge in full_power.

        $env = $this->svc->adjudicateBatch(['payload' => $payload]);

        $this->assertContains('atlas_arm_not_forge', $env['cases'][0]['hard_failures']);
        $this->assertFalse($env['cases'][0]['valid']);
    }

    // Helpers

    /**
     * Build a release-shaped payload of N cases distributed across canonical
     * task categories. Receipts are minimal but valid so every gate passes by
     * default — individual tests then dirty specific fields to assert hard
     * failures / suspicious results.
     *
     * @return array<string,mixed>
     */
    private function releasePayload(int $caseCount): array
    {
        $categories = [
            'backend_logic',
            'frontend_ui',
            'realistic_bugfix',
            'refactor',
            'test_design',
            'architecture',
            'integration',
            'performance_edge_case',
            'docs',
            'security',
        ];
        $runs = [];
        for ($i = 0; $i < $caseCount; $i++) {
            $cat = $categories[$i % count($categories)];
            $runs[] = $this->makeRunFixture('run-'.$i, $cat);
        }

        return [
            'schema_version' => AtlasForgeRivalsAdjudicatorV2Service::BATCH_INPUT_SCHEMA_VERSION,
            'preset' => 'release',
            'mode' => 'fair',
            'arms' => [
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
            ],
            'runs' => $runs,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function makeRunFixture(string $runId, string $taskCategory): array
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
            'case_id' => 'arena-'.$taskCategory.'-fixture-'.$runId,
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
