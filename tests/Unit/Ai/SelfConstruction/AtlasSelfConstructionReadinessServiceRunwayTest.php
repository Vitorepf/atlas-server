<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReservationRepository;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionReadinessServiceRunwayTest extends TestCase
{
    private function service(): AtlasSelfConstructionReadinessService
    {
        return new AtlasSelfConstructionReadinessService(new AtlasSelfConstructionReservationRepository);
    }

    public function test_servable_now_below_active_worker_floor_returns_replenish_now(): void
    {
        $r = $this->service()->autonomousOsRunway([
            'queue_health' => ['servable_now' => 4],
            'active_worker_count' => 4,
            'floor_per_worker' => 2.0,
        ]);

        self::assertSame('replenish_now', $r['runway_status']);
        self::assertNotSame('ready', $r['runway_status']);
    }

    public function test_malformed_signal_returns_stop_and_repair_with_concrete_blockers(): void
    {
        $r = $this->service()->autonomousOsRunway([
            'queue_health' => ['servable_now' => 100, 'malformed_count' => 3],
            'active_worker_count' => 2,
        ]);

        self::assertSame('stop_and_repair', $r['runway_status']);
        self::assertContains('malformed_tasks:3', $r['blockers']);
    }

    public function test_recoverable_signal_returns_stop_and_repair_with_concrete_blockers(): void
    {
        $r = $this->service()->autonomousOsRunway([
            'queue_health' => ['servable_now' => 100, 'recoverable_count' => 5],
            'active_worker_count' => 2,
        ]);

        self::assertSame('stop_and_repair', $r['runway_status']);
        self::assertContains('recoverable_tasks_pending_recovery:5', $r['blockers']);
    }

    public function test_lease_leak_signal_returns_stop_and_repair_with_concrete_blockers(): void
    {
        $r = $this->service()->autonomousOsRunway([
            'queue_health' => ['servable_now' => 100, 'lease_leak_count' => 2],
            'active_worker_count' => 2,
        ]);

        self::assertSame('stop_and_repair', $r['runway_status']);
        self::assertContains('lease_leak_detected:2', $r['blockers']);
    }

    public function test_healthy_queue_with_enough_servable_now_returns_ready(): void
    {
        $r = $this->service()->autonomousOsRunway([
            'queue_health' => ['servable_now' => 20],
            'active_worker_count' => 2,
            'floor_per_worker' => 2.0,
        ]);

        self::assertSame('ready', $r['runway_status']);
        self::assertArrayHasKey('claimable_per_active_worker', $r);
        self::assertSame(10.0, $r['claimable_per_active_worker']);
        self::assertSame([], $r['blockers']);
    }
}
