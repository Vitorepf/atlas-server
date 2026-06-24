<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\AudiencePanelVerdict;
use PHPUnit\Framework\TestCase;

/**
 * AudiencePanelVerdict — the actionable verdict over the persona panel: lost_count/total, the dominant
 * (most-shared) failure mode, the shared fix, and who would buy. The audience-dimension bottleneck.
 */
class AudiencePanelVerdictTest extends TestCase
{
    public function test_a_hollow_page_loses_the_panel_with_a_shared_fix(): void
    {
        $v = (new AudiencePanelVerdict)->assess('Buy our amazing world-class product now. Best ever. Order today.', 'weight loss');
        $this->assertSame($v['total'], $v['lost_count'], 'a hollow page loses everyone');
        $this->assertNotNull($v['dominant_failure_mode']);
        $this->assertSame($v['dominant_failure_mode'], $v['shared_fix']);
        $this->assertStringContainsString('Perde', $v['summary']);
    }

    public function test_a_grounded_page_keeps_at_least_one_buyer(): void
    {
        $v = (new AudiencePanelVerdict)->assess(
            'If you are a woman over 40 and the scale will not move, it is not your fault. '
            .'Dr. Aronson tracked 312 women and 9 out of 10 dropped a dress size in 6 weeks. '
            .'You risk nothing with a 60-day money-back guarantee. Watch the free presentation.',
            'weight loss'
        );
        $this->assertLessThan($v['total'], $v['lost_count'], 'a grounded page should not lose everyone');
        $this->assertNotEmpty($v['would_buy']);
    }

    public function test_structure_is_complete(): void
    {
        $v = (new AudiencePanelVerdict)->assess('Some copy here for the panel to react to.', 'finance');
        foreach (['lost_count', 'total', 'lost', 'would_buy', 'dominant_failure_mode', 'shared_fix', 'summary'] as $k) {
            $this->assertArrayHasKey($k, $v);
        }
        $this->assertIsArray($v['lost']);
    }
}
