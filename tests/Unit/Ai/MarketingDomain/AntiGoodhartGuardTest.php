<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\AntiGoodhartGuard;
use PHPUnit\Framework\TestCase;

/**
 * Locks the anti-Goodhart guard: copy that GAMES markers (buzzword soup, claims without proof,
 * placeholders, AI tells, keyword stuffing, vague intensifiers, naked CTA, adjective overload)
 * scores high hollowness; substantive copy with concrete scenes + cited proof scores zero. This is
 * the guardrail that keeps the amplifier from optimizing for marker count instead of conversion.
 */
class AntiGoodhartGuardTest extends TestCase
{
    private string $hollow = 'Our amazing revolutionary product is incredible! 90% improvement! 5x better! '
        ."Lorem ipsum [YOUR HEADLINE]. As an AI language model, let's delve into this tapestry. "
        .'Click here to buy now! Very effective. Really good. Extremely powerful. '
        .'Effective effective effective effective effective effective! '
        .'Beautiful wonderful effective comfortable able powerful incredible useful.';

    private string $solid = 'It was 3:47am when Karen, 47, in Phoenix saw the scale finally move. '
        .'A study at Harvard published in NEJM showed 41 lbs lost in 12 weeks. '
        .'Dr. Attia confirmed the mechanism. Watch the free presentation to see the protocol.';

    public function test_hollow_copy_scores_fraudulent_with_multiple_flags(): void
    {
        $r = (new AntiGoodhartGuard)->inspect($this->hollow);

        $this->assertGreaterThanOrEqual(75, $r['hollowness']);
        $this->assertContains($r['grade'], ['hollow', 'fraudulent']);
        $this->assertGreaterThanOrEqual(4, count($r['flags']));

        $keys = array_column($r['flags'], 'key');
        foreach (['placeholder_residue', 'ai_tells', 'keyword_stuffing', 'naked_cta'] as $k) {
            $this->assertContains($k, $keys, "Expected flag {$k}");
        }
    }

    public function test_substantive_copy_scores_zero(): void
    {
        $r = (new AntiGoodhartGuard)->inspect($this->solid);

        $this->assertSame(0, $r['hollowness']);
        $this->assertSame('substantive', $r['grade']);
        $this->assertEmpty($r['flags']);
    }

    public function test_claims_without_proof_caught_when_no_authority_cited(): void
    {
        $copy = 'You will see 95% improvement. 3x faster. 200x more effective. Just click here.';
        $r = (new AntiGoodhartGuard)->inspect($copy);

        $keys = array_column($r['flags'], 'key');
        $this->assertContains('claims_without_proof', $keys);
    }
}
