<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\AbandonPointSimulator;
use PHPUnit\Framework\TestCase;

/**
 * AbandonPointSimulator — the faithful audience simulation that finds WHERE the tab closes. Replays the
 * persona panel on cumulative prefixes and reports the abandon ZONE per persona. Turns the aggregate
 * scalar into a coordinate (which zone bleeds the most + the objection at the leave point).
 */
class AbandonPointSimulatorTest extends TestCase
{
    public function test_a_hollow_page_loses_more_than_a_grounded_one(): void
    {
        $sim = new AbandonPointSimulator;
        $weak = $sim->simulate('Buy our amazing product now. It is the best, world-class and revolutionary. Order today.', 'weight loss');
        $strong = $sim->simulate(
            'If you are a woman over 40 and the scale will not move, it is not your fault. '
            .'Dr. Aronson tracked 312 women and 9 out of 10 dropped a dress size in 6 weeks. '
            .'Imagine waking up lighter. You risk nothing with a 60-day money-back guarantee. Watch the free presentation now.',
            'weight loss'
        );
        $this->assertGreaterThan(0, $weak['total']);
        $this->assertLessThanOrEqual($weak['lost_count'], $strong['lost_count'], 'a grounded page must lose no MORE personas than a hollow one');
    }

    public function test_reports_the_abandon_zone_and_objection_per_persona(): void
    {
        $sim = new AbandonPointSimulator;
        $r = $sim->simulate('Buy now. Best product ever. Order today.', 'weight loss');
        $this->assertNotEmpty($r['abandon_by_persona']);
        foreach ($r['abandon_by_persona'] as $a) {
            $this->assertArrayHasKey('sentence_index', $a);
            $this->assertArrayHasKey('zone', $a);
            $this->assertArrayHasKey('objection', $a);
        }
        $this->assertNotNull($r['worst_zone']);
    }

    public function test_empty_copy_is_graceful(): void
    {
        $r = (new AbandonPointSimulator)->simulate('   ', 'weight loss');
        $this->assertSame(0, $r['total']);
        $this->assertNull($r['worst_zone']);
    }
}
