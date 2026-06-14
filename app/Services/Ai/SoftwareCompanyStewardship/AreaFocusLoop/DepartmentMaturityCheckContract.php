<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Minimal data contract for the department maturity check in
 * {@see LoopPreflightCycleFirewallService}. Step 1 of 3: shape only — no
 * firewall service wiring in this class.
 */
final class DepartmentMaturityCheckContract
{
    public const SCHEMA = 'atlas.software_company_stewardship.department_maturity_check.v1';

    public const MATURITY_MATRIX_CANONICAL = 'docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md';

    public const CHECK_ID = 'department_maturity_check';

    public const MATURITY_REPORT_SCHEMA = 'atlas.aaeos.department_maturity.v1';

    /** R3+ routing requires each required department at or above this level. */
    public const MIN_ROUTING_LEVEL = 'L2';

    public const MIN_ROUTING_LEVEL_NUMERIC = 2;

    public const ROUTING_TIER_R3_PLUS = 'R3+';

    public const BLOCKER_REQUIRED_DEPARTMENT_BELOW_L2 = 'required_department_below_l2';

    /**
     * @param  list<string>  $requiredDepartmentIds
     * @param  array<string,string>  $departmentMaturitySnapshot
     */
    private function __construct(
        public readonly string $areaId,
        public readonly string $focus,
        public readonly string $routingTier,
        public readonly array $requiredDepartmentIds,
        public readonly array $departmentMaturitySnapshot,
    ) {}

    public static function defaults(
        string $areaId = 'agentic_engineering_os',
        string $focus = 'dev_forge',
    ): self {
        return new self(
            areaId: $areaId,
            focus: $focus,
            routingTier: self::ROUTING_TIER_R3_PLUS,
            requiredDepartmentIds: ['dev'],
            departmentMaturitySnapshot: ['dev' => self::MIN_ROUTING_LEVEL],
        );
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $requiredDepartmentIds = array_values(array_unique(array_map(
            static fn (string $id): string => strtolower(trim($id)),
            array_filter(
                (array) ($input['required_department_ids'] ?? ['dev']),
                static fn (mixed $id): bool => is_string($id) && trim($id) !== '',
            ),
        )));

        $snapshot = [];
        foreach ((array) ($input['department_maturity_snapshot'] ?? ['dev' => self::MIN_ROUTING_LEVEL]) as $departmentId => $level) {
            if (! is_string($departmentId) || ! is_string($level)) {
                continue;
            }

            $snapshot[strtolower(trim($departmentId))] = strtoupper(trim($level));
        }

        return new self(
            areaId: trim((string) ($input['area_id'] ?? 'agentic_engineering_os')),
            focus: trim((string) ($input['focus'] ?? 'dev_forge')),
            routingTier: strtoupper(trim((string) ($input['routing_tier'] ?? self::ROUTING_TIER_R3_PLUS))),
            requiredDepartmentIds: $requiredDepartmentIds,
            departmentMaturitySnapshot: $snapshot,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $immatureDepartmentIds = [];
        if ($this->requiresMaturityFloor()) {
            foreach ($this->requiredDepartmentIds as $departmentId) {
                $level = $this->departmentMaturitySnapshot[$departmentId] ?? 'L0';
                if ($this->levelNumeric($level) < self::MIN_ROUTING_LEVEL_NUMERIC) {
                    $immatureDepartmentIds[] = $departmentId;
                }
            }
        }

        $blocksPreflightCycle = $immatureDepartmentIds !== [];

        return [
            'schema_version' => self::SCHEMA,
            'check_id' => self::CHECK_ID,
            'maturity_report_schema' => self::MATURITY_REPORT_SCHEMA,
            'maturity_matrix_canonical' => self::MATURITY_MATRIX_CANONICAL,
            'min_routing_level' => self::MIN_ROUTING_LEVEL,
            'routing_tier_r3_plus' => self::ROUTING_TIER_R3_PLUS,
            'area_id' => $this->areaId,
            'focus' => $this->focus,
            'inputs' => [
                'routing_tier' => $this->routingTier,
                'required_department_ids' => $this->requiredDepartmentIds,
                'department_maturity_snapshot' => $this->departmentMaturitySnapshot,
            ],
            'outputs' => [
                'blocks_preflight_cycle' => $blocksPreflightCycle,
                'blocker_ids' => $blocksPreflightCycle ? [self::BLOCKER_REQUIRED_DEPARTMENT_BELOW_L2] : [],
                'immature_department_ids' => $immatureDepartmentIds,
            ],
        ];
    }

    private function requiresMaturityFloor(): bool
    {
        if ($this->routingTier === self::ROUTING_TIER_R3_PLUS) {
            return true;
        }

        if (preg_match('/^R(\d+)/', $this->routingTier, $matches) === 1) {
            return (int) $matches[1] >= 3;
        }

        return false;
    }

    private function levelNumeric(string $level): int
    {
        if (preg_match('/^L(\d+)$/', strtoupper(trim($level)), $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }
}
