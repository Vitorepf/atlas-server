<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Anomaly\AtlasLoopAnomalyBaselineReporter;
use Tests\TestCase;

final class AtlasLoopAnomalyBaselineReporterTest extends TestCase
{
    private function window(): array
    {
        return ['start' => '2026-06-25T05:00:00+00:00', 'end' => '2026-06-25T06:00:00+00:00'];
    }

    private function receipt(string $outcome, string $ts): array
    {
        return ['outcome' => $outcome, 'ts' => $ts];
    }

    public function test_baseline_returns_counts_and_rates_for_each_signal(): void
    {
        $receipts = [
            $this->receipt('completion', '2026-06-25T05:05:00+00:00'),
            $this->receipt('completion', '2026-06-25T05:10:00+00:00'),
            $this->receipt('give_back', '2026-06-25T05:15:00+00:00'),
            $this->receipt('merge', '2026-06-25T05:20:00+00:00'),
            $this->receipt('cancel', '2026-06-25T05:30:00+00:00'),
        ];
        $r = (new AtlasLoopAnomalyBaselineReporter)->baseline($this->window(), $receipts);

        $this->assertSame(AtlasLoopAnomalyBaselineReporter::SCHEMA, $r['schema_version']);
        $this->assertSame(2, $r['completion']['count_in_window']);
        $this->assertSame(5, $r['completion']['total_in_window']);
        $this->assertEqualsWithDelta(0.4, $r['completion']['rate'], 1e-9);
        $this->assertSame(1, $r['give_back']['count_in_window']);
        $this->assertSame(1, $r['merge']['count_in_window']);
        $this->assertSame(1, $r['cancel']['count_in_window']);
        foreach (['completion', 'give_back', 'merge', 'cancel'] as $sig) {
            $this->assertSame('2026-06-25T05:00:00+00:00', $r[$sig]['window_start']);
            $this->assertSame('2026-06-25T06:00:00+00:00', $r[$sig]['window_end']);
        }
    }

    public function test_receipts_outside_window_are_excluded(): void
    {
        $receipts = [
            $this->receipt('completion', '2026-06-25T04:59:59+00:00'), // before
            $this->receipt('completion', '2026-06-25T06:00:00+00:00'), // at end (excluded)
            $this->receipt('completion', '2026-06-25T05:30:00+00:00'), // inside
        ];
        $r = (new AtlasLoopAnomalyBaselineReporter)->baseline($this->window(), $receipts);

        $this->assertSame(1, $r['completion']['count_in_window']);
        $this->assertSame(1, $r['completion']['total_in_window']);
    }

    public function test_zero_total_gives_zero_rate_not_division_error(): void
    {
        $r = (new AtlasLoopAnomalyBaselineReporter)->baseline($this->window(), []);

        foreach (AtlasLoopAnomalyBaselineReporter::TRACKED_SIGNALS as $sig) {
            $this->assertSame(0, $r[$sig]['count_in_window']);
            $this->assertSame(0, $r[$sig]['total_in_window']);
            $this->assertSame(0.0, $r[$sig]['rate']);
        }
    }

    public function test_other_outcomes_are_counted_in_total_but_not_in_any_signal(): void
    {
        $receipts = [
            $this->receipt('completion', '2026-06-25T05:10:00+00:00'),
            $this->receipt('unknown_outcome', '2026-06-25T05:20:00+00:00'),
        ];
        $r = (new AtlasLoopAnomalyBaselineReporter)->baseline($this->window(), $receipts);

        $this->assertSame(2, $r['completion']['total_in_window']);
        $this->assertSame(1, $r['completion']['count_in_window']);
        $this->assertEqualsWithDelta(0.5, $r['completion']['rate'], 1e-9);
        $this->assertSame(0, $r['give_back']['count_in_window']);
    }

    public function test_output_is_byte_identical_across_calls_with_same_input(): void
    {
        $receipts = [
            $this->receipt('completion', '2026-06-25T05:10:00+00:00'),
            $this->receipt('give_back', '2026-06-25T05:20:00+00:00'),
        ];
        $a = (new AtlasLoopAnomalyBaselineReporter)->baseline($this->window(), $receipts);
        $b = (new AtlasLoopAnomalyBaselineReporter)->baseline($this->window(), $receipts);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_payload_contains_only_counts_and_rates_no_scores_or_adjectives(): void
    {
        $receipts = [$this->receipt('completion', '2026-06-25T05:10:00+00:00')];
        $r = (new AtlasLoopAnomalyBaselineReporter)->baseline($this->window(), $receipts);

        $json = (string) json_encode($r);
        foreach (['severity', 'verdict', 'score', 'alert', 'critical', 'danger', 'concerning', 'high', 'low', 'medium'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $json, "payload must not contain {$forbidden}");
        }
    }
}
