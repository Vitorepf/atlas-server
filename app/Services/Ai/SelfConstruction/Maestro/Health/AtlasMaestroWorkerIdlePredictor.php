<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Health;

use App\Services\Ai\SelfConstruction\AtlasTaskCoordinationHealthService;
use App\Services\Ai\SelfConstruction\AtlasTaskServingSentinel;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class AtlasMaestroWorkerIdlePredictor
{
    public const SCHEMA = 'atlas.maestro.health.worker_idle_predictor.v1';

    private const MIN_WINDOW_SECONDS = 30;

    private Closure $clock;

    /**
     * @param  object|null  $coordinationHealth Object exposing snapshot(): array<string,mixed>.
     * @param  object|null  $sentinel Object exposing status(): array<string,mixed>.
     */
    public function __construct(
        private readonly ?object $coordinationHealth = null,
        private readonly ?object $sentinel = null,
        ?callable $clock = null,
    ) {
        $this->clock = $clock instanceof Closure
            ? $clock
            : Closure::fromCallable($clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    /**
     * @return array{
     *     schema:string,
     *     claimable_depth:int,
     *     serve_total:int,
     *     window_elapsed_seconds:int,
     *     serve_rate_per_minute:float,
     *     seconds_until_dry:int|null,
     *     projected_idle_at_iso8601:string|null,
     *     confidence:string,
     *     reason?:string
     * }
     */
    public function project(): array
    {
        $now = ($this->clock)()->setTimezone(new DateTimeZone('UTC'));
        $health = $this->coordinationHealthSnapshot();
        $serving = $this->servingStatus();

        $claimableDepth = $this->claimableDepth($health);
        $activeWorkers = $this->activeWorkers($health);
        $serveTotal = max(0, (int) ($serving['serve_total'] ?? 0));
        $elapsedSeconds = $this->windowElapsedSeconds($serving, $now);
        $windowEstimated = $this->windowIsEstimated($serving);
        $windowTooSmall = $elapsedSeconds < self::MIN_WINDOW_SECONDS;

        $serveRatePerMinute = $elapsedSeconds > 0
            ? round($serveTotal / ($elapsedSeconds / 60), 6)
            : 0.0;

        $degradedWindow = $windowEstimated || $windowTooSmall;
        $confidence = $degradedWindow ? 'low' : $this->confidence($serveTotal);

        $projection = [
            'schema' => self::SCHEMA,
            'claimable_depth' => $claimableDepth,
            'active_workers' => $activeWorkers,
            'serve_total' => $serveTotal,
            'window_elapsed_seconds' => $elapsedSeconds,
            'window_estimated' => $windowEstimated,
            'serve_rate_per_minute' => $serveRatePerMinute,
            'seconds_until_dry' => null,
            'projected_idle_at_iso8601' => null,
            'confidence' => $confidence,
        ];

        if ($serveRatePerMinute <= 0.0) {
            $projection['reason'] = 'no_consumption_observed';

            return $projection;
        }

        if ($degradedWindow) {
            $projection['reason'] = $windowEstimated ? 'window_stale' : 'window_too_small';

            return $projection;
        }

        $secondsUntilDry = $claimableDepth === 0
            ? 0
            : (int) ceil(($claimableDepth / $serveRatePerMinute) * 60);

        $projection['seconds_until_dry'] = $secondsUntilDry;
        $projection['projected_idle_at_iso8601'] = $now
            ->modify('+'.$secondsUntilDry.' seconds')
            ->format(DATE_ATOM);

        return $projection;
    }

    /** @return array<string,mixed> */
    private function coordinationHealthSnapshot(): array
    {
        $health = $this->coordinationHealth ?? new AtlasTaskCoordinationHealthService;

        return method_exists($health, 'snapshot') ? (array) $health->snapshot() : [];
    }

    /** @return array<string,mixed> */
    private function servingStatus(): array
    {
        $sentinel = $this->sentinel ?? new AtlasTaskServingSentinel;

        return method_exists($sentinel, 'status') ? (array) $sentinel->status() : [];
    }

    /** @param array<string,mixed> $health */
    private function activeWorkers(array $health): int
    {
        foreach (['active_leases', 'claimed', 'active_workers', 'workers_active'] as $key) {
            if (isset($health[$key]) && is_numeric($health[$key])) {
                return max(0, (int) $health[$key]);
            }
        }

        return 0;
    }

    /** @param array<string,mixed> $serving */
    private function windowIsEstimated(array $serving): bool
    {
        foreach (['window_elapsed_seconds', 'serve_window_elapsed_seconds', 'elapsed_seconds', 'tail_window_seconds', 'window_seconds'] as $key) {
            if (isset($serving[$key]) && is_numeric($serving[$key])) {
                return false;
            }
        }
        foreach (['window_started_at', 'window_started_at_iso8601', 'first_serve_at', 'first_serve_at_iso8601'] as $key) {
            if (isset($serving[$key])) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $health */
    private function claimableDepth(array $health): int
    {
        if (isset($health['claimable_depth']) && is_numeric($health['claimable_depth'])) {
            return max(0, (int) $health['claimable_depth']);
        }

        $distribution = (array) ($health['queue_status_distribution'] ?? []);
        if (isset($distribution['claimable']) && is_numeric($distribution['claimable'])) {
            return max(0, (int) $distribution['claimable']);
        }

        return 0;
    }

    /** @param array<string,mixed> $serving */
    private function windowElapsedSeconds(array $serving, DateTimeImmutable $now): int
    {
        foreach (['window_elapsed_seconds', 'serve_window_elapsed_seconds', 'elapsed_seconds', 'tail_window_seconds', 'window_seconds'] as $key) {
            if (isset($serving[$key]) && is_numeric($serving[$key])) {
                return max(0, (int) $serving[$key]);
            }
        }

        $startedAt = $this->timestamp($serving, ['window_started_at', 'window_started_at_iso8601', 'first_serve_at', 'first_serve_at_iso8601']);
        if ($startedAt !== null) {
            $endedAt = $this->timestamp($serving, ['window_ended_at', 'window_ended_at_iso8601', 'last_serve_at', 'last_serve_at_iso8601', 'generated_at']);

            return max(0, ($endedAt ?? $now->getTimestamp()) - $startedAt);
        }

        return 60;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $keys
     */
    private function timestamp(array $payload, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (! isset($payload[$key])) {
                continue;
            }

            if (is_numeric($payload[$key])) {
                return (int) $payload[$key];
            }

            if (is_string($payload[$key]) && trim($payload[$key]) !== '') {
                try {
                    return (new DateTimeImmutable($payload[$key]))->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return null;
    }

    private function confidence(int $serveTotal): string
    {
        if ($serveTotal >= 10) {
            return 'high';
        }

        if ($serveTotal >= 3) {
            return 'medium';
        }

        return 'low';
    }
}
