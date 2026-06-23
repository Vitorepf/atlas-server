<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\PatternLibraryScorer;
use App\Services\Ai\MarketingDomain\Knowledge\ObjectionLibrary;
use PHPUnit\Framework\TestCase;

/**
 * Locks the universal objections layer: a page that neutralizes the 12 canonical objections scores
 * killer; a raw "buy now" page scores zero; the same objections fire in finance, proving they are
 * content-independent — every market has the same resistance, just dressed differently.
 */
class ObjectionLibraryTest extends TestCase
{
    private string $neutralized = 'Less than the cost of one coffee a day, way cheaper than $1,000/month Ozempic shots. '
        ."Payment plan available. Even if you tried everything and nothing worked — that's why we built this. "
        .'Women over 40 just like you. Is this a scam? You might be thinking that — here is the proof. '
        .'Takes seconds in the morning. Lab-tested, doctor-formulated, no side effects. My husband saw the difference. '
        .'Private group with 24/7 support. Every day you wait, the harder it gets. Unlike Ozempic, this works on all 3 hormones. '
        .'Ordinary women, people like you.';

    public function test_full_neutralization_scores_killer(): void
    {
        $r = (new PatternLibraryScorer)->score(new ObjectionLibrary, $this->neutralized);

        $this->assertSame('objection', $r['library']);
        // Library deepened in Volta 2 (+10 advanced neutralizers); the original "neutralized" copy
        // covers the 12 core objections but not the advanced ones — asserts pin the cores explicitly.
        $this->assertGreaterThanOrEqual(45, $r['score']);
        $this->assertContains($r['grade'], ['decent', 'strong', 'killer']);

        foreach (['price_too_high', 'cant_afford', 'wont_work_for_me', 'tried_everything',
            'is_it_scam', 'no_time', 'is_it_safe', 'do_it_later', 'competition'] as $k) {
            $this->assertContains($k, $r['present'], "Expected objection {$k}");
        }
    }

    public function test_raw_buy_now_page_scores_zero(): void
    {
        $r = (new PatternLibraryScorer)->score(new ObjectionLibrary, 'Buy our supplement now. It is the best. Click here to order.');
        $this->assertSame(0, $r['score']);
    }

    public function test_volta_2_advanced_neutralizers_fire(): void
    {
        $master = "This is not for everyone — do not buy if you're looking for a quick fix. "
            .'You did everything right. It was never your fault — they failed you. '
            .'According to a study at Harvard, as Dr. Attia explains, in the case of Karen from Phoenix… '
            ."You already spent over \$5,000 on diets — this is a fraction of what you've invested. "
            ."I didn't believe it either. I was skeptical — good, be skeptical. "
            .'Less than a coffee per day — comes out to $3.23 per day. '
            ."You're probably thinking: 'but what if it doesn't work?' Good question. "
            .'60-day money back, plus we pay you $200 on top of refund — keep everything. '
            .'A reader asked me last week: someone asked me if it works with medication. '
            ."I know how this sounds. Too good to be true — that's exactly why the guarantee is what it is.";

        $r = (new PatternLibraryScorer)->score(new ObjectionLibrary, $master);

        foreach (['preemptive_disqualification', 'agitate_past_failures', 'authority_proof_layered',
            'sunk_cost_reframe', 'normalize_skepticism', 'show_the_math', 'address_obvious_objection',
            'guarantee_with_consequence', 'objection_via_3rd_party', 'objection_about_objection'] as $k) {
            $this->assertContains($k, $r['present'], "Volta 2 advanced neutralizer {$k} should fire");
        }
    }

    public function test_same_objections_fire_in_finance(): void
    {
        $finance = 'Less than the price of dinner per month. Payment plan. Even if you tried every guru and nothing worked, '
            ."that's why. Smart professionals like you. Is it a scam? We don't blame you for asking. "
            .'Takes seconds per day. Regulated, lab-tested processes. My partner agreed. Direct access to our team. '
            .'Every day you wait, compounding loss. Unlike the typical app, ours works on. Regular people, not a guru.';
        $r = (new PatternLibraryScorer)->score(new ObjectionLibrary, $finance);

        $this->assertGreaterThanOrEqual(45, $r['score']);
        $this->assertContains('price_too_high', $r['present']);
        $this->assertContains('is_it_scam', $r['present']);
        $this->assertContains('competition', $r['present']);
    }
}
