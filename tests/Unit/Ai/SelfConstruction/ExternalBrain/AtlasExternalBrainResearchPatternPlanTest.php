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

    // ── toTaskOpportunity() ───────────────────────────────────────────────────

    private function acceptedEntry(array $overrides = []): array
    {
        return array_merge([
            'provenance'                  => 'atlas:journal:loop-evidence-2026',
            'comparison_summary'          => 'Pattern A outperforms B for local-first wiring',
            'atlas_adaptation_hypothesis' => 'Wrap pattern A behind AtlasWiringAdapter, keep provider-free',
            'idea'                        => 'evidence-driven wiring',
        ], $overrides);
    }

    public function test_to_task_opportunity_returns_draft_for_accepted_entry(): void
    {
        $r = $this->planner->toTaskOpportunity($this->acceptedEntry());

        $this->assertTrue($r['accepted']);
        $this->assertNull($r['rejection_reason']);
        $this->assertIsArray($r['draft']);

        foreach (['source_provenance', 'adaptation_hypothesis', 'implementability_notes',
                  'anti_hype_risks', 'allowed_files_hint', 'runnable_acceptance_hint'] as $key) {
            $this->assertArrayHasKey($key, $r['draft'], "draft must contain {$key}");
        }
    }

    public function test_draft_source_provenance_matches_entry_provenance(): void
    {
        $r = $this->planner->toTaskOpportunity($this->acceptedEntry());
        $this->assertSame('atlas:journal:loop-evidence-2026', $r['draft']['source_provenance']);
    }

    public function test_draft_adaptation_hypothesis_matches_entry(): void
    {
        $r = $this->planner->toTaskOpportunity($this->acceptedEntry());
        $this->assertSame(
            'Wrap pattern A behind AtlasWiringAdapter, keep provider-free',
            $r['draft']['adaptation_hypothesis']
        );
    }

    public function test_draft_implementability_notes_reference_comparison_and_adaptation(): void
    {
        $r = $this->planner->toTaskOpportunity($this->acceptedEntry());
        $notes = $r['draft']['implementability_notes'];

        $this->assertStringContainsString('Pattern A outperforms B', $notes);
        $this->assertStringContainsString('AtlasWiringAdapter', $notes);
    }

    public function test_draft_runnable_acceptance_hint_includes_idea(): void
    {
        $r = $this->planner->toTaskOpportunity($this->acceptedEntry());
        $hint = $r['draft']['runnable_acceptance_hint'];

        $this->assertNotEmpty($hint);
        $this->assertStringContainsString('evidence-driven wiring', $hint);
    }

    public function test_draft_accepts_explicit_runnable_acceptance_hint(): void
    {
        $r = $this->planner->toTaskOpportunity($this->acceptedEntry([
            'runnable_acceptance_hint' => 'php artisan test --filter=AtlasWiringAdapterTest',
        ]));

        $this->assertSame('php artisan test --filter=AtlasWiringAdapterTest', $r['draft']['runnable_acceptance_hint']);
    }

    public function test_draft_accepts_explicit_allowed_files_hint(): void
    {
        $files = ['app/Services/Ai/SelfConstruction/AtlasWiringAdapter.php'];
        $r = $this->planner->toTaskOpportunity($this->acceptedEntry(['allowed_files_hint' => $files]));

        $this->assertSame($files, $r['draft']['allowed_files_hint']);
    }

    public function test_anti_hype_risks_always_include_structural_risks(): void
    {
        $r = $this->planner->toTaskOpportunity($this->acceptedEntry());
        $risks = implode(' ', $r['draft']['anti_hype_risks']);

        $this->assertStringContainsString('orphan_wiring', $risks);
        $this->assertStringContainsString('provider_bleed', $risks);
    }

    public function test_anti_hype_risks_flag_hype_signals_in_adaptation_hypothesis(): void
    {
        $r = $this->planner->toTaskOpportunity($this->acceptedEntry([
            'atlas_adaptation_hypothesis' => 'This revolutionary pattern completely transforms how Atlas works',
        ]));

        $risks = implode(' ', $r['draft']['anti_hype_risks']);
        $this->assertStringContainsString('hype_claim:revolutionary', $risks);
        $this->assertStringContainsString('hype_claim:completely_transforms', $risks);
    }

    public function test_to_task_opportunity_rejects_entry_missing_provenance(): void
    {
        $r = $this->planner->toTaskOpportunity([
            'comparison_summary'          => 'A vs B',
            'atlas_adaptation_hypothesis' => 'use A',
        ]);

        $this->assertFalse($r['accepted']);
        $this->assertStringContainsString('provenance', $r['rejection_reason']);
        $this->assertNull($r['draft']);
    }

    public function test_to_task_opportunity_rejects_entry_missing_comparison_summary(): void
    {
        $r = $this->planner->toTaskOpportunity([
            'provenance'                  => 'atlas:journal:x',
            'atlas_adaptation_hypothesis' => 'use A',
        ]);

        $this->assertFalse($r['accepted']);
        $this->assertStringContainsString('comparison_summary', $r['rejection_reason']);
    }

    public function test_to_task_opportunity_rejects_entry_missing_adaptation_hypothesis(): void
    {
        $r = $this->planner->toTaskOpportunity([
            'provenance'         => 'atlas:journal:x',
            'comparison_summary' => 'A beats B',
        ]);

        $this->assertFalse($r['accepted']);
        $this->assertStringContainsString('atlas_adaptation_hypothesis', $r['rejection_reason']);
    }

    public function test_hype_only_entry_without_provenance_never_produces_draft(): void
    {
        // No provenance, hype-filled hypothesis → must be rejected, no draft.
        $r = $this->planner->toTaskOpportunity([
            'comparison_summary'          => 'revolutionary game changer unlimited 10x',
            'atlas_adaptation_hypothesis' => 'completely transforms everything',
        ]);

        $this->assertFalse($r['accepted']);
        $this->assertNull($r['draft']);
    }

    // ── AC1: adoption_score + minimum rejection ───────────────────────────────

    public function test_draft_includes_adoption_score_in_range(): void
    {
        $r = $this->planner->toTaskOpportunity($this->acceptedEntry([
            'allowed_files_hint' => ['app/Services/Ai/AtlasWiringAdapter.php'],
        ]));

        $this->assertTrue($r['accepted']);
        $this->assertArrayHasKey('adoption_score', $r['draft']);
        $score = $r['draft']['adoption_score'];
        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    public function test_entry_below_minimum_adoption_score_is_rejected(): void
    {
        // no trusted prefix → -0.20; vague hypothesis (<30 chars) → -0.25; no files hint → -0.15 = 0.40
        $r = $this->planner->toTaskOpportunity([
            'provenance'                  => 'some-old-source-without-prefix',
            'comparison_summary'          => 'A is better than B',
            'atlas_adaptation_hypothesis' => 'use it',
        ]);

        $this->assertFalse($r['accepted']);
        $this->assertSame('below_minimum_adoption_score', $r['rejection_reason']);
        $this->assertNull($r['draft']);
        $this->assertLessThan(0.50, $r['adoption_score']);
    }

    public function test_adoption_score_is_deterministic(): void
    {
        $entry = $this->acceptedEntry();
        $a = $this->planner->toTaskOpportunity($entry);
        $b = $this->planner->toTaskOpportunity($entry);

        $this->assertSame($a['draft']['adoption_score'], $b['draft']['adoption_score']);
    }

    // ── AC2: score explanations for each penalty ──────────────────────────────

    public function test_stale_provenance_lowers_score(): void
    {
        $trusted = $this->planner->toTaskOpportunity($this->acceptedEntry());
        $stale   = $this->planner->toTaskOpportunity($this->acceptedEntry([
            'provenance' => 'some-old-book-without-prefix',
        ]));

        $this->assertLessThan(
            $trusted['draft']['adoption_score'],
            $stale['draft']['adoption_score'],
        );
        $this->assertContains(
            'stale_or_unverifiable_provenance:-0.20',
            $stale['draft']['score_explanation'],
        );
    }

    public function test_hype_wording_lowers_score(): void
    {
        $clean = $this->planner->toTaskOpportunity($this->acceptedEntry());
        $hyped = $this->planner->toTaskOpportunity($this->acceptedEntry([
            'atlas_adaptation_hypothesis' => 'Wrap pattern A behind AtlasWiringAdapter, keeping it revolutionary for unlimited gains',
        ]));

        $this->assertLessThan(
            $clean['draft']['adoption_score'],
            $hyped['draft']['adoption_score'],
        );
        $explanationStr = implode(' ', $hyped['draft']['score_explanation']);
        $this->assertStringContainsString('hype_wording', $explanationStr);
    }

    public function test_missing_allowed_files_hint_lowers_score(): void
    {
        $withHint    = $this->planner->toTaskOpportunity($this->acceptedEntry(['allowed_files_hint' => ['app/A.php']]));
        $withoutHint = $this->planner->toTaskOpportunity($this->acceptedEntry());

        $this->assertGreaterThan(
            $withoutHint['draft']['adoption_score'],
            $withHint['draft']['adoption_score'],
        );
        $this->assertContains('missing_allowed_files_hint:-0.15', $withoutHint['draft']['score_explanation']);
    }

    public function test_score_explanation_is_empty_for_ideal_entry(): void
    {
        $r = $this->planner->toTaskOpportunity($this->acceptedEntry([
            'atlas_adaptation_hypothesis' => 'Wrap pattern A behind AtlasWiringAdapter to close the self-wiring gap',
            'allowed_files_hint'          => ['app/Services/Ai/AtlasWiringAdapter.php'],
        ]));

        $this->assertTrue($r['accepted']);
        $this->assertSame([], $r['draft']['score_explanation']);
        $this->assertSame(1.0, $r['draft']['adoption_score']);
    }
}
