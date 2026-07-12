<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AtlasDecide;

use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\AtlasDecide\AtlasDecideRouteRegretService;
use Tests\TestCase;

final class AtlasDecideLiveFeedbackRegretCommandTest extends TestCase
{
    private string $outcomePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outcomePath = sys_get_temp_dir().'/atlas_decide_regret_'.uniqid('', true).'.jsonl';
        $service = new AtlasDecideLiveOutcomeFeedbackService;
        $service->setLogPathForTesting($this->outcomePath);
        $this->app->instance(AtlasDecideLiveOutcomeFeedbackService::class, $service);
    }

    protected function tearDown(): void
    {
        @unlink($this->outcomePath);

        parent::tearDown();
    }

    public function test_regret_report_compares_chosen_route_to_fallback_with_named_denominators(): void
    {
        $service = app(AtlasDecideLiveOutcomeFeedbackService::class);

        for ($i = 0; $i < 5; $i++) {
            $service->record([
                'task_category' => 'code_generation',
                'role' => 'primary',
                'framework' => $i % 2 === 0 ? 'laravel' : 'symfony',
                'provider' => 'cheap_provider',
                'model' => 'cheap-1',
                'result' => $i < 3
                    ? AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS
                    : AtlasDecideLiveOutcomeFeedbackService::RESULT_FAILURE,
                'proven_real' => $i < 3,
                'quality_score' => $i < 3 ? 0.8 : null,
                'cost_usd' => 0.01,
                'latency_ms' => 2000,
                'routing_basis' => 'cost_outcome',
                'fallback_provider' => 'safe_provider',
                'fallback_model' => 'safe-1',
            ]);

            $service->record([
                'task_category' => 'code_generation',
                'role' => 'primary',
                'framework' => $i % 2 === 0 ? 'laravel' : 'symfony',
                'provider' => 'safe_provider',
                'model' => 'safe-1',
                'result' => AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS,
                'proven_real' => true,
                'quality_score' => 0.95,
                'cost_usd' => 0.05,
                'latency_ms' => 4000,
            ]);
        }

        $payload = $this->regretReport();
        $scope = $payload['scopes'][0] ?? [];

        $this->artisan('atlas:atlas-decide:live-feedback', ['--regret' => true, '--json' => true])
            ->expectsOutputToContain('"measure_id": "atlas.decide.route_regret.v2"')
            ->assertExitCode(0);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame('atlas.decide.route_regret.v2', $payload['measure_id']);
        $this->assertSame('ok', $scope['status'] ?? null);
        $this->assertSame(['task_category' => 'code_generation', 'role' => 'primary'], $scope['scope'] ?? null);
        $this->assertSame(5, data_get($scope, 'denominators.pair_n'));
        $this->assertSame(5, data_get($scope, 'denominators.chosen_n'));
        $this->assertSame(5, data_get($scope, 'denominators.counterfactual_n'));
        $this->assertSame(3, data_get($scope, 'thresholds.min_evidence'));
        $this->assertSame(5, data_get($scope, 'thresholds.min_calls'));
        $this->assertSame('cheap_provider', data_get($scope, 'chosen.provider'));
        $this->assertSame('safe_provider', data_get($scope, 'counterfactual.provider'));
        $this->assertIsFloat($scope['regret_proxy'] ?? null);
    }

    public function test_regret_report_is_honest_insufficient_n_without_number(): void
    {
        $service = app(AtlasDecideLiveOutcomeFeedbackService::class);

        for ($i = 0; $i < 2; $i++) {
            $service->record([
                'task_category' => 'code_generation',
                'role' => 'primary',
                'provider' => 'cheap_provider',
                'result' => AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS,
                'proven_real' => true,
                'quality_score' => 0.8,
                'cost_usd' => 0.01,
                'routing_basis' => 'cost_outcome',
                'fallback_provider' => 'safe_provider',
            ]);
        }

        $payload = $this->regretReport();
        $scope = $payload['scopes'][0] ?? [];

        $this->assertSame('insufficient_n', $scope['status'] ?? null);
        $this->assertSame(2, data_get($scope, 'denominators.pair_n'));
        $this->assertArrayNotHasKey('regret_proxy', $scope);
    }

    public function test_exploration_uses_displaced_greedy_pick_as_counterfactual(): void
    {
        $service = app(AtlasDecideLiveOutcomeFeedbackService::class);

        for ($i = 0; $i < 5; $i++) {
            $service->record([
                'task_category' => 'code_generation',
                'role' => 'primary',
                'provider' => 'exploratory_provider',
                'result' => AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS,
                'proven_real' => true,
                'quality_score' => 0.75,
                'cost_usd' => 0.02,
                'routing_basis' => 'exploration',
                'fallback_provider' => 'second_best_provider',
                'would_have_been_greedy_provider' => 'greedy_provider',
            ]);

            $service->record([
                'task_category' => 'code_generation',
                'role' => 'primary',
                'provider' => 'greedy_provider',
                'result' => AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS,
                'proven_real' => true,
                'quality_score' => 0.9,
                'cost_usd' => 0.04,
            ]);
        }

        $payload = $this->regretReport();
        $scope = $payload['scopes'][0] ?? [];

        $this->assertSame('would_have_been_greedy', $scope['counterfactual_kind'] ?? null);
        $this->assertSame('greedy_provider', data_get($scope, 'counterfactual.provider'));
    }

    /** @return array<string,mixed> */
    private function regretReport(): array
    {
        return app(AtlasDecideRouteRegretService::class)->report();
    }
}
