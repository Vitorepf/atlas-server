<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompressionChangeBudget;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCompressionChangeBudgetTest extends TestCase
{
    private function budgeter(): AtlasExternalBrainCompressionChangeBudget
    {
        return new AtlasExternalBrainCompressionChangeBudget;
    }

    private function safeFacts(array $overrides = []): array
    {
        return array_merge([
            'risk_level' => 'low',
            'proof_coverage' => 1.0,
            'has_rollback_path' => true,
            'worker_capacity' => 5,
        ], $overrides);
    }

    // ── AC: large_safe_budget_case ──────────────────────────────────────────

    public function test_large_safe_budget_case(): void
    {
        $r = $this->budgeter()->budget(['facts' => $this->safeFacts()]);

        $this->assertSame('proceed', $r['decision']);
        $this->assertSame(20, $r['max_files']);
        $this->assertGreaterThan(0, $r['max_actions']);
        $this->assertSame([], $r['required_prework']);
    }

    // ── AC: high_risk_small_budget_case ─────────────────────────────────────

    public function test_high_risk_small_budget_case(): void
    {
        $r = $this->budgeter()->budget(['facts' => $this->safeFacts(['risk_level' => 'high'])]);

        $this->assertSame('proceed', $r['decision']);
        $this->assertSame(3, $r['max_files']);
        $this->assertLessThan(20, $r['max_files']);
    }

    public function test_medium_risk_produces_intermediate_budget(): void
    {
        $r = $this->budgeter()->budget(['facts' => $this->safeFacts(['risk_level' => 'medium'])]);

        $this->assertSame(10, $r['max_files']);
    }

    // ── low proof coverage shrinks or holds ──────────────────────────────────

    public function test_moderately_low_proof_coverage_shrinks_budget(): void
    {
        $r = $this->budgeter()->budget(['facts' => $this->safeFacts(['proof_coverage' => 0.4])]);

        $this->assertSame('proceed', $r['decision']);
        $this->assertSame(10, $r['max_files']);
        $this->assertContains('proof_coverage_improvement', $r['required_prework']);
    }

    public function test_severely_low_proof_coverage_holds(): void
    {
        $r = $this->budgeter()->budget(['facts' => $this->safeFacts(['proof_coverage' => 0.1])]);

        $this->assertSame('hold', $r['decision']);
        $this->assertSame(0, $r['max_files']);
        $this->assertSame(0, $r['max_actions']);
    }

    // ── missing rollback path always holds ──────────────────────────────────

    public function test_missing_rollback_path_holds_regardless_of_risk(): void
    {
        $r = $this->budgeter()->budget(['facts' => $this->safeFacts(['has_rollback_path' => false])]);

        $this->assertSame('hold', $r['decision']);
        $this->assertContains('rollback_path_proof', $r['required_prework']);
    }

    // ── worker capacity bounds max_actions ────────────────────────────────────

    public function test_low_worker_capacity_bounds_max_actions(): void
    {
        $r = $this->budgeter()->budget(['facts' => $this->safeFacts(['worker_capacity' => 1, 'risk_level' => 'low'])]);

        $this->assertLessThanOrEqual(5, $r['max_actions']);
    }

    // ── Determinism ────────────────────────────────────────────────────────

    public function test_budget_is_deterministic(): void
    {
        $facts = ['facts' => $this->safeFacts()];
        $a = $this->budgeter()->budget($facts);
        $b = $this->budgeter()->budget($facts);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_schema_version_present(): void
    {
        $r = $this->budgeter()->budget([]);
        $this->assertSame(AtlasExternalBrainCompressionChangeBudget::SCHEMA, $r['schema']);
    }
}
