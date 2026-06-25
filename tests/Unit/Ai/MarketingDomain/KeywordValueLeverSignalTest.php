<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\KeywordKnowledgeCore;
use App\Services\Ai\MarketingDomain\Campaign\KeywordValueLeverSignal;
use PHPUnit\Framework\TestCase;

/**
 * Locks Hormozi's value equation operationalized: the keyword reveals the dominant desire-lever the ad/page
 * must lead with. Deterministic. The canonical law is asserted present.
 */
class KeywordValueLeverSignalTest extends TestCase
{
    private KeywordValueLeverSignal $s;

    protected function setUp(): void
    {
        $this->s = new KeywordValueLeverSignal;
    }

    public function test_detects_dominant_lever(): void
    {
        $cases = [
            'lose weight fast overnight' => 'time',
            'lose weight at home no exercise' => 'effort',
            'clinically proven weight loss that works' => 'likelihood',
            'reverse diabetes permanently' => 'dream',
        ];
        foreach ($cases as $kw => $lever) {
            $this->assertSame($lever, $this->s->assess($kw)['dominant'], $kw);
            $this->assertNotEmpty($this->s->assess($kw)['copy_directive']);
        }
    }

    public function test_no_lever_returns_none(): void
    {
        $r = $this->s->assess('orivelle nail pen');
        $this->assertSame('none', $r['dominant']);
        $this->assertSame([], $r['levers']);
    }

    public function test_value_equation_law_is_canonical(): void
    {
        $law = (new KeywordKnowledgeCore)->cite('hormozi-value-equation');
        $this->assertNotNull($law);
        $this->assertStringContainsString('Hormozi', $law['statement']);
    }
}
