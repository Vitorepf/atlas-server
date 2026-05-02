<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

/**
 * Engine F0 — pin the contract of config('atlas.report.*').
 *
 * The single feature flag (Decisão #4) and per-kind measurement windows (Decisão #13)
 * MUST exist with safe defaults so the engine code can read them without nullchecks.
 */
class AtlasReportConfigTest extends TestCase
{
    public function test_engine_version_default_is_legacy(): void
    {
        $version = config('atlas.report.engine_version');

        $this->assertSame('legacy', $version,
            'Default MUST be legacy so deploying the engine code without setting the flag does NOT change behavior.');
    }

    public function test_recommendation_measurement_windows_per_kind_have_correct_defaults(): void
    {
        $windows = config('atlas.report.recommendation_measurement_window_days');

        $this->assertIsArray($windows);
        $this->assertSame(3, $windows['cost'],
            'Cost is deterministic — 3 days is enough to confirm a rate change took effect.');
        $this->assertSame(7, $windows['latency'],
            'Latency follows weekly usage patterns — 7 days captures one full cycle.');
        $this->assertSame(14, $windows['quality'],
            'Quality includes human feedback with weekly cycle — 14 days = 2 cycles for stable measurement.');
        $this->assertSame(7, $windows['default'],
            'Default window for unknown kinds — same as latency, the most common case.');
    }

    public function test_finding_min_gain_fraction_default_is_35_percent(): void
    {
        $threshold = config('atlas.report.finding_min_gain_fraction');

        $this->assertEqualsWithDelta(0.35, $threshold, 0.0001,
            'Decisão #10: 35% threshold won 3/5 lentes by asymmetric cost — false positive of '
                .'recommendation burns channel credibility worse than missed marginal finding.');
    }

    public function test_engine_version_can_be_overridden_via_env(): void
    {
        // Sanity check that env-driven override works (in case future operators flip via .env)
        config(['atlas.report.engine_version' => 'shadow']);

        $this->assertSame('shadow', config('atlas.report.engine_version'));
    }
}
