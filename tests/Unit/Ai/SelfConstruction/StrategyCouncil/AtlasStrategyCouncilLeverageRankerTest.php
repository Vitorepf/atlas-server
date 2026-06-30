<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\StrategyCouncil;

use App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilLeverageRanker;
use Tests\TestCase;

final class AtlasStrategyCouncilLeverageRankerTest extends TestCase
{
    private function candidate(array $overrides = []): array
    {
        return $overrides + [
            'candidate_id' => 'c-1',
            'organ' => 'cortex',
            'capability_gap' => 1,
            'user_impact' => 1,
            'autonomy_unlock' => 1,
            'waste_reduction' => 1,
            'risk' => 1,
            'evidence_refs' => ['receipt:r1'],
            'dependency_count' => 1,
        ];
    }

    public function test_higher_autonomy_unlock_ranks_first(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['candidate_id' => 'lo', 'autonomy_unlock' => 1]),
            $this->candidate(['candidate_id' => 'hi', 'autonomy_unlock' => 9]),
        ]);

        $this->assertSame(['hi', 'lo'], array_column($verdict['ranked'], 'candidate_id'));
    }

    public function test_higher_capability_gap_ranks_before_lower_when_autonomy_ties(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['candidate_id' => 'lo', 'capability_gap' => 1]),
            $this->candidate(['candidate_id' => 'hi', 'capability_gap' => 9]),
        ]);

        $this->assertSame('hi', $verdict['ranked'][0]['candidate_id']);
    }

    public function test_higher_user_impact_ranks_before_lower_when_autonomy_and_capability_gap_tie(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['candidate_id' => 'lo', 'user_impact' => 1, 'capability_gap' => 5]),
            $this->candidate(['candidate_id' => 'hi', 'user_impact' => 9, 'capability_gap' => 5]),
        ]);

        $this->assertSame('hi', $verdict['ranked'][0]['candidate_id']);
    }

    public function test_reasons_include_capability_gap_and_user_impact(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['capability_gap' => 7, 'user_impact' => 4]),
        ]);

        $reasons = $verdict['ranked'][0]['reasons'];
        $this->assertContains('capability_gap=7', $reasons, 'capability_gap must be in the reasons vector');
        $this->assertContains('user_impact=4', $reasons, 'user_impact must be in the reasons vector');
        $this->assertContains('autonomy_unlock=1', $reasons, 'autonomy_unlock still in reasons');
        $this->assertContains('waste_reduction=1', $reasons, 'waste_reduction still in reasons');
    }

    public function test_lower_waste_reduction_loses_to_higher_when_autonomy_ties(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['candidate_id' => 'low-waste-reduction', 'waste_reduction' => 1]),
            $this->candidate(['candidate_id' => 'high-waste-reduction', 'waste_reduction' => 9]),
        ]);

        $this->assertSame('high-waste-reduction', $verdict['ranked'][0]['candidate_id']);
    }

    public function test_no_evidence_refs_blocks_candidate(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['candidate_id' => 'no-evidence', 'evidence_refs' => []]),
        ]);

        $this->assertSame([], $verdict['ranked']);
        $this->assertCount(1, $verdict['rejected']);
        $this->assertContains('rejected:no_evidence_refs', $verdict['rejected'][0]['reasons']);
    }

    public function test_proxy_only_candidate_is_rejected(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate([
                'candidate_id' => 'proxy',
                'proxy_signals' => ['novelty', 'line_churn'],
                'capability_gap' => 0,
                'user_impact' => 0,
                'autonomy_unlock' => 0,
            ]),
        ]);

        $this->assertSame([], $verdict['ranked']);
        $this->assertCount(1, $verdict['rejected']);
        $this->assertStringContainsString('rejected:proxy_signals_only', $verdict['rejected'][0]['reasons'][0]);
    }

    public function test_deterministic_tie_break_by_candidate_id_asc(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['candidate_id' => 'charlie']),
            $this->candidate(['candidate_id' => 'alpha']),
            $this->candidate(['candidate_id' => 'bravo']),
        ]);

        $this->assertSame(['alpha', 'bravo', 'charlie'], array_column($verdict['ranked'], 'candidate_id'));
    }

    public function test_verdict_carries_no_composite_score_field(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([$this->candidate()]);
        // Reason vectors are transparent, NOT a single composite score.
        foreach ($verdict['ranked'][0]['factors'] as $key => $_) {
            $this->assertStringNotContainsString('composite', (string) $key);
            $this->assertStringNotContainsString('hype', (string) $key);
            $this->assertStringNotContainsString('total_score', (string) $key);
        }
    }

    public function test_ranking_is_deterministic_byte_identical(): void
    {
        $svc = new AtlasStrategyCouncilLeverageRanker;
        $a = $svc->rank([$this->candidate(['candidate_id' => 'a']), $this->candidate(['candidate_id' => 'b'])]);
        $b = $svc->rank([$this->candidate(['candidate_id' => 'a']), $this->candidate(['candidate_id' => 'b'])]);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_higher_unblocks_count_ranks_first_when_autonomy_ties(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['candidate_id' => 'lo', 'unblocks_count' => 1]),
            $this->candidate(['candidate_id' => 'hi', 'unblocks_count' => 9]),
        ]);

        $this->assertSame('hi', $verdict['ranked'][0]['candidate_id']);
    }

    public function test_reasons_include_unblocks_count_and_risk_reduction(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['unblocks_count' => 3, 'risk_reduction' => 5]),
        ]);

        $reasons = $verdict['ranked'][0]['reasons'];
        $this->assertContains('unblocks_count=3', $reasons);
        $this->assertContains('risk_reduction=5', $reasons);
    }

    public function test_task_count_only_proxy_is_rejected(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate([
                'candidate_id' => 'task-count-only',
                'proxy_signals' => ['task_count'],
                'capability_gap' => 0,
                'user_impact' => 0,
                'autonomy_unlock' => 0,
            ]),
        ]);

        $this->assertSame([], $verdict['ranked']);
        $this->assertCount(1, $verdict['rejected']);
    }

    public function test_green_self_report_only_proxy_is_rejected(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate([
                'candidate_id' => 'self-report',
                'proxy_signals' => ['green_self_report'],
                'capability_gap' => 0,
                'user_impact' => 0,
                'autonomy_unlock' => 0,
            ]),
        ]);

        $this->assertSame([], $verdict['ranked']);
        $this->assertCount(1, $verdict['rejected']);
    }
}
