<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainIndirectionCollapseAdvisor;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainIndirectionCollapseAdvisorTest extends TestCase
{
    private function advisor(): AtlasExternalBrainIndirectionCollapseAdvisor
    {
        return new AtlasExternalBrainIndirectionCollapseAdvisor;
    }

    private function safeCandidate(array $overrides = []): array
    {
        return array_merge([
            'is_public' => false,
            'consumer_count' => 1,
            'covered_by_tests' => true,
            'behavior_equivalent' => true,
        ], $overrides);
    }

    // ── AC: safe_collapse_case ──────────────────────────────────────────────

    public function test_safe_collapse_case_when_non_public_single_consumer_covered_and_equivalent(): void
    {
        $r = $this->advisor()->advise(['candidate' => $this->safeCandidate()]);

        $this->assertSame('collapse', $r['recommendation']);
        $this->assertTrue($r['collapse_approved']);
        $this->assertSame([], $r['required_proof']);
    }

    // ── AC: public_api_hold_case ────────────────────────────────────────────

    public function test_public_api_hold_case(): void
    {
        $r = $this->advisor()->advise(['candidate' => $this->safeCandidate(['is_public' => true])]);

        $this->assertSame('hold', $r['recommendation']);
        $this->assertFalse($r['collapse_approved']);
        $this->assertContains('public_api_requires_explicit_deprecation_proof', $r['required_proof']);
    }

    // ── AC: multi-consumer indirection holds with required proof ────────────

    public function test_multi_consumer_indirection_holds(): void
    {
        $r = $this->advisor()->advise(['candidate' => $this->safeCandidate(['consumer_count' => 3])]);

        $this->assertSame('hold', $r['recommendation']);
        $this->assertContains('multi_consumer_indirection_requires_all_consumer_migration_proof', $r['required_proof']);
    }

    public function test_zero_consumer_indirection_holds(): void
    {
        $r = $this->advisor()->advise(['candidate' => $this->safeCandidate(['consumer_count' => 0])]);

        $this->assertSame('hold', $r['recommendation']);
        $this->assertContains('zero_consumer_indirection_requires_dead_code_proof_not_collapse_advice', $r['required_proof']);
    }

    // ── AC: uncovered behavior holds with required proof ────────────────────

    public function test_uncovered_behavior_holds(): void
    {
        $r = $this->advisor()->advise(['candidate' => $this->safeCandidate(['covered_by_tests' => false])]);

        $this->assertSame('hold', $r['recommendation']);
        $this->assertContains('missing_test_coverage_proof', $r['required_proof']);
    }

    public function test_non_equivalent_behavior_holds(): void
    {
        $r = $this->advisor()->advise(['candidate' => $this->safeCandidate(['behavior_equivalent' => false])]);

        $this->assertSame('hold', $r['recommendation']);
        $this->assertContains('missing_behavior_equivalence_proof', $r['required_proof']);
    }

    public function test_multiple_violations_are_all_named(): void
    {
        $r = $this->advisor()->advise(['candidate' => [
            'is_public' => true,
            'consumer_count' => 5,
            'covered_by_tests' => false,
            'behavior_equivalent' => false,
        ]]);

        $this->assertCount(4, $r['required_proof']);
    }

    // ── Determinism ────────────────────────────────────────────────────────

    public function test_advise_is_deterministic(): void
    {
        $facts = ['candidate' => $this->safeCandidate()];
        $a = $this->advisor()->advise($facts);
        $b = $this->advisor()->advise($facts);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_schema_version_present(): void
    {
        $r = $this->advisor()->advise([]);
        $this->assertSame(AtlasExternalBrainIndirectionCollapseAdvisor::SCHEMA, $r['schema']);
    }
}
