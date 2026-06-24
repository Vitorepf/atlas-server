<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\PhoneticMistypeForge;
use PHPUnit\Framework\TestCase;

/**
 * Locks the #1 leaked-money vein from the real-sales dissection: the phonetic mistype cone of a coined name
 * must contain the variants that ACTUALLY sold (orvelle, orville from orivelle — 473 real sales the OS
 * generated zero of). Deterministic + dedup + excludes the original.
 */
class PhoneticMistypeForgeTest extends TestCase
{
    private PhoneticMistypeForge $f;

    protected function setUp(): void
    {
        $this->f = new PhoneticMistypeForge;
    }

    public function test_cone_captures_the_real_winning_mistypes(): void
    {
        $cone = $this->f->cone('orivelle');
        $this->assertContains('orvelle', $cone, 'orvelle vendeu 335× — tem que estar no cone');
        $this->assertContains('orville', $cone, 'orville vendeu 138× — edit-2 fonético');
        $this->assertNotContains('orivelle', $cone, 'nunca o original');
    }

    public function test_forge_crosses_cone_with_suffixes_mutating_only_the_name(): void
    {
        $r = $this->f->forge('orivelle', ['fungus pen', 'nail pen']);
        $this->assertContains('orvelle nail pen', $r, 'espelha o dado real (orvelle nail pen vendeu 335×)');
        $this->assertContains('orville fungus pen', $r, 'orville fungus pen vendeu 138×');
    }

    public function test_deterministic_and_ignores_junk(): void
    {
        $this->assertSame($this->f->cone('orivelle'), $this->f->cone('orivelle'));
        $this->assertSame([], $this->f->cone('abc'), 'curto demais → sem cone');
        $this->assertSame([], $this->f->cone('glp1 at home'), 'não-alfabético/multi-token → sem cone');
    }
}
