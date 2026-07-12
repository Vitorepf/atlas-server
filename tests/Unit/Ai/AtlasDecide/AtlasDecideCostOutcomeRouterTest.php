<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AtlasDecide;

use App\Services\Ai\AtlasDecide\AtlasDecideCostOutcomeRouter;
use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\AcosMax\AcosMaxMeasureSeriesRegistry;
use Tests\TestCase;

class AtlasDecideCostOutcomeRouterTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().'/atlas_cost_outcome_'.uniqid('', true);
        @mkdir($this->tmpRoot.'/ledger', 0775, true);
        config(['atlas_rivals.ledger_root' => $this->tmpRoot.'/ledger']);
        config(['atlas.patamar4.adml_cost_outcome' => []]);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpRoot);
        parent::tearDown();
    }

    private function rmdirRecursive(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $full = $path.'/'.$f;
            is_dir($full) ? $this->rmdirRecursive($full) : @unlink($full);
        }
        @rmdir($path);
    }

    private function buildRouter(): AtlasDecideCostOutcomeRouter
    {
        return new AtlasDecideCostOutcomeRouter(
            null,
            static fn ($p) => is_string($p) && $p !== '' ? $p : null,
            static fn ($provider, $model) => is_string($model) && $model !== '' ? $model : null,
            static fn ($p) => in_array($p, ['codex_cli', 'claude_cli', 'minimax'], true),
            static fn ($v) => is_numeric($v) ? (float) $v : null,
        );
    }

    /** @return array<string,mixed> */
    private function certifiedEntry(string $provider, string $model, float $score = 90.0, float $cost = 0.05, string $runId = 'r1', ?int $latencyMs = null): array
    {
        return [
            'provider' => $provider,
            'model' => $model,
            'valid_for_ranking' => true,
            'tests_passed' => true,
            'replay_passed' => true,
            'hard_failures' => [],
            'score_total' => $score,
            'cost_estimate' => $cost,
            'latency_ms' => $latencyMs,
            'recorded_at' => '2026-06-01T00:00:00+00:00',
            'run_id' => $runId,
            'evidence_source' => 'forge_rivals_provider_performance_ledger',
        ];
    }

    private function cfg(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'min_evidence' => 3,
            'min_certification_rate' => 0.8,
            'min_score' => 80.0,
            'max_score_drop' => 3.0,
            'require_measured_cost' => true,
            'min_cost_samples' => 1,
        ], $overrides);
    }

    // ── isCertifiedCostOutcomeEntry: forge_rivals source ─────────────────────

    public function test_certified_entry_when_all_flags_set(): void
    {
        $router = $this->buildRouter();

        $this->assertTrue($router->isCertifiedCostOutcomeEntry($this->certifiedEntry('codex_cli', 'gpt-5.5')));
    }

    public function test_not_certified_when_valid_for_ranking_false(): void
    {
        $router = $this->buildRouter();
        $entry = $this->certifiedEntry('codex_cli', 'gpt-5.5');
        $entry['valid_for_ranking'] = false;

        $this->assertFalse($router->isCertifiedCostOutcomeEntry($entry));
    }

    public function test_not_certified_when_tests_passed_false(): void
    {
        $router = $this->buildRouter();
        $entry = $this->certifiedEntry('codex_cli', 'gpt-5.5');
        $entry['tests_passed'] = false;

        $this->assertFalse($router->isCertifiedCostOutcomeEntry($entry));
    }

    public function test_not_certified_when_hard_failures_present(): void
    {
        $router = $this->buildRouter();
        $entry = $this->certifiedEntry('codex_cli', 'gpt-5.5');
        $entry['hard_failures'] = ['assertion_failure'];

        $this->assertFalse($router->isCertifiedCostOutcomeEntry($entry));
    }

    // ── isCertifiedCostOutcomeEntry: live_outcome_feedback source ─────────────

    public function test_live_feedback_certified_when_success_numeric_scores_and_verified_basis(): void
    {
        $router = $this->buildRouter();
        $entry = [
            'evidence_source' => 'live_outcome_feedback',
            'result' => AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS,
            'quality_score' => 0.9,
            'score_total' => 90.0,
            'verified_basis' => AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_SERVER_VERIFIED,
            'certified_receipt_id' => 'receipt-1',
            'hard_failures' => [],
        ];

        $this->assertTrue($router->isCertifiedCostOutcomeEntry($entry));
    }

    public function test_live_feedback_success_without_verified_basis_has_zero_ranking_weight(): void
    {
        $router = $this->buildRouter();
        $entry = [
            'evidence_source' => 'live_outcome_feedback',
            'result' => AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS,
            'quality_score' => 0.9,
            'score_total' => 90.0,
            'hard_failures' => [],
        ];

        $this->assertFalse($router->isCertifiedCostOutcomeEntry($entry));
    }

    public function test_live_feedback_not_certified_when_result_failure(): void
    {
        $router = $this->buildRouter();
        $entry = [
            'evidence_source' => 'live_outcome_feedback',
            'result' => AtlasDecideLiveOutcomeFeedbackService::RESULT_FAILURE,
            'quality_score' => 0.9,
            'score_total' => 90.0,
            'hard_failures' => [],
        ];

        $this->assertFalse($router->isCertifiedCostOutcomeEntry($entry));
    }

    public function test_live_feedback_not_certified_when_quality_score_missing(): void
    {
        $router = $this->buildRouter();
        $entry = [
            'evidence_source' => 'live_outcome_feedback',
            'result' => AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS,
            'quality_score' => null,
            'score_total' => 90.0,
            'hard_failures' => [],
        ];

        $this->assertFalse($router->isCertifiedCostOutcomeEntry($entry));
    }

    // ── costOutcomeRoute: disabled ────────────────────────────────────────────

    public function test_route_disabled_returns_disabled_status(): void
    {
        config(['atlas.patamar4.adml_cost_outcome.enabled' => false]);
        $router = $this->buildRouter();

        $result = $router->costOutcomeRoute('backend', 'builder', null, 'schema.v1');

        $this->assertSame('disabled', $result['status']);
        $this->assertFalse($result['enabled']);
        $this->assertFalse($result['external_provider_call']);
        $this->assertFalse($result['provider_tokens_spent']);
    }

    // ── costOutcomeRoute: empty ledger ────────────────────────────────────────

    public function test_route_blocked_when_no_evidence(): void
    {
        config(['atlas.patamar4.adml_cost_outcome.enabled' => true]);
        $router = $this->buildRouter();

        $result = $router->costOutcomeRoute('backend', 'builder', null, 'schema.v1');

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('no_relevant_cost_outcome_evidence', $result['blockers']);
        $this->assertSame(0, $result['candidate_count']);
    }

    // ── evidence_deficit: new field ───────────────────────────────────────────

    public function test_evidence_deficit_equals_gap_to_min_evidence(): void
    {
        $router = $this->buildRouter();
        $cfg = $this->cfg(['min_evidence' => 3]);

        // 2 certified entries → deficit = 1
        $entries = [
            $this->certifiedEntry('codex_cli', 'gpt-5.5', runId: 'r1'),
            $this->certifiedEntry('codex_cli', 'gpt-5.5', runId: 'r2'),
        ];

        $candidates = $router->costOutcomeCandidates($entries, $cfg);

        $this->assertCount(1, $candidates);
        $this->assertSame(1, $candidates[0]['evidence_deficit']);
    }

    public function test_evidence_deficit_zero_when_certified_count_meets_minimum(): void
    {
        $router = $this->buildRouter();
        $cfg = $this->cfg(['min_evidence' => 3]);

        $entries = [
            $this->certifiedEntry('codex_cli', 'gpt-5.5', runId: 'r1'),
            $this->certifiedEntry('codex_cli', 'gpt-5.5', runId: 'r2'),
            $this->certifiedEntry('codex_cli', 'gpt-5.5', runId: 'r3'),
        ];

        $candidates = $router->costOutcomeCandidates($entries, $cfg);

        $this->assertSame(0, $candidates[0]['evidence_deficit']);
    }

    public function test_evidence_deficit_zero_when_certified_count_exceeds_minimum(): void
    {
        $router = $this->buildRouter();
        $cfg = $this->cfg(['min_evidence' => 2]);

        $entries = [
            $this->certifiedEntry('codex_cli', 'gpt-5.5', runId: 'r1'),
            $this->certifiedEntry('codex_cli', 'gpt-5.5', runId: 'r2'),
            $this->certifiedEntry('codex_cli', 'gpt-5.5', runId: 'r3'),
        ];

        $candidates = $router->costOutcomeCandidates($entries, $cfg);

        $this->assertSame(0, $candidates[0]['evidence_deficit']);
    }

    public function test_evidence_deficit_per_candidate_independently(): void
    {
        $router = $this->buildRouter();
        $cfg = $this->cfg(['min_evidence' => 3]);

        $entries = [
            // codex_cli: 1 certified → deficit 2
            $this->certifiedEntry('codex_cli', 'gpt-5.5', runId: 'r1'),
            // claude_cli: 3 certified → deficit 0
            $this->certifiedEntry('claude_cli', 'claude-opus', runId: 'r2'),
            $this->certifiedEntry('claude_cli', 'claude-opus', runId: 'r3'),
            $this->certifiedEntry('claude_cli', 'claude-opus', runId: 'r4'),
        ];

        $candidates = $router->costOutcomeCandidates($entries, $cfg);
        $byProvider = [];
        foreach ($candidates as $c) {
            $byProvider[$c['provider']] = $c['evidence_deficit'];
        }

        $this->assertSame(2, $byProvider['codex_cli']);
        $this->assertSame(0, $byProvider['claude_cli']);
    }

    public function test_multk01_interval_is_insufficient_below_three_certified_samples(): void
    {
        $router = $this->buildRouter();

        $candidates = $router->costOutcomeCandidates([
            $this->certifiedEntry('codex_cli', 'gpt-5.5', runId: 'r1'),
            $this->certifiedEntry('codex_cli', 'gpt-5.5', runId: 'r2'),
        ], $this->cfg(['min_evidence' => 1]));

        $this->assertSame('insufficient_n', $candidates[0]['uncertainty_interval']['status']);
        $this->assertSame(2, $candidates[0]['uncertainty_interval']['n']);
        $this->assertArrayNotHasKey('lower_bound', $candidates[0]['uncertainty_interval']);
    }

    public function test_multk01_interval_is_deterministic_and_width_shrinks_with_more_evidence(): void
    {
        $router = $this->buildRouter();

        $small = $router->costOutcomeCandidates([
            $this->certifiedEntry('codex_cli', 'gpt-5.5', runId: 'r1'),
            $this->certifiedEntry('codex_cli', 'gpt-5.5', runId: 'r2'),
            $this->certifiedEntry('codex_cli', 'gpt-5.5', runId: 'r3'),
        ], $this->cfg(['min_evidence' => 1]))[0]['uncertainty_interval'];

        $smallAgain = $router->costOutcomeCandidates([
            $this->certifiedEntry('codex_cli', 'gpt-5.5', runId: 'r1'),
            $this->certifiedEntry('codex_cli', 'gpt-5.5', runId: 'r2'),
            $this->certifiedEntry('codex_cli', 'gpt-5.5', runId: 'r3'),
        ], $this->cfg(['min_evidence' => 1]))[0]['uncertainty_interval'];

        $large = $router->costOutcomeCandidates(array_map(
            fn (int $i): array => $this->certifiedEntry('codex_cli', 'gpt-5.5', runId: 'r'.$i),
            range(1, 20),
        ), $this->cfg(['min_evidence' => 1]))[0]['uncertainty_interval'];

        $this->assertSame($small, $smallAgain);
        $this->assertSame('ok', $small['status']);
        $this->assertSame('ok', $large['status']);
        $this->assertLessThan(
            $small['upper_bound'] - $small['lower_bound'],
            $large['upper_bound'] - $large['lower_bound'],
        );
    }

    public function test_multk01_interval_does_not_change_cost_outcome_selection(): void
    {
        config(['atlas.patamar4.adml_cost_outcome.enabled' => true]);
        $feedback = new AtlasDecideLiveOutcomeFeedbackService();
        $feedback->setLogPathForTesting(sys_get_temp_dir().'/atlas_live_outcomes_'.uniqid('', true).'.jsonl');
        foreach (range(1, 3) as $i) {
            $feedback->record([
                'task_category' => 'backend',
                'role' => 'builder',
                'framework' => 'laravel',
                'provider' => 'codex_cli',
                'model' => 'gpt-5.5',
                'result' => AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS,
                'verified_basis' => AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_SERVER_VERIFIED,
                'certified_receipt_id' => 'codex-receipt-'.$i,
                'quality_score' => 0.92,
                'cost_usd' => 0.05,
                'entry_hash' => 'codex-'.$i,
            ]);
            $feedback->record([
                'task_category' => 'backend',
                'role' => 'builder',
                'framework' => 'laravel',
                'provider' => 'claude_cli',
                'model' => 'opus',
                'result' => AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS,
                'verified_basis' => AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_SERVER_VERIFIED,
                'certified_receipt_id' => 'claude-receipt-'.$i,
                'quality_score' => 0.93,
                'cost_usd' => 0.10,
                'entry_hash' => 'claude-'.$i,
            ]);
        }

        $router = new AtlasDecideCostOutcomeRouter(
            $feedback,
            static fn ($p) => is_string($p) && $p !== '' ? $p : null,
            static fn ($provider, $model) => is_string($model) && $model !== '' ? $model : null,
            static fn ($p) => in_array($p, ['codex_cli', 'claude_cli', 'minimax'], true),
            static fn ($v) => is_numeric($v) ? (float) $v : null,
        );

        $route = $router->costOutcomeRoute('backend', 'builder', 'laravel', 'schema.v1');

        $this->assertSame('ready', $route['status']);
        $this->assertSame('codex_cli', data_get($route, 'selected.provider'));
        $this->assertSame('ok', data_get($route, 'selected.uncertainty_interval.status'));
    }

    public function test_maxk03_default_multi_objective_weights_preserve_cost_first_selection(): void
    {
        config([
            'atlas.patamar4.adml_cost_outcome.enabled' => true,
            'atlas.patamar4.adml_cost_outcome.multi_objective' => [
                'enabled' => true,
                'risk_class' => 'default',
                'weights' => ['success' => 0.0, 'cost' => 1.0, 'latency' => 0.0],
            ],
        ]);
        $feedback = new AtlasDecideLiveOutcomeFeedbackService();
        $feedback->setLogPathForTesting(sys_get_temp_dir().'/atlas_live_outcomes_'.uniqid('', true).'.jsonl');
        foreach (range(1, 3) as $i) {
            $feedback->record([
                'task_category' => 'backend',
                'role' => 'builder',
                'framework' => 'laravel',
                'provider' => 'codex_cli',
                'model' => 'gpt-5.5',
                'result' => AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS,
                'verified_basis' => AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_SERVER_VERIFIED,
                'certified_receipt_id' => 'codex-maxk03-'.$i,
                'quality_score' => 0.91,
                'cost_usd' => 0.05,
                'latency_ms' => 3000,
                'entry_hash' => 'codex-maxk03-'.$i,
            ]);
            $feedback->record([
                'task_category' => 'backend',
                'role' => 'builder',
                'framework' => 'laravel',
                'provider' => 'claude_cli',
                'model' => 'opus',
                'result' => AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS,
                'verified_basis' => AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_SERVER_VERIFIED,
                'certified_receipt_id' => 'claude-maxk03-'.$i,
                'quality_score' => 0.91,
                'cost_usd' => 0.10,
                'latency_ms' => 100,
                'entry_hash' => 'claude-maxk03-'.$i,
            ]);
        }

        $route = $this->routerWithFeedback($feedback)->costOutcomeRoute('backend', 'builder', 'laravel', 'schema.v1');

        $this->assertSame('ready', $route['status']);
        $this->assertSame('codex_cli', data_get($route, 'selected.provider'));
        $this->assertSame(1.0, data_get($route, 'selected.multi_objective.weights.cost'));
        $this->assertSame('operator_config', data_get($route, 'selected.multi_objective.source'));
    }

    public function test_maxk03_latency_weight_can_choose_faster_route_and_ignores_caller_weights(): void
    {
        config([
            'atlas.patamar4.adml_cost_outcome.enabled' => true,
            'atlas.patamar4.adml_cost_outcome.multi_objective' => [
                'enabled' => true,
                'risk_class' => 'interactive',
                'weights' => ['success' => 0.0, 'cost' => 0.0, 'latency' => 1.0],
            ],
        ]);
        $feedback = new AtlasDecideLiveOutcomeFeedbackService();
        $feedback->setLogPathForTesting(sys_get_temp_dir().'/atlas_live_outcomes_'.uniqid('', true).'.jsonl');
        foreach (range(1, 3) as $i) {
            $feedback->record([
                'task_category' => 'backend',
                'role' => 'builder',
                'framework' => 'laravel',
                'provider' => 'codex_cli',
                'model' => 'gpt-5.5',
                'result' => AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS,
                'verified_basis' => AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_SERVER_VERIFIED,
                'certified_receipt_id' => 'codex-latency-'.$i,
                'quality_score' => 0.91,
                'cost_usd' => 0.01,
                'latency_ms' => 4000,
                'entry_hash' => 'codex-latency-'.$i,
                'multi_objective' => ['score' => 999.0, 'weights' => ['latency' => 0.0]],
            ]);
            $feedback->record([
                'task_category' => 'backend',
                'role' => 'builder',
                'framework' => 'laravel',
                'provider' => 'claude_cli',
                'model' => 'opus',
                'result' => AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS,
                'verified_basis' => AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_SERVER_VERIFIED,
                'certified_receipt_id' => 'claude-latency-'.$i,
                'quality_score' => 0.91,
                'cost_usd' => 0.20,
                'latency_ms' => 100,
                'entry_hash' => 'claude-latency-'.$i,
            ]);
        }

        $route = $this->routerWithFeedback($feedback)->costOutcomeRoute('backend', 'builder', 'laravel', 'schema.v1');

        $this->assertSame('ready', $route['status']);
        $this->assertSame('claude_cli', data_get($route, 'selected.provider'));
        $this->assertSame('interactive', data_get($route, 'selected.multi_objective.risk_class'));
        $this->assertSame(1.0, data_get($route, 'selected.multi_objective.weights.latency'));
        $this->assertFalse(data_get($route, 'selected.multi_objective.caller_supplied_weights_allowed'));
    }

    public function test_unverified_live_successes_do_not_change_cost_outcome_candidate_score_or_cost(): void
    {
        $router = $this->buildRouter();
        $cfg = $this->cfg(['min_evidence' => 1]);
        $verified = [
            [
                'evidence_source' => 'live_outcome_feedback',
                'provider' => 'codex_cli',
                'model' => 'gpt-5.5',
                'result' => AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS,
                'quality_score' => 0.8,
                'score_total' => 80.0,
                'cost_estimate' => 0.05,
                'verified_basis' => AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_SERVER_VERIFIED,
                'certified_receipt_id' => 'receipt-ok',
                'hard_failures' => [],
                'recorded_at' => '2026-06-01T00:00:00+00:00',
            ],
        ];
        $withUnverified = array_merge($verified, [[
            'evidence_source' => 'live_outcome_feedback',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'result' => AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS,
            'quality_score' => 1.0,
            'score_total' => 100.0,
            'cost_estimate' => 0.001,
            'verified_basis' => AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_CLAIMED,
            'hard_failures' => [],
            'recorded_at' => '2026-06-02T00:00:00+00:00',
        ]]);

        $baseline = $router->costOutcomeCandidates($verified, $cfg)[0];
        $candidate = $router->costOutcomeCandidates($withUnverified, $cfg)[0];

        $this->assertSame($baseline['certified_count'], $candidate['certified_count']);
        $this->assertSame($baseline['average_score'], $candidate['average_score']);
        $this->assertSame($baseline['average_cost_estimate'], $candidate['average_cost_estimate']);
        $this->assertSame(1, $candidate['zero_weight_outcome_count']);
    }

    public function test_multk01_series_is_registered_for_elev20s(): void
    {
        $entry = collect((new AcosMaxMeasureSeriesRegistry())->entries())
            ->firstWhere('slice', 'MULTK-01');

        $this->assertSame(AtlasDecideCostOutcomeRouter::MULTK01_MEASURE_ID, $entry['series'] ?? null);
        $this->assertSame('computed_reader_field', $entry['source_type'] ?? null);
        $this->assertSame('freeze:atlas.decide.cost_outcome_uncertainty.v1', $entry['ttl_source'] ?? null);
    }

    public function test_esp05_zero_weight_outcome_series_is_registered_for_elev20s(): void
    {
        $entry = collect((new AcosMaxMeasureSeriesRegistry())->entries())
            ->firstWhere('slice', 'ESP-05');

        $this->assertSame(AtlasDecideLiveOutcomeFeedbackService::ZERO_WEIGHT_MEASURE_ID, $entry['series'] ?? null);
        $this->assertSame('computed_reader_field', $entry['source_type'] ?? null);
        $this->assertSame('freeze:atlas.decide.zero_weight_outcomes.v1', $entry['ttl_source'] ?? null);
    }

    private function routerWithFeedback(AtlasDecideLiveOutcomeFeedbackService $feedback): AtlasDecideCostOutcomeRouter
    {
        return new AtlasDecideCostOutcomeRouter(
            $feedback,
            static fn ($p) => is_string($p) && $p !== '' ? $p : null,
            static fn ($provider, $model) => is_string($model) && $model !== '' ? $model : null,
            static fn ($p) => in_array($p, ['codex_cli', 'claude_cli', 'minimax'], true),
            static fn ($v) => is_numeric($v) ? (float) $v : null,
        );
    }
}
