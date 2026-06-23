<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\ConversionPipelineValidator;
use PHPUnit\Framework\TestCase;

class ConversionPipelineValidatorTest extends TestCase
{
    private ConversionPipelineValidator $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ConversionPipelineValidator;
    }

    public function test_complete_stack_is_100_and_not_blind(): void
    {
        $v = $this->svc->validate([
            'affiliate_network' => 'clickbank',
            'tracker' => 'binom',
            'has_auto_tagging' => true,
            'captures_gclid' => true,
            'uses_data_manager_api' => true,
            'enhanced_conversions' => true,
            'postback_macros' => ['{tid}', '{click_id}', '{fbclid}'],
            'has_email_auth' => true,
        ]);

        $this->assertSame(100, $v['readiness']);
        $this->assertFalse($v['blind_risk']);
        $this->assertSame([], $v['missing']);
        $this->assertSame([], $v['ordered_steps']);
    }

    public function test_bare_stack_is_blind_and_emits_ordered_steps(): void
    {
        $v = $this->svc->validate(['affiliate_network' => 'clickbank']);

        $this->assertLessThan(50, $v['readiness']);
        $this->assertTrue($v['blind_risk']);
        $this->assertContains('data_manager_api', $v['missing']);
        $this->assertNotEmpty($v['ordered_steps']);
    }

    public function test_postback_macro_diagnostic_flags_missing(): void
    {
        $p = $this->svc->validatePostback(['{tid}']);
        $this->assertFalse($p['complete']);
        $this->assertContains('{click_id}', $p['missing']);

        $full = $this->svc->validatePostback(['{tid}', '{click_id}', '{fbclid}']);
        $this->assertTrue($full['complete']);
    }

    public function test_plan_by_stack_emits_data_manager_api_status(): void
    {
        $plan = $this->svc->planByStack('clickbank', 'google_search');
        $this->assertStringContainsString('Data Manager API', $plan['data_manager_api_status']);
        $this->assertArrayHasKey('{click_id}', $plan['macro_mapping']);
    }
}
