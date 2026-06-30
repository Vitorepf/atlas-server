<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Health;

use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroWorkerIdlePredictor;
use DateTimeImmutable;
use DateTimeZone;
use Tests\TestCase;

final class AtlasMaestroWorkerIdlePredictorTest extends TestCase
{
    public function test_project_calculates_idle_projection_from_claimable_depth_and_serve_window(): void
    {
        $now = new DateTimeImmutable('2026-06-24T12:00:00+00:00', new DateTimeZone('UTC'));
        $health = new class
        {
            public int $snapshotCalls = 0;

            /** @return array<string,mixed> */
            public function snapshot(): array
            {
                $this->snapshotCalls++;

                return ['claimable_depth' => 12];
            }
        };
        $sentinel = new class
        {
            public int $statusCalls = 0;

            /** @return array<string,mixed> */
            public function status(): array
            {
                $this->statusCalls++;

                return [
                    'serve_total' => 6,
                    'window_elapsed_seconds' => 120,
                ];
            }
        };

        $projection = (new AtlasMaestroWorkerIdlePredictor(
            $health,
            $sentinel,
            static fn (): DateTimeImmutable => $now,
        ))->project();

        $this->assertSame('atlas.maestro.health.worker_idle_predictor.v1', $projection['schema']);
        $this->assertSame(12, $projection['claimable_depth']);
        $this->assertSame(6, $projection['serve_total']);
        $this->assertSame(120, $projection['window_elapsed_seconds']);
        $this->assertEqualsWithDelta(3.0, $projection['serve_rate_per_minute'], 0.000001);
        $this->assertSame(240, $projection['seconds_until_dry']);
        $this->assertSame('2026-06-24T12:04:00+00:00', $projection['projected_idle_at_iso8601']);
        $this->assertSame('medium', $projection['confidence']);
        $this->assertArrayNotHasKey('reason', $projection);
        $this->assertSame(1, $health->snapshotCalls);
        $this->assertSame(1, $sentinel->statusCalls);
    }

    public function test_zero_consumption_returns_no_consumption_reason_without_fake_eta(): void
    {
        $projection = (new AtlasMaestroWorkerIdlePredictor(
            new class
            {
                /** @return array<string,mixed> */
                public function snapshot(): array
                {
                    return ['claimable_depth' => 5];
                }
            },
            new class
            {
                /** @return array<string,mixed> */
                public function status(): array
                {
                    return [
                        'serve_total' => 0,
                        'window_elapsed_seconds' => 300,
                    ];
                }
            },
        ))->project();

        $this->assertSame(5, $projection['claimable_depth']);
        $this->assertEqualsWithDelta(0.0, $projection['serve_rate_per_minute'], 0.000001);
        $this->assertNull($projection['seconds_until_dry']);
        $this->assertNull($projection['projected_idle_at_iso8601']);
        $this->assertSame('low', $projection['confidence']);
        $this->assertSame('no_consumption_observed', $projection['reason']);
    }

    public function test_empty_queue_projection_and_confidence_bucket_boundaries(): void
    {
        $now = new DateTimeImmutable('2026-06-24T12:00:00+00:00', new DateTimeZone('UTC'));

        $low = $this->project(depth: 4, serveTotal: 2, elapsedSeconds: 60, now: $now);
        $medium = $this->project(depth: 4, serveTotal: 3, elapsedSeconds: 60, now: $now);
        $high = $this->project(depth: 0, serveTotal: 10, elapsedSeconds: 60, now: $now);

        $this->assertSame('low', $low['confidence']);
        $this->assertSame('medium', $medium['confidence']);
        $this->assertSame('high', $high['confidence']);
        $this->assertSame(0, $high['claimable_depth']);
        $this->assertSame(0, $high['seconds_until_dry']);
        $this->assertSame('2026-06-24T12:00:00+00:00', $high['projected_idle_at_iso8601']);
    }

    public function test_active_leases_from_health_snapshot_included_in_projection(): void
    {
        $health = new class
        {
            /** @return array<string,mixed> */
            public function snapshot(): array
            {
                return ['claimable_depth' => 8, 'active_leases' => 3];
            }
        };
        $sentinel = new class
        {
            /** @return array<string,mixed> */
            public function status(): array
            {
                return ['serve_total' => 10, 'window_elapsed_seconds' => 60];
            }
        };

        $projection = (new AtlasMaestroWorkerIdlePredictor($health, $sentinel))->project();

        $this->assertSame(3, $projection['active_workers']);
    }

    public function test_claimed_count_from_health_snapshot_included_in_projection(): void
    {
        $health = new class
        {
            /** @return array<string,mixed> */
            public function snapshot(): array
            {
                return ['claimable_depth' => 5, 'claimed' => 7];
            }
        };
        $sentinel = new class
        {
            /** @return array<string,mixed> */
            public function status(): array
            {
                return ['serve_total' => 10, 'window_elapsed_seconds' => 60];
            }
        };

        $projection = (new AtlasMaestroWorkerIdlePredictor($health, $sentinel))->project();

        $this->assertSame(7, $projection['active_workers']);
    }

    public function test_tiny_window_produces_low_confidence_and_no_eta(): void
    {
        // 20s window < 30s MIN_WINDOW_SECONDS — rate unreliable during swarm ramp
        $health = new class
        {
            /** @return array<string,mixed> */
            public function snapshot(): array
            {
                return ['claimable_depth' => 10];
            }
        };
        $sentinel = new class
        {
            /** @return array<string,mixed> */
            public function status(): array
            {
                return ['serve_total' => 5, 'window_elapsed_seconds' => 20];
            }
        };

        $projection = (new AtlasMaestroWorkerIdlePredictor($health, $sentinel))->project();

        $this->assertSame('low', $projection['confidence']);
        $this->assertNull($projection['seconds_until_dry']);
        $this->assertNull($projection['projected_idle_at_iso8601']);
        $this->assertSame('window_too_small', $projection['reason']);
    }

    public function test_stale_window_produces_low_confidence_and_no_eta(): void
    {
        // No timing data at all in serving status → window is estimated (fallback)
        $health = new class
        {
            /** @return array<string,mixed> */
            public function snapshot(): array
            {
                return ['claimable_depth' => 10];
            }
        };
        $sentinel = new class
        {
            /** @return array<string,mixed> */
            public function status(): array
            {
                return ['serve_total' => 8]; // no window timing keys
            }
        };

        $projection = (new AtlasMaestroWorkerIdlePredictor($health, $sentinel))->project();

        $this->assertTrue($projection['window_estimated']);
        $this->assertSame('low', $projection['confidence']);
        $this->assertNull($projection['seconds_until_dry']);
        $this->assertNull($projection['projected_idle_at_iso8601']);
        $this->assertSame('window_stale', $projection['reason']);
    }

    /** @return array<string,mixed> */
    private function project(int $depth, int $serveTotal, int $elapsedSeconds, DateTimeImmutable $now): array
    {
        return (new AtlasMaestroWorkerIdlePredictor(
            new class($depth)
            {
                public function __construct(private readonly int $depth) {}

                /** @return array<string,mixed> */
                public function snapshot(): array
                {
                    return [
                        'queue_status_distribution' => [
                            'claimable' => $this->depth,
                        ],
                    ];
                }
            },
            new class($serveTotal, $elapsedSeconds)
            {
                public function __construct(
                    private readonly int $serveTotal,
                    private readonly int $elapsedSeconds,
                ) {}

                /** @return array<string,mixed> */
                public function status(): array
                {
                    return [
                        'serve_total' => $this->serveTotal,
                        'window_elapsed_seconds' => $this->elapsedSeconds,
                    ];
                }
            },
            static fn (): DateTimeImmutable => $now,
        ))->project();
    }
}
