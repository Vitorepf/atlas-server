<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\KeywordClusterer;
use App\Services\Ai\MarketingDomain\Campaign\KeywordUniverseEnumerator;
use PHPUnit\Framework\TestCase;

/**
 * Locks the L7 clustering: the universe is grouped into single-theme (STAG) ad groups deterministically,
 * with barbell match-type and the hero isolated — and SEM BURACOS (every keyword in exactly one cluster).
 */
class KeywordClustererTest extends TestCase
{
    private KeywordClusterer $c;

    private function rows(): array
    {
        return (new KeywordUniverseEnumerator)->enumerate(['blue salt trick', 'salt trick'])['keywords'];
    }

    protected function setUp(): void
    {
        $this->c = new KeywordClusterer;
    }

    public function test_no_holes_every_keyword_in_exactly_one_cluster(): void
    {
        $rows = $this->rows();
        $r = $this->c->cluster($rows);
        $this->assertTrue($r['no_holes']);

        $assigned = [];
        foreach ($r['clusters'] as $cluster) {
            foreach ($cluster['keywords'] as $kw) {
                $this->assertArrayNotHasKey($kw, $assigned, "keyword em 2 clusters: {$kw}");
                $assigned[$kw] = true;
            }
        }
        $uniqueInput = array_unique(array_column($rows, 'keyword'));
        $this->assertSame(count($uniqueInput), count($assigned));
    }

    public function test_clusters_are_single_theme_with_barbell_match_type(): void
    {
        foreach ($this->c->cluster($this->rows())['clusters'] as $cluster) {
            // single root + single tier per ad group (STAG)
            $this->assertNotSame('', $cluster['root']);
            $this->assertMatchesRegularExpression('/^T\d$/', $cluster['tier']);
            if ($cluster['tier'] === 'T4') {
                $this->assertSame('exact/phrase', $cluster['match_type']);
                $this->assertTrue($cluster['is_hero']);
            } else {
                $this->assertSame('phrase', $cluster['match_type']);
            }
        }
    }

    public function test_deterministic_and_order_independent(): void
    {
        $rows = $this->rows();
        $a = $this->c->cluster($rows);
        $shuffled = array_reverse($rows);
        $b = $this->c->cluster($shuffled);
        $this->assertSame(
            array_column($a['clusters'], 'theme'),
            array_column($b['clusters'], 'theme'),
            'mesma topologia independente da ordem de entrada',
        );
    }
}
