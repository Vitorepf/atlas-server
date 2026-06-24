<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopNetDiffCertCollector;
use Tests\TestCase;

final class AtlasLoopNetDiffCertCollectorTest extends TestCase
{
    public function test_happy_path_collects_both_sides_with_distinct_shas(): void
    {
        $result = $this->collector()->collect(
            'cert-123',
            $this->side('maximize', 0.73, '2026-06-24T17:00:00Z', 'base-sha'),
            $this->side('maximize', 0.84, '2026-06-24T17:05:00Z', 'candidate-sha'),
        );

        $this->assertSame([
            'armed',
            'reason',
            'cert_id',
            'baseline_side',
            'candidate_side',
            'both_sides_present',
        ], array_keys($result));
        $this->assertTrue($result['armed']);
        $this->assertNull($result['reason']);
        $this->assertTrue($result['both_sides_present']);
        $this->assertSame('cert-123', $result['cert_id']);
        $this->assertSame(0.73, $result['baseline_side']['value']);
        $this->assertSame('base-sha', $result['baseline_side']['source_sha']);
        $this->assertSame(0.84, $result['candidate_side']['value']);
        $this->assertSame('candidate-sha', $result['candidate_side']['source_sha']);
    }

    public function test_baseline_equals_candidate_fails_closed(): void
    {
        $result = $this->collector()->collect(
            'cert-flat',
            $this->side('gate', 1.0, '2026-06-24T17:00:00Z', 'same-sha'),
            $this->side('gate', 1.0, '2026-06-24T17:05:00Z', 'same-sha'),
        );

        $this->assertFalse($result['armed']);
        $this->assertSame('baseline_equals_candidate', $result['reason']);
        $this->assertTrue($result['both_sides_present']);
    }

    public function test_missing_or_non_finite_candidate_value_fails_closed(): void
    {
        foreach (
            [
                'missing' => array_diff_key($this->side('maximize', 0.84, '2026-06-24T17:05:00Z', 'candidate-sha'), ['value' => true]),
                'nan' => $this->side('maximize', NAN, '2026-06-24T17:05:00Z', 'candidate-sha'),
                'inf' => $this->side('maximize', INF, '2026-06-24T17:05:00Z', 'candidate-sha'),
                'flagged' => $this->side('maximize', 0.84, '2026-06-24T17:05:00Z', 'candidate-sha', false),
            ] as $label => $candidate
        ) {
            $result = $this->collector()->collect(
                'cert-'.$label,
                $this->side('maximize', 0.73, '2026-06-24T17:00:00Z', 'base-sha'),
                $candidate,
            );

            $this->assertFalse($result['armed'], $label);
            $this->assertSame('candidate_non_finite', $result['reason'], $label);
            $this->assertFalse($result['both_sides_present'], $label);
            $this->assertNull($result['candidate_side'], $label);
        }
    }

    public function test_collector_never_mutates_inputs_and_is_idempotent(): void
    {
        $baseline = $this->side('MINIMIZE', '10.5', '2026-06-24T17:00:00Z', 'base-sha');
        $candidate = $this->side('minimize', 8.25, '2026-06-24T17:05:00Z', 'candidate-sha');
        $baselineBefore = $baseline;
        $candidateBefore = $candidate;

        $first = $this->collector()->collect('cert-idempotent', $baseline, $candidate);
        $second = $this->collector()->collect('cert-idempotent', $baseline, $candidate);

        $this->assertSame($baselineBefore, $baseline);
        $this->assertSame($candidateBefore, $candidate);
        $this->assertSame($first, $second);
        $this->assertSame('minimize', $first['baseline_side']['metric_kind']);
        $this->assertSame(10.5, $first['baseline_side']['value']);
    }

    public function test_provider_free_collector_does_not_spawn_processes(): void
    {
        $source = (string) file_get_contents(app_path('Services/Ai/AutonomousEvolution/AtlasLoopNetDiffCertCollector.php'));

        $this->assertStringContainsString('docs/loop-os-architecture.md §9', $source);
        $this->assertStringNotContainsString('Process', $source);
        $this->assertStringNotContainsString('shell_exec', $source);
        $this->assertStringNotContainsString('exec(', $source);
    }

    private function collector(): AtlasLoopNetDiffCertCollector
    {
        return new AtlasLoopNetDiffCertCollector;
    }

    /**
     * @return array<string,mixed>
     */
    private function side(string $kind, mixed $value, string $capturedAt, string $sourceSha, bool $metricFinite = true): array
    {
        return [
            'metric_kind' => $kind,
            'value' => $value,
            'captured_at' => $capturedAt,
            'source_sha' => $sourceSha,
            'metric_finite' => $metricFinite,
        ];
    }
}
