<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Concurrency;

use App\Services\Ai\SelfConstruction\Maestro\Concurrency\AtlasMaestroLeaseContentionBackoffAdvisor;
use Tests\TestCase;

final class AtlasMaestroLeaseContentionBackoffAdvisorTest extends TestCase
{
    private function advisor(): AtlasMaestroLeaseContentionBackoffAdvisor
    {
        return new AtlasMaestroLeaseContentionBackoffAdvisor;
    }

    private function facts(array $overrides = []): array
    {
        return array_merge([
            'active_leases' => 2,
            'servable_now' => 10,
            'recent_commit_failures' => 0,
            'recent_index_lock_retries' => 0,
            'average_task_minutes' => 8.0,
            'target_worker_count' => 2,
        ], $overrides);
    }

    public function test_high_servable_now_with_no_contention_recommends_spawn_more(): void
    {
        $result = $this->advisor()->advise($this->facts(['servable_now' => 10, 'active_leases' => 2]));

        $this->assertSame(AtlasMaestroLeaseContentionBackoffAdvisor::DECISION_SPAWN_MORE, $result['decision']);
        $this->assertGreaterThan(0, $result['target_worker_delta']);
    }

    public function test_commit_failures_trigger_backoff(): void
    {
        $result = $this->advisor()->advise($this->facts(['recent_commit_failures' => 3]));

        $this->assertSame(AtlasMaestroLeaseContentionBackoffAdvisor::DECISION_BACKOFF, $result['decision']);
        $this->assertLessThan(0, $result['target_worker_delta']);
        $this->assertStringContainsString('contention:commit_failures', implode(',', $result['reason_codes']));
    }

    public function test_index_lock_retries_trigger_backoff(): void
    {
        $result = $this->advisor()->advise($this->facts(['recent_index_lock_retries' => 5]));

        $this->assertSame(AtlasMaestroLeaseContentionBackoffAdvisor::DECISION_BACKOFF, $result['decision']);
        $this->assertStringContainsString('contention:index_lock_retries', implode(',', $result['reason_codes']));
    }

    public function test_active_leases_saturating_queue_triggers_backoff(): void
    {
        $result = $this->advisor()->advise($this->facts(['active_leases' => 5, 'servable_now' => 2]));

        $this->assertSame(AtlasMaestroLeaseContentionBackoffAdvisor::DECISION_BACKOFF, $result['decision']);
        $this->assertStringContainsString('saturation:', implode(',', $result['reason_codes']));
    }

    public function test_does_not_back_off_merely_because_servable_now_is_high_without_contention(): void
    {
        // No contention input exists for "health=false" — this advisor never sees that flag at all,
        // so high servable_now with zero contention/saturation evidence must never resolve to backoff.
        $result = $this->advisor()->advise($this->facts(['servable_now' => 50, 'active_leases' => 3]));

        $this->assertNotSame(AtlasMaestroLeaseContentionBackoffAdvisor::DECISION_BACKOFF, $result['decision']);
    }

    public function test_moderate_headroom_holds_current(): void
    {
        $result = $this->advisor()->advise($this->facts(['active_leases' => 3, 'servable_now' => 4]));

        $this->assertSame(AtlasMaestroLeaseContentionBackoffAdvisor::DECISION_HOLD_CURRENT, $result['decision']);
        $this->assertSame(0, $result['target_worker_delta']);
    }

    public function test_output_includes_all_three_required_fields(): void
    {
        $result = $this->advisor()->advise($this->facts());

        $this->assertArrayHasKey('decision', $result);
        $this->assertArrayHasKey('target_worker_delta', $result);
        $this->assertArrayHasKey('retry_after_seconds', $result);
        $this->assertArrayHasKey('reason_codes', $result);
    }

    public function test_backoff_retry_after_scales_with_average_task_minutes(): void
    {
        $short = $this->advisor()->advise($this->facts(['recent_commit_failures' => 3, 'average_task_minutes' => 4.0]));
        $long = $this->advisor()->advise($this->facts(['recent_commit_failures' => 3, 'average_task_minutes' => 40.0]));

        $this->assertGreaterThan($short['retry_after_seconds'], $long['retry_after_seconds']);
    }

    public function test_result_is_deterministic_for_identical_input(): void
    {
        $advisor = $this->advisor();
        $facts = $this->facts(['recent_commit_failures' => 3]);

        $this->assertSame($advisor->advise($facts), $advisor->advise($facts));
    }

    // ── worker-quality cap on spawn_more ────────────────────────────────────

    public function test_high_servable_now_does_not_spawn_more_when_recent_give_back_rate_breaches_ceiling(): void
    {
        $result = $this->advisor()->advise($this->facts([
            'servable_now' => 10,
            'active_leases' => 2,
            'recent_give_back_rate' => 0.5,
        ]));

        $this->assertNotSame(AtlasMaestroLeaseContentionBackoffAdvisor::DECISION_SPAWN_MORE, $result['decision']);
        $this->assertNotEmpty($result['quality_gate_reason_codes']);
        $this->assertStringContainsString('recent_give_back_rate', implode(',', $result['quality_gate_reason_codes']));
    }

    public function test_high_servable_now_does_not_spawn_more_when_weak_green_rate_breaches_ceiling(): void
    {
        $result = $this->advisor()->advise($this->facts([
            'servable_now' => 10,
            'active_leases' => 2,
            'weak_green_rate' => 0.6,
        ]));

        $this->assertNotSame(AtlasMaestroLeaseContentionBackoffAdvisor::DECISION_SPAWN_MORE, $result['decision']);
        $this->assertNotEmpty($result['quality_gate_reason_codes']);
        $this->assertStringContainsString('weak_green_rate', implode(',', $result['quality_gate_reason_codes']));
    }

    public function test_high_quality_recent_completion_evidence_allows_spawn_more_with_burst_cap(): void
    {
        $result = $this->advisor()->advise($this->facts([
            'servable_now' => 50,
            'active_leases' => 2,
            'recent_give_back_rate' => 0.05,
            'weak_green_rate' => 0.0,
        ]));

        $this->assertSame(AtlasMaestroLeaseContentionBackoffAdvisor::DECISION_SPAWN_MORE, $result['decision']);
        $this->assertLessThanOrEqual(4, $result['target_worker_delta']);
        $this->assertEmpty($result['quality_gate_reason_codes']);
    }

    public function test_output_includes_quality_gate_reason_codes_key_even_when_empty(): void
    {
        $result = $this->advisor()->advise($this->facts());

        $this->assertArrayHasKey('quality_gate_reason_codes', $result);
        $this->assertSame([], $result['quality_gate_reason_codes']);
    }

    public function test_source_performs_no_process_sleep_mutation_provider_or_git_calls(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/Maestro/Concurrency/AtlasMaestroLeaseContentionBackoffAdvisor.php'));
        foreach (['exec(', 'shell_exec(', 'proc_open(', 'sleep(', 'usleep(', '->enqueue(', '->claim(', 'Http::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "advisor must not perform {$forbidden}");
        }
    }

    // ── backoff_seconds, jitter_band, fairness_reason ──

    public function test_output_includes_backoff_seconds_jitter_band_fairness_reason(): void
    {
        $result = $this->advisor()->advise($this->facts([
            'active_leases' => 10,
            'servable_now' => 5,
            'recent_commit_failures' => 3,
        ]));

        $this->assertArrayHasKey('backoff_seconds', $result);
        $this->assertArrayHasKey('jitter_band', $result);
        $this->assertArrayHasKey('fairness_reason', $result);
    }

    public function test_backoff_seconds_zero_when_no_contention(): void
    {
        $result = $this->advisor()->advise($this->facts([
            'active_leases' => 2,
            'servable_now' => 10,
        ]));

        $this->assertSame(0, $result['backoff_seconds']);
        $this->assertSame(0, $result['jitter_band']);
        $this->assertSame('', $result['fairness_reason']);
    }

    public function test_backoff_seconds_positive_when_contention(): void
    {
        $result = $this->advisor()->advise($this->facts([
            'active_leases' => 10,
            'servable_now' => 5,
            'recent_commit_failures' => 3,
        ]));

        $this->assertGreaterThan(0, $result['backoff_seconds']);
        $this->assertGreaterThan(0, $result['jitter_band']);
    }

    public function test_fairness_reason_empty_below_starvation_threshold(): void
    {
        $result = $this->advisor()->advise($this->facts([
            'active_leases' => 10,
            'servable_now' => 5,
            'recent_commit_failures' => 3,
            'consecutive_contention_rounds' => 2,
            'worker_class' => 'hermes-muscle-3',
            'total_active_workers' => 5,
        ]));

        $this->assertSame('', $result['fairness_reason']);
    }

    public function test_fairness_reason_present_at_starvation_threshold(): void
    {
        $result = $this->advisor()->advise($this->facts([
            'active_leases' => 10,
            'servable_now' => 5,
            'recent_commit_failures' => 3,
            'consecutive_contention_rounds' => 3,
            'worker_class' => 'hermes-muscle-3',
            'total_active_workers' => 5,
        ]));

        $this->assertStringContainsString('starvation_prevention', $result['fairness_reason']);
        $this->assertStringContainsString('hermes-muscle-3', $result['fairness_reason']);
    }

    public function test_backoff_seconds_scales_with_consecutive_contention(): void
    {
        $low = $this->advisor()->advise($this->facts([
            'active_leases' => 10,
            'servable_now' => 5,
            'recent_commit_failures' => 3,
            'consecutive_contention_rounds' => 1,
        ]));
        $high = $this->advisor()->advise($this->facts([
            'active_leases' => 10,
            'servable_now' => 5,
            'recent_commit_failures' => 3,
            'consecutive_contention_rounds' => 5,
        ]));

        $this->assertGreaterThan($low['backoff_seconds'], $high['backoff_seconds']);
    }

    public function test_jitter_band_scales_with_consecutive_contention(): void
    {
        $low = $this->advisor()->advise($this->facts([
            'active_leases' => 10,
            'servable_now' => 5,
            'recent_commit_failures' => 3,
            'consecutive_contention_rounds' => 0,
        ]));
        $high = $this->advisor()->advise($this->facts([
            'active_leases' => 10,
            'servable_now' => 5,
            'recent_commit_failures' => 3,
            'consecutive_contention_rounds' => 4,
        ]));

        $this->assertGreaterThan($low['jitter_band'], $high['jitter_band']);
    }

    // ── per_client_contention and client_worker_deltas ──

    public function test_output_has_client_worker_deltas(): void
    {
        $result = $this->advisor()->advise($this->facts([
            'servable_now' => 10,
            'active_leases' => 2,
            'per_client_contention' => [
                ['client_id' => 'muscle-1', 'commit_failures' => 0],
                ['client_id' => 'muscle-2', 'commit_failures' => 0],
            ],
        ]));
        $this->assertArrayHasKey('client_worker_deltas', $result);
        $this->assertCount(2, $result['client_worker_deltas']);
    }

    public function test_per_client_contention_backs_off_only_noisy_client(): void
    {
        $result = $this->advisor()->advise($this->facts([
            'servable_now' => 10,
            'active_leases' => 2,
            'per_client_contention' => [
                ['client_id' => 'muscle-1', 'commit_failures' => 0],
                ['client_id' => 'muscle-2', 'commit_failures' => 3],
            ],
        ]));

        $deltas = $result['client_worker_deltas'];
        $this->assertCount(2, $deltas);
        $this->assertSame('muscle-1', $deltas[0]['client_id']);
        $this->assertSame(1, $deltas[0]['worker_delta']);
        $this->assertSame('muscle-2', $deltas[1]['client_id']);
        $this->assertSame(-1, $deltas[1]['worker_delta']);
        $this->assertSame('per_client_contention_backoff', $deltas[1]['reason']);
    }

    public function test_global_contention_produces_global_backoff(): void
    {
        $result = $this->advisor()->advise($this->facts([
            'servable_now' => 5,
            'active_leases' => 2,
            'recent_commit_failures' => 3,
            'per_client_contention' => [
                ['client_id' => 'muscle-1', 'commit_failures' => 0],
                ['client_id' => 'muscle-2', 'commit_failures' => 0],
            ],
        ]));

        $deltas = $result['client_worker_deltas'];
        $this->assertCount(2, $deltas);
        $this->assertSame(-1, $deltas[0]['worker_delta']);
        $this->assertSame(-1, $deltas[1]['worker_delta']);
        $this->assertSame('global_contention_backoff', $deltas[0]['reason']);
    }

    public function test_client_worker_deltas_has_deterministic_client_ids(): void
    {
        $facts = $this->facts([
            'servable_now' => 10,
            'active_leases' => 2,
            'per_client_contention' => [
                ['client_id' => 'muscle-a', 'commit_failures' => 0],
                ['client_id' => 'muscle-b', 'commit_failures' => 0],
            ],
        ]);
        $a = $this->advisor()->advise($facts);
        $b = $this->advisor()->advise($facts);
        $this->assertSame(
            json_encode($a['client_worker_deltas']),
            json_encode($b['client_worker_deltas']),
        );
    }

    public function test_empty_per_client_contention_yields_empty_deltas(): void
    {
        $result = $this->advisor()->advise($this->facts([
            'servable_now' => 10,
            'active_leases' => 2,
        ]));
        $this->assertSame([], $result['client_worker_deltas']);
    }
}
