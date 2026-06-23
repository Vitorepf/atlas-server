<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Scoring\PageAuditScorer;
use PHPUnit\Framework\TestCase;

class PageAuditScorerTest extends TestCase
{
    private PageAuditScorer $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new PageAuditScorer;
    }

    public function test_strong_page_scores_high(): void
    {
        $a = $this->svc->auditPage([
            'ad_headline' => 'Pink Gelatin Weight Loss Recipe',
            'title' => 'The Pink Gelatin Weight Loss Recipe',
            'vsl_promise' => 'lose weight with pink gelatin recipe',
            'form_fields' => ['email'],
            'cta_placements' => ['top', 'middle', 'bottom'],
            'load_time_ms' => 1800,
            'trust_near_top' => true,
            'conversion_goals' => 1,
        ]);

        $this->assertGreaterThanOrEqual(80, $a['overall_score']);
    }

    public function test_slow_bloated_page_flags_bottleneck(): void
    {
        $a = $this->svc->auditPage([
            'ad_headline' => 'Pink Gelatin Weight Loss',
            'title' => 'Pink Gelatin Weight Loss Recipe',
            'vsl_promise' => 'pink gelatin recipe',
            'form_fields' => ['name', 'email', 'phone', 'address', 'age', 'gender'],
            'cta_placements' => ['bottom'],
            'load_time_ms' => 6000,
            'trust_near_top' => false,
            'conversion_goals' => 3,
        ]);

        $this->assertLessThan(70, $a['overall_score']);
        $this->assertNotEmpty($a['recommendations']);
        $this->assertContains($a['bottleneck_lever'], ['load_time', 'form_fields', 'messaging_focus', 'cta_clarity', 'trust_position']);
    }

    public function test_deterministic(): void
    {
        $page = ['ad_headline' => 'a b', 'title' => 'a b', 'vsl_promise' => 'a', 'load_time_ms' => 2000, 'cta_placements' => ['x', 'y']];
        $this->assertSame($this->svc->auditPage($page), $this->svc->auditPage($page));
    }
}
