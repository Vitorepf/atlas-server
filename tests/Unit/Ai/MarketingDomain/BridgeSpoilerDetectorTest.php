<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\BridgeSpoilerDetector;
use PHPUnit\Framework\TestCase;

/**
 * Locks the single most important bridge-conversion guard: a bridge that feeds a long VSL must NOT
 * reveal the product name, physical form, named ingredients, price, scarcity numbers, or guarantee —
 * all of which the VSL withholds for its 30-40 minute warmup. Leaking them spoils the VSL and kills
 * conversion silently. This is the test that would have caught the delivered page's leak.
 */
class BridgeSpoilerDetectorTest extends TestCase
{
    /** @return array<int,array{term:string,category:string,severity:string,is_regex?:bool}> */
    private function catalog(): array
    {
        return [
            ['term' => 'Lipo Bliss', 'category' => 'product_name', 'severity' => 'critical'],
            ['term' => 'Triple Hormone Drops', 'category' => 'product_name', 'severity' => 'critical'],
            ['term' => 'the drops', 'category' => 'physical_form', 'severity' => 'critical'],
            ['term' => 'drop under the tongue', 'category' => 'physical_form', 'severity' => 'critical'],
            ['term' => 'Berberine', 'category' => 'named_ingredient', 'severity' => 'critical'],
            ['term' => 'Resveratrol', 'category' => 'named_ingredient', 'severity' => 'critical'],
            ['term' => '/\$\s?\d{1,4}\b/', 'category' => 'price', 'severity' => 'high', 'is_regex' => true],
            ['term' => '60-day money-back', 'category' => 'guarantee', 'severity' => 'medium'],
        ];
    }

    public function test_leaky_bridge_is_flagged_as_spoiling_the_vsl(): void
    {
        $leaky = 'The Lipo Bliss Triple Hormone Drops are four ingredients — Berberine and Resveratrol — '
            .'one drop under the tongue. Normally $97, today $49. Backed by a 60-day money-back guarantee.';
        $r = (new BridgeSpoilerDetector)->inspect($leaky, $this->catalog());

        $this->assertSame('spoils_the_vsl', $r['verdict']);
        $this->assertSame('critical', $r['worst']);
        $cats = array_column($r['leaks'], 'category');
        $this->assertContains('product_name', $cats);
        $this->assertContains('physical_form', $cats);
        $this->assertContains('named_ingredient', $cats);
        $this->assertContains('price', $cats);
    }

    public function test_warmup_only_bridge_is_clean(): void
    {
        $warmup = 'If you are a woman over 40 who tried everything, it was never your willpower. '
            .'Three fat-burning hormones quietly fall out of sync after 40. Big Pharma would rather you '
            .'never connect the dots. Four natural ingredients, no injection, no Ozempic. '
            .'Watch the free presentation before it is taken offline.';
        $r = (new BridgeSpoilerDetector)->inspect($warmup, $this->catalog());

        $this->assertSame('clean', $r['verdict']);
        $this->assertSame(0, $r['n']);
    }

    public function test_count_of_ingredients_is_allowed_naming_is_not(): void
    {
        $countOnly = 'A mixture of four natural ingredients that recreates the effect at home.';
        $named = 'A mixture with Berberine and Resveratrol.';

        $this->assertSame(0, (new BridgeSpoilerDetector)->inspect($countOnly, $this->catalog())['n']);
        $this->assertGreaterThan(0, (new BridgeSpoilerDetector)->inspect($named, $this->catalog())['n']);
    }

    public function test_transcript_stamps_the_reveal_second(): void
    {
        // ~16 chars/sec → "Berberine" at char ~1610 ≈ 100s ≈ 1:40
        $transcript = str_repeat('warmup story about the struggle and the hidden cause. ', 31).' Berberine is the first ingredient.';
        $r = (new BridgeSpoilerDetector)->inspect('It contains Berberine.', $this->catalog(), $transcript);

        $leak = collect($r['leaks'])->firstWhere('category', 'named_ingredient');
        $this->assertNotNull($leak);
        $this->assertNotNull($leak['vsl_reveals_at_seconds']);
        $this->assertGreaterThan(60, $leak['vsl_reveals_at_seconds']);
    }

    public function test_each_leak_carries_a_concrete_fix(): void
    {
        $r = (new BridgeSpoilerDetector)->inspect('Try the drops with Berberine for $49.', $this->catalog());
        foreach ($r['leaks'] as $l) {
            $this->assertNotEmpty($l['fix']);
            $this->assertContains($l['severity'], ['critical', 'high', 'medium', 'low']);
        }
    }
}
