<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog\Checks;

use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;
use Carbon\CarbonImmutable;

/**
 * ELEV-24 — Watchdog de disco livre.
 *
 * Verifica se o volume de `storage/atlas` tem espaço suficiente para os
 * ledgers append-only. Threshold em GB é configurável.
 *
 * Comportamento contratual: `background` (produtores autônomos) pausam com
 * contador de skip; `interactive` (turno vivo) NUNCA é bloqueado. Esta classe
 * apenas EXPÕE o sinal para a WDG; o pause do background é responsabilidade
 * do produtor (que consulta este check via `isBelowFloor()`).
 */
final class DiskFreeWatchdogCheck implements AtlasWatchdogCheck
{
    /** @var callable():array{path:string,free_bytes:int,total_bytes:int} */
    private $probe;

    private CarbonImmutable $now;

    private int $floorGb;

    /**
     * @param  callable():array{path:string,free_bytes:int,total_bytes:int}|null  $probe
     */
    public function __construct(
        ?callable $probe = null,
        ?CarbonImmutable $now = null,
        ?int $floorGb = null,
    ) {
        $this->probe = $probe ?? function (): array {
            $path = storage_path('atlas');
            if (! is_dir($path)) {
                $path = storage_path();
            }
            $free = @disk_free_space($path);
            $total = @disk_total_space($path);

            return [
                'path' => $path,
                'free_bytes' => is_numeric($free) ? (int) $free : 0,
                'total_bytes' => is_numeric($total) ? (int) $total : 0,
            ];
        };
        $this->now = $now ?? CarbonImmutable::now('UTC');
        $this->floorGb = $floorGb ?? (int) config('atlas_resource_budget.disk_free_floor_gb', 5);
    }

    public function id(): string
    {
        return 'elev-24.disk_free';
    }

    public function isBelowFloor(): bool
    {
        $probed = ($this->probe)();
        $freeGb = (int) floor((int) $probed['free_bytes'] / (1024 ** 3));

        return $freeGb < $this->floorGb;
    }

    public function run(): AtlasWatchdogCheckResult
    {
        $probed = ($this->probe)();
        $freeBytes = (int) ($probed['free_bytes'] ?? 0);
        $totalBytes = (int) ($probed['total_bytes'] ?? 0);
        $freeGb = (int) floor($freeBytes / (1024 ** 3));
        $totalGb = (int) floor($totalBytes / (1024 ** 3));

        $evidence = [
            'schema_version' => 'atlas.acos.disk_free_watchdog.v1',
            'generated_at' => $this->now->toIso8601String(),
            'path' => (string) ($probed['path'] ?? ''),
            'free_gb' => $freeGb,
            'total_gb' => $totalGb,
            'floor_gb' => $this->floorGb,
            'background_should_pause' => $freeGb < $this->floorGb,
        ];

        if ($freeGb < $this->floorGb) {
            return AtlasWatchdogCheckResult::alert($evidence, [
                'code' => 'disk_below_floor',
                'message' => 'Free disk below declared floor; background producers should pause.',
                'free_gb' => $freeGb,
                'floor_gb' => $this->floorGb,
            ]);
        }

        return AtlasWatchdogCheckResult::ok($evidence);
    }
}
