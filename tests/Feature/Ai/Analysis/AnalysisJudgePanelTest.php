<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Analysis;

use App\Services\Ai\Analysis\AnalysisHonestyGate;
use App\Services\Ai\Analysis\AnalysisJudgePanelService;
use App\Services\Ai\Analysis\ConsistencyLensJudge;
use App\Services\Ai\Analysis\UncertaintyLensJudge;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * G6 — cross-domain multi-perspective analysis panel + metric-family-aware
 * honesty gate. Everything under test is deterministic: no provider call.
 */
class AnalysisJudgePanelTest extends TestCase
{
    private function panel(): AnalysisJudgePanelService
    {
        return new AnalysisJudgePanelService(new AnalysisHonestyGate);
    }

    /**
     * @return array<string,mixed>
     */
    private function honestFinancialAnalysis(): array
    {
        return [
            'metric_family' => 'financial_backtest',
            'headline_metric' => 'deflated_sharpe',
            'metrics' => [
                'deflated_sharpe' => 0.42,
                'holdout_sharpe' => 0.31,
                'n_trials' => 128,
            ],
            'out_of_sample' => ['sealed holdout window, never used for selection'],
            'claims' => [
                [
                    'id' => 'c1',
                    'text' => 'Strategy keeps positive deflated risk-adjusted return after trial-count deflation.',
                    'polarity' => 'positive',
                    'confidence' => 0.8,
                    'evidence_refs' => ['report:dsr_run_128_trials', 'dataset:frozen_daily_2024'],
                ],
                [
                    'id' => 'c2',
                    'text' => 'Sealed holdout stays positive with enough trades to count.',
                    'polarity' => 'positive',
                    'confidence' => 0.75,
                    'evidence_refs' => ['report:holdout_eval', 'ledger:holdout_trades'],
                ],
            ],
            'conclusion' => [
                'text' => 'Promising but small edge; keep propose-only.',
                'claim_refs' => ['c1', 'c2'],
            ],
            'verdict' => 'positive',
            'limitations' => [
                'single asset, single timeframe',
                'no transaction-cost stress beyond baseline fees',
            ],
        ];
    }

    public function test_honest_financial_analysis_certifies(): void
    {
        $result = $this->panel()->run($this->honestFinancialAnalysis());

        $this->assertTrue($result['certified']);
        $this->assertSame('certified', $result['decision']);
        $this->assertSame([], $result['reasons']);
        $this->assertTrue($result['honesty']['certified']);
        $this->assertSame('financial_backtest', $result['honesty']['metric_family']);
        $this->assertSame(3, $result['panel']['accept_count']);
        $this->assertTrue($result['panel']['majority_confirmed']);
    }

    public function test_win_rate_as_headline_metric_is_refused(): void
    {
        $analysis = $this->honestFinancialAnalysis();
        $analysis['headline_metric'] = 'win_rate';
        $analysis['metrics']['win_rate'] = 0.81;

        $result = $this->panel()->run($analysis);

        $this->assertFalse($result['certified']);
        $this->assertSame('honesty_refused', $result['decision']);
        $this->assertContains('win_rate_forbidden_as_headline', $result['honesty']['reasons']);
    }

    public function test_financial_metrics_under_non_financial_family_refused_as_mismatch(): void
    {
        $analysis = [
            'metric_family' => 'generic_claims',
            'metrics' => [
                'win_rate' => 0.78,
                'sharpe' => 2.4,
            ],
            'claims' => [
                [
                    'id' => 'c1',
                    'text' => 'The strategy made money.',
                    'polarity' => 'positive',
                    'confidence' => 0.6,
                    'evidence_refs' => ['log:equity_curve'],
                ],
            ],
            'limitations' => ['short sample'],
        ];

        $result = $this->panel()->run($analysis);

        $this->assertFalse($result['certified']);
        $this->assertSame('honesty_refused', $result['decision']);
        $this->assertContains('metric_family_mismatch', $result['honesty']['reasons']);
        // The mismatch must be the ONLY honesty refusal here: the generic
        // checks themselves pass, proving the anti-fake-secure check fired.
        $this->assertSame(['metric_family_mismatch'], $result['honesty']['reasons']);
    }

    public function test_engineering_family_without_totals_is_refused(): void
    {
        $analysis = [
            'metric_family' => 'engineering_tests',
            'metrics' => [
                'pass_rate' => 0.98,
            ],
            'claims' => [
                [
                    'id' => 'c1',
                    'text' => 'Nearly every test passes.',
                    'polarity' => 'positive',
                    'confidence' => 0.7,
                    'evidence_refs' => ['ci:run_4211'],
                ],
            ],
            'limitations' => ['flaky suite not quarantined yet'],
        ];

        $result = $this->panel()->run($analysis);

        $this->assertFalse($result['certified']);
        $this->assertSame('honesty_refused', $result['decision']);
        $this->assertContains('engineering_pass_rate_without_totals', $result['honesty']['reasons']);
        $this->assertContains('engineering_missing_tests_total', $result['honesty']['reasons']);
    }

    public function test_zero_limitations_analysis_is_refuted_by_uncertainty_lens(): void
    {
        $analysis = [
            'metric_family' => 'generic_claims',
            'claims' => [
                [
                    'id' => 'c1',
                    'text' => 'Everything is perfect.',
                    'polarity' => 'positive',
                    'confidence' => 0.8,
                    'evidence_refs' => ['doc:self_report'],
                ],
            ],
            // no limitations, no blind_spots: over-claiming by construction.
        ];

        $result = $this->panel()->run($analysis);

        $this->assertFalse($result['certified']);

        $uncertaintySeat = $this->seat($result, UncertaintyLensJudge::LENS);
        $this->assertSame('refute', $uncertaintySeat['verdict']);
        $this->assertContains('zero_uncertainty_over_claim', $uncertaintySeat['reasons']);
        $this->assertContains('zero_uncertainty_over_claim', $result['reasons']);
    }

    public function test_internal_contradiction_is_refuted_by_consistency_lens(): void
    {
        $analysis = [
            'metric_family' => 'generic_claims',
            'metrics' => ['latency_ms' => 10.0],
            'claims' => [
                [
                    'id' => 'c1',
                    'text' => 'Latency is low.',
                    'polarity' => 'positive',
                    'confidence' => 0.95,
                    'evidence_refs' => ['trace:t1'], // 1 ref for >0.9 confidence: evidence lens refutes too
                    'metrics' => ['latency_ms' => 42.0], // contradicts the top-level metric
                ],
            ],
            'conclusion' => ['claim_refs' => ['c1', 'c_missing']],
            'limitations' => ['only one load profile measured'],
        ];

        $result = $this->panel()->run($analysis);

        $this->assertFalse($result['certified']);
        $this->assertSame('majority_refute', $result['decision']);

        $consistencySeat = $this->seat($result, ConsistencyLensJudge::LENS);
        $this->assertSame('refute', $consistencySeat['verdict']);
        $this->assertContains('metric_value_contradiction:latency_ms', $consistencySeat['reasons']);
        $this->assertContains('conclusion_references_missing_claim:c_missing', $consistencySeat['reasons']);
    }

    public function test_two_of_three_accepts_with_honesty_pass_certifies(): void
    {
        // Honesty passes (generic: evidenced claims + limitation) and exactly
        // one lens (consistency) refutes via a verdict/polarity mismatch:
        // 2/3 accepts + honesty pass => certified under strict majority.
        $analysis = [
            'metric_family' => 'generic_claims',
            'verdict' => 'positive',
            'claims' => [
                [
                    'id' => 'c1',
                    'text' => 'One supportive observation.',
                    'polarity' => 'positive',
                    'confidence' => 0.6,
                    'evidence_refs' => ['note:n1'],
                ],
                [
                    'id' => 'c2',
                    'text' => 'A contrary observation.',
                    'polarity' => 'negative',
                    'confidence' => 0.6,
                    'evidence_refs' => ['note:n2'],
                ],
                [
                    'id' => 'c3',
                    'text' => 'Another contrary observation.',
                    'polarity' => 'negative',
                    'confidence' => 0.6,
                    'evidence_refs' => ['note:n3'],
                ],
            ],
            'limitations' => ['small observation set'],
        ];

        $result = $this->panel()->run($analysis);

        $this->assertTrue($result['honesty']['certified']);
        $this->assertSame(2, $result['panel']['accept_count']);
        $this->assertSame(1, $result['panel']['refute_count']);
        $this->assertSame(2, $result['panel']['majority_threshold']);
        $this->assertTrue($result['certified']);
        $this->assertSame('certified', $result['decision']);

        $consistencySeat = $this->seat($result, ConsistencyLensJudge::LENS);
        $this->assertSame('refute', $consistencySeat['verdict']);
        $this->assertContains('verdict_inconsistent_with_claim_polarity', $consistencySeat['reasons']);
    }

    public function test_unknown_metric_family_fails_closed(): void
    {
        $analysis = [
            'metric_family' => 'vibes_only',
            'claims' => [
                [
                    'id' => 'c1',
                    'text' => 'Trust me.',
                    'polarity' => 'positive',
                    'confidence' => 0.5,
                    'evidence_refs' => ['doc:somewhere'],
                ],
            ],
            'limitations' => ['none of this was measured'],
        ];

        $result = $this->panel()->run($analysis);

        $this->assertFalse($result['certified']);
        $this->assertSame('honesty_refused', $result['decision']);
        $this->assertContains('unknown_metric_family', $result['honesty']['reasons']);
        $this->assertSame('vibes_only', $result['honesty']['metric_family']);
    }

    public function test_cli_runs_temp_json_file_and_exits_zero(): void
    {
        $analysis = $this->honestFinancialAnalysis();
        $analysis['domain'] = 'finance';

        $path = tempnam(sys_get_temp_dir(), 'atlas_g6_analysis_');
        $this->assertIsString($path);
        file_put_contents($path, (string) json_encode($analysis));

        try {
            $exit = Artisan::call('atlas:domain:analyze', [
                '--input' => $path,
                '--json' => true,
            ]);
            $output = Artisan::output();

            $this->assertSame(0, $exit);
            $this->assertStringContainsString('"decision": "certified"', $output);
            $this->assertStringContainsString('"certified": true', $output);
            $this->assertStringContainsString('"domain_resolution"', $output);
        } finally {
            @unlink($path);
        }
    }

    public function test_cli_unreadable_input_exits_one(): void
    {
        $exit = Artisan::call('atlas:domain:analyze', [
            '--input' => '/nonexistent/path/analysis.json',
        ]);

        $this->assertSame(1, $exit);
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array{lens:string, verdict:string, reasons:list<string>}
     */
    private function seat(array $result, string $lens): array
    {
        foreach ($result['panel']['seats'] as $seat) {
            if ($seat['lens'] === $lens) {
                return $seat;
            }
        }

        $this->fail('Panel seat not found for lens: '.$lens);
    }
}
