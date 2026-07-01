<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Health;

use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroOldestPacketRotationAdvisor;
use Tests\TestCase;

final class AtlasMaestroOldestPacketRotationAdvisorTest extends TestCase
{
    private function advisor(): AtlasMaestroOldestPacketRotationAdvisor
    {
        return new AtlasMaestroOldestPacketRotationAdvisor;
    }

    private function facts(array $overrides = []): array
    {
        return array_merge([
            'queue_age' => ['p95' => 10.0],
            'oldest_packet_ids' => ['tp-old-1', 'tp-old-2'],
            'claimable_depth' => 5,
            'active_leases' => 2,
            'serve_rate_per_minute' => 3.0,
        ], $overrides);
    }

    public function test_fresh_queue_with_low_depth_results_in_observe(): void
    {
        $result = $this->advisor()->advise($this->facts());

        $this->assertSame(AtlasMaestroOldestPacketRotationAdvisor::ACTION_OBSERVE, $result['action']);
    }

    public function test_stale_high_depth_low_consumption_with_no_active_leases_recommends_rotate(): void
    {
        $result = $this->advisor()->advise($this->facts([
            'queue_age' => ['p95' => 90.0],
            'claimable_depth' => 30,
            'active_leases' => 0,
            'serve_rate_per_minute' => 0.2,
        ]));

        $this->assertSame(AtlasMaestroOldestPacketRotationAdvisor::ACTION_ROTATE_OLDEST, $result['action']);
        $this->assertSame(['tp-old-1', 'tp-old-2'], $result['packet_ids_to_surface']);
    }

    public function test_stale_high_depth_low_consumption_with_active_leases_recommends_surface(): void
    {
        $result = $this->advisor()->advise($this->facts([
            'queue_age' => ['p95' => 90.0],
            'claimable_depth' => 30,
            'active_leases' => 4,
            'serve_rate_per_minute' => 0.2,
        ]));

        $this->assertSame(AtlasMaestroOldestPacketRotationAdvisor::ACTION_SURFACE_OLDEST_TO_MUSCLES, $result['action']);
        $this->assertNotEmpty($result['packet_ids_to_surface']);
    }

    public function test_stale_with_unknown_consumption_still_triggers_rotation_path(): void
    {
        $result = $this->advisor()->advise($this->facts([
            'queue_age' => ['p95' => 90.0],
            'claimable_depth' => 30,
            'active_leases' => 0,
            'serve_rate_per_minute' => null,
        ]));

        $this->assertSame(AtlasMaestroOldestPacketRotationAdvisor::ACTION_ROTATE_OLDEST, $result['action']);
        $this->assertContains('consumption_unknown', $result['reason_codes']);
    }

    public function test_stale_but_low_depth_recommends_do_not_rotate(): void
    {
        $result = $this->advisor()->advise($this->facts([
            'queue_age' => ['p95' => 90.0],
            'claimable_depth' => 3,
        ]));

        $this->assertSame(AtlasMaestroOldestPacketRotationAdvisor::ACTION_DO_NOT_ROTATE, $result['action']);
    }

    public function test_stale_high_depth_but_healthy_consumption_does_not_rotate(): void
    {
        $result = $this->advisor()->advise($this->facts([
            'queue_age' => ['p95' => 90.0],
            'claimable_depth' => 30,
            'serve_rate_per_minute' => 5.0,
        ]));

        $this->assertNotSame(AtlasMaestroOldestPacketRotationAdvisor::ACTION_ROTATE_OLDEST, $result['action']);
        $this->assertNotSame(AtlasMaestroOldestPacketRotationAdvisor::ACTION_SURFACE_OLDEST_TO_MUSCLES, $result['action']);
    }

    public function test_action_set_never_includes_origination(): void
    {
        $allActions = [
            AtlasMaestroOldestPacketRotationAdvisor::ACTION_ROTATE_OLDEST,
            AtlasMaestroOldestPacketRotationAdvisor::ACTION_SURFACE_OLDEST_TO_MUSCLES,
            AtlasMaestroOldestPacketRotationAdvisor::ACTION_OBSERVE,
            AtlasMaestroOldestPacketRotationAdvisor::ACTION_DO_NOT_ROTATE,
        ];
        foreach ($allActions as $action) {
            $this->assertStringNotContainsString('originat', $action);
        }
    }

    public function test_output_includes_all_three_required_fields(): void
    {
        $result = $this->advisor()->advise($this->facts());

        $this->assertArrayHasKey('action', $result);
        $this->assertArrayHasKey('reason_codes', $result);
        $this->assertArrayHasKey('packet_ids_to_surface', $result);
    }

    public function test_result_is_deterministic_for_identical_input(): void
    {
        $advisor = $this->advisor();
        $facts = $this->facts(['queue_age' => ['p95' => 90.0], 'claimable_depth' => 30, 'active_leases' => 0]);

        $this->assertSame($advisor->advise($facts), $advisor->advise($facts));
    }

    public function test_source_performs_no_mutation_sleep_spawn_provider_fs_or_git_io(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/Maestro/Health/AtlasMaestroOldestPacketRotationAdvisor.php'));
        foreach (['->enqueue(', '->dequeue(', 'sleep(', 'usleep(', 'exec(', 'shell_exec(', 'proc_open(', 'Http::', 'file_put_contents('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "advisor must not perform {$forbidden}");
        }
    }

    // ── classifyOldestPackets(): AC2/AC3/AC4 per-packet rotation classification ──

    private function packet(array $overrides = []): array
    {
        return array_merge([
            'task_packet_id' => 'tp-1',
            'age_minutes' => 30.0,
            'value_decay_score' => 0.0,
            'staleness_score' => 0.0,
            'worker_fit_score' => 1.0,
            'blocked_history_count' => 0,
            'proof_freshness_score' => 1.0,
        ], $overrides);
    }

    public function test_clean_packet_is_served_now(): void
    {
        $result = $this->advisor()->classifyOldestPackets([$this->packet()]);

        $this->assertSame(AtlasMaestroOldestPacketRotationAdvisor::PACKET_ACTION_SERVE_NOW, $result['classified_packets'][0]['action']);
        $this->assertNotEmpty($result['classified_packets'][0]['rationale']);
    }

    public function test_high_value_decay_is_retired(): void
    {
        $result = $this->advisor()->classifyOldestPackets([$this->packet(['value_decay_score' => 0.9])]);

        $this->assertSame(AtlasMaestroOldestPacketRotationAdvisor::PACKET_ACTION_RETIRE, $result['classified_packets'][0]['action']);
        $this->assertStringContainsString('decayed', $result['classified_packets'][0]['rationale']);
    }

    public function test_ancient_packet_with_any_decay_is_retired(): void
    {
        // Below the hard decay floor, but ancient (>= AGE_ANCIENT_MINUTES) with nonzero decay.
        $result = $this->advisor()->classifyOldestPackets([$this->packet(['age_minutes' => 300.0, 'value_decay_score' => 0.1])]);

        $this->assertSame(AtlasMaestroOldestPacketRotationAdvisor::PACKET_ACTION_RETIRE, $result['classified_packets'][0]['action']);
    }

    public function test_ancient_packet_with_zero_decay_is_not_retired_by_age_alone(): void
    {
        $result = $this->advisor()->classifyOldestPackets([$this->packet(['age_minutes' => 300.0, 'value_decay_score' => 0.0])]);

        $this->assertNotSame(AtlasMaestroOldestPacketRotationAdvisor::PACKET_ACTION_RETIRE, $result['classified_packets'][0]['action']);
    }

    public function test_stale_proof_freshness_is_reshaped(): void
    {
        $result = $this->advisor()->classifyOldestPackets([$this->packet(['proof_freshness_score' => 0.1])]);

        $this->assertSame(AtlasMaestroOldestPacketRotationAdvisor::PACKET_ACTION_RESHAPE, $result['classified_packets'][0]['action']);
    }

    public function test_high_staleness_is_reshaped(): void
    {
        $result = $this->advisor()->classifyOldestPackets([$this->packet(['staleness_score' => 0.7])]);

        $this->assertSame(AtlasMaestroOldestPacketRotationAdvisor::PACKET_ACTION_RESHAPE, $result['classified_packets'][0]['action']);
    }

    public function test_low_worker_fit_is_keep_waiting(): void
    {
        $result = $this->advisor()->classifyOldestPackets([$this->packet(['worker_fit_score' => 0.1])]);

        $this->assertSame(AtlasMaestroOldestPacketRotationAdvisor::PACKET_ACTION_KEEP_WAITING, $result['classified_packets'][0]['action']);
    }

    public function test_repeated_blocked_history_is_quarantined(): void
    {
        $result = $this->advisor()->classifyOldestPackets([$this->packet(['blocked_history_count' => 5])]);

        $this->assertSame(AtlasMaestroOldestPacketRotationAdvisor::PACKET_ACTION_QUARANTINE, $result['classified_packets'][0]['action']);
        $this->assertStringContainsString('blocked', $result['classified_packets'][0]['rationale']);
    }

    public function test_quarantine_takes_priority_over_all_other_signals(): void
    {
        $result = $this->advisor()->classifyOldestPackets([$this->packet([
            'blocked_history_count' => 4,
            'value_decay_score' => 0.9,
            'staleness_score' => 0.9,
            'worker_fit_score' => 0.0,
            'proof_freshness_score' => 0.0,
        ])]);

        $this->assertSame(AtlasMaestroOldestPacketRotationAdvisor::PACKET_ACTION_QUARANTINE, $result['classified_packets'][0]['action']);
    }

    public function test_signals_report_all_six_considered_dimensions(): void
    {
        $result = $this->advisor()->classifyOldestPackets([$this->packet()]);
        $signals = implode(' ', $result['classified_packets'][0]['signals']);

        foreach (['age_minutes:', 'value_decay_score:', 'staleness_score:', 'worker_fit_score:', 'blocked_history_count:', 'proof_freshness_score:'] as $prefix) {
            $this->assertStringContainsString($prefix, $signals);
        }
    }

    public function test_classify_multiple_packets_returns_one_entry_each(): void
    {
        $result = $this->advisor()->classifyOldestPackets([
            $this->packet(['task_packet_id' => 'a']),
            $this->packet(['task_packet_id' => 'b', 'value_decay_score' => 0.95]),
        ]);

        $this->assertSame(2, $result['count']);
        $ids = array_column($result['classified_packets'], 'task_packet_id');
        $this->assertSame(['a', 'b'], $ids);
    }

    public function test_classify_empty_packets_returns_zero_count(): void
    {
        $result = $this->advisor()->classifyOldestPackets([]);

        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['classified_packets']);
    }

    public function test_classify_action_set_never_includes_origination(): void
    {
        $allActions = [
            AtlasMaestroOldestPacketRotationAdvisor::PACKET_ACTION_SERVE_NOW,
            AtlasMaestroOldestPacketRotationAdvisor::PACKET_ACTION_RESHAPE,
            AtlasMaestroOldestPacketRotationAdvisor::PACKET_ACTION_RETIRE,
            AtlasMaestroOldestPacketRotationAdvisor::PACKET_ACTION_KEEP_WAITING,
            AtlasMaestroOldestPacketRotationAdvisor::PACKET_ACTION_QUARANTINE,
        ];
        foreach ($allActions as $action) {
            $this->assertStringNotContainsString('originat', $action);
        }
    }

    public function test_classify_is_deterministic(): void
    {
        $advisor = $this->advisor();
        $packets = [$this->packet(['value_decay_score' => 0.85])];

        $this->assertSame($advisor->classifyOldestPackets($packets), $advisor->classifyOldestPackets($packets));
    }
}
