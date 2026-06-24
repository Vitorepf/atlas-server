<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Campaign\KeywordQualityIndex;
use App\Services\Ai\MarketingDomain\Campaign\QualifiedKeywordDossier;
use App\Services\Ai\MarketingDomain\Campaign\QualifiedKeywordPatternEngine;
use PHPUnit\Framework\TestCase;

/**
 * Locks the launch selection: from the scored keywords, route investible+low-risk → recommended, the
 * proven money-losers → rejected, and the account-death risks → a SEPARATE operator-decides bucket
 * (never silently dropped — SINAL, not freio).
 */
class QualifiedKeywordDossierTest extends TestCase
{
    private function row(string $kw, int $score, string $verdict, string $risk): array
    {
        return [
            'keyword' => $kw,
            'score' => $score,
            'family' => 'mechanism_trick',
            'match_type' => 'exact/phrase',
            'intent' => ['tier' => 'T4'],
            'investment' => ['verdict' => $verdict, 'basis' => 'forecast_prior'],
            'mind_state' => ['awareness' => 'most_aware', 'page_angle' => 'direct_offer_reminder'],
            'account_risk' => ['risk_level' => $risk, 'flags' => $risk === 'high' ? [['type' => 'restricted_drug']] : []],
        ];
    }

    public function test_partitions_into_recommended_high_risk_and_rejected(): void
    {
        $scored = [
            $this->row('a', 90, 'investimento', 'none'),
            $this->row('b', 80, 'gasto', 'none'),
            $this->row('c', 85, 'teste', 'high'),
            $this->row('d', 70, 'investimento', 'none'),
        ];

        $r = (new QualifiedKeywordDossier)->select($scored, 2);

        $recKw = array_column($r['recommended'], 'keyword');
        $this->assertContains('a', $recKw);
        $this->assertContains('d', $recKw);
        $this->assertNotContains('b', $recKw); // gasto → rejected
        $this->assertNotContains('c', $recKw); // high risk → operator-decides bucket

        $this->assertSame('b', $r['rejected'][0]['keyword']);
        $this->assertSame('c', $r['high_risk'][0]['keyword']);
        $this->assertTrue($r['enough']);
    }

    public function test_end_to_end_routes_drug_hero_to_operator_decides_not_dropped(): void
    {
        $asset = new AiMarketingVslAsset([
            'mechanism_name' => 'Triple Hormone Drops Protocol',
            'trick' => 'at-home retatrutide protocol',
            'niche' => 'weight loss',
            'power_phrases' => ['four simple ingredients', 'activates three fat-burning hormones'],
            'offer' => ['product_name' => 'Lipo Bliss'],
        ]);
        $engine = (new QualifiedKeywordPatternEngine)->build($asset);
        $scored = (new KeywordQualityIndex)->scoreEngineResult($engine, $asset, ['payout' => 120, 'cvr' => 0.012])['scored'];

        $r = (new QualifiedKeywordDossier)->select($scored, 5);

        // the retatrutide-bearing keywords are investible but account-death risks → surfaced, not dropped.
        $highRiskKw = array_column($r['high_risk'], 'keyword');
        $this->assertNotEmpty($highRiskKw, 'drug-bearing owned roots must be surfaced for the operator to decide');
        foreach ($highRiskKw as $kw) {
            $this->assertStringContainsString('retatrutide', $kw);
        }
        // nothing is lost: every scored keyword lands in exactly one bucket.
        $total = count($r['recommended']) + count($r['high_risk']) + count($r['rejected']);
        $this->assertSame(count($scored), $total);
    }
}
