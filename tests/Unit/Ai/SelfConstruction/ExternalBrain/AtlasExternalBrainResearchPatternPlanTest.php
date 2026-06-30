<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainResearchPatternPlan;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainResearchPatternPlanTest extends TestCase
{
    private AtlasExternalBrainResearchPatternPlan $planner;

    protected function setUp(): void
    {
        $this->planner = new AtlasExternalBrainResearchPatternPlan;
    }

    public function test_thin_idea_expands_into_source_categories(): void
    {
        $plan = $this->planner->plan('semantic vector search');

        $this->assertTrue($plan['accepted']);
        $this->assertNotEmpty($plan['source_categories']);
        $this->assertArrayHasKey('atlas_journals', $plan['source_categories']);
        $this->assertArrayHasKey('oss_repositories', $plan['source_categories']);
        $this->assertArrayHasKey('academic_papers', $plan['source_categories']);
    }

    public function test_plan_includes_comparison_questions(): void
    {
        $plan = $this->planner->plan('recursive self-improvement loop');

        $this->assertNotEmpty($plan['comparison_questions']);
        $this->assertIsArray($plan['comparison_questions']);
        // At least one question references the idea
        $found = array_filter($plan['comparison_questions'], fn ($q) => str_contains($q, 'recursive self-improvement loop'));
        $this->assertNotEmpty($found, 'at least one comparison question must reference the idea');
    }

    public function test_plan_includes_freshness_requirements_per_category(): void
    {
        $plan = $this->planner->plan('embedding-based retrieval');

        $this->assertNotEmpty($plan['freshness_requirements']);
        foreach (array_keys($plan['source_categories']) as $cat) {
            $this->assertArrayHasKey($cat, $plan['freshness_requirements']);
            $this->assertArrayHasKey('max_age_days', $plan['freshness_requirements'][$cat]);
            $this->assertTrue($plan['freshness_requirements'][$cat]['must_cite_source']);
        }
    }

    public function test_plan_includes_adoption_risks(): void
    {
        $plan = $this->planner->plan('provider-backed decision engine');

        $this->assertNotEmpty($plan['adoption_risks']);
        $this->assertArrayHasKey('provider_dependency', $plan['adoption_risks']);
        $this->assertArrayHasKey('goodhart_drift', $plan['adoption_risks']);
        $this->assertArrayHasKey('incomplete_wiring', $plan['adoption_risks']);
    }

    public function test_empty_idea_returns_not_accepted(): void
    {
        $plan = $this->planner->plan('');

        $this->assertFalse($plan['accepted']);
        $this->assertEmpty($plan['source_categories']);
    }

    public function test_whitespace_idea_returns_not_accepted(): void
    {
        $plan = $this->planner->plan('   ');

        $this->assertFalse($plan['accepted']);
    }

    public function test_exclude_categories_removes_them_from_plan(): void
    {
        $plan = $this->planner->plan('merkle proof', ['exclude_categories' => ['academic_papers', 'industry_case_studies']]);

        $this->assertArrayNotHasKey('academic_papers', $plan['source_categories']);
        $this->assertArrayNotHasKey('industry_case_studies', $plan['source_categories']);
        $this->assertArrayHasKey('oss_repositories', $plan['source_categories']);
    }

    public function test_focus_areas_add_extra_comparison_questions(): void
    {
        $plan = $this->planner->plan('compaction strategy', ['focus_areas' => ['latency', 'memory footprint']]);

        $questions = implode(' ', $plan['comparison_questions']);
        $this->assertStringContainsString('latency', $questions);
        $this->assertStringContainsString('memory footprint', $questions);
    }

    public function test_atlas_journals_freshness_capped_at_30_days(): void
    {
        $plan = $this->planner->plan('something', ['max_age_days' => 365]);

        $this->assertLessThanOrEqual(30, $plan['freshness_requirements']['atlas_journals']['max_age_days']);
    }

    public function test_provenance_required_is_always_true(): void
    {
        $plan = $this->planner->plan('any idea');

        $this->assertTrue($plan['provenance_required']);
    }

    public function test_schema_key_present(): void
    {
        $plan = $this->planner->plan('test');

        $this->assertSame(AtlasExternalBrainResearchPatternPlan::SCHEMA, $plan['schema']);
    }

    public function test_accept_requires_provenance_comparison_and_hypothesis(): void
    {
        $gate = new AtlasExternalBrainResearchPatternPlan;

        $this->assertFalse($gate->accept([]));
        $this->assertFalse($gate->accept(['provenance' => 'atlas:journal:x']));
        $this->assertFalse($gate->accept(['provenance' => 'atlas:journal:x', 'comparison_summary' => 'A vs B']));
        $this->assertTrue($gate->accept([
            'provenance'                  => 'atlas:journal:x',
            'comparison_summary'          => 'A is faster than B for local-first use',
            'atlas_adaptation_hypothesis' => 'wrap A behind AtlasResearchAdapter, keep provider-free',
        ]));
    }

    public function test_accept_rejects_empty_string_fields(): void
    {
        $gate = new AtlasExternalBrainResearchPatternPlan;

        $this->assertFalse($gate->accept([
            'provenance'                  => '',
            'comparison_summary'          => 'ok',
            'atlas_adaptation_hypothesis' => 'ok',
        ]));
    }
}
