<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\BlackinkNegativeMiner;
use PHPUnit\Framework\TestCase;

/**
 * Locks the real-money negative miner: n-grams that recur in losing terms (traffic, zero sale) and NEVER
 * appear in a converting term become negative candidates, ranked by wasted clicks. The pétreo safety rule —
 * never block a token that ever converted — is what keeps it from killing the campeã.
 */
class BlackinkNegativeMinerTest extends TestCase
{
    private BlackinkNegativeMiner $m;

    protected function setUp(): void
    {
        $this->m = new BlackinkNegativeMiner;
    }

    private function rows(): array
    {
        return [
            ['term' => 'gelatin trick', 'clicks' => 500, 'conversions' => 10],     // WINNER → protege 'gelatin','trick'
            ['term' => 'gelatin recipe', 'clicks' => 200, 'conversions' => 0],     // loser
            ['term' => 'pink gelatin recipe', 'clicks' => 150, 'conversions' => 0], // loser ('recipe' recorre)
            ['term' => 'free gelatin sample', 'clicks' => 100, 'conversions' => 0], // loser ('free' aparece 1x)
        ];
    }

    public function test_recurring_loser_ngram_becomes_negative(): void
    {
        $r = $this->m->mine($this->rows(), minWastedClicks: 50, loserMinClicks: 30);
        $ngrams = array_column($r['negatives'], 'ngram');
        $this->assertContains('recipe', $ngrams, "'recipe' recorre em losers e nunca vendeu → negativo");
    }

    public function test_never_blocks_a_token_that_converted(): void
    {
        $r = $this->m->mine($this->rows());
        $ngrams = array_column($r['negatives'], 'ngram');
        $this->assertNotContains('gelatin', $ngrams, "'gelatin' vendeu (gelatin trick) → JAMAIS negativo");
        $this->assertNotContains('trick', $ngrams);
        $this->assertGreaterThan(0, $r['protected_count']);
    }

    public function test_bigram_of_a_multiword_winner_is_protected(): void
    {
        // regressão do bug real (achado na validação): 'trick recipe' é bigrama de "gelatin trick recipe"
        // (vendeu 4×) E aparece em losers; a trava TEM que protegê-lo mesmo sendo termo de 3 palavras.
        $rows = [
            ['term' => 'gelatin trick recipe', 'clicks' => 700, 'conversions' => 4], // WINNER 3 palavras
            ['term' => 'free trick recipe', 'clicks' => 400, 'conversions' => 0],
            ['term' => 'easy trick recipe', 'clicks' => 300, 'conversions' => 0],
        ];
        $ngrams = array_column($this->m->mine($rows)['negatives'], 'ngram');
        $this->assertNotContains('trick recipe', $ngrams, "bigrama de winner de 3 palavras → JAMAIS negativo");
        $this->assertNotContains('recipe', $ngrams);
    }

    public function test_single_occurrence_is_not_negative(): void
    {
        $r = $this->m->mine($this->rows());
        $ngrams = array_column($r['negatives'], 'ngram');
        $this->assertNotContains('sample', $ngrams, 'aparece em 1 só termo → não negativar por fluke');
    }

    public function test_deterministic_order_by_waste(): void
    {
        $a = $this->m->mine($this->rows());
        $b = $this->m->mine(array_reverse($this->rows()));
        $this->assertSame(array_column($a['negatives'], 'ngram'), array_column($b['negatives'], 'ngram'));
    }
}
