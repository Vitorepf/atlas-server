<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\Aaeos\Control\AaeosSelfEvolutionQualityLoop;
use App\Services\Ai\Rivals\Core\RivalsCurriculumLadder;
use Tests\TestCase;

/**
 * P2g-EVOL: proxy-only evolution (LOC/test-count/landing-rate) is refused.
 */
final class AaeosSelfEvolutionNoProxyCleanupTasksTest extends TestCase
{
    public function test_proxy_cleanup_intent_is_refused(): void
    {
        $admit = AaeosSelfEvolutionQualityLoop::admitIntent([
            'kind' => 'proxy_cleanup',
            'objective' => 'increase_test_count and landing_rate_only cosmetic_cleanup_only',
            'anti_proxy' => 'none',
            'proxy_only' => true,
            'sole_metric_test_count' => true,
        ]);

        $this->assertFalse($admit['admit']);
        $this->assertContains('proxy_only_evolution_refused', $admit['blockers']);
    }

    public function test_loc_only_and_landing_rate_markers_are_proxy(): void
    {
        $this->assertTrue(AaeosSelfEvolutionQualityLoop::isProxyIntent([
            'kind' => 'cleanup',
            'objective' => 'loc_only refactor with no frontier measure',
            'anti_proxy' => 'n/a',
        ]));
        $this->assertTrue(AaeosSelfEvolutionQualityLoop::isProxyIntent([
            'kind' => 'metrics',
            'objective' => 'optimize landing_rate_only dashboard',
            'anti_proxy' => 'n/a',
            'sole_metric_landing_rate' => true,
        ]));
        $this->assertFalse(AaeosSelfEvolutionQualityLoop::isProxyIntent([
            'kind' => 'raise_m_excellence',
            'objective' => 'Improve dual-arm EXCELLENCE_PASS on frontier suite',
            'anti_proxy' => 'Must move N_atlas/N_raw; forbid LOC proxies',
            'excellence_target' => 'raise_M_excellence',
        ]));
    }

    public function test_plan_never_emits_proxy_only_intents_on_quality_path(): void
    {
        $plan = AaeosSelfEvolutionQualityLoop::planFromRawRates([
            'n_raw' => 0.10,
            'n_atlas' => 0.20,
            'dual_arm' => true,
            'same_mu' => true,
            'curriculum_role' => RivalsCurriculumLadder::ROLE_FRONTIER,
            'frontier_saturated' => false,
            'sample_n' => 20,
            'r104_transport_open' => false,
            'r104_symmetry_attested' => true,
            'claim_m_threshold' => 50.0,
        ], [
            'ambition_m_threshold' => 50.0,
        ]);

        $this->assertSame(AaeosSelfEvolutionQualityLoop::DISPOSITION_ORIGINATE_QUALITY, $plan['disposition']);
        $this->assertTrue($plan['enqueue_allowed']);
        foreach ($plan['enqueue_intents'] as $intent) {
            $this->assertFalse(AaeosSelfEvolutionQualityLoop::isProxyIntent($intent));
            $this->assertNotEmpty($intent['anti_proxy']);
            $admit = AaeosSelfEvolutionQualityLoop::admitIntent($intent);
            $this->assertTrue($admit['admit'], implode(',', $admit['blockers']));
        }
        $this->assertSame([], $plan['refused_proxy_intents']);
    }

    public function test_admit_requires_anti_proxy_contract(): void
    {
        $admit = AaeosSelfEvolutionQualityLoop::admitIntent([
            'kind' => 'raise_m_excellence',
            'objective' => 'Improve dual-arm frontier excellence pass rate for Atlas path',
            'anti_proxy' => '',
        ]);

        $this->assertFalse($admit['admit']);
        $this->assertContains('anti_proxy_contract_required', $admit['blockers']);
    }
}
