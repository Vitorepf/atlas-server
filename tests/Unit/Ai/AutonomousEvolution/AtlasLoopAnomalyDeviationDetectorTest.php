<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Anomaly\AtlasLoopAnomalyDeviationDetector;
use Tests\TestCase;

final class AtlasLoopAnomalyDeviationDetectorTest extends TestCase
{
    private function currentWindow(): array
    {
        return [
            'window_start' => '2026-06-25T05:00:00+00:00',
            'window_end' => '2026-06-25T06:00:00+00:00',
        ];
    }

    public function test_signal_exceeding_two_sigma_is_reported(): void
    {
        $baseline = [
            'give_back' => ['rate' => 0.10, 'buckets' => [0.08, 0.09, 0.10, 0.11, 0.12]],
            'completion' => ['rate' => 0.85, 'buckets' => [0.84, 0.85, 0.86, 0.85, 0.85]],
        ];
        $current = array_replace($this->currentWindow(), [
            'give_back' => ['rate' => 0.50],  // huge spike
            'completion' => ['rate' => 0.86], // within 1 sigma
        ]);

        $out = (new AtlasLoopAnomalyDeviationDetector)->detect($baseline, $current);

        $this->assertCount(1, $out);
        $this->assertSame('give_back', $out[0]['signal']);
        $this->assertSame(0.10, $out[0]['baseline_rate']);
        $this->assertSame(0.50, $out[0]['current_rate']);
        $this->assertGreaterThan(2.0, abs($out[0]['delta_in_sigmas']));
        $this->assertSame('2026-06-25T05:00:00+00:00', $out[0]['window_start']);
    }

    public function test_signal_within_two_sigma_is_not_reported(): void
    {
        $baseline = [
            'give_back' => ['rate' => 0.10, 'buckets' => [0.05, 0.10, 0.15, 0.10, 0.10]],
        ];
        $current = array_replace($this->currentWindow(), [
            'give_back' => ['rate' => 0.13],
        ]);

        $out = (new AtlasLoopAnomalyDeviationDetector)->detect($baseline, $current);

        $this->assertSame([], $out);
    }

    public function test_zero_sigma_baseline_returns_empty_list_not_divide_by_zero(): void
    {
        $baseline = [
            'give_back' => ['rate' => 0.10, 'buckets' => [0.10, 0.10, 0.10, 0.10]],
        ];
        $current = array_replace($this->currentWindow(), [
            'give_back' => ['rate' => 0.50],
        ]);

        $out = (new AtlasLoopAnomalyDeviationDetector)->detect($baseline, $current);
        $this->assertSame([], $out, 'no historical variance ⇒ no deviation FACT (and no divide by zero)');
    }

    public function test_empty_buckets_return_empty_list(): void
    {
        $baseline = [
            'give_back' => ['rate' => 0.10, 'buckets' => []],
        ];
        $current = array_replace($this->currentWindow(), [
            'give_back' => ['rate' => 0.50],
        ]);

        $this->assertSame([], (new AtlasLoopAnomalyDeviationDetector)->detect($baseline, $current));
    }

    public function test_records_are_facts_only_no_verdict_or_score_keys(): void
    {
        $baseline = [
            'give_back' => ['rate' => 0.10, 'buckets' => [0.08, 0.09, 0.10, 0.11, 0.12]],
        ];
        $current = array_replace($this->currentWindow(), [
            'give_back' => ['rate' => 0.50],
        ]);

        $out = (new AtlasLoopAnomalyDeviationDetector)->detect($baseline, $current);
        $row = $out[0];

        $this->assertSame(['signal', 'baseline_rate', 'current_rate', 'sigma', 'delta_in_sigmas', 'window_start', 'window_end'], array_keys($row));
        foreach (['anomalous', 'verdict', 'score', 'severity', 'alarm', 'critical'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row);
        }
    }
}
