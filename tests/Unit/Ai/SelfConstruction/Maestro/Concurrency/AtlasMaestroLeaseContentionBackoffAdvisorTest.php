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

    public function test_source_performs_no_process_sleep_mutation_provider_or_git_calls(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/Maestro/Concurrency/AtlasMaestroLeaseContentionBackoffAdvisor.php'));
        foreach (['exec(', 'shell_exec(', 'proc_open(', 'sleep(', 'usleep(', '->enqueue(', '->claim(', 'Http::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "advisor must not perform {$forbidden}");
        }
    }
}
