<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\CelebrityLaneForge;
use PHPUnit\Framework\TestCase;

/**
 * Locks the #3 rule: celebrity × domain × POSSESSION-noun is the highest-CVR lane (sanjay gupta brain health
 * supplement 36.14%). Never an INFORMATION suffix (dr oz jello recipe 1.93%). Less-saturated celebrity first
 * (sanjay 36% vs dr oz, a generic-recipe term). Deterministic.
 */
class CelebrityLaneForgeTest extends TestCase
{
    private CelebrityLaneForge $f;

    protected function setUp(): void
    {
        $this->f = new CelebrityLaneForge;
    }

    public function test_generates_celebrity_domain_possession_matrix(): void
    {
        $rows = $this->f->forge(['Sanjay Gupta'], ['brain'], ['supplement', 'pill']);
        $kws = array_column($rows, 'keyword');
        $this->assertContains('sanjay gupta brain supplement', $kws);
        $this->assertContains('sanjay gupta brain pill', $kws);
    }

    public function test_never_generates_information_suffix(): void
    {
        $rows = $this->f->forge(['Dr Sanjay Gupta'], ['memory']);
        foreach (array_column($rows, 'keyword') as $kw) {
            $this->assertStringNotContainsString('recipe', $kw, 'lane de celebridade nunca gera sufixo de informação');
            $this->assertStringNotContainsString('free', $kw);
            $this->assertStringNotContainsString('trick', $kw);
        }
    }

    public function test_saturated_celebrity_is_flagged_and_deprioritized(): void
    {
        $rows = $this->f->forge(['Dr Oz', 'Sanjay Gupta'], ['brain'], ['supplement']);
        // a menos saturada (sanjay) vem primeiro; dr oz marcado saturated
        $this->assertSame('sanjay gupta', $rows[0]['celebrity'], 'celebridade menos saturada lidera (aposta de CVR alto)');
        $drOz = array_values(array_filter($rows, fn ($r) => $r['celebrity'] === 'dr oz'));
        $this->assertTrue($drOz[0]['saturated']);
    }

    public function test_no_domain_still_makes_celebrity_possession(): void
    {
        $rows = $this->f->forge(['Sanjay Gupta'], [], ['supplement']);
        $this->assertSame('sanjay gupta supplement', $rows[0]['keyword']);
    }
}
