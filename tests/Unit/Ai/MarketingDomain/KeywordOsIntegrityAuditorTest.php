<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\KeywordOsIntegrityAuditor;
use PHPUnit\Framework\TestCase;

/**
 * Locks the self-audit: the OS proves its own output is hole-free and contradiction-free every run.
 * A clean dossier passes; each injected violation is caught with a precise reason.
 */
class KeywordOsIntegrityAuditorTest extends TestCase
{
    private KeywordOsIntegrityAuditor $a;

    protected function setUp(): void
    {
        $this->a = new KeywordOsIntegrityAuditor;
    }

    public function test_clean_dossier_passes(): void
    {
        $run = [
            'regimes' => [
                'harvest' => [['keyword' => 'orivelle']],
                'seed' => [['keyword' => 'gelatin trick']],
                'probe' => [],
                'cross_negatives' => ['by_regime' => [
                    'harvest' => ['negatives' => [['term' => 'gelatin trick']]],
                    'seed' => ['negatives' => [['term' => 'orivelle']]],
                ]],
            ],
            'revenue_ranking' => [
                'ranked' => [['keyword' => 'orivelle'], ['keyword' => 'gelatin trick']],
                'vital_few' => ['vital_few' => [['keyword' => 'orivelle']]],
            ],
            'budget_portfolio' => ['portfolio' => [['keyword' => 'orivelle', 'captured_profit' => 100]]],
            'launch_selection' => ['recommended' => [['keyword' => 'orivelle']]],
            'negatives' => ['list' => ['free']],
            'campaign_blueprint' => ['campaigns' => [['ad_groups' => [['keywords' => [['keyword' => 'orivelle']]]]]]],
        ];
        $r = $this->a->audit($run);
        $this->assertTrue($r['ok'], implode(' | ', $r['violations']));
        $this->assertContains('I1_recomendada_vs_negada', $r['checked']);
    }

    public function test_catches_recommended_and_negated_contradiction(): void
    {
        $run = [
            'launch_selection' => ['recommended' => [['keyword' => 'gelatin trick']]],
            'negatives' => ['list' => ['gelatin trick']],
        ];
        $r = $this->a->audit($run);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('I1', $r['violations'][0]);
    }

    public function test_catches_money_loser_in_portfolio_and_self_block(): void
    {
        $loser = $this->a->audit(['budget_portfolio' => ['portfolio' => [['keyword' => 'x', 'captured_profit' => -5]]]]);
        $this->assertFalse($loser['ok']);
        $this->assertStringContainsString('I4', implode(' ', $loser['violations']));

        $selfBlock = $this->a->audit([
            'regimes' => ['harvest' => [['keyword' => 'orivelle']], 'cross_negatives' => ['by_regime' => ['harvest' => ['negatives' => [['term' => 'orivelle']]]]]],
        ]);
        $this->assertFalse($selfBlock['ok']);
        $this->assertStringContainsString('I5', implode(' ', $selfBlock['violations']));
    }
}
