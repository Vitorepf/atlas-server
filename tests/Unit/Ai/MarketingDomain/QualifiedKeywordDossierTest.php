<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Campaign\KeywordQualityIndex;
use App\Services\Ai\MarketingDomain\Campaign\QualifiedKeywordDossier;
use App\Services\Ai\MarketingDomain\Campaign\QualifiedKeywordPatternEngine;
use PHPUnit\Framework\TestCase;

/**
 * Locks the launch selection. POR PADRÃO o motor RECOMENDA a malícia agressiva — keyword de droga/evasiva
 * (a inteligência do Atlas) entra em `recommended`, NUNCA é quarentenada. Risco-de-conta/compliance é um
 * FLUXO SEPARADO que o operador invoca (compliance_mode=true) — só aí a quarentena acontece.
 * Constituição: [[atlas-no-moral-limit-in-engine]] / [[white-page-separate-explicit]].
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

    public function test_default_recommends_the_aggressive_malice_no_quarantine(): void
    {
        $scored = [
            $this->row('a', 90, 'investimento', 'none'),
            $this->row('b', 80, 'gasto', 'none'),
            $this->row('c', 85, 'teste', 'high'),  // keyword evasiva/de droga = a malícia do Atlas
            $this->row('d', 70, 'investimento', 'none'),
        ];

        $r = (new QualifiedKeywordDossier)->select($scored, 2); // default: SEM compliance

        $recKw = array_column($r['recommended'], 'keyword');
        $this->assertContains('a', $recKw);
        $this->assertContains('d', $recKw);
        $this->assertContains('c', $recKw, 'a keyword agressiva/de-risco é RECOMENDADA por padrão, não quarentenada');
        $this->assertNotContains('b', $recKw);   // gasto → rejected (decisão de NEGÓCIO, não moral)
        $this->assertSame('b', $r['rejected'][0]['keyword']);
        $this->assertSame([], $r['high_risk'], 'nada quarentenado sem o operador pedir compliance');
    }

    public function test_compliance_mode_is_the_separate_flow_that_quarantines(): void
    {
        $scored = [
            $this->row('a', 90, 'investimento', 'none'),
            $this->row('c', 85, 'teste', 'high'),
        ];

        $r = (new QualifiedKeywordDossier)->select($scored, 1, ['compliance_mode' => true]);

        $this->assertNotContains('c', array_column($r['recommended'], 'keyword'));
        $this->assertSame('c', $r['high_risk'][0]['keyword'], 'no fluxo de compliance que o operador invoca, o risco vai pro hold');
    }

    public function test_nothing_is_lost_every_keyword_lands_in_one_bucket(): void
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

        // default: as keywords de retatrutide (a malícia) entram em recommended, NÃO quarentenadas
        $r = (new QualifiedKeywordDossier)->select($scored, 5);
        $this->assertSame([], $r['high_risk'], 'malícia agressiva é recomendada por padrão');
        $recKw = array_column($r['recommended'], 'keyword');
        $this->assertNotEmpty(array_filter($recKw, fn ($k) => str_contains($k, 'retatrutide')), 'as owned-roots de droga estão em recommended');

        $total = count($r['recommended']) + count($r['high_risk']) + count($r['rejected']);
        $this->assertSame(count($scored), $total, 'nada se perde: cada keyword num balde');
    }
}
