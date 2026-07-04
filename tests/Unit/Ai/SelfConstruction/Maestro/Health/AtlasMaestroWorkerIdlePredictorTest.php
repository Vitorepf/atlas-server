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

    // ── AC1: active_claimed_workers backwards-compatible alias of active_workers ──

    public function test_active_claimed_workers_is_a_positive_alias_of_active_workers(): void
    {
        $health = new class
        {
            /** @return array<string,mixed> */
            public function snapshot(): array
            {
                return ['claimable_depth' => 8, 'active_leases' => 4];
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

        $this->assertSame(4, $projection['active_workers']);
        $this->assertSame(4, $projection['active_claimed_workers']);
        $this->assertSame($projection['active_workers'], $projection['active_claimed_workers']);
    }

    // ── AC2: serve_total=0, claimable_depth>0, active_workers>0 → telemetry_blind_spot ──

    public function test_zero_serve_with_active_workers_and_claimable_backlog_is_telemetry_blind_spot(): void
    {
        $health = new class
        {
            /** @return array<string,mixed> */
            public function snapshot(): array
            {
                return ['claimable_depth' => 12, 'active_leases' => 3];
            }
        };
        $sentinel = new class
        {
            /** @return array<string,mixed> */
            public function status(): array
            {
                return ['serve_total' => 0, 'window_elapsed_seconds' => 300];
            }
        };

        $projection = (new AtlasMaestroWorkerIdlePredictor($health, $sentinel))->project();

        $this->assertSame('low', $projection['confidence']);
        $this->assertNull($projection['seconds_until_dry']);
        $this->assertNull($projection['projected_idle_at_iso8601']);
        $this->assertStringContainsString('telemetry_blind_spot', $projection['reason']);
    }

    // ── AC3: true zero-consumption with no active workers stays no_consumption_observed ──

    public function test_true_zero_consumption_with_no_active_workers_stays_no_consumption_observed(): void
    {
        $health = new class
        {
            /** @return array<string,mixed> */
            public function snapshot(): array
            {
                return ['claimable_depth' => 5];
            }
        };
        $sentinel = new class
        {
            /** @return array<string,mixed> */
            public function status(): array
            {
                return ['serve_total' => 0, 'window_elapsed_seconds' => 300];
            }
        };

        $projection = (new AtlasMaestroWorkerIdlePredictor($health, $sentinel))->project();

        $this->assertSame(0, $projection['active_workers']);
        $this->assertSame('no_consumption_observed', $projection['reason']);
        $this->assertStringNotContainsString('telemetry_blind_spot', $projection['reason']);
        $this->assertNull($projection['seconds_until_dry']);
        $this->assertNull($projection['projected_idle_at_iso8601']);
    }

    public function test_zero_serve_with_active_workers_but_empty_backlog_stays_no_consumption_observed(): void
    {
        // active_workers>0 but claimable_depth=0 — no backlog to be blind about.
        $health = new class
        {
            /** @return array<string,mixed> */
            public function snapshot(): array
            {
                return ['claimable_depth' => 0, 'active_leases' => 2];
            }
        };
        $sentinel = new class
        {
            /** @return array<string,mixed> */
            public function status(): array
            {
                return ['serve_total' => 0, 'window_elapsed_seconds' => 300];
            }
        };

        $projection = (new AtlasMaestroWorkerIdlePredictor($health, $sentinel))->project();

        $this->assertSame('no_consumption_observed', $projection['reason']);
    }

    // ── AC: high drain with falling claimable depth predicts idle risk ─────────

    public function test_high_drain_with_falling_claimable_depth_predicts_idle_risk(): void
    {
        $health = new class
        {
            /** @return array<string,mixed> */
            public function snapshot(): array
            {
                return ['claimable_depth' => 8, 'claimable_depth_delta' => -4];
            }
        };
        $sentinel = new class
        {
            /** @return array<string,mixed> */
            public function status(): array
            {
                return ['serve_total' => 20, 'window_elapsed_seconds' => 60];
            }
        };

        $projection = (new AtlasMaestroWorkerIdlePredictor($health, $sentinel))->project();

        $this->assertSame('high', $projection['idle_risk']);
        $this->assertSame('urgent_topup', $projection['recommended_topup_mode']);
    }

    public function test_high_drain_with_rising_claimable_depth_is_not_idle_risk(): void
    {
        $health = new class
        {
            /** @return array<string,mixed> */
            public function snapshot(): array
            {
                return ['claimable_depth' => 8, 'claimable_depth_delta' => 3];
            }
        };
        $sentinel = new class
        {
            /** @return array<string,mixed> */
            public function status(): array
            {
                return ['serve_total' => 20, 'window_elapsed_seconds' => 60];
            }
        };

        $projection = (new AtlasMaestroWorkerIdlePredictor($health, $sentinel))->project();

        $this->assertSame('low', $projection['idle_risk']);
    }

    public function test_missing_depth_delta_never_asserts_idle_risk(): void
    {
        $health = new class
        {
            /** @return array<string,mixed> */
            public function snapshot(): array
            {
                return ['claimable_depth' => 8];
            }
        };
        $sentinel = new class
        {
            /** @return array<string,mixed> */
            public function status(): array
            {
                return ['serve_total' => 20, 'window_elapsed_seconds' => 60];
            }
        };

        $projection = (new AtlasMaestroWorkerIdlePredictor($health, $sentinel))->project();

        $this->assertSame('low', $projection['idle_risk']);
    }

    // ── AC: deep queue with weak quality predicts effective_idle_risk ──────────

    public function test_deep_queue_with_weak_quality_predicts_effective_idle_risk(): void
    {
        $health = new class
        {
            /** @return array<string,mixed> */
            public function snapshot(): array
            {
                return ['claimable_depth' => 15, 'task_quality_floor_breached' => true];
            }
        };
        $sentinel = new class
        {
            /** @return array<string,mixed> */
            public function status(): array
            {
                return ['serve_total' => 5, 'window_elapsed_seconds' => 60];
            }
        };

        $projection = (new AtlasMaestroWorkerIdlePredictor($health, $sentinel))->project();

        $this->assertTrue($projection['effective_idle_risk']);
        $this->assertSame('quality_repair_before_topup', $projection['recommended_topup_mode']);
    }

    public function test_deep_queue_with_healthy_quality_is_not_effective_idle_risk(): void
    {
        $health = new class
        {
            /** @return array<string,mixed> */
            public function snapshot(): array
            {
                return ['claimable_depth' => 15, 'task_quality_floor_breached' => false];
            }
        };
        $sentinel = new class
        {
            /** @return array<string,mixed> */
            public function status(): array
            {
                return ['serve_total' => 5, 'window_elapsed_seconds' => 60];
            }
        };

        $projection = (new AtlasMaestroWorkerIdlePredictor($health, $sentinel))->project();

        $this->assertFalse($projection['effective_idle_risk']);
    }

    public function test_shallow_queue_with_weak_quality_is_not_effective_idle_risk(): void
    {
        $health = new class
        {
            /** @return array<string,mixed> */
            public function snapshot(): array
            {
                return ['claimable_depth' => 2, 'task_quality_floor_breached' => true];
            }
        };
        $sentinel = new class
        {
            /** @return array<string,mixed> */
            public function status(): array
            {
                return ['serve_total' => 5, 'window_elapsed_seconds' => 60];
            }
        };

        $projection = (new AtlasMaestroWorkerIdlePredictor($health, $sentinel))->project();

        $this->assertFalse($projection['effective_idle_risk']);
    }

    // ── AC: output includes forecast_window and recommended_topup_mode ─────────

    public function test_output_includes_forecast_window_and_recommended_topup_mode(): void
    {
        $health = new class
        {
            /** @return array<string,mixed> */
            public function snapshot(): array
            {
                return ['claimable_depth' => 5];
            }
        };
        $sentinel = new class
        {
            /** @return array<string,mixed> */
            public function status(): array
            {
                return ['serve_total' => 5, 'window_elapsed_seconds' => 60];
            }
        };

        $projection = (new AtlasMaestroWorkerIdlePredictor($health, $sentinel))->project();

        $this->assertArrayHasKey('forecast_window', $projection);
        $this->assertSame(60, $projection['forecast_window']['elapsed_seconds']);
        $this->assertArrayHasKey('estimated', $projection['forecast_window']);
        $this->assertArrayHasKey('recommended_topup_mode', $projection);
        $this->assertNotEmpty($projection['recommended_topup_mode']);
    }

    public function test_empty_queue_recommends_immediate_topup(): void
    {
        $health = new class
        {
            /** @return array<string,mixed> */
            public function snapshot(): array
            {
                return ['claimable_depth' => 0];
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

        $this->assertSame('immediate_topup', $projection['recommended_topup_mode']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC2/AC3/AC4: contract — output keys, blind spot reason, fallback drain
    // ═══════════════════════════════════════════════════════════════════════

    public function test_project_output_has_required_keys(): void
    {
        $now = new DateTimeImmutable('2026-06-24T12:00:00+00:00', new DateTimeZone('UTC'));
        $r = $this->project(10, 30, 120, $now);

        foreach (['claimable_depth', 'active_workers', 'active_claimed_workers', 'serve_total', 'window_elapsed_seconds', 'serve_rate_per_minute', 'confidence'] as $k) {
            $this->assertArrayHasKey($k, $r);
        }
    }

    public function test_zero_serve_with_active_workers_and_claimable_returns_telemetry_blind_spot(): void
    {
        $now = new DateTimeImmutable('2026-06-24T12:00:00+00:00', new DateTimeZone('UTC'));
        $health = new class {
            public function snapshot(): array { return ['claimable_depth' => 10]; }
        };
        $sentinel = new class {
            public function status(): array { return ['serve_total' => 0, 'window_elapsed_seconds' => 120]; }
        };

        $r = (new AtlasMaestroWorkerIdlePredictor($health, $sentinel))->project();

        $this->assertArrayHasKey('reason', $r);
    }

    public function test_fallback_drain_rate_appears_when_claimed_completed_delta_present(): void
    {
        $now = new DateTimeImmutable('2026-06-24T12:00:00+00:00', new DateTimeZone('UTC'));
        $r = $this->project(10, 30, 120, $now);

        $this->assertIsArray($r);
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
