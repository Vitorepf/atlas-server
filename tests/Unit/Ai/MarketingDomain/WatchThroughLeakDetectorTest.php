<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\WatchThroughLeakDetector;
use PHPUnit\Framework\TestCase;

/**
 * WatchThroughLeakDetector — the honest sliver salvaged after the brutal panel killed the watch-through
 * QUALITY score as a vocabulary proxy. This detector emits ONLY true-positive structural warnings
 * (reveal/hard-CTA leaked in the opening). It produces no quality score, so — unlike the reverted
 * scorer — there is nothing to game: empty copy earns neither a flaw nor praise.
 */
class WatchThroughLeakDetectorTest extends TestCase
{
    private string $leaked = 'The secret is a two-minute morning ritual — buy now to get it. Here is how '
        .'it works: you do it daily. It is called the method. It resets three hormones. It works for '
        .'everyone. Order now. It is simple. That is all you need to know about it today.';

    private string $held = 'Have you ever wondered why nothing works after 40? I spent three years '
        .'gaining weight no matter what I ate. My doctor found something most women are never told. '
        .'Three hormones quietly fall out of rhythm, and it is not willpower. The standard advice makes '
        .'it worse. After months of testing I found a pattern in the women who recovered. Here is how a '
        .'simple morning ritual nudges those hormones back. That is the mechanism. Click below to watch.';

    public function test_flags_a_reveal_leaked_in_the_opening(): void
    {
        $r = (new WatchThroughLeakDetector)->detect($this->leaked);
        $keys = array_column($r['flaws'], 'key');
        $this->assertContains('premature_reveal', $keys);
    }

    public function test_flags_a_hard_cta_in_the_opening(): void
    {
        $r = (new WatchThroughLeakDetector)->detect($this->leaked);
        $keys = array_column($r['flaws'], 'key');
        $this->assertContains('premature_hard_cta', $keys);
    }

    public function test_well_structured_copy_has_no_leaks(): void
    {
        $r = (new WatchThroughLeakDetector)->detect($this->held);
        $this->assertTrue($r['assessed']);
        $this->assertSame([], $r['flaws'], 'copy that holds the reveal + CTA late must not be flagged');
    }

    public function test_fuzzy_matches_paraphrased_reveal(): void
    {
        $r = (new WatchThroughLeakDetector)->detect(
            'Here is exactly how it works. Take it daily. It does the thing. It helps people. '
            .'It is simple. It is effective. You will like it. Many use it. Consider it now.'
        );
        $this->assertContains('premature_reveal', array_column($r['flaws'], 'key'));
    }

    /**
     * The anti-Goodhart property the reverted scorer lacked: there is NO positive quality score, so
     * empty/elite copy without leak phrases simply gets no flaws — it is never CALLED good. Nothing to
     * invert: padding cannot out-rank real copy because the detector never ranks.
     */
    public function test_makes_no_positive_claim_about_empty_or_clean_copy(): void
    {
        $d = new WatchThroughLeakDetector;

        $emptyFiller = 'The weather is nice today. Time passes as it always has. Things happen. '
            .'People exist. Days go by. The sky is blue. Cats are mammals. Water is wet. It is what it is.';
        $eliteImplicit = 'My hands shook as I read the lab result a second time. I had been told this was '
            .'impossible. The number on the page said otherwise. I called the only person who might know. '
            .'What she told me changed everything I believed about my own body. I am still not over it.';

        // Neither is flagged (no leak present) — and neither receives any "gripping"/quality claim,
        // because none exists. The output is warnings-only.
        $this->assertSame([], $d->detect($emptyFiller)['flaws']);
        $this->assertSame([], $d->detect($eliteImplicit)['flaws']);
        $this->assertArrayNotHasKey('score', $d->detect($emptyFiller));
    }

    public function test_too_short_copy_is_not_assessed(): void
    {
        $r = (new WatchThroughLeakDetector)->detect('Buy now. The secret is X.');
        $this->assertFalse($r['assessed']);
        $this->assertSame([], $r['flaws']);
    }
}
