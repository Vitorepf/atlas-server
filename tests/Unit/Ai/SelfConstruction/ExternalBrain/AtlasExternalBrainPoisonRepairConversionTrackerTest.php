<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPoisonRepairConversionTracker;
use Tests\TestCase;

final class AtlasExternalBrainPoisonRepairConversionTrackerTest extends TestCase
{
    private function svc(): AtlasExternalBrainPoisonRepairConversionTracker
    {
        return new AtlasExternalBrainPoisonRepairConversionTracker;
    }

    private function event(string $rootCause, string $status, int $attempts = 1): array
    {
        return ['root_cause' => $rootCause, 'status' => $status, 'repair_attempts' => $attempts];
    }

    // ── AC1: output structure ───────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->svc()->track([]);

        $this->assertArrayHasKey('schema', $r);
        $this->assertArrayHasKey('family_metrics', $r);
        $this->assertArrayHasKey('top_repair_candidate', $r);
        $this->assertArrayHasKey('dead_end_families', $r);
        $this->assertArrayHasKey('learning_notes', $r);
        $this->assertSame(AtlasExternalBrainPoisonRepairConversionTracker::SCHEMA, $r['schema']);
    }

    public function test_empty_input_produces_empty_metrics(): void
    {
        $r = $this->svc()->track([]);

        $this->assertSame([], $r['family_metrics']);
        $this->assertNull($r['top_repair_candidate']);
        $this->assertSame([], $r['dead_end_families']);
    }

    // ── AC2: repeated give_back packets with same root cause grouped into family ──

    public function test_repeated_packets_with_same_root_cause_are_grouped_into_one_family(): void
    {
        $r = $this->svc()->track([
            $this->event('missing_evidence', 'pending'),
            $this->event('missing_evidence', 'pending'),
            $this->event('missing_evidence', 'pending'),
        ]);

        $this->assertArrayHasKey('missing_evidence', $r['family_metrics']);
        $this->assertSame(3, $r['family_metrics']['missing_evidence']['count']);
    }

    public function test_family_metrics_includes_repair_status(): void
    {
        $r = $this->svc()->track([
            $this->event('scope_collision', 'pending'),
        ]);

        $this->assertArrayHasKey('repair_status', $r['family_metrics']['scope_collision']);
        $this->assertIsString($r['family_metrics']['scope_collision']['repair_status']);
    }

    public function test_distinct_root_causes_produce_distinct_families(): void
    {
        $r = $this->svc()->track([
            $this->event('missing_evidence', 'pending'),
            $this->event('scope_collision', 'pending'),
        ]);

        $this->assertCount(2, $r['family_metrics']);
        $this->assertArrayHasKey('missing_evidence', $r['family_metrics']);
        $this->assertArrayHasKey('scope_collision', $r['family_metrics']);
    }

    public function test_events_without_root_cause_are_ignored(): void
    {
        $r = $this->svc()->track([
            ['status' => 'pending'],
            ['root_cause' => '', 'status' => 'pending'],
        ]);

        $this->assertSame([], $r['family_metrics']);
    }

    // ── AC3: successful repairs raise conversion_rate ────────────────────────────

    public function test_successful_repairs_raise_conversion_rate(): void
    {
        $low = $this->svc()->track([
            $this->event('fam-a', 'repaired_success'),
            $this->event('fam-a', 'pending'),
            $this->event('fam-a', 'pending'),
            $this->event('fam-a', 'pending'),
        ])['family_metrics']['fam-a']['conversion_rate'];

        $high = $this->svc()->track([
            $this->event('fam-a', 'repaired_success'),
            $this->event('fam-a', 'repaired_success'),
            $this->event('fam-a', 'repaired_success'),
            $this->event('fam-a', 'pending'),
        ])['family_metrics']['fam-a']['conversion_rate'];

        $this->assertGreaterThan($low, $high, 'more successful repairs must raise conversion_rate');
    }

    public function test_all_success_yields_fully_converted_status_and_rate_one(): void
    {
        $r = $this->svc()->track([
            $this->event('fam-b', 'repaired_success'),
            $this->event('fam-b', 'repaired_success'),
        ]);

        $this->assertSame('fully_converted', $r['family_metrics']['fam-b']['repair_status']);
        $this->assertSame(1.0, $r['family_metrics']['fam-b']['conversion_rate']);
    }

    // ── AC4: unchanged retries lower conversion_rate + emit stop_retrying_unchanged ──

    public function test_unchanged_retries_lower_conversion_rate(): void
    {
        $withoutUnchanged = $this->svc()->track([
            $this->event('fam-c', 'repaired_success'),
        ])['family_metrics']['fam-c']['conversion_rate'];

        $withUnchanged = $this->svc()->track([
            $this->event('fam-c', 'repaired_success'),
            $this->event('fam-c', 'repaired_unchanged'),
            $this->event('fam-c', 'repaired_unchanged'),
        ])['family_metrics']['fam-c']['conversion_rate'];

        $this->assertLessThan($withoutUnchanged, $withUnchanged,
            'unchanged retries diluting the family must lower conversion_rate');
    }

    public function test_all_unchanged_with_zero_success_emits_stop_retrying_unchanged(): void
    {
        $r = $this->svc()->track([
            $this->event('fam-d', 'repaired_unchanged'),
            $this->event('fam-d', 'repaired_unchanged'),
        ]);

        $this->assertContains('stop_retrying_unchanged', $r['family_metrics']['fam-d']['signals']);
        $this->assertSame('stuck_unchanged', $r['family_metrics']['fam-d']['repair_status']);
    }

    public function test_mixed_success_and_unchanged_does_not_emit_stop_retrying_unchanged(): void
    {
        $r = $this->svc()->track([
            $this->event('fam-e', 'repaired_success'),
            $this->event('fam-e', 'repaired_unchanged'),
        ]);

        $this->assertNotContains('stop_retrying_unchanged', $r['family_metrics']['fam-e']['signals']);
    }

    // ── AC5: family_metrics, top_repair_candidate, dead_end_families, learning_notes ──

    public function test_all_retired_family_is_a_dead_end(): void
    {
        $r = $this->svc()->track([
            $this->event('fam-f', 'retired'),
            $this->event('fam-f', 'retired'),
        ]);

        $this->assertContains('fam-f', $r['dead_end_families']);
        $this->assertSame('dead_end', $r['family_metrics']['fam-f']['repair_status']);
    }

    public function test_dead_end_family_is_excluded_from_top_repair_candidate(): void
    {
        $r = $this->svc()->track([
            $this->event('dead-fam', 'retired'),
            $this->event('dead-fam', 'retired'),
            $this->event('dead-fam', 'retired'),
            $this->event('live-fam', 'pending'),
        ]);

        $this->assertSame('live-fam', $r['top_repair_candidate']);
    }

    public function test_top_repair_candidate_is_the_largest_non_converted_non_dead_end_family(): void
    {
        $r = $this->svc()->track([
            $this->event('small-fam', 'pending'),
            $this->event('big-fam', 'pending'),
            $this->event('big-fam', 'pending'),
            $this->event('big-fam', 'pending'),
        ]);

        $this->assertSame('big-fam', $r['top_repair_candidate']);
    }

    public function test_fully_converted_family_is_not_the_top_repair_candidate(): void
    {
        $r = $this->svc()->track([
            $this->event('done-fam', 'repaired_success'),
            $this->event('done-fam', 'repaired_success'),
            $this->event('done-fam', 'repaired_success'),
            $this->event('pending-fam', 'pending'),
        ]);

        $this->assertSame('pending-fam', $r['top_repair_candidate']);
    }

    public function test_learning_notes_mention_dead_end_families(): void
    {
        $r = $this->svc()->track([
            $this->event('dead-fam', 'retired'),
        ]);

        $found = false;
        foreach ($r['learning_notes'] as $note) {
            if (str_contains($note, 'dead-fam') && str_contains($note, 'dead end')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'learning_notes must explain why a dead-end family was retired');
    }

    public function test_learning_notes_mention_fully_converted_families(): void
    {
        $r = $this->svc()->track([
            $this->event('done-fam', 'repaired_success'),
        ]);

        $found = false;
        foreach ($r['learning_notes'] as $note) {
            if (str_contains($note, 'done-fam') && str_contains($note, 'fully converted')) {
                $found = true;
            }
        }
        $this->assertTrue($found);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $events = [
            $this->event('fam-x', 'repaired_success'),
            $this->event('fam-x', 'pending'),
            $this->event('fam-y', 'retired'),
        ];

        $a = $this->svc()->track($events);
        $b = $this->svc()->track($events);

        $this->assertSame(json_encode($a, JSON_UNESCAPED_SLASHES), json_encode($b, JSON_UNESCAPED_SLASHES));
    }

    public function test_repair_attempts_are_summed_per_family(): void
    {
        $r = $this->svc()->track([
            $this->event('fam-z', 'pending', 2),
            $this->event('fam-z', 'pending', 3),
        ]);

        $this->assertSame(5, $r['family_metrics']['fam-z']['repair_attempts']);
    }
}
