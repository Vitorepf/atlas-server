<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\ReFinderFabricationPlanner;
use PHPUnit\Framework\TestCase;

/**
 * Locks the moat execution (#8): planting a coined token must come WITH pre-owning its entire search
 * neighborhood (exact + mistype cone + descriptor matrix) so the fabricated future search is won alone.
 * Plus the plant plan (recall) and the track key (attribute the re-find to the advertorial). Deterministic.
 */
class ReFinderFabricationPlannerTest extends TestCase
{
    private ReFinderFabricationPlanner $p;

    protected function setUp(): void
    {
        $this->p = new ReFinderFabricationPlanner;
    }

    public function test_pre_owns_the_whole_search_neighborhood_of_the_token(): void
    {
        $plan = $this->p->plan('orivelle', ['categories' => ['fungus'], 'forms' => ['pen']]);

        $this->assertTrue($plan['plantable']);
        $this->assertSame('orivelle', $plan['own_now']['exact']);
        // o cone de mistype é pré-possuído (orvelle vendeu 335× num leilão virgem)
        $this->assertContains('orvelle', $plan['own_now']['mistype_cone']);
        // a matriz de descritor (orivelle anti fungal pen 37%) também
        $this->assertNotEmpty($plan['own_now']['descriptor_matrix']);
        $this->assertGreaterThan(1, $plan['own_now']['count']);
    }

    public function test_plant_plan_and_track_key_are_present(): void
    {
        $plan = $this->p->plan('blue salt trick');
        $this->assertGreaterThanOrEqual(3, $plan['plant']['seed_count']);
        $this->assertNotEmpty($plan['plant']['positions']);
        $this->assertSame('blue salt trick', $plan['track']['branded_search_key']);
        // o head do cone é a palavra mais longa/coinável ("trick" tem 5; "blue"/"salt" 4) — pega a mais longa
        $this->assertNotEmpty($plan['own_now']['mistype_cone']);
    }

    public function test_empty_token_is_not_plantable_and_deterministic(): void
    {
        $this->assertFalse($this->p->plan('')['plantable']);
        $this->assertSame($this->p->plan('orivelle'), $this->p->plan('orivelle'));
    }
}
