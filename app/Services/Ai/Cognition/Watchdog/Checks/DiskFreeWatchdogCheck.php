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
    public const FIELD_PATH = 'path';
    public const FIELD_FREE_GB = 'free_gb';
    public const FIELD_FLOOR_GB = 'floor_gb';
    public const FIELD_FREE_BYTES = 'free_bytes';
    public const FIELD_TOTAL_BYTES = 'total_bytes';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_CODE = 'code';
    public const FIELD_TOTAL_GB = 'total_gb';
    public const FIELD_GENERATED_AT = 'generated_at';
    public const FIELD_BACKGROUND_SHOULD_PAUSE = 'background_should_pause';
    public const FIELD_MESSAGE = 'message';
    public const FIELD_ATLAS = 'atlas';
    public const FIELD_DISK_BELOW_FLOOR = 'disk_below_floor';

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
            $path = storage_path(self::FIELD_ATLAS);
            if (! is_dir($path)) {
                $path = storage_path();
            }
            $free = @disk_free_space($path);
            $total = @disk_total_space($path);

            return [
                self::FIELD_PATH => $path,
                self::FIELD_FREE_BYTES => is_numeric($free) ? (int) $free : 0,
                self::FIELD_TOTAL_BYTES => is_numeric($total) ? (int) $total : 0,
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
        $freeGb = (int) floor((int) (AiValueNormalizer::finiteFloatOrNull($probed[self::FIELD_FREE_BYTES] ?? null) ?? 0) / (1024 ** 3));

        return $freeGb < $this->floorGb;
    }

    public function run(): AtlasWatchdogCheckResult
    {
        $probed = ($this->probe)();
        $freeBytes = (int) (AiValueNormalizer::finiteFloatOrNull($probed[self::FIELD_FREE_BYTES] ?? null) ?? 0);
        $totalBytes = (int) (AiValueNormalizer::finiteFloatOrNull($probed[self::FIELD_TOTAL_BYTES] ?? null) ?? 0);
        $freeGb = (int) floor($freeBytes / (1024 ** 3));
        $totalGb = (int) floor($totalBytes / (1024 ** 3));

        $evidence = [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_GENERATED_AT => $this->now->toIso8601String(),
            self::FIELD_PATH => (AiValueNormalizer::trimmedStringOrNull($probed[self::FIELD_PATH] ?? null) ?? ''),
            self::FIELD_FREE_GB => $freeGb,
            self::FIELD_TOTAL_GB => $totalGb,
            self::FIELD_FLOOR_GB => $this->floorGb,
            self::FIELD_BACKGROUND_SHOULD_PAUSE => $freeGb < $this->floorGb,
        ];

        if ($freeGb < $this->floorGb) {
            return AtlasWatchdogCheckResult::alert($evidence, [
                self::FIELD_CODE => self::FIELD_DISK_BELOW_FLOOR,
                self::FIELD_MESSAGE => 'Free disk below declared floor; background producers should pause.',
                self::FIELD_FREE_GB => $freeGb,
                self::FIELD_FLOOR_GB => $this->floorGb,
            ]);
        }

        return AtlasWatchdogCheckResult::ok($evidence);
    }
}
