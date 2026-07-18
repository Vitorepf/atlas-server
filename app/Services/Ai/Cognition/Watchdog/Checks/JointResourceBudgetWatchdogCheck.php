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
    public const FIELD_MEASURED_HEADROOM_MB = 'measured_headroom_mb';
    public const FIELD_OVER_CAP_COMPONENTS = 'over_cap_components';
    public const FIELD_HOST_RAM_GIB = 'host_ram_gib';
    public const FIELD_ENGINE_FLOOR_GIB = 'engine_floor_gib';
    public const FIELD_TOTAL_RAM_CAP_MB = 'total_ram_cap_mb';
    public const FIELD_PAPER_HEADROOM_MB = 'paper_headroom_mb';
    public const FIELD_COMPONENTS = 'components';
    public const FIELD_DECLARED_PAPER_STATUS = 'declared_paper_status';
    public const FIELD_MEASURED_RAM_MB = 'measured_ram_mb';
    public const FIELD_PAPER_STATUS = 'paper_status';
    public const FIELD_REASONS = 'reasons';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_MESSAGE = 'message';
    public const FIELD_CODE = 'code';
    public const FIELD_GENERATED_AT = 'generated_at';
    public const FIELD_PAPER_OVERSHOOT = 'paper_overshoot';
    public const FIELD_JOINT_RESOURCE_BUDGET_BREACH = 'joint_resource_budget_breach';

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
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_GENERATED_AT => $now->toIso8601String(),
            self::FIELD_HOST_RAM_GIB => $report[self::FIELD_HOST_RAM_GIB],
            self::FIELD_ENGINE_FLOOR_GIB => $report[self::FIELD_ENGINE_FLOOR_GIB],
            self::FIELD_TOTAL_RAM_CAP_MB => $report[self::FIELD_TOTAL_RAM_CAP_MB],
            self::FIELD_PAPER_STATUS => $report[self::FIELD_DECLARED_PAPER_STATUS],
            self::FIELD_PAPER_HEADROOM_MB => $report[self::FIELD_PAPER_HEADROOM_MB],
            self::FIELD_MEASURED_RAM_MB => $report[self::FIELD_MEASURED_RAM_MB],
            self::FIELD_MEASURED_HEADROOM_MB => $report[self::FIELD_MEASURED_HEADROOM_MB],
            self::FIELD_OVER_CAP_COMPONENTS => $report[self::FIELD_OVER_CAP_COMPONENTS],
            self::FIELD_COMPONENTS => $report[self::FIELD_COMPONENTS],
        ];

        $reasons = [];
        if ($report[self::FIELD_DECLARED_PAPER_STATUS] === self::FIELD_PAPER_OVERSHOOT) {
            $reasons[] = 'paper_overshoot';
        }
        if ($report[self::FIELD_OVER_CAP_COMPONENTS] !== []) {
            $reasons[] = 'component_over_ram_cap';
        }
        if (is_int($report[self::FIELD_MEASURED_HEADROOM_MB]) && $report[self::FIELD_MEASURED_HEADROOM_MB] < 0) {
            $reasons[] = 'measured_overshoot';
        }

        if ($reasons !== []) {
            return AtlasWatchdogCheckResult::alert($evidence, [
                self::FIELD_CODE => self::FIELD_JOINT_RESOURCE_BUDGET_BREACH,
                self::FIELD_REASONS => $reasons,
                self::FIELD_MESSAGE => 'Joint resource budget breached vs declared cap and/or host ceiling.',
            ]);
        }

        return AtlasWatchdogCheckResult::ok($evidence);
    }
}
