<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Content\ObjectionLoopEngine;
use PHPUnit\Framework\TestCase;

/**
 * ObjectionLoopEngine — the Belfort re-close loop: acknowledge → reframe the dropped certainty axis with
 * new proof → take-away → re-ask. Grounded in the asset, deterministic.
 */
class ObjectionLoopEngineTest extends TestCase
{
    public function test_loop_has_all_four_belfort_steps(): void
    {
        $loop = (new ObjectionLoopEngine)->loop($this->asset(), 'wont_work_for_me');
        foreach (['acknowledge', 'reframe', 'takeaway', 'reask'] as $step) {
            $this->assertArrayHasKey($step, $loop['steps']);
            $this->assertNotEmpty($loop['steps'][$step]);
        }
        // Take-away (reactance) and a re-ask are present in the assembled loop.
        $this->assertStringContainsString('not for everyone', $loop['loop']);
        $this->assertStringContainsString('next step', $loop['loop']);
    }

    public function test_reframe_is_grounded_in_the_mechanism(): void
    {
        $loop = (new ObjectionLoopEngine)->loop($this->asset(['mechanism_name' => 'The 3-Hormone Reset']), 'wont_work_for_me');
        $this->assertStringContainsString('The 3-Hormone Reset', $loop['reframe'] ?? $loop['steps']['reframe']);
    }

    public function test_scam_objection_routes_to_the_company_certainty_axis(): void
    {
        $loop = (new ObjectionLoopEngine)->loop($this->asset(), 'is_it_scam');
        $this->assertSame('company', $loop['certainty_axis']);
        $this->assertStringContainsStringIgnoringCase('guarantee', $loop['steps']['reframe']);
    }

    public function test_cross_niche_finance_default_objection(): void
    {
        $loop = (new ObjectionLoopEngine)->loop($this->asset(['niche' => 'finance', 'mechanism_name' => 'The Allocation Rule']));
        $this->assertNotEmpty($loop['loop']);
        $this->assertNotEmpty($loop['objection']);
    }

    private function asset(array $attrs = []): AiMarketingVslAsset
    {
        return new AiMarketingVslAsset($attrs + ['niche' => 'weight loss', 'core_promise' => 'lose the weight']);
    }
}
