<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainContextBudgetDistiller;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainContextBudgetDistillerTest extends TestCase
{
    private function distiller(): AtlasExternalBrainContextBudgetDistiller
    {
        return new AtlasExternalBrainContextBudgetDistiller;
    }

    private function sec(array $overrides = []): array
    {
        return array_merge([
            'id'              => 'sec-1',
            'type'            => 'other',
            'content'         => 'some content',
            'signal_score'    => 0.80,
            'freshness_score' => 0.80,
            'unblock_value'   => 0.50,
            'token_count'     => 100,
            'is_duplicate'    => false,
            'is_stale'        => false,
        ], $overrides);
    }

    // ── Output shape ──────────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->distiller()->distill(['context_sections' => []]);

        foreach (['schema_version', 'distilled_context', 'retained_sections',
                  'omitted_sections', 'budget_used', 'risk_of_loss',
                  'missing_critical_sections', 'duplicate_canonical_summaries'] as $k) {
            $this->assertArrayHasKey($k, $r);
        }
    }

    // ── AC1: ranked by relevance, freshness, risk and unblock_value ───────────

    public function test_higher_unblock_value_is_retained_over_lower_when_budget_is_tight(): void
    {
        // Budget fits exactly one 100-token section. Two candidates with same signal
        // but different unblock_value — only the high-unblock one should fit.
        $highUnblock = $this->sec(['id' => 'high', 'unblock_value' => 1.0, 'signal_score' => 0.60]);
        $lowUnblock  = $this->sec(['id' => 'low',  'unblock_value' => 0.0, 'signal_score' => 0.60]);

        $r = $this->distiller()->distill([
            'budget_tokens'    => 100,
            'context_sections' => [$highUnblock, $lowUnblock],
        ]);

        $retainedIds = array_column($r['retained_sections'], 'id');
        $this->assertContains('high', $retainedIds);
        $this->assertNotContains('low', $retainedIds);
    }

    public function test_higher_freshness_is_retained_over_stale_when_budget_is_tight(): void
    {
        $fresh = $this->sec(['id' => 'fresh', 'freshness_score' => 1.0, 'signal_score' => 0.60, 'unblock_value' => 0.0]);
        $old   = $this->sec(['id' => 'old',   'freshness_score' => 0.0, 'signal_score' => 0.60, 'unblock_value' => 0.0]);

        $r = $this->distiller()->distill([
            'budget_tokens'    => 100,
            'context_sections' => [$fresh, $old],
        ]);

        $retainedIds = array_column($r['retained_sections'], 'id');
        $this->assertContains('fresh', $retainedIds);
        $this->assertNotContains('old', $retainedIds);
    }

    public function test_low_composite_rank_section_is_omitted(): void
    {
        // signal=0.1, freshness=0.1, unblock=0.0 → composite=0.08 < 0.50
        $low = $this->sec(['id' => 'weak', 'signal_score' => 0.10, 'freshness_score' => 0.10, 'unblock_value' => 0.0]);

        $r = $this->distiller()->distill([
            'budget_tokens'    => 9999,
            'context_sections' => [$low],
        ]);

        $this->assertNotContains('weak', array_column($r['retained_sections'], 'id'));
        $this->assertContains('low_composite_rank', array_column($r['omitted_sections'], 'reason'));
    }

    // ── AC2: stale duplicate facts collapsed with provenance_count ─────────────

    public function test_duplicate_sections_are_collapsed_with_provenance_count(): void
    {
        $canon = $this->sec(['id' => 'dup-1', 'type' => 'recent_failure', 'signal_score' => 0.90, 'is_duplicate' => true]);
        $dupe  = $this->sec(['id' => 'dup-2', 'type' => 'recent_failure', 'signal_score' => 0.70, 'is_duplicate' => true]);

        $r = $this->distiller()->distill([
            'budget_tokens'    => 9999,
            'context_sections' => [$canon, $dupe],
        ]);

        $summaries = $r['duplicate_canonical_summaries'];
        $this->assertCount(1, $summaries);
        $this->assertSame(2, $summaries[0]['provenance_count']);
        $this->assertSame('dup-1', $summaries[0]['retained_id']);
    }

    public function test_best_ranked_duplicate_is_retained_not_dropped(): void
    {
        // Both duplicates — best (highest composite rank) should appear in retained_sections.
        $best   = $this->sec(['id' => 'best', 'type' => 'recent_failure', 'signal_score' => 0.95, 'is_duplicate' => true, 'freshness_score' => 0.9, 'unblock_value' => 0.9]);
        $worse  = $this->sec(['id' => 'bad',  'type' => 'recent_failure', 'signal_score' => 0.50, 'is_duplicate' => true]);

        $r = $this->distiller()->distill([
            'budget_tokens'    => 9999,
            'context_sections' => [$best, $worse],
        ]);

        $retainedIds = array_column($r['retained_sections'], 'id');
        $this->assertContains('best', $retainedIds);
        $this->assertNotContains('bad', $retainedIds);
    }

    public function test_duplicates_with_different_canonical_keys_form_separate_groups(): void
    {
        $a1 = $this->sec(['id' => 'a1', 'type' => 'recent_failure', 'canonical_key' => 'group-a', 'is_duplicate' => true, 'signal_score' => 0.9]);
        $a2 = $this->sec(['id' => 'a2', 'type' => 'recent_failure', 'canonical_key' => 'group-a', 'is_duplicate' => true, 'signal_score' => 0.7]);
        $b1 = $this->sec(['id' => 'b1', 'type' => 'other',          'canonical_key' => 'group-b', 'is_duplicate' => true, 'signal_score' => 0.8]);

        $r = $this->distiller()->distill([
            'budget_tokens'    => 9999,
            'context_sections' => [$a1, $a2, $b1],
        ]);

        $this->assertCount(2, $r['duplicate_canonical_summaries']);
        $keys = array_column($r['duplicate_canonical_summaries'], 'canonical_key');
        $this->assertContains('group-a', $keys);
        $this->assertContains('group-b', $keys);
    }

    // ── AC3: critical safety constraints preserved before nice-to-have ─────────

    public function test_tier1_canonical_decision_is_retained_before_low_signal_sections(): void
    {
        $critical = $this->sec(['id' => 'crit', 'type' => 'canonical_decision', 'signal_score' => 0.60, 'token_count' => 100]);
        $nicetohave = $this->sec(['id' => 'nice', 'type' => 'other', 'signal_score' => 0.99, 'token_count' => 50]);

        // Budget fits both but if only one fits, critical wins — budget=100 forces choice.
        $r = $this->distiller()->distill([
            'budget_tokens'    => 100,
            'context_sections' => [$critical, $nicetohave],
        ]);

        $retainedIds = array_column($r['retained_sections'], 'id');
        $this->assertContains('crit', $retainedIds);
        $this->assertNotContains('nice', $retainedIds);
    }

    public function test_tier1_omission_sets_risk_high(): void
    {
        $critical = $this->sec(['id' => 'crit', 'type' => 'canonical_decision', 'token_count' => 1000]);

        $r = $this->distiller()->distill([
            'budget_tokens'    => 1,   // cannot fit anything
            'context_sections' => [$critical],
        ]);

        $this->assertSame('high', $r['risk_of_loss']);
    }

    public function test_no_critical_omission_means_risk_low(): void
    {
        $safe = $this->sec(['id' => 's1', 'type' => 'other', 'token_count' => 50]);

        $r = $this->distiller()->distill([
            'budget_tokens'    => 9999,
            'context_sections' => [$safe],
        ]);

        $this->assertSame('low', $r['risk_of_loss']);
    }

    public function test_always_drop_types_are_never_retained(): void
    {
        $trace = $this->sec(['id' => 'tr', 'type' => 'provider_trace', 'signal_score' => 1.0]);
        $stale = $this->sec(['id' => 'st', 'type' => 'stale_summary',  'signal_score' => 1.0]);

        $r = $this->distiller()->distill([
            'budget_tokens'    => 9999,
            'context_sections' => [$trace, $stale],
        ]);

        $this->assertSame([], $r['retained_sections']);
    }

    // ── AC4: deterministic, no providers ─────────────────────────────────────

    public function test_distill_is_deterministic(): void
    {
        $input = ['budget_tokens' => 500, 'context_sections' => [
            $this->sec(['id' => 'x', 'signal_score' => 0.80, 'freshness_score' => 0.70, 'unblock_value' => 0.60]),
            $this->sec(['id' => 'y', 'signal_score' => 0.65, 'freshness_score' => 0.50, 'unblock_value' => 0.30]),
        ]];

        $a = $this->distiller()->distill($input);
        $b = $this->distiller()->distill($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
