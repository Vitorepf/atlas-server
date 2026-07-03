<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroLeaseLifetimeHistogram;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasMaestroLeaseLifetimeHistogram does not crash when a lease carries
 * a malformed leased_at or expires_at timestamp.
 */
final class AtlasMaestroLeaseLifetimeHistogramHardeningTest extends TestCase
{
    private function makeHistogram(array $leases): AtlasMaestroLeaseLifetimeHistogram
    {
        $repo = new class($leases) {
            public function __construct(
                /** @var list<array<string,mixed>> */
                private array $leases,
            ) {}

            /** @return list<array<string,mixed>> */
            public function activeLeases(): array
            {
                return $this->leases;
            }
        };

        return new AtlasMaestroLeaseLifetimeHistogram(
            leases: $repo,
            clock: fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-03T20:00:00+00:00', new DateTimeZone('UTC')),
        );
    }

    // ── Malformed leased_at ──────────────────────────────────────────────────────

    public function test_malformed_leased_at_does_not_throw(): void
    {
        $histogram = $this->makeHistogram([
            [
                'lease_id' => 'lease-1',
                'leased_at' => 'not-a-date',
                'client_id' => 'worker-1',
            ],
        ]);

        // Should not throw — the bad date is skipped and falls back to clock time.
        $result = $histogram->histogram();

        $this->assertArrayHasKey('total_active', $result);
        $this->assertIsInt($result['total_active']);
    }

    public function test_malformed_leased_at_falls_back_to_clock(): void
    {
        $histogram = $this->makeHistogram([
            [
                'lease_id' => 'lease-1',
                'leased_at' => 'completely-invalid-timestamp',
                'client_id' => 'worker-1',
            ],
        ]);

        $result = $histogram->histogram();

        // The lifetime should be 0 since leased_at falls back to clock time (now).
        $this->assertSame(0, $result['oldest_seconds']);
    }

    // ── Malformed expires_at ─────────────────────────────────────────────────────

    public function test_malformed_expires_at_does_not_throw(): void
    {
        $histogram = $this->makeHistogram([
            [
                'lease_id' => 'lease-1',
                'leased_at' => '2026-07-03T19:00:00+00:00',
                'expires_at' => 'not-a-date',
                'client_id' => 'worker-1',
            ],
        ]);

        // Should not throw — the bad expires_at is skipped.
        $result = $histogram->histogram();

        $this->assertArrayHasKey('total_active', $result);
        $this->assertSame(1, $result['total_active']);
    }

    public function test_malformed_expires_at_skips_near_expiry_check(): void
    {
        $histogram = $this->makeHistogram([
            [
                'lease_id' => 'lease-1',
                'leased_at' => '2026-07-03T19:00:00+00:00',
                'expires_at' => 'garbage',
                'client_id' => 'worker-1',
            ],
        ]);

        $result = $histogram->histogram();

        // With malformed expires_at, near_expiry and expired counts should be 0.
        $this->assertSame(0, $result['near_expiry_count']);
        $this->assertSame(0, $result['expired_count']);
    }

    // ── Both malformed ───────────────────────────────────────────────────────────

    public function test_both_dates_malformed_does_not_crash(): void
    {
        $histogram = $this->makeHistogram([
            [
                'lease_id' => 'lease-1',
                'leased_at' => 'bad-leased',
                'expires_at' => 'bad-expires',
                'client_id' => 'worker-1',
            ],
        ]);

        $result = $histogram->histogram();

        $this->assertArrayHasKey('total_active', $result);
        $this->assertSame(1, $result['total_active']);
    }

    // ── Mixed valid and malformed ────────────────────────────────────────────────

    public function test_mixed_valid_and_malformed_leased_at(): void
    {
        $histogram = $this->makeHistogram([
            [
                'lease_id' => 'lease-1',
                'leased_at' => '2026-07-03T19:00:00+00:00',
                'client_id' => 'worker-1',
            ],
            [
                'lease_id' => 'lease-2',
                'leased_at' => 'not-a-date',
                'client_id' => 'worker-2',
            ],
        ]);

        $result = $histogram->histogram();

        $this->assertSame(2, $result['total_active']);
    }
}
