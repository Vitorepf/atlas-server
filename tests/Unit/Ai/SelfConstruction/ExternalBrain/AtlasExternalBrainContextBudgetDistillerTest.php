<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

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
            'id'           => 's1',
            'type'         => 'canonical_decision',
            'content'      => 'content s1',
            'signal_score' => 0.9,
            'is_duplicate' => false,
            'is_stale'     => false,
            'token_count'  => 100,
        ], $overrides);
    }

    // ── AC4: output shape ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->distiller()->distill([]);
        $this->assertSame(AtlasExternalBrainContextBudgetDistiller::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('distilled_context', $r);
        $this->assertArrayHasKey('retained_sections', $r);
        $this->assertArrayHasKey('omitted_sections', $r);
        $this->assertArrayHasKey('budget_used', $r);
        $this->assertArrayHasKey('risk_of_loss', $r);
    }

    // ── AC2: canonical sections retained ─────────────────────────────────────

    public function test_canonical_decision_retained_within_budget(): void
    {
        $r = $this->distiller()->distill([
            'context_sections' => [$this->sec(['type' => 'canonical_decision'])],
            'budget_tokens'    => 500,
        ]);
        $this->assertCount(1, $r['retained_sections']);
        $this->assertSame('canonical_decision', $r['retained_sections'][0]['type']);
        $this->assertSame('low', $r['risk_of_loss']);
    }

    public function test_all_tier1_and_tier2_types_retained(): void
    {
        $sections = [
            $this->sec(['id' => 'a', 'type' => 'canonical_decision']),
            $this->sec(['id' => 'b', 'type' => 'ac_definition']),
            $this->sec(['id' => 'c', 'type' => 'queue_constraint']),
            $this->sec(['id' => 'd', 'type' => 'recent_failure']),
            $this->sec(['id' => 'e', 'type' => 'target_path']),
        ];
        $r = $this->distiller()->distill(['context_sections' => $sections, 'budget_tokens' => 10000]);
        $retainedIds = array_column($r['retained_sections'], 'id');
        $this->assertContains('a', $retainedIds);
        $this->assertContains('b', $retainedIds);
        $this->assertContains('c', $retainedIds);
        $this->assertContains('d', $retainedIds);
        $this->assertContains('e', $retainedIds);
    }

    // ── AC3: always-drop sections ─────────────────────────────────────────────

    public function test_provider_trace_always_omitted(): void
    {
        $r = $this->distiller()->distill([
            'context_sections' => [$this->sec(['type' => 'provider_trace', 'signal_score' => 1.0])],
            'budget_tokens'    => 10000,
        ]);
        $this->assertEmpty($r['retained_sections']);
        $this->assertSame('always_dropped_type', $r['omitted_sections'][0]['reason']);
    }

    public function test_stale_summary_always_omitted(): void
    {
        $r = $this->distiller()->distill([
            'context_sections' => [$this->sec(['type' => 'stale_summary', 'signal_score' => 1.0])],
            'budget_tokens'    => 10000,
        ]);
        $this->assertEmpty($r['retained_sections']);
    }

    public function test_stale_section_omitted_regardless_of_type(): void
    {
        $r = $this->distiller()->distill([
            'context_sections' => [$this->sec(['is_stale' => true, 'signal_score' => 0.99])],
            'budget_tokens'    => 10000,
        ]);
        $this->assertEmpty($r['retained_sections']);
        $this->assertSame('stale', $r['omitted_sections'][0]['reason']);
    }

    public function test_duplicate_section_omitted(): void
    {
        $r = $this->distiller()->distill([
            'context_sections' => [$this->sec(['is_duplicate' => true])],
            'budget_tokens'    => 10000,
        ]);
        $this->assertEmpty($r['retained_sections']);
        $this->assertSame('duplicate', $r['omitted_sections'][0]['reason']);
    }

    public function test_low_signal_non_tiered_section_omitted(): void
    {
        $r = $this->distiller()->distill([
            'context_sections' => [$this->sec(['type' => 'history', 'signal_score' => 0.3])],
            'budget_tokens'    => 10000,
        ]);
        $this->assertEmpty($r['retained_sections']);
        $this->assertSame('low_signal', $r['omitted_sections'][0]['reason']);
    }

    // ── Budget enforcement ────────────────────────────────────────────────────

    public function test_budget_used_matches_retained_token_sum(): void
    {
        $sections = [
            $this->sec(['id' => 'a', 'token_count' => 200]),
            $this->sec(['id' => 'b', 'token_count' => 150]),
        ];
        $r = $this->distiller()->distill(['context_sections' => $sections, 'budget_tokens' => 10000]);
        $this->assertSame(350, $r['budget_used']);
    }

    public function test_section_exceeding_budget_is_omitted(): void
    {
        // Budget 150, section costs 200 → omitted.
        $r = $this->distiller()->distill([
            'context_sections' => [$this->sec(['token_count' => 200])],
            'budget_tokens'    => 150,
        ]);
        $this->assertEmpty($r['retained_sections']);
        $this->assertSame('budget_exhausted', $r['omitted_sections'][0]['reason']);
    }

    // ── risk_of_loss ──────────────────────────────────────────────────────────

    public function test_risk_is_high_when_canonical_decision_dropped_for_budget(): void
    {
        $r = $this->distiller()->distill([
            'context_sections' => [$this->sec(['type' => 'canonical_decision', 'token_count' => 1000])],
            'budget_tokens'    => 50,
        ]);
        $this->assertSame('high', $r['risk_of_loss']);
    }

    public function test_risk_is_medium_when_tier2_dropped_for_budget(): void
    {
        $r = $this->distiller()->distill([
            'context_sections' => [$this->sec(['type' => 'recent_failure', 'token_count' => 1000])],
            'budget_tokens'    => 50,
        ]);
        $this->assertSame('medium', $r['risk_of_loss']);
    }

    public function test_risk_is_low_when_only_non_critical_dropped(): void
    {
        $r = $this->distiller()->distill([
            'context_sections' => [
                $this->sec(['id' => 'a', 'type' => 'history', 'signal_score' => 0.6, 'token_count' => 1000]),
            ],
            'budget_tokens' => 50,
        ]);
        $this->assertSame('low', $r['risk_of_loss']);
    }

    // ── distilled_context content ─────────────────────────────────────────────

    public function test_distilled_context_joins_retained_section_content(): void
    {
        $r = $this->distiller()->distill([
            'context_sections' => [
                $this->sec(['id' => 'a', 'content' => 'First section']),
                $this->sec(['id' => 'b', 'content' => 'Second section', 'type' => 'ac_definition']),
            ],
            'budget_tokens' => 10000,
        ]);
        $this->assertStringContainsString('First section', $r['distilled_context']);
        $this->assertStringContainsString('Second section', $r['distilled_context']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = ['context_sections' => [
            $this->sec(['id' => 'a', 'type' => 'canonical_decision', 'token_count' => 100]),
            $this->sec(['id' => 'b', 'type' => 'provider_trace']),
            $this->sec(['id' => 'c', 'type' => 'history', 'signal_score' => 0.6, 'token_count' => 50]),
        ], 'budget_tokens' => 200];
        $a = $this->distiller()->distill($facts);
        $b = $this->distiller()->distill($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }

    // ── AC2: anti_proxy_rule is Tier-1 ────────────────────────────────────────

    public function test_anti_proxy_rule_retained_as_tier1(): void
    {
        $r = $this->distiller()->distill([
            'context_sections' => [$this->sec(['type' => 'anti_proxy_rule', 'token_count' => 50])],
            'budget_tokens'    => 10000,
        ]);
        $this->assertCount(1, $r['retained_sections']);
        $this->assertSame('anti_proxy_rule', $r['retained_sections'][0]['type']);
        $this->assertSame('low', $r['risk_of_loss']);
    }

    public function test_anti_proxy_rule_budget_exhaustion_causes_high_risk(): void
    {
        $r = $this->distiller()->distill([
            'context_sections' => [$this->sec(['type' => 'anti_proxy_rule', 'token_count' => 1000])],
            'budget_tokens'    => 10,
        ]);
        $this->assertSame('high', $r['risk_of_loss']);
    }

    // ── AC4: missing_critical_sections ────────────────────────────────────────

    public function test_output_has_missing_critical_sections_key(): void
    {
        $r = $this->distiller()->distill([]);
        $this->assertArrayHasKey('missing_critical_sections', $r);
        $this->assertIsArray($r['missing_critical_sections']);
    }

    public function test_missing_critical_sections_lists_absent_tier1_types(): void
    {
        // Only canonical_decision supplied — rest of Tier 1 should appear in missing
        $r = $this->distiller()->distill([
            'context_sections' => [$this->sec(['type' => 'canonical_decision'])],
            'budget_tokens'    => 10000,
        ]);
        $this->assertContains('anti_proxy_rule',      $r['missing_critical_sections']);
        $this->assertContains('queue_constraint',     $r['missing_critical_sections']);
        $this->assertContains('target_path',          $r['missing_critical_sections']);
        $this->assertNotContains('canonical_decision', $r['missing_critical_sections']);
    }

    public function test_missing_critical_sections_empty_when_all_tier1_present(): void
    {
        $sections = [];
        foreach (['canonical_decision', 'ac_definition', 'anti_proxy_rule', 'queue_constraint', 'target_path'] as $i => $type) {
            $sections[] = $this->sec(['id' => "s{$i}", 'type' => $type]);
        }
        $r = $this->distiller()->distill(['context_sections' => $sections, 'budget_tokens' => 10000]);
        $this->assertSame([], $r['missing_critical_sections']);
    }

    public function test_missing_critical_sections_not_affected_by_always_drop(): void
    {
        // provider_trace supplied — it's not Tier-1, so doesn't appear in missing
        $r = $this->distiller()->distill([
            'context_sections' => [$this->sec(['type' => 'provider_trace'])],
            'budget_tokens'    => 10000,
        ]);
        $this->assertNotContains('provider_trace', $r['missing_critical_sections']);
    }

    public function test_empty_input_reports_all_tier1_as_missing(): void
    {
        $r = $this->distiller()->distill([]);
        $missing = $r['missing_critical_sections'];
        foreach (['canonical_decision', 'ac_definition', 'anti_proxy_rule', 'queue_constraint', 'target_path'] as $type) {
            $this->assertContains($type, $missing);
        }
    }
}
