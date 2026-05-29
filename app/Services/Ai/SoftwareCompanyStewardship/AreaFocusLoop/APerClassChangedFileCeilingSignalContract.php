<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Minimal data contract for the per-class changed-file ceiling signal in
 * {@see StewardshipMergeAutonomyPolicyService}. Step 1 of 3: shape only — no
 * merge autonomy policy wiring in this class.
 */
final class APerClassChangedFileCeilingSignalContract
{
    public const SCHEMA = 'atlas.software_company_stewardship.per_class_changed_file_ceiling_signal.v1';

    public const AP774_CANONICAL = 'docs/ap/AP-774-stewardship-merge-autonomy-policy-contract.md';

    public const QUALITY_BAR_MATRIX_CANONICAL = 'docs/engineering-knowledge-base/atlas-aaeos-department-quality-bar-matrix.md';

    public const DECISION_SCHEMA = StewardshipMergeAutonomyPolicyService::DECISION_SCHEMA;

    public const SIGNAL_ID = 'per_class_changed_file_ceiling_exceeded';

    public const POLICY_REASON = 'changed_file_count_exceeds_policy';

    /** Matches {@see StewardshipMergeAutonomyPolicyService} default max_auto_merge_files input. */
    public const DEFAULT_MAX_AUTO_MERGE_FILES = 5;

    /** Factory-scoped AreaFocusLoop patches are expected to stay app+test paired. */
    public const FACTORY_SCOPED_CHANGED_FILE_CEILING = 2;

    public const MERGE_CLASS_DOCS_AND_TESTS = 'docs_and_tests';

    public const MERGE_CLASS_BUGFIX = 'bugfix';

    public const MERGE_CLASS_FACTORY_SCOPED_CODE_OR_MIXED = 'factory_scoped_code_or_mixed';

    public const MERGE_CLASS_BOUNDED_PACKET_CODE_OR_MIXED = 'bounded_packet_code_or_mixed';

    /** Step 1: contract shape only — policy service does not consume this signal yet. */
    public const INFORMATIONAL_SIGNAL_ONLY = true;

    /**
     * @param  list<string>  $changedFiles
     * @param  list<string>  $boundedPacketAllowedFiles
     */
    private function __construct(
        public readonly string $areaId,
        public readonly string $focus,
        public readonly string $mergeClass,
        public readonly array $changedFiles,
        public readonly int $maxAutoMergeFiles,
        public readonly array $boundedPacketAllowedFiles,
    ) {}

    public static function defaults(
        string $areaId = 'agentic_engineering_os',
        string $focus = 'dev_forge',
    ): self {
        return new self(
            areaId: $areaId,
            focus: $focus,
            mergeClass: self::MERGE_CLASS_DOCS_AND_TESTS,
            changedFiles: [],
            maxAutoMergeFiles: self::DEFAULT_MAX_AUTO_MERGE_FILES,
            boundedPacketAllowedFiles: [],
        );
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $changedFiles = array_values(array_filter(
            (array) ($input['changed_files'] ?? []),
            static fn (mixed $file): bool => is_string($file) && trim($file) !== '',
        ));
        $boundedPacketAllowedFiles = array_values(array_filter(
            (array) ($input['bounded_packet_allowed_files'] ?? []),
            static fn (mixed $file): bool => is_string($file) && trim($file) !== '',
        ));

        return new self(
            areaId: trim((string) ($input['area_id'] ?? 'agentic_engineering_os')),
            focus: trim((string) ($input['focus'] ?? 'dev_forge')),
            mergeClass: self::normalizeMergeClass((string) ($input['merge_class'] ?? self::MERGE_CLASS_DOCS_AND_TESTS)),
            changedFiles: $changedFiles,
            maxAutoMergeFiles: max(1, (int) ($input['max_auto_merge_files'] ?? self::DEFAULT_MAX_AUTO_MERGE_FILES)),
            boundedPacketAllowedFiles: $boundedPacketAllowedFiles,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $changedFileCount = count($this->changedFiles);
        $configuredCeiling = self::resolveConfiguredCeiling(
            $this->mergeClass,
            $this->maxAutoMergeFiles,
            $this->boundedPacketAllowedFiles,
        );
        $exceedsCeiling = $changedFileCount > $configuredCeiling;

        return [
            'schema_version' => self::SCHEMA,
            'signal_id' => self::SIGNAL_ID,
            'decision_schema' => self::DECISION_SCHEMA,
            'ap774_canonical' => self::AP774_CANONICAL,
            'quality_bar_matrix_canonical' => self::QUALITY_BAR_MATRIX_CANONICAL,
            'informational_signal_only' => self::INFORMATIONAL_SIGNAL_ONLY,
            'default_max_auto_merge_files' => self::DEFAULT_MAX_AUTO_MERGE_FILES,
            'factory_scoped_changed_file_ceiling' => self::FACTORY_SCOPED_CHANGED_FILE_CEILING,
            'area_id' => $this->areaId,
            'focus' => $this->focus,
            'inputs' => [
                'merge_class' => $this->mergeClass,
                'changed_files' => $this->changedFiles,
                'max_auto_merge_files' => $this->maxAutoMergeFiles,
                'bounded_packet_allowed_files' => $this->boundedPacketAllowedFiles,
            ],
            'outputs' => [
                'configured_per_class_changed_file_ceiling' => $configuredCeiling,
                'changed_file_count' => $changedFileCount,
                'exceeds_per_class_changed_file_ceiling' => $exceedsCeiling,
                'surfaces_scope_creep_before_merge' => $exceedsCeiling,
                'signal_id' => $exceedsCeiling ? self::SIGNAL_ID : null,
                'policy_reason' => $exceedsCeiling ? self::POLICY_REASON : null,
            ],
        ];
    }

    private static function normalizeMergeClass(string $mergeClass): string
    {
        $normalized = strtolower(trim($mergeClass));

        return match ($normalized) {
            self::MERGE_CLASS_FACTORY_SCOPED_CODE_OR_MIXED,
            self::MERGE_CLASS_BOUNDED_PACKET_CODE_OR_MIXED,
            self::MERGE_CLASS_BUGFIX,
            self::MERGE_CLASS_DOCS_AND_TESTS => $normalized,
            default => self::MERGE_CLASS_DOCS_AND_TESTS,
        };
    }

    /**
     * @param  list<string>  $boundedPacketAllowedFiles
     */
    private static function resolveConfiguredCeiling(
        string $mergeClass,
        int $maxAutoMergeFiles,
        array $boundedPacketAllowedFiles,
    ): int {
        if ($mergeClass === self::MERGE_CLASS_BOUNDED_PACKET_CODE_OR_MIXED) {
            return $boundedPacketAllowedFiles !== []
                ? count($boundedPacketAllowedFiles)
                : $maxAutoMergeFiles;
        }

        if ($mergeClass === self::MERGE_CLASS_FACTORY_SCOPED_CODE_OR_MIXED) {
            return self::FACTORY_SCOPED_CHANGED_FILE_CEILING;
        }

        return $maxAutoMergeFiles;
    }
}
