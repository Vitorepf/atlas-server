<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainResearchPatternPlan;
use Tests\TestCase;

final class AtlasExternalBrainResearchPatternPlanTest extends TestCase
{
    private function svc(): AtlasExternalBrainResearchPatternPlan
    {
        return new AtlasExternalBrainResearchPatternPlan;
    }

    /** Builds an entry that passes accept() AND the adoption-score floor. */
    private function passingEntry(array $overrides = []): array
    {
        return array_merge([
            'provenance'                  => 'github:owner/repo#pr-123',
            'comparison_summary'          => 'This technique differs from the naive approach by using bounded evidence gates that prevent runaway inference.',
            'atlas_adaptation_hypothesis' => 'Wrap the OSS pattern in AtlasEvidenceGateService to enforce local-first constraints before each iteration.',
            'allowed_files_hint'          => ['app/Services/Ai/SelfConstruction/ExternalBrain/AtlasSomething.php'],
            'idea'                        => 'bounded evidence gate',
        ], $overrides);
    }

    // ── AC1: runnable gate (implicit) ─────────────────────────────────────────

    public function test_ac1_plan_returns_expected_schema_keys(): void
    {
        $r = $this->svc()->plan('adaptive threshold');

        $this->assertArrayHasKey('schema',                 $r);
        $this->assertArrayHasKey('idea',                   $r);
        $this->assertArrayHasKey('source_categories',      $r);
        $this->assertArrayHasKey('comparison_questions',   $r);
        $this->assertArrayHasKey('freshness_requirements', $r);
        $this->assertArrayHasKey('adoption_risks',         $r);
        $this->assertArrayHasKey('provenance_required',    $r);
        $this->assertArrayHasKey('accepted',               $r);
    }

    // ── AC2: empty idea rejected; valid idea produces required fields ─────────

    public function test_ac2_empty_idea_is_rejected_with_empty_collections(): void
    {
        $r = $this->svc()->plan('');

        $this->assertFalse($r['accepted']);
        $this->assertEmpty($r['source_categories']);
        $this->assertEmpty($r['comparison_questions']);
        $this->assertEmpty($r['freshness_requirements']);
        $this->assertEmpty($r['adoption_risks']);
    }

    public function test_ac2_whitespace_only_idea_is_rejected(): void
    {
        $r = $this->svc()->plan('   ');

        $this->assertFalse($r['accepted']);
    }

    public function test_ac2_valid_idea_is_accepted(): void
    {
        $r = $this->svc()->plan('adaptive threshold selection');

        $this->assertTrue($r['accepted']);
    }

    public function test_ac2_valid_idea_produces_source_categories(): void
    {
        $r = $this->svc()->plan('dynamic context compression');

        $this->assertNotEmpty($r['source_categories'],
            'source_categories must be non-empty for a valid idea');
    }

    public function test_ac2_valid_idea_produces_comparison_questions(): void
    {
        $r = $this->svc()->plan('semantic deduplication');

        $this->assertNotEmpty($r['comparison_questions'],
            'comparison_questions must be non-empty for a valid idea');
    }

    public function test_ac2_valid_idea_produces_freshness_requirements(): void
    {
        $r = $this->svc()->plan('heldout benchmark rotation');

        $this->assertNotEmpty($r['freshness_requirements'],
            'freshness_requirements must be non-empty for a valid idea');
    }

    public function test_ac2_valid_idea_produces_adoption_risks(): void
    {
        $r = $this->svc()->plan('evidence provenance chain');

        $this->assertNotEmpty($r['adoption_risks'],
            'adoption_risks must be non-empty for a valid idea');
    }

    public function test_ac2_exclude_categories_removes_them_from_source_categories(): void
    {
        $r = $this->svc()->plan('some idea', ['exclude_categories' => ['academic_papers']]);

        $this->assertArrayNotHasKey('academic_papers', $r['source_categories']);
    }

    public function test_ac2_focus_areas_add_extra_comparison_questions(): void
    {
        $base   = $this->svc()->plan('idea');
        $withFocus = $this->svc()->plan('idea', ['focus_areas' => ['latency', 'throughput']]);

        $this->assertGreaterThan(
            count($base['comparison_questions']),
            count($withFocus['comparison_questions']),
            'focus_areas must inject additional comparison questions',
        );
    }

    // ── AC3: toTaskOpportunity rejects missing provenance / comparison / hypothesis ──

    public function test_ac3_missing_provenance_is_rejected(): void
    {
        $r = $this->svc()->toTaskOpportunity([
            'comparison_summary'          => 'Some comparison',
            'atlas_adaptation_hypothesis' => 'Some valid hypothesis long enough to be accepted here',
        ]);

        $this->assertFalse($r['accepted']);
        $this->assertSame('missing_or_empty_provenance', $r['rejection_reason']);
        $this->assertNull($r['draft']);
    }

    public function test_ac3_empty_provenance_is_rejected(): void
    {
        $r = $this->svc()->toTaskOpportunity([
            'provenance'                  => '',
            'comparison_summary'          => 'Some comparison',
            'atlas_adaptation_hypothesis' => 'Some valid hypothesis long enough to be accepted here',
        ]);

        $this->assertFalse($r['accepted']);
        $this->assertSame('missing_or_empty_provenance', $r['rejection_reason']);
    }

    public function test_ac3_missing_comparison_summary_is_rejected(): void
    {
        $r = $this->svc()->toTaskOpportunity([
            'provenance'                  => 'github:repo#1',
            'atlas_adaptation_hypothesis' => 'Some valid hypothesis long enough to be accepted here',
        ]);

        $this->assertFalse($r['accepted']);
        $this->assertSame('missing_or_empty_comparison_summary', $r['rejection_reason']);
    }

    public function test_ac3_missing_atlas_adaptation_hypothesis_is_rejected(): void
    {
        $r = $this->svc()->toTaskOpportunity([
            'provenance'         => 'github:repo#1',
            'comparison_summary' => 'Some comparison text here',
        ]);

        $this->assertFalse($r['accepted']);
        $this->assertSame('missing_or_empty_atlas_adaptation_hypothesis', $r['rejection_reason']);
    }

    public function test_ac3_entry_with_all_required_fields_is_not_auto_rejected(): void
    {
        // acceptance should not fail on the structural check (score check may still apply)
        $svc = $this->svc();
        $this->assertTrue($svc->accept($this->passingEntry()));
    }

    // ── AC4: below MIN_ADOPTION_SCORE returns score_explanation ───────────────

    public function test_ac4_hype_wording_reduces_adoption_score(): void
    {
        // Entry with all required fields but hype wording + vague hypothesis + no allowed_files_hint
        $r = $this->svc()->toTaskOpportunity([
            'provenance'                  => 'github:owner/repo#1',
            'comparison_summary'          => 'This is revolutionary and completely transforms the pipeline',
            'atlas_adaptation_hypothesis' => 'game changer',  // vague + hype
        ]);

        // Should fail: vague (<30 chars), hype signals, and no allowed_files_hint
        $this->assertFalse($r['accepted']);
        $this->assertArrayHasKey('score_explanation', $r);
        $this->assertNotEmpty($r['score_explanation']);
    }

    public function test_ac4_below_floor_returns_score_explanation_not_draft(): void
    {
        // Deliberately triggers multiple penalties to sink below 0.50:
        // stale provenance (no prefix): -0.20
        // vague hypothesis (<30 chars):  -0.25
        // missing allowed_files_hint:    -0.15
        // total: -0.60 → score 0.40
        $r = $this->svc()->toTaskOpportunity([
            'provenance'                  => 'some internal note',   // no trusted prefix
            'comparison_summary'          => 'Some comparison here',
            'atlas_adaptation_hypothesis' => 'short',               // < 30 chars
        ]);

        $this->assertFalse($r['accepted']);
        $this->assertSame('below_minimum_adoption_score', $r['rejection_reason']);
        $this->assertArrayHasKey('adoption_score', $r);
        $this->assertArrayHasKey('score_explanation', $r);
        $this->assertNotEmpty($r['score_explanation']);
        $this->assertNull($r['draft']);
    }

    public function test_ac4_score_explanation_contains_penalty_keys(): void
    {
        $r = $this->svc()->toTaskOpportunity([
            'provenance'                  => 'some internal note',   // no trusted prefix → penalty
            'comparison_summary'          => 'Comparison text',
            'atlas_adaptation_hypothesis' => 'short',               // < 30 chars → penalty
        ]);

        $this->assertSame('below_minimum_adoption_score', $r['rejection_reason']);
        // score_explanation is a list of penalty strings
        $found = false;
        foreach ($r['score_explanation'] as $penalty) {
            if (str_contains($penalty, 'vague_adaptation_hypothesis') || str_contains($penalty, 'stale')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'score_explanation must name at least one penalty reason');
    }

    public function test_ac4_passing_entry_returns_draft(): void
    {
        $r = $this->svc()->toTaskOpportunity($this->passingEntry());

        $this->assertTrue($r['accepted']);
        $this->assertNull($r['rejection_reason']);
        $this->assertNotNull($r['draft']);
        $this->assertArrayHasKey('source_provenance',     $r['draft']);
        $this->assertArrayHasKey('adaptation_hypothesis', $r['draft']);
        $this->assertArrayHasKey('anti_hype_risks',       $r['draft']);
        $this->assertArrayHasKey('adoption_score',        $r['draft']);
        $this->assertArrayHasKey('score_explanation',     $r['draft']);
    }

    public function test_ac4_passing_entry_includes_runnable_acceptance_hint(): void
    {
        $r = $this->svc()->toTaskOpportunity($this->passingEntry());

        $this->assertArrayHasKey('runnable_acceptance_hint', $r['draft']);
        $this->assertNotEmpty($r['draft']['runnable_acceptance_hint']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_deterministic_plan_output(): void
    {
        $svc = $this->svc();
        $this->assertSame(
            json_encode($svc->plan('adaptive threshold', ['focus_areas' => ['latency']]), JSON_UNESCAPED_SLASHES),
            json_encode($svc->plan('adaptive threshold', ['focus_areas' => ['latency']]), JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_deterministic_to_task_opportunity(): void
    {
        $entry = $this->passingEntry();
        $svc   = $this->svc();
        $this->assertSame(
            json_encode($svc->toTaskOpportunity($entry), JSON_UNESCAPED_SLASHES),
            json_encode($svc->toTaskOpportunity($entry), JSON_UNESCAPED_SLASHES),
        );
    }
}
