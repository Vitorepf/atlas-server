<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Console\Commands\AtlasLoopEvolutionReportCommand;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The evolution report must MEASURE HONESTLY — the same anti-cosmetic bar the loop itself is held to applies
 * to the instrument that judges it. These pin the two correctness rules: (1) origination source collapses to
 * its supplying-lane prefix; (2) the GAIN verdict is time-fair — it judges RATES, so a longer baseline can
 * never look "better" merely by accumulating more raw tasks while its per-hour/ratio quality is worse.
 */
final class AtlasLoopEvolutionReportCommandTest extends TestCase
{
    private function invoke(string $method, array $args): mixed
    {
        $cmd = new AtlasLoopEvolutionReportCommand();
        $m = new ReflectionMethod($cmd, $method);
        $m->setAccessible(true);

        return $m->invoke($cmd, ...$args);
    }

    public function test_normalize_source_collapses_lane_prefix_and_handles_empty(): void
    {
        $this->assertSame('producer', $this->invoke('normalizeSource', ['producer:objective']));
        $this->assertSame('coverage_gap_characterization', $this->invoke('normalizeSource', ['coverage_gap_characterization']));
        $this->assertSame('unattributed', $this->invoke('normalizeSource', ['']));
        $this->assertSame('unattributed', $this->invoke('normalizeSource', ['   ']));
    }

    public function test_gain_is_time_fair_rates_not_raw_counts(): void
    {
        // the baseline ran LONGER: more raw substantive (10) and cert (8) — but LOWER rates. The current
        // campaign has fewer raw counts yet HIGHER per-hour throughput and HIGHER ratios. Time-fair => better.
        $base = ['substantive' => 10, 'substantive_ratio' => 0.30, 'tasks_per_hour' => 5.0, 'cert_count' => 8, 'cert_rate' => 0.40, 'proxy' => 0];
        $cur = ['substantive' => 3, 'substantive_ratio' => 0.45, 'tasks_per_hour' => 9.0, 'cert_count' => 2, 'cert_rate' => 0.50, 'proxy' => 0];

        $gain = $this->invoke('gain', [$base, $cur]);

        $this->assertTrue($gain['better'], 'higher rates must read as BETTER even with fewer raw counts');
        $this->assertEqualsWithDelta(0.15, $gain['substantive_ratio'], 0.0001);
        $this->assertEqualsWithDelta(4.0, $gain['tasks_per_hour'], 0.0001);
    }

    public function test_gain_flags_worse_when_rates_drop(): void
    {
        $base = ['substantive' => 1, 'substantive_ratio' => 0.50, 'tasks_per_hour' => 9.0, 'cert_count' => 1, 'cert_rate' => 0.50, 'proxy' => 0];
        $cur = ['substantive' => 1, 'substantive_ratio' => 0.30, 'tasks_per_hour' => 4.0, 'cert_count' => 1, 'cert_rate' => 0.20, 'proxy' => 2];

        $gain = $this->invoke('gain', [$base, $cur]);

        $this->assertFalse($gain['better'], 'dropping rates must read as worse, never laundered by raw counts');
    }
}
