<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Health;

use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroQuarantineBurnDownScheduler;
use Tests\TestCase;

final class AtlasMaestroQuarantineBurnDownSchedulerTest extends TestCase
{
    private function scheduler(): AtlasMaestroQuarantineBurnDownScheduler
    {
        return new AtlasMaestroQuarantineBurnDownScheduler();
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->scheduler()->schedule([]);

        $this->assertSame(AtlasMaestroQuarantineBurnDownScheduler::SCHEMA, $result['schema']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->scheduler()->schedule([]);

        foreach (['schema', 'lanes', 'total_quarantined', 'do_not_requeue_count'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
    }

    public function test_lanes_has_all_four_keys(): void
    {
        $result = $this->scheduler()->schedule([]);

        foreach (['retire', 'operator_only', 'respec', 'wait'] as $lane) {
            $this->assertArrayHasKey($lane, $result['lanes']);
        }
    }

    public function test_each_lane_entry_has_required_fields(): void
    {
        $result = $this->scheduler()->schedule([
            ['packet_id' => 'x', 'is_self_target' => true],
        ]);

        $entry = $result['lanes']['retire'][0];
        foreach (['packet_id', 'do_not_requeue', 'reason', 'next_action'] as $f) {
            $this->assertArrayHasKey($f, $entry);
        }
    }

    public function test_empty_input_yields_all_empty_lanes(): void
    {
        $result = $this->scheduler()->schedule([]);

        $this->assertSame([], $result['lanes']['retire']);
        $this->assertSame([], $result['lanes']['operator_only']);
        $this->assertSame([], $result['lanes']['respec']);
        $this->assertSame([], $result['lanes']['wait']);
        $this->assertSame(0, $result['total_quarantined']);
    }

    // ── retire lane ───────────────────────────────────────────────────────────

    public function test_self_target_packet_goes_to_retire(): void
    {
        $result = $this->scheduler()->schedule([
            ['packet_id' => 'p1', 'is_self_target' => true],
        ]);

        $this->assertCount(1, $result['lanes']['retire']);
        $this->assertSame('p1', $result['lanes']['retire'][0]['packet_id']);
    }

    public function test_self_target_reason_is_forbidden_self_target(): void
    {
        $result = $this->scheduler()->schedule([
            ['packet_id' => 'p1', 'is_self_target' => true],
        ]);

        $this->assertSame('forbidden_self_target', $result['lanes']['retire'][0]['reason']);
    }

    public function test_self_target_do_not_requeue_is_true(): void
    {
        $result = $this->scheduler()->schedule([
            ['packet_id' => 'p1', 'is_self_target' => true],
        ]);

        $this->assertTrue($result['lanes']['retire'][0]['do_not_requeue']);
    }

    public function test_repeated_poison_at_threshold_goes_to_retire(): void
    {
        $result = $this->scheduler()->schedule([
            ['packet_id' => 'p2', 'poison_attempt_count' => AtlasMaestroQuarantineBurnDownScheduler::POISON_THRESHOLD],
        ]);

        $this->assertCount(1, $result['lanes']['retire']);
        $this->assertStringContainsString('repeated_poison', $result['lanes']['retire'][0]['reason']);
    }

    public function test_poison_below_threshold_does_not_retire(): void
    {
        $result = $this->scheduler()->schedule([
            ['packet_id' => 'p3', 'poison_attempt_count' => AtlasMaestroQuarantineBurnDownScheduler::POISON_THRESHOLD - 1],
        ]);

        $this->assertSame([], $result['lanes']['retire']);
    }

    // ── operator_only lane ────────────────────────────────────────────────────

    public function test_requires_operator_decision_goes_to_operator_only(): void
    {
        $result = $this->scheduler()->schedule([
            ['packet_id' => 'p4', 'requires_operator_decision' => true],
        ]);

        $this->assertCount(1, $result['lanes']['operator_only']);
        $this->assertSame('p4', $result['lanes']['operator_only'][0]['packet_id']);
        $this->assertFalse($result['lanes']['operator_only'][0]['do_not_requeue']);
    }

    public function test_has_sensitive_data_goes_to_operator_only(): void
    {
        $result = $this->scheduler()->schedule([
            ['packet_id' => 'p5', 'has_sensitive_data' => true],
        ]);

        $this->assertCount(1, $result['lanes']['operator_only']);
        $this->assertSame('contains_sensitive_data', $result['lanes']['operator_only'][0]['reason']);
    }

    // ── respec lane ───────────────────────────────────────────────────────────

    public function test_spec_is_ambiguous_goes_to_respec(): void
    {
        $result = $this->scheduler()->schedule([
            ['packet_id' => 'p6', 'spec_is_ambiguous' => true],
        ]);

        $this->assertCount(1, $result['lanes']['respec']);
        $this->assertSame('spec_is_ambiguous', $result['lanes']['respec'][0]['reason']);
        $this->assertFalse($result['lanes']['respec'][0]['do_not_requeue']);
    }

    public function test_out_of_scope_files_goes_to_respec(): void
    {
        $result = $this->scheduler()->schedule([
            ['packet_id' => 'p7', 'out_of_scope_files' => true],
        ]);

        $this->assertCount(1, $result['lanes']['respec']);
        $this->assertStringContainsString('out_of_scope', $result['lanes']['respec'][0]['reason']);
    }

    // ── wait lane ────────────────────────────────────────────────────────────

    public function test_plain_blocked_packet_goes_to_wait(): void
    {
        $result = $this->scheduler()->schedule([
            ['packet_id' => 'p8'],
        ]);

        $this->assertCount(1, $result['lanes']['wait']);
        $this->assertFalse($result['lanes']['wait'][0]['do_not_requeue']);
    }

    public function test_blocker_description_included_in_wait_reason(): void
    {
        $result = $this->scheduler()->schedule([
            ['packet_id' => 'p9', 'blocker_description' => 'upstream-organ-not-ready'],
        ]);

        $this->assertStringContainsString('upstream-organ-not-ready', $result['lanes']['wait'][0]['reason']);
    }

    // ── lane priority ─────────────────────────────────────────────────────────

    public function test_retire_takes_priority_over_operator_only(): void
    {
        $result = $this->scheduler()->schedule([
            ['packet_id' => 'p10', 'is_self_target' => true, 'requires_operator_decision' => true],
        ]);

        $this->assertCount(1, $result['lanes']['retire']);
        $this->assertSame([], $result['lanes']['operator_only']);
    }

    public function test_operator_only_takes_priority_over_respec(): void
    {
        $result = $this->scheduler()->schedule([
            ['packet_id' => 'p11', 'requires_operator_decision' => true, 'spec_is_ambiguous' => true],
        ]);

        $this->assertCount(1, $result['lanes']['operator_only']);
        $this->assertSame([], $result['lanes']['respec']);
    }

    public function test_respec_takes_priority_over_wait(): void
    {
        $result = $this->scheduler()->schedule([
            ['packet_id' => 'p12', 'spec_is_ambiguous' => true, 'blocker_description' => 'something'],
        ]);

        $this->assertCount(1, $result['lanes']['respec']);
        $this->assertSame([], $result['lanes']['wait']);
    }

    // ── totals ────────────────────────────────────────────────────────────────

    public function test_total_quarantined_sums_all_lanes(): void
    {
        $result = $this->scheduler()->schedule([
            ['packet_id' => 'a', 'is_self_target' => true],
            ['packet_id' => 'b', 'requires_operator_decision' => true],
            ['packet_id' => 'c', 'spec_is_ambiguous' => true],
            ['packet_id' => 'd'],
        ]);

        $this->assertSame(4, $result['total_quarantined']);
    }

    public function test_do_not_requeue_count_counts_only_retire_lane(): void
    {
        $result = $this->scheduler()->schedule([
            ['packet_id' => 'a', 'is_self_target' => true],
            ['packet_id' => 'b', 'poison_attempt_count' => 5],
            ['packet_id' => 'c', 'requires_operator_decision' => true],
        ]);

        $this->assertSame(2, $result['do_not_requeue_count']);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $packets = [
            ['packet_id' => 'a', 'is_self_target' => true],
            ['packet_id' => 'b', 'spec_is_ambiguous' => true],
            ['packet_id' => 'c'],
        ];

        $this->assertSame($this->scheduler()->schedule($packets), $this->scheduler()->schedule($packets));
    }
}
