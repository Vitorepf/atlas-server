<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskServing;

use App\Services\Ai\SelfConstruction\TaskServing\AtlasTaskLeaseMismatchExplainer;
use Tests\TestCase;

final class AtlasTaskLeaseMismatchExplainerTest extends TestCase
{
    private function snapshot(array $overrides = []): array
    {
        return array_merge([
            'healthy' => true,
            'leases_match_claimed' => true,
            'active_leases' => 0,
            'claimed_records' => 0,
            'servable_now' => 10,
            'recoverable' => ['total' => 0],
            'health_flags' => [
                'dry_queue' => false,
                'serving_jammed' => false,
            ],
        ], $overrides);
    }

    public function test_healthy_snapshot_yields_healthy_verdict_with_no_action(): void
    {
        $result = (new AtlasTaskLeaseMismatchExplainer)->explain($this->snapshot());

        $this->assertSame(AtlasTaskLeaseMismatchExplainer::VERDICT_HEALTHY, $result['verdict']);
        $this->assertFalse($result['serving_impact']);
        $this->assertSame('no_action', $result['recommendation']);
    }

    public function test_recoverable_backlog_recommends_reap_leases_before_respec(): void
    {
        $result = (new AtlasTaskLeaseMismatchExplainer)->explain($this->snapshot([
            'healthy' => false,
            'leases_match_claimed' => false,
            'recoverable' => ['total' => 3],
        ]));

        $this->assertSame(AtlasTaskLeaseMismatchExplainer::VERDICT_RECOVERABLE_BACKLOG, $result['verdict']);
        $this->assertSame('reap_leases', $result['recommendation']);
    }

    public function test_recoverable_backlog_wins_even_when_otherwise_healthy(): void
    {
        // recoverable backlog is an operational state, not a breach — healthy can still be true,
        // but reap_leases must still be recommended before any task respec.
        $result = (new AtlasTaskLeaseMismatchExplainer)->explain($this->snapshot([
            'healthy' => true,
            'leases_match_claimed' => true,
            'recoverable' => ['total' => 1],
        ]));

        $this->assertSame(AtlasTaskLeaseMismatchExplainer::VERDICT_RECOVERABLE_BACKLOG, $result['verdict']);
        $this->assertSame('reap_leases', $result['recommendation']);
    }

    public function test_lease_accounting_mismatch_does_not_recommend_quarantine(): void
    {
        $result = (new AtlasTaskLeaseMismatchExplainer)->explain($this->snapshot([
            'healthy' => false,
            'leases_match_claimed' => false,
            'active_leases' => 5,
            'claimed_records' => 2,
            'recoverable' => ['total' => 0],
            'servable_now' => 7,
        ]));

        $this->assertSame(AtlasTaskLeaseMismatchExplainer::VERDICT_LEASE_ACCOUNTING_MISMATCH, $result['verdict']);
        $this->assertFalse($result['serving_impact']);
        $this->assertSame('observe_or_reconcile_accounting', $result['recommendation']);
        $this->assertStringNotContainsString('quarantine', $result['recommendation']);
    }

    public function test_serving_blocked_by_dry_queue_names_the_blocking_flag(): void
    {
        $result = (new AtlasTaskLeaseMismatchExplainer)->explain($this->snapshot([
            'healthy' => false,
            'leases_match_claimed' => true,
            'servable_now' => 0,
            'recoverable' => ['total' => 0],
            'health_flags' => ['dry_queue' => true, 'serving_jammed' => false],
        ]));

        $this->assertSame(AtlasTaskLeaseMismatchExplainer::VERDICT_SERVING_BLOCKED, $result['verdict']);
        $this->assertTrue($result['serving_impact']);
        $this->assertSame('dry_queue', $result['blocking_flag']);
    }

    public function test_serving_blocked_by_serving_jammed_names_the_blocking_flag(): void
    {
        $result = (new AtlasTaskLeaseMismatchExplainer)->explain($this->snapshot([
            'healthy' => false,
            'leases_match_claimed' => true,
            'servable_now' => 0,
            'recoverable' => ['total' => 0],
            'health_flags' => ['dry_queue' => false, 'serving_jammed' => true],
        ]));

        $this->assertSame(AtlasTaskLeaseMismatchExplainer::VERDICT_SERVING_BLOCKED, $result['verdict']);
        $this->assertTrue($result['serving_impact']);
        $this->assertSame('serving_jammed', $result['blocking_flag']);
    }

    public function test_unrecognized_unhealthy_state_falls_back_to_degraded(): void
    {
        $result = (new AtlasTaskLeaseMismatchExplainer)->explain($this->snapshot([
            'healthy' => false,
            'leases_match_claimed' => false,
            'active_leases' => 1,
            'claimed_records' => 1,
            'servable_now' => 5,
            'recoverable' => ['total' => 0],
        ]));

        $this->assertSame(AtlasTaskLeaseMismatchExplainer::VERDICT_DEGRADED, $result['verdict']);
        $this->assertTrue($result['serving_impact']);
    }

    public function test_result_is_deterministic_for_identical_input(): void
    {
        $explainer = new AtlasTaskLeaseMismatchExplainer;
        $snapshot = $this->snapshot(['healthy' => false, 'recoverable' => ['total' => 2]]);

        $this->assertSame($explainer->explain($snapshot), $explainer->explain($snapshot));
    }
}
