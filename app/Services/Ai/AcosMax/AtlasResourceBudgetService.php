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
        $this->budget = $budget ?? (array) config('atlas_resource_budget', []);
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
        $components = (array) ($this->budget['components'] ?? []);
        $rows = [];
        $totalRamCap = 0;
        $measuredSum = 0;
        $anyMeasured = false;
        $overCap = [];

        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }
            $name = AiValueNormalizer::trimmedString($component['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $ramCap = max(0, (int) ($component['ram_cap_mb'] ?? 0));
            $diskCap = max(0, (int) ($component['disk_cap_mb'] ?? 0));
            $totalRamCap += $ramCap;

            $measured = $this->probeComponent($name);
            $ramActual = $measured['ram_mb'] ?? null;
            $diskActual = $measured['disk_mb'] ?? null;

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
                'name' => $name,
                'purpose' => AiValueNormalizer::trimmedString($component['purpose'] ?? ''),
                'ram_cap_mb' => $ramCap,
                'ram_actual_mb' => $ramActual,
                'disk_cap_mb' => $diskCap,
                'disk_actual_mb' => $diskActual,
                'cpu_share' => AiValueNormalizer::trimmedString($component['cpu_share'] ?? 'shared') ?: 'shared',
                'status' => $componentStatus,
                'probe_hint' => AiValueNormalizer::trimmedString($component['probe_hint'] ?? ''),
            ];
        }

        $hostGib = max(1, (int) ($this->budget['host_ram_gib'] ?? 48));
        $engineFloorGib = max(0, (int) ($this->budget['engine_floor_gib'] ?? 12));
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
            'schema_version' => (string) ($this->budget['schema_version'] ?? 'atlas.resource_budget.v1'),
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
            return ['ram_mb' => null, 'disk_mb' => null];
        }
        $probed = ($this->probe)($name);
        if (! is_array($probed)) {
            return ['ram_mb' => null, 'disk_mb' => null];
        }
        $ram = $probed['ram_mb'] ?? null;
        $disk = $probed['disk_mb'] ?? null;

        return [
            'ram_mb' => is_int($ram) ? $ram : (is_numeric($ram) ? (int) $ram : null),
            'disk_mb' => is_int($disk) ? $disk : (is_numeric($disk) ? (int) $disk : null),
        ];
    }
}
