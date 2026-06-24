<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Closure;
use Throwable;

final class AtlasLoopFleetSizeAutotuner
{
    public const SCHEMA = 'atlas.loop.fleet_autotune.v1';

    /**
     * @param  Closure():array<string,mixed>|null  $pressureSampler
     */
    public function __construct(
        private readonly ?Closure $pressureSampler = null,
        private readonly ?string $snapshotDir = null,
    ) {
    }

    /**
     * @param  array<string,mixed>  $pressureSample
     * @return array{schema:string,current_cap:int,recommended_cap:int,pressure_score:int,reason:list<string>}
     */
    public function recommend(int $currentCap, array $pressureSample): array
    {
        $currentCap = max(0, $currentCap);
        $fleetInFlight = max(0, (int) ($pressureSample['fleet_in_flight'] ?? 0));
        $loadAvg = max(0.0, (float) ($pressureSample['load_avg_1m'] ?? 0.0));
        $memFreePct = max(0.0, min(100.0, (float) ($pressureSample['mem_free_pct'] ?? 100.0)));
        $providerP95 = max(0, (int) ($pressureSample['provider_p95_ms'] ?? 0));

        $score = min(100, (int) round(
            min(35, $fleetInFlight * 8)
            + min(25, $loadAvg * 8)
            + min(25, max(0.0, 40.0 - $memFreePct) * 0.8)
            + min(15, $providerP95 / 200),
        ));

        $baseline = max(1, $currentCap > 0 ? $currentCap : max(1, $fleetInFlight + 1));
        $reasons = [];
        if ($score >= 75) {
            $recommended = max(1, $baseline - 1);
            $reasons[] = 'high_pressure_reduce_cap';
        } elseif ($score <= 30) {
            $recommended = $baseline + 1;
            $reasons[] = 'low_pressure_can_raise_cap';
        } else {
            $recommended = $baseline;
            $reasons[] = 'medium_pressure_hold_cap';
        }

        if ($memFreePct < 20.0) {
            $reasons[] = 'memory_pressure';
        }
        if ($providerP95 >= 2000) {
            $reasons[] = 'provider_latency_high';
        }

        return [
            'schema' => self::SCHEMA,
            'current_cap' => $currentCap,
            'recommended_cap' => $recommended,
            'pressure_score' => $score,
            'reason' => array_values(array_unique($reasons)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function pressureSample(): array
    {
        if ($this->pressureSampler !== null) {
            return ($this->pressureSampler)();
        }

        return [
            'fleet_in_flight' => (new AtlasLoopFleetGovernor)->fleetInFlight(),
            'load_avg_1m' => $this->loadAverage(),
            'mem_free_pct' => $this->memoryFreePct(),
            'provider_p95_ms' => (int) (new AtlasLoopProviderHealthProbe)->snapshot('unknown', 3600)['p95_ms'],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function writeAdvisorySnapshot(int $currentCap, ?array $pressureSample = null): ?array
    {
        if (! (bool) config('atlas.loop.fleet_autotuner_enabled', false)) {
            return null;
        }

        $snapshot = $this->recommend($currentCap, $pressureSample ?? $this->pressureSample());
        $dir = $this->snapshotDir ?? storage_path('app/atlas/loop/fleet-autotune');
        try {
            if (! is_dir($dir) && ! @mkdir($dir, 0o775, true) && ! is_dir($dir)) {
                return $snapshot;
            }
            @file_put_contents($dir.'/latest.json', json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
        } catch (Throwable) {
            // advisory-only: write failure must never affect keepalive.
        }

        return $snapshot;
    }

    private function loadAverage(): float
    {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;

        return is_array($load) && isset($load[0]) ? (float) $load[0] : 0.0;
    }

    private function memoryFreePct(): float
    {
        $output = @shell_exec('vm_stat 2>/dev/null');
        if (! is_string($output) || $output === '') {
            return 100.0;
        }

        preg_match_all('/^(Pages free|Pages inactive|Pages speculative|Pages active|Pages wired down):\\s+([0-9]+)/m', $output, $matches, PREG_SET_ORDER);
        $pages = [];
        foreach ($matches as $match) {
            $pages[$match[1]] = (int) $match[2];
        }
        $free = ($pages['Pages free'] ?? 0) + ($pages['Pages inactive'] ?? 0) + ($pages['Pages speculative'] ?? 0);
        $total = $free + ($pages['Pages active'] ?? 0) + ($pages['Pages wired down'] ?? 0);
        if ($total <= 0) {
            return 100.0;
        }

        return round(($free / $total) * 100, 2);
    }
}
