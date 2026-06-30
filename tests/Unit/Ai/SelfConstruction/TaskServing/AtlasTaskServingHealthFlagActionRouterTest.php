<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskServing;

use App\Services\Ai\SelfConstruction\TaskServing\AtlasTaskServingHealthFlagActionRouter;
use PHPUnit\Framework\TestCase;

final class AtlasTaskServingHealthFlagActionRouterTest extends TestCase
{
    private function router(): AtlasTaskServingHealthFlagActionRouter
    {
        return new AtlasTaskServingHealthFlagActionRouter;
    }

    private function snapshot(array $overrides = []): array
    {
        return array_merge([
            'healthy' => true,
            'servable_now' => 10,
            'recoverable' => ['total' => 0],
            'leases_match_claimed' => true,
            'health_flags' => [
                'dry_queue' => false,
                'serving_jammed' => false,
                'recoverable_backlog' => false,
                'lease_leak_detected' => false,
                'malformed_risk' => false,
            ],
            'worker_drain_forecast' => ['queue_pressure' => 'low'],
        ], $overrides);
    }

    // ── AC: output shape ───────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->router()->route($this->snapshot());

        $this->assertArrayHasKey('primary_action', $r);
        $this->assertArrayHasKey('secondary_actions', $r);
        $this->assertArrayHasKey('human_readable_reason', $r);
        $this->assertNotEmpty($r['human_readable_reason']);
    }

    // ── AC: routing table ──────────────────────────────────────────────────────

    public function test_dry_queue_routes_to_replenish_or_repair(): void
    {
        $r = $this->router()->route($this->snapshot([
            'health_flags' => ['dry_queue' => true],
        ]));

        $this->assertSame(AtlasTaskServingHealthFlagActionRouter::ACTION_REPLENISH_OR_REPAIR, $r['primary_action']);
    }

    public function test_serving_jammed_routes_to_replenish_or_repair(): void
    {
        $r = $this->router()->route($this->snapshot([
            'health_flags' => ['serving_jammed' => true],
        ]));

        $this->assertSame(AtlasTaskServingHealthFlagActionRouter::ACTION_REPLENISH_OR_REPAIR, $r['primary_action']);
    }

    public function test_recoverable_backlog_routes_to_reap_leases(): void
    {
        $r = $this->router()->route($this->snapshot([
            'recoverable' => ['total' => 4],
            'health_flags' => ['recoverable_backlog' => true],
        ]));

        $this->assertSame(AtlasTaskServingHealthFlagActionRouter::ACTION_REAP_LEASES, $r['primary_action']);
    }

    public function test_malformed_risk_routes_to_sweep_malformed(): void
    {
        $r = $this->router()->route($this->snapshot([
            'health_flags' => ['malformed_risk' => true],
        ]));

        $this->assertSame(AtlasTaskServingHealthFlagActionRouter::ACTION_SWEEP_MALFORMED, $r['primary_action']);
    }

    public function test_lease_leak_with_servable_queue_routes_to_inspect_lease_parity(): void
    {
        $r = $this->router()->route($this->snapshot([
            'servable_now' => 8,
            'leases_match_claimed' => false,
            'health_flags' => ['lease_leak_detected' => true],
        ]));

        $this->assertSame(AtlasTaskServingHealthFlagActionRouter::ACTION_INSPECT_LEASE_PARITY, $r['primary_action']);
    }

    public function test_clean_servable_queue_routes_to_continue_work(): void
    {
        $r = $this->router()->route($this->snapshot());
        $this->assertSame(AtlasTaskServingHealthFlagActionRouter::ACTION_CONTINUE_WORK, $r['primary_action']);
    }

    // ── AC: harmless pressure vs true jam — healthy=false but servable_now high ──

    public function test_unhealthy_due_to_lease_mismatch_but_high_servable_now_is_not_treated_as_jam(): void
    {
        $r = $this->router()->route($this->snapshot([
            'healthy' => false,
            'servable_now' => 20,
            'leases_match_claimed' => false,
            'health_flags' => ['lease_leak_detected' => true],
        ]));

        $this->assertNotSame(AtlasTaskServingHealthFlagActionRouter::ACTION_REPLENISH_OR_REPAIR, $r['primary_action']);
        $this->assertSame(AtlasTaskServingHealthFlagActionRouter::ACTION_INSPECT_LEASE_PARITY, $r['primary_action']);
    }

    // ── AC: deterministic priority when multiple flags true ──────────────────

    public function test_dry_queue_takes_priority_over_recoverable_backlog(): void
    {
        $r = $this->router()->route($this->snapshot([
            'recoverable' => ['total' => 3],
            'health_flags' => ['dry_queue' => true, 'recoverable_backlog' => true],
        ]));

        $this->assertSame(AtlasTaskServingHealthFlagActionRouter::ACTION_REPLENISH_OR_REPAIR, $r['primary_action']);
        $this->assertContains(AtlasTaskServingHealthFlagActionRouter::ACTION_REAP_LEASES, $r['secondary_actions']);
    }

    public function test_recoverable_backlog_takes_priority_over_malformed_risk(): void
    {
        $r = $this->router()->route($this->snapshot([
            'recoverable' => ['total' => 2],
            'health_flags' => ['recoverable_backlog' => true, 'malformed_risk' => true],
        ]));

        $this->assertSame(AtlasTaskServingHealthFlagActionRouter::ACTION_REAP_LEASES, $r['primary_action']);
        $this->assertContains(AtlasTaskServingHealthFlagActionRouter::ACTION_SWEEP_MALFORMED, $r['secondary_actions']);
    }

    public function test_malformed_risk_takes_priority_over_lease_parity(): void
    {
        $r = $this->router()->route($this->snapshot([
            'servable_now' => 5,
            'leases_match_claimed' => false,
            'health_flags' => ['malformed_risk' => true, 'lease_leak_detected' => true],
        ]));

        $this->assertSame(AtlasTaskServingHealthFlagActionRouter::ACTION_SWEEP_MALFORMED, $r['primary_action']);
        $this->assertContains(AtlasTaskServingHealthFlagActionRouter::ACTION_INSPECT_LEASE_PARITY, $r['secondary_actions']);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_route_is_deterministic(): void
    {
        $snapshot = $this->snapshot(['health_flags' => ['dry_queue' => true]]);
        $a = $this->router()->route($snapshot);
        $b = $this->router()->route($snapshot);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_router_source_has_no_artisan_git_or_provider_calls(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../../../../app/Services/Ai/SelfConstruction/TaskServing/AtlasTaskServingHealthFlagActionRouter.php');
        foreach (['Artisan::', 'exec(', 'shell_exec', 'Process::', 'Http::', 'DB::', '->update(', '->delete('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "router must not call {$forbidden}");
        }
    }
}
