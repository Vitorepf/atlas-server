<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainStaleEvidenceVeto;
use PHPUnit\Framework\TestCase;

final class AtlasBrainStaleEvidenceVetoTest extends TestCase
{
    private AtlasBrainStaleEvidenceVeto $veto;

    protected function setUp(): void
    {
        $this->veto = new AtlasBrainStaleEvidenceVeto;
    }

    // ── AC2: negative age → accept / future_dated_skipped ─────────────────────

    public function test_negative_age_returns_accept_with_future_dated_reason(): void
    {
        $r = $this->veto->review(-1);

        $this->assertSame('accept', $r['verdict']);
        $this->assertSame('future_dated_skipped', $r['reason']);
    }

    public function test_negative_age_includes_schema(): void
    {
        $r = $this->veto->review(-100);

        $this->assertSame(AtlasBrainStaleEvidenceVeto::SCHEMA, $r['schema']);
    }

    // ── AC3: age ≤ threshold → accept / within_freshness_window ──────────────

    public function test_zero_age_returns_accept(): void
    {
        $r = $this->veto->review(0);

        $this->assertSame('accept', $r['verdict']);
        $this->assertSame('within_freshness_window', $r['reason']);
    }

    public function test_age_equal_to_threshold_returns_accept(): void
    {
        $r = $this->veto->review(3600);

        $this->assertSame('accept', $r['verdict']);
        $this->assertSame('within_freshness_window', $r['reason']);
    }

    public function test_age_below_threshold_returns_accept(): void
    {
        $r = $this->veto->review(1800, 3600);

        $this->assertSame('accept', $r['verdict']);
    }

    public function test_custom_threshold_respected(): void
    {
        $r = $this->veto->review(500, 500);

        $this->assertSame('accept', $r['verdict']);
    }

    // ── AC4: age > threshold → veto with age, threshold, named reason ─────────

    public function test_age_above_threshold_returns_veto(): void
    {
        $r = $this->veto->review(3601);

        $this->assertSame('veto', $r['verdict']);
    }

    public function test_veto_includes_age_seconds(): void
    {
        $r = $this->veto->review(7200, 3600);

        $this->assertSame(7200, $r['age_seconds']);
    }

    public function test_veto_includes_threshold_seconds(): void
    {
        $r = $this->veto->review(7200, 3600);

        $this->assertSame(3600, $r['threshold_seconds']);
    }

    public function test_veto_reason_names_both_values(): void
    {
        $r = $this->veto->review(7200, 3600);

        $this->assertStringContainsString('7200', $r['reason']);
        $this->assertStringContainsString('3600', $r['reason']);
    }

    public function test_veto_includes_schema(): void
    {
        $r = $this->veto->review(9999, 100);

        $this->assertSame(AtlasBrainStaleEvidenceVeto::SCHEMA, $r['schema']);
    }

    // ── reviewNewest: multiple evidence ages ──────────────────────────────────

    public function test_review_newest_empty_returns_veto(): void
    {
        $r = $this->veto->reviewNewest([]);

        $this->assertSame('veto', $r['verdict']);
        $this->assertSame('no_evidence_supplied', $r['reason']);
    }

    public function test_review_newest_uses_minimum_age(): void
    {
        // newest = 100s, threshold = 3600 → accept
        $r = $this->veto->reviewNewest([9000, 100, 7200], 3600);

        $this->assertSame('accept', $r['verdict']);
        $this->assertSame(100, $r['age_seconds']);
    }

    public function test_review_newest_veto_when_even_newest_is_stale(): void
    {
        // newest = 5000s, threshold = 3600 → veto
        $r = $this->veto->reviewNewest([5000, 9000], 3600);

        $this->assertSame('veto', $r['verdict']);
        $this->assertSame(5000, $r['age_seconds']);
    }
}
