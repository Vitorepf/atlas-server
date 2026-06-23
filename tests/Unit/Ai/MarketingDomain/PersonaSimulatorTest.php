<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\PersonaSimulator;
use PHPUnit\Framework\TestCase;

/**
 * Locks the persona-level simulation: instead of measuring markers (what the page HAS), the
 * simulator models the reaction of 3 skeptical personas (woman 40+ fatigued of dieting, ex-Ozempic
 * buyer, busy mom) — will_watch + will_close + first_objection + what_she_needs_next per persona.
 * It's the "I judge as the brutal panel" brief from the loop directive, cristalizado em código.
 */
class PersonaSimulatorTest extends TestCase
{
    private string $strong = 'For women over 40 who tried everything: it was never your willpower. '
        .'Three hormones quietly fall out of sync. Big Pharma hides it. As seen on CBS. Dr. Attia at Harvard. '
        .'Unlike Ozempic at $1,000 a month, no needle. 3 seconds in the morning, no diet, no gym.';

    private string $weak = 'Our amazing revolutionary supplement. Just take it. So easy. Transform your life!';

    public function test_strong_copy_wins_all_three_personas(): void
    {
        $sim = (new PersonaSimulator)->simulate($this->strong);

        foreach (['woman_40_diet_fatigue', 'ex_ozempic_buyer', 'busy_mom_no_time'] as $p) {
            $this->assertArrayHasKey($p, $sim);
            $this->assertGreaterThan(0.3, $sim[$p]['will_watch'], "{$p} should want to watch");
            $this->assertLessThan(0.4, $sim[$p]['will_close'], "{$p} should NOT close fast");
        }
    }

    public function test_weak_copy_loses_all_three_personas(): void
    {
        $sim = (new PersonaSimulator)->simulate($this->weak);

        foreach (['woman_40_diet_fatigue', 'ex_ozempic_buyer', 'busy_mom_no_time'] as $p) {
            $this->assertLessThan(0.3, $sim[$p]['will_watch'], "{$p} should NOT want to watch flat copy");
            $this->assertGreaterThan(0.4, $sim[$p]['will_close'], "{$p} should close flat copy fast");
        }
    }

    public function test_audience_score_separates_strong_from_weak(): void
    {
        $sim = new PersonaSimulator;
        $this->assertGreaterThan($sim->audienceScore($this->weak), $sim->audienceScore($this->strong));
    }

    public function test_each_persona_returns_concrete_next_action_hint(): void
    {
        $sim = (new PersonaSimulator)->simulate($this->weak);
        foreach ($sim as $p) {
            $this->assertNotEmpty($p['first_objection']);
            $this->assertNotEmpty($p['what_she_needs_next']);
        }
    }
}
