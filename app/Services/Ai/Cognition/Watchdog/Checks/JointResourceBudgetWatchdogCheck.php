<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog\Checks;

use App\Services\Ai\AcosMax\AtlasResourceBudgetService;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;
use Carbon\CarbonImmutable;

/**
 * ELEV-27 — Watchdog: alerta quando um componente fura o próprio cap
 * (`over_ram_cap`) OU a soma declarada fura o piso do motor (`paper_overshoot`)
 * OU o uso REAL fura o teto do host (`measured_overshoot`).
 */
final readonly class JointResourceBudgetWatchdogCheck implements AtlasWatchdogCheck
{
    public const SCHEMA_VERSION = 'atlas.acos.joint_resource_budget_watchdog.v1';

    public const CHECK_ID = 'elev-27.joint_resource_budget';

    public function __construct(
        private AtlasResourceBudgetService $service,
        private ?CarbonImmutable $now = null,
    ) {}

    public function id(): string
    {
        return self::CHECK_ID;
    }

    public function run(): AtlasWatchdogCheckResult
    {
        $now = $this->now ?? CarbonImmutable::now('UTC');
        $report = $this->service->report();

        $evidence = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => $now->toIso8601String(),
            'host_ram_gib' => $report['host_ram_gib'],
            'engine_floor_gib' => $report['engine_floor_gib'],
            'total_ram_cap_mb' => $report['total_ram_cap_mb'],
            'paper_status' => $report['declared_paper_status'],
            'paper_headroom_mb' => $report['paper_headroom_mb'],
            'measured_ram_mb' => $report['measured_ram_mb'],
            'measured_headroom_mb' => $report['measured_headroom_mb'],
            'over_cap_components' => $report['over_cap_components'],
            'components' => $report['components'],
        ];

        $reasons = [];
        if ($report['declared_paper_status'] === 'paper_overshoot') {
            $reasons[] = 'paper_overshoot';
        }
        if ($report['over_cap_components'] !== []) {
            $reasons[] = 'component_over_ram_cap';
        }
        if (is_int($report['measured_headroom_mb']) && $report['measured_headroom_mb'] < 0) {
            $reasons[] = 'measured_overshoot';
        }

        if ($reasons !== []) {
            return AtlasWatchdogCheckResult::alert($evidence, [
                'code' => 'joint_resource_budget_breach',
                'reasons' => $reasons,
                'message' => 'Joint resource budget breached vs declared cap and/or host ceiling.',
            ]);
        }

        return AtlasWatchdogCheckResult::ok($evidence);
    }
}
