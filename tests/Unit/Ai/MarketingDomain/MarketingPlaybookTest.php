<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;
use PHPUnit\Framework\TestCase;

class MarketingPlaybookTest extends TestCase
{
    private MarketingPlaybook $pb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pb = new MarketingPlaybook;
    }

    public function test_action_space_is_the_operators_nine_canonical_actions(): void
    {
        $this->assertCount(9, MarketingPlaybook::ACTION_SPACE);
        $this->assertContains(MarketingPlaybook::ACTION_REFRESH_VSL, MarketingPlaybook::ACTION_SPACE);
        $this->assertContains(MarketingPlaybook::ACTION_EDIT_HOOK, MarketingPlaybook::ACTION_SPACE);
        $this->assertContains(MarketingPlaybook::ACTION_STRENGTHEN_CLOSE, MarketingPlaybook::ACTION_SPACE);
    }

    public function test_awareness_router_is_deterministic_and_maps_to_a_great_lead(): void
    {
        $solution = $this->pb->routeAwareness('solution_aware');
        $this->assertSame('solution_aware', $solution['awareness']);
        $this->assertSame('big_secret', $solution['lead_type']);
        $this->assertSame('mechanism', $solution['sophistication']);
        $this->assertNotSame('', $solution['lead_how']);

        // most-aware leads with the offer
        $this->assertSame('offer', $this->pb->routeAwareness('most_aware')['lead_type']);

        // same input → same output
        $this->assertSame($solution, $this->pb->routeAwareness('solution_aware'));
    }

    public function test_unknown_awareness_falls_back_to_cold_paid_safe_default(): void
    {
        $unknown = $this->pb->routeAwareness('   ');
        $this->assertSame('problem_aware', $unknown['awareness']);
        $this->assertSame('unique_mechanism', $unknown['sophistication']);
    }

    public function test_vsl_anatomy_blocks_carry_the_weak_symptom_link(): void
    {
        $blocks = $this->pb->vslAnatomy();
        $keys = array_column($blocks, 'block');
        $this->assertContains('hook', $keys);
        $this->assertContains('solution_mechanism', $keys);
        $this->assertContains('offer', $keys);

        foreach ($blocks as $b) {
            $this->assertArrayHasKey('weak_symptom', $b, "block {$b['block']} must declare the symptom it causes when weak");
            $this->assertNotSame('', $b['rule']);
        }
    }

    public function test_value_equation_leverage_is_the_denominator(): void
    {
        $ve = $this->pb->valueEquation();
        $this->assertStringContainsString('DENOMINADOR', $ve['insight']);
        $this->assertSame('denominador', $ve['terms']['time_delay']['side']);
        $this->assertSame('denominador', $ve['terms']['effort_sacrifice']['side']);
    }

    public function test_compliance_knowledge_is_uptime_not_a_gate(): void
    {
        $k = $this->pb->accountUptimeKnowledge();
        // Operator directive: Atlas never gates/refuses. This block must declare itself non-gate.
        $this->assertFalse($k['is_gate']);
        $this->assertStringContainsString('não recusa', $k['framing']);
    }

    public function test_demand_gen_and_email_arc_gaps_are_now_covered(): void
    {
        $dg = $this->pb->demandGen();
        $this->assertCount(4, $dg['best_practices']);
        $this->assertStringContainsString('tROAS', $dg['bidding']);

        $email = $this->pb->emailArc();
        $this->assertStringContainsString('SOS', $email['arc']);
        $this->assertArrayHasKey('deliverability', $email);
    }

    public function test_conversion_pipeline_knows_data_manager_api_is_in_effect(): void
    {
        $cp = $this->pb->conversionPipeline();
        $this->assertStringContainsString('Data Manager API', $cp['data_manager_api']['status']);
        $this->assertStringContainsString('2026-06-15', $cp['data_manager_api']['status']);
    }
}
