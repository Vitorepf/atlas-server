<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * ELEV-27 — Orçamento conjunto de recursos da máquina.
 *
 * Read-only. Combina o cap DECLARADO por componente com o USO REAL (RSS/du)
 * medido por um probe injetável — nunca certifica pelo declarado sozinho.
 *
 * O probe é `callable(string $component): array{ram_mb:int|null, disk_mb:int|null}`.
 * Ausente → uso `null` (o WDG reporta `unmeasured`; ainda soma caps para checar
 * consistência do papel).
 */
final class AtlasResourceBudgetService
{
    public const SCHEMA = 'atlas.resource_budget.v1';

    public const BUDGET_CONFIG_KEY = 'atlas_resource_budget';

    public const DEFAULT_HOST_RAM_GIB = 48;

    public const DEFAULT_ENGINE_FLOOR_GIB = 12;
    public const FIELD_RAM_MB = 'ram_mb';
    public const FIELD_DISK_MB = 'disk_mb';
    public const FIELD_NAME = 'name';
    public const FIELD_PURPOSE = 'purpose';
    public const FIELD_RAM_CAP_MB = 'ram_cap_mb';
    public const FIELD_RAM_ACTUAL_MB = 'ram_actual_mb';
    public const FIELD_DISK_CAP_MB = 'disk_cap_mb';
    public const FIELD_STATUS = 'status';

    /** @var array<string,mixed> */
    private array $budget;

    /** @var callable|null */
    private $probe;

    /**
     * @param  array<string,mixed>|null  $budget
     * @param  callable|null  $probe  callable(string): array{ram_mb:int|null,disk_mb:int|null}
     */
    public function __construct(?array $budget = null, ?callable $probe = null)
    {
        $this->budget = $budget ?? AiValueNormalizer::arrayOrEmpty(config(self::BUDGET_CONFIG_KEY, []));
        $this->probe = $probe;
    }

    /**
     * @return array{
     *     schema_version:string,
     *     host_ram_gib:int,
     *     engine_floor_gib:int,
     *     total_ram_cap_mb:int,
     *     engine_floor_mb:int,
     *     host_ram_mb:int,
     *     declared_paper_status:string,
     *     paper_headroom_mb:int,
     *     measured_ram_mb:int|null,
     *     measured_headroom_mb:int|null,
     *     over_cap_components:list<string>,
     *     components:list<array<string,mixed>>
     * }
     */
    public function report(): array
    {
        $components = AiValueNormalizer::arrayOrEmpty($this->budget['components'] ?? null);
        $rows = [];
        $totalRamCap = 0;
        $measuredSum = 0;
        $anyMeasured = false;
        $overCap = [];

        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }
            $name = AiValueNormalizer::trimmedStringOrNull($component[self::FIELD_NAME] ?? null) ?? '';
            if ($name === '') {
                continue;
            }
            $ramCap = max(0, (int) (AiValueNormalizer::finiteFloatOrNull($component[self::FIELD_RAM_CAP_MB] ?? null) ?? 0));
            $diskCap = max(0, (int) (AiValueNormalizer::finiteFloatOrNull($component[self::FIELD_DISK_CAP_MB] ?? null) ?? 0));
            $totalRamCap += $ramCap;

            $measured = $this->probeComponent($name);
            $ramActual = $measured[self::FIELD_RAM_MB] ?? null;
            $diskActual = $measured[self::FIELD_DISK_MB] ?? null;

            $componentStatus = 'unmeasured';
            if (is_int($ramActual)) {
                $anyMeasured = true;
                $measuredSum += $ramActual;
                $componentStatus = $ramActual > $ramCap ? 'over_ram_cap' : 'within_ram_cap';
                if ($componentStatus === 'over_ram_cap') {
                    $overCap[] = $name;
                }
            }

            $rows[] = [
                self::FIELD_NAME => $name,
                self::FIELD_PURPOSE => AiValueNormalizer::trimmedStringOrNull($component[self::FIELD_PURPOSE] ?? null) ?? '',
                self::FIELD_RAM_CAP_MB => $ramCap,
                self::FIELD_RAM_ACTUAL_MB => $ramActual,
                self::FIELD_DISK_CAP_MB => $diskCap,
                'disk_actual_mb' => $diskActual,
                'cpu_share' => AiValueNormalizer::trimmedStringOrNull($component['cpu_share'] ?? null) ?? 'shared',
                self::FIELD_STATUS => $componentStatus,
                'probe_hint' => AiValueNormalizer::trimmedStringOrNull($component['probe_hint'] ?? null) ?? '',
            ];
        }

        $hostGib = max(1, (int) (AiValueNormalizer::finiteFloatOrNull($this->budget['host_ram_gib'] ?? null) ?? self::DEFAULT_HOST_RAM_GIB));
        $engineFloorGib = max(0, (int) (AiValueNormalizer::finiteFloatOrNull($this->budget['engine_floor_gib'] ?? null) ?? self::DEFAULT_ENGINE_FLOOR_GIB));
        $hostMb = $hostGib * 1024;
        $engineFloorMb = $engineFloorGib * 1024;

        $paperSum = $totalRamCap + $engineFloorMb;
        $paperStatus = $paperSum <= $hostMb ? 'paper_fits' : 'paper_overshoot';
        $paperHeadroom = $hostMb - $paperSum;

        $measuredHeadroom = null;
        if ($anyMeasured) {
            $measuredHeadroom = $hostMb - $measuredSum - $engineFloorMb;
        }

        return [
            'schema_version' => AiValueNormalizer::trimmedStringOrNull($this->budget['schema_version'] ?? null) ?? self::SCHEMA,
            'host_ram_gib' => $hostGib,
            'engine_floor_gib' => $engineFloorGib,
            'total_ram_cap_mb' => $totalRamCap,
            'engine_floor_mb' => $engineFloorMb,
            'host_ram_mb' => $hostMb,
            'declared_paper_status' => $paperStatus,
            'paper_headroom_mb' => $paperHeadroom,
            'measured_ram_mb' => $anyMeasured ? $measuredSum : null,
            'measured_headroom_mb' => $measuredHeadroom,
            'over_cap_components' => array_values(array_unique($overCap)),
            'components' => $rows,
        ];
    }

    /**
     * @return array{ram_mb:int|null,disk_mb:int|null}
     */
    private function probeComponent(string $name): array
    {
        if ($this->probe === null) {
            return [self::FIELD_RAM_MB => null, self::FIELD_DISK_MB => null];
        }
        $probed = ($this->probe)($name);
        if (! is_array($probed)) {
            return [self::FIELD_RAM_MB => null, self::FIELD_DISK_MB => null];
        }
        $ram = $probed[self::FIELD_RAM_MB] ?? null;
        $disk = $probed[self::FIELD_DISK_MB] ?? null;

        return [
            self::FIELD_RAM_MB => ($ramFloat = AiValueNormalizer::finiteFloatOrNull($ram)) === null ? null : (int) $ramFloat,
            self::FIELD_DISK_MB => ($diskFloat = AiValueNormalizer::finiteFloatOrNull($disk)) === null ? null : (int) $diskFloat,
        ];
    }
}
