<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog\Checks;

use App\Services\Ai\Support\AiValueNormalizer;
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
    public const SCHEMA_VERSION = 'atlas.acos.disk_free_watchdog.v1';

    public const CHECK_ID = 'elev-24.disk_free';

    public const DEFAULT_FLOOR_GB = 5;

    public const FLOOR_GB_CONFIG_KEY = 'atlas_resource_budget.disk_free_floor_gb';

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
        $this->floorGb = $floorGb ?? (int) (AiValueNormalizer::finiteFloatOrNull(config(self::FLOOR_GB_CONFIG_KEY, self::DEFAULT_FLOOR_GB)) ?? self::DEFAULT_FLOOR_GB);
    }

    public function id(): string
    {
        return self::CHECK_ID;
    }

    public function isBelowFloor(): bool
    {
        $probed = ($this->probe)();
        $freeGb = (int) floor((int) (AiValueNormalizer::finiteFloatOrNull($probed['free_bytes'] ?? null) ?? 0) / (1024 ** 3));

        return $freeGb < $this->floorGb;
    }

    public function run(): AtlasWatchdogCheckResult
    {
        $probed = ($this->probe)();
        $freeBytes = (int) (AiValueNormalizer::finiteFloatOrNull($probed['free_bytes'] ?? null) ?? 0);
        $totalBytes = (int) (AiValueNormalizer::finiteFloatOrNull($probed['total_bytes'] ?? null) ?? 0);
        $freeGb = (int) floor($freeBytes / (1024 ** 3));
        $totalGb = (int) floor($totalBytes / (1024 ** 3));

        $evidence = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => $this->now->toIso8601String(),
            'path' => (AiValueNormalizer::trimmedStringOrNull($probed['path'] ?? null) ?? ''),
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
