<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainRunRetrospectiveCompiler;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainRunRetrospectiveCompilerTest extends TestCase
{
    private function compiler(): AtlasExternalBrainRunRetrospectiveCompiler
    {
        return new AtlasExternalBrainRunRetrospectiveCompiler;
    }

    private function out(string $specId, string $outcome, string $category = 'gate-impl', array $extra = []): array
    {
        return array_merge(['spec_id' => $specId, 'outcome' => $outcome, 'category' => $category], $extra);
    }

    // ── AC1: summary includes success / give_back / rejected / duplicated / low-yield patterns ──

    public function test_summary_has_required_keys(): void
    {
        $r = $this->compiler()->compile([]);

        foreach (['total_outcomes', 'success_count', 'give_back_count',
                  'rejected_count', 'duplicate_count', 'proxy_smell_count',
                  'yield_rate', 'wasted_token_ratio'] as $k) {
            $this->assertArrayHasKey($k, $r['summary']);
        }
    }

    public function test_summary_counts_success_and_give_back(): void
    {
        $outcomes = [
            $this->out('s1', 'success'),
            $this->out('s2', 'success'),
            $this->out('g1', 'give_back'),
        ];

        $r = $this->compiler()->compile($outcomes);

        $this->assertSame(2, $r['summary']['success_count']);
        $this->assertSame(1, $r['summary']['give_back_count']);
        $this->assertSame(0, $r['summary']['rejected_count']);
    }

    public function test_summary_counts_rejected(): void
    {
        $outcomes = [
            $this->out('r1', 'rejected'),
            $this->out('r2', 'rejected'),
        ];

        $r = $this->compiler()->compile($outcomes);

        $this->assertSame(2, $r['summary']['rejected_count']);
    }

    public function test_summary_counts_duplicates_by_reason(): void
    {
        $outcomes = [
            $this->out('d1', 'give_back', 'gate-impl', ['reason' => 'duplicate_target']),
            $this->out('d2', 'rejected',  'gate-impl', ['reason' => 'is_duplicate_of_existing']),
        ];

        $r = $this->compiler()->compile($outcomes);

        $this->assertSame(2, $r['summary']['duplicate_count']);
    }

    public function test_summary_yield_rate_reflects_low_yield(): void
    {
        $outcomes = [
            $this->out('s1', 'success'),
            $this->out('g1', 'give_back'),
            $this->out('g2', 'give_back'),
            $this->out('g3', 'give_back'),
        ];

        $r = $this->compiler()->compile($outcomes);

        $this->assertLessThan(0.40, $r['summary']['yield_rate']);
    }

    // ── AC2: next_cycle_adjustments has all 4 required keys ──────────────────

    public function test_next_cycle_adjustments_has_required_keys(): void
    {
        $r = $this->compiler()->compile([]);

        $adj = $r['next_cycle_adjustments'];
        foreach (['promote_patterns', 'avoid_patterns', 'consolidate_targets', 'research_gaps'] as $k) {
            $this->assertArrayHasKey($k, $adj);
        }
    }

    public function test_high_yield_category_appears_in_promote_patterns(): void
    {
        // 3 successes in one category → yield ≥ 70%
        $outcomes = array_fill(0, 3, $this->out('s', 'success', 'bug-hunt'));

        $r = $this->compiler()->compile($outcomes);

        $this->assertContains('category:bug-hunt', $r['next_cycle_adjustments']['promote_patterns']);
    }

    public function test_low_yield_category_appears_in_avoid_patterns(): void
    {
        // 1 success + 3 give_backs in same category → yield 25%
        $outcomes = [
            $this->out('s1', 'success',   'research'),
            $this->out('g1', 'give_back', 'research'),
            $this->out('g2', 'give_back', 'research'),
            $this->out('g3', 'give_back', 'research'),
        ];

        $r = $this->compiler()->compile($outcomes);

        $this->assertContains('category:research', $r['next_cycle_adjustments']['avoid_patterns']);
    }

    public function test_duplicate_targets_appear_in_consolidate_targets(): void
    {
        $outcomes = [
            $this->out('dup-a', 'give_back', 'gate-impl', ['reason' => 'duplicate_target']),
            $this->out('dup-b', 'give_back', 'gate-impl', ['reason' => 'duplicate_target']),
        ];

        $r = $this->compiler()->compile($outcomes);

        $this->assertNotEmpty($r['next_cycle_adjustments']['consolidate_targets']);
    }

    public function test_weak_evidence_outcomes_add_research_gap(): void
    {
        $outcomes = [
            $this->out('w1', 'give_back', 'gate-impl', ['reason' => 'insufficient_context']),
        ];

        $r = $this->compiler()->compile($outcomes);

        $this->assertContains(
            'weak_evidence:gather_evidence_before_next_cycle',
            $r['next_cycle_adjustments']['research_gaps'],
        );
    }

    // ── AC3: token-waste or repeated rejection changes next cycle recommendation ──

    public function test_high_token_waste_ratio_adds_avoid_pattern(): void
    {
        // All tokens wasted (only give_back)
        $outcomes = [
            $this->out('g1', 'give_back', 'gate-impl', ['tokens_spent' => 500]),
            $this->out('g2', 'give_back', 'gate-impl', ['tokens_spent' => 500]),
        ];

        $r = $this->compiler()->compile($outcomes);

        $this->assertGreaterThan(0.50, $r['summary']['wasted_token_ratio']);
        $avoidPatterns = implode('|', $r['next_cycle_adjustments']['avoid_patterns']);
        $this->assertStringContainsString('high_token_waste', $avoidPatterns);
    }

    public function test_repeated_rejection_adds_consolidate_target(): void
    {
        $outcomes = array_fill(0, 3, $this->out('r', 'rejected', 'gate-impl'));

        $r = $this->compiler()->compile($outcomes);

        $consolidate = implode('|', $r['next_cycle_adjustments']['consolidate_targets']);
        $this->assertStringContainsString('repeated_rejection', $consolidate);
    }

    public function test_padding_detected_adds_avoid_quota_padding(): void
    {
        $outcomes = [
            $this->out('p1', 'proxy_smell', 'gate-impl', ['poison_patterns' => ['template_farm']]),
        ];

        $r = $this->compiler()->compile($outcomes);

        $this->assertSame(AtlasExternalBrainRunRetrospectiveCompiler::SIGNAL_PADDING_DETECTED, $r['integrity_signal']);
        $this->assertContains('quota_padding_patterns', $r['next_cycle_adjustments']['avoid_patterns']);
    }

    // ── AC4: pure and deterministic ───────────────────────────────────────────

    public function test_compile_is_deterministic(): void
    {
        $outcomes = [
            $this->out('s1', 'success',   'bug-hunt',  ['tokens_spent' => 100, 'leverage_score' => 0.85]),
            $this->out('g1', 'give_back', 'research',  ['tokens_spent' => 200, 'reason' => 'scope_too_large']),
            $this->out('r1', 'rejected',  'gate-impl', ['tokens_spent' => 150, 'reason' => 'contradictory_acceptance']),
        ];

        $a = $this->compiler()->compile($outcomes, ['run_id' => 'test-run']);
        $b = $this->compiler()->compile($outcomes, ['run_id' => 'test-run']);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
