<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Minimal data contract for the zero-downtime gate in
 * {@see AreaFocusDevForgeReleaseService}. Step 1 of 3: shape only — no
 * release service wiring in this class.
 */
final class ZeroDowntimeGateContract
{
    public const SCHEMA = 'atlas.software_company_stewardship.zero_downtime_gate.v1';

    public const MATURITY_MATRIX_CANONICAL = 'docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md';

    public const GATE_ID = 'zero_downtime_gate';

    public const INVARIANT_NO_MIGRATION_WITHOUT_ROLLBACK = 'no_migration_without_rollback';

    public const INVARIANT_NO_BREAKING_SCHEMA_CHANGE_WITHOUT_FEATURE_FLAG = 'no_breaking_schema_change_without_feature_flag';

    /** Delivery L3 floor: any migration without rollback violates zero-downtime. */
    public const MAX_ALLOWED_MIGRATIONS_WITHOUT_ROLLBACK = 0;

    /** Delivery L3 floor: any breaking schema change without feature flag violates zero-downtime. */
    public const MAX_ALLOWED_BREAKING_SCHEMA_CHANGES_WITHOUT_FEATURE_FLAG = 0;

    private function __construct(
        public readonly string $areaId,
        public readonly string $focus,
        public readonly string $departmentId,
        public readonly int $migrationsWithoutRollbackCount,
        public readonly int $breakingSchemaChangesWithoutFeatureFlagCount,
    ) {}

    public static function defaults(
        string $areaId = 'agentic_engineering_os',
        string $focus = 'dev_forge',
    ): self {
        return new self(
            areaId: $areaId,
            focus: $focus,
            departmentId: 'delivery',
            migrationsWithoutRollbackCount: 0,
            breakingSchemaChangesWithoutFeatureFlagCount: 0,
        );
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            areaId: trim((string) ($input['area_id'] ?? 'agentic_engineering_os')),
            focus: trim((string) ($input['focus'] ?? 'dev_forge')),
            departmentId: trim((string) ($input['department_id'] ?? 'delivery')),
            migrationsWithoutRollbackCount: max(0, (int) ($input['migrations_without_rollback_count'] ?? 0)),
            breakingSchemaChangesWithoutFeatureFlagCount: max(
                0,
                (int) ($input['breaking_schema_changes_without_feature_flag_count'] ?? 0),
            ),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $migrationViolation = $this->migrationsWithoutRollbackCount > self::MAX_ALLOWED_MIGRATIONS_WITHOUT_ROLLBACK;
        $breakingChangeViolation = $this->breakingSchemaChangesWithoutFeatureFlagCount
            > self::MAX_ALLOWED_BREAKING_SCHEMA_CHANGES_WITHOUT_FEATURE_FLAG;
        $blocksRelease = $migrationViolation || $breakingChangeViolation;

        $blockerIds = [];
        if ($migrationViolation) {
            $blockerIds[] = self::INVARIANT_NO_MIGRATION_WITHOUT_ROLLBACK;
        }
        if ($breakingChangeViolation) {
            $blockerIds[] = self::INVARIANT_NO_BREAKING_SCHEMA_CHANGE_WITHOUT_FEATURE_FLAG;
        }

        return [
            'schema_version' => self::SCHEMA,
            'gate_id' => self::GATE_ID,
            'maturity_matrix_canonical' => self::MATURITY_MATRIX_CANONICAL,
            'invariants' => [
                self::INVARIANT_NO_MIGRATION_WITHOUT_ROLLBACK,
                self::INVARIANT_NO_BREAKING_SCHEMA_CHANGE_WITHOUT_FEATURE_FLAG,
            ],
            'max_allowed_migrations_without_rollback' => self::MAX_ALLOWED_MIGRATIONS_WITHOUT_ROLLBACK,
            'max_allowed_breaking_schema_changes_without_feature_flag' => self::MAX_ALLOWED_BREAKING_SCHEMA_CHANGES_WITHOUT_FEATURE_FLAG,
            'area_id' => $this->areaId,
            'focus' => $this->focus,
            'inputs' => [
                'department_id' => $this->departmentId,
                'migrations_without_rollback_count' => $this->migrationsWithoutRollbackCount,
                'breaking_schema_changes_without_feature_flag_count' => $this->breakingSchemaChangesWithoutFeatureFlagCount,
            ],
            'outputs' => [
                'satisfies_zero_downtime_invariant' => ! $blocksRelease,
                'blocks_dev_forge_release' => $blocksRelease,
                'blocker_ids' => $blockerIds,
            ],
        ];
    }
}
