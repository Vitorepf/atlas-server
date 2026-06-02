<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S87 — Builds the missing-work packets every AAEOS department still needs to
 * reach a computed maturity of L4 or higher.
 *
 * Pure planner: no I/O, no clock, no randomness. Every returned field is derived
 * from the `maturity` and `qualityBar` method inputs through real rules.
 *
 * Hard rules enforced here:
 *  - target level is always L4 (numeric 4);
 *  - a department is only treated as L4 when its level is computed from real
 *    evidence — prose claims (`maturity_prose`) never count;
 *  - a missing packet is only `executable` when it carries both a non-empty
 *    `allowed_files` list<string> and a non-empty `test_command`.
 */
final class AaeosDepartmentL4ClosurePlanner
{
    private const SCHEMA_VERSION = 'atlas.aaeos.department_l4_closure_plan.v1';

    private const TARGET_LEVEL = 'L4';

    private const TARGET_LEVEL_VALUE = 4;

    private const OWNER_DOC = 'docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md';

    /**
     * Canonical AAEOS departments (mirrors AtlasAaeosDepartmentRegistryService::CANONICAL_DEPARTMENTS).
     *
     * @var list<string>
     */
    private const CANONICAL_DEPARTMENTS = [
        'product', 'architect', 'research', 'dev', 'debug', 'review',
        'qa', 'security', 'forge', 'delivery', 'memory',
    ];

    /**
     * @var array<string,int>
     */
    private const LEVEL_VALUES = [
        'L0' => 0,
        'L1' => 1,
        'L2' => 2,
        'L3' => 3,
        'L4' => 4,
        'L5' => 5,
        'L6' => 6,
        'L7' => 7,
    ];

    /**
     * @param  array<string,mixed>  $maturity   per-department computed maturity inputs
     * @param  array<string,mixed>  $qualityBar per-department L4 quality-bar inputs
     * @return array{
     *     schema_version: string,
     *     target_level: string,
     *     target_level_value: int,
     *     owner_doc: string,
     *     departments: list<array{
     *         department_id: string,
     *         current_level: string,
     *         current_level_value: int,
     *         target_level: string,
     *         level_gap: int,
     *         marked_l4: bool,
     *         has_computed_evidence: bool,
     *         quality_bar_met: bool,
     *         needs_packet: bool
     *     }>,
     *     missing_packets: list<array{
     *         department_id: string,
     *         current_level: string,
     *         current_level_value: int,
     *         target_level: string,
     *         level_gap: int,
     *         allowed_files: list<string>,
     *         test_command: string,
     *         executable: bool,
     *         blockers: list<string>,
     *         evidence_refs: list<string>
     *     }>,
     *     marked_l4_departments: list<string>,
     *     departments_needing_packets: list<string>,
     *     all_departments_l4: bool,
     *     evidence_refs: list<string>
     * }
     */
    public function plan(array $maturity, array $qualityBar): array
    {
        $departments = [];
        $missingPackets = [];
        $markedL4 = [];
        $needingPackets = [];
        $planEvidenceRefs = [];

        foreach (self::CANONICAL_DEPARTMENTS as $departmentId) {
            $deptMaturity = $this->subArray($maturity, $departmentId);
            $deptQualityBar = $this->subArray($qualityBar, $departmentId);

            $currentValue = $this->levelValue($deptMaturity);
            $currentLevel = $this->levelLabel($currentValue);
            $levelGap = max(self::TARGET_LEVEL_VALUE - $currentValue, 0);

            $evidenceRefs = $this->evidenceRefs($deptMaturity);
            $hasComputedEvidence = $evidenceRefs !== [];
            $qualityBarMet = $this->qualityBarMet($deptQualityBar);

            // L4 is computed: it requires the level to be at/above L4 AND real
            // computed evidence backing it. Prose alone never marks L4.
            $isMarkedL4 = $currentValue >= self::TARGET_LEVEL_VALUE && $hasComputedEvidence;
            $needsPacket = ! $isMarkedL4;

            $departments[] = [
                'department_id' => $departmentId,
                'current_level' => $currentLevel,
                'current_level_value' => $currentValue,
                'target_level' => self::TARGET_LEVEL,
                'level_gap' => $levelGap,
                'marked_l4' => $isMarkedL4,
                'has_computed_evidence' => $hasComputedEvidence,
                'quality_bar_met' => $qualityBarMet,
                'needs_packet' => $needsPacket,
            ];

            if ($isMarkedL4) {
                $markedL4[] = $departmentId;

                foreach ($evidenceRefs as $ref) {
                    $planEvidenceRefs[] = $departmentId.':'.$ref;
                }

                continue;
            }

            $needingPackets[] = $departmentId;

            $allowedFiles = $this->allowedFiles($deptMaturity);
            $testCommand = $this->testCommand($deptMaturity);

            $blockers = $this->packetBlockers(
                $allowedFiles,
                $testCommand,
                $hasComputedEvidence,
                $qualityBarMet,
            );

            // A packet is executable only when it has both allowed_files and a
            // test command; missing either keeps it non-executable.
            $executable = $allowedFiles !== [] && $testCommand !== '';

            $missingPackets[] = [
                'department_id' => $departmentId,
                'current_level' => $currentLevel,
                'current_level_value' => $currentValue,
                'target_level' => self::TARGET_LEVEL,
                'level_gap' => $levelGap,
                'allowed_files' => $allowedFiles,
                'test_command' => $testCommand,
                'executable' => $executable,
                'blockers' => $blockers,
                'evidence_refs' => $evidenceRefs,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'target_level' => self::TARGET_LEVEL,
            'target_level_value' => self::TARGET_LEVEL_VALUE,
            'owner_doc' => self::OWNER_DOC,
            'departments' => $departments,
            'missing_packets' => $missingPackets,
            'marked_l4_departments' => $markedL4,
            'departments_needing_packets' => $needingPackets,
            'all_departments_l4' => $missingPackets === [],
            'evidence_refs' => $planEvidenceRefs,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function subArray(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string,mixed>  $deptMaturity
     */
    private function levelValue(array $deptMaturity): int
    {
        $raw = $deptMaturity['current_level'] ?? $deptMaturity['level'] ?? null;

        if (is_int($raw)) {
            return $this->clampLevel($raw);
        }

        if (is_string($raw)) {
            $normalized = strtoupper(trim($raw));

            if (array_key_exists($normalized, self::LEVEL_VALUES)) {
                return self::LEVEL_VALUES[$normalized];
            }

            if (is_numeric($normalized)) {
                return $this->clampLevel((int) $normalized);
            }
        }

        return 0;
    }

    private function clampLevel(int $value): int
    {
        return min(max($value, 0), 7);
    }

    private function levelLabel(int $value): string
    {
        return 'L'.$this->clampLevel($value);
    }

    /**
     * @param  array<string,mixed>  $deptMaturity
     * @return list<string>
     */
    private function evidenceRefs(array $deptMaturity): array
    {
        return $this->stringList($deptMaturity['evidence'] ?? $deptMaturity['evidence_refs'] ?? []);
    }

    /**
     * @param  array<string,mixed>  $deptMaturity
     * @return list<string>
     */
    private function allowedFiles(array $deptMaturity): array
    {
        return $this->stringList($deptMaturity['allowed_files'] ?? []);
    }

    /**
     * @param  array<string,mixed>  $deptMaturity
     */
    private function testCommand(array $deptMaturity): string
    {
        $raw = $deptMaturity['test_command'] ?? '';

        return is_string($raw) ? trim($raw) : '';
    }

    /**
     * @param  array<string,mixed>  $deptQualityBar
     */
    private function qualityBarMet(array $deptQualityBar): bool
    {
        if ($deptQualityBar === []) {
            return false;
        }

        foreach ($deptQualityBar as $metric => $satisfied) {
            if ($satisfied !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $allowedFiles
     * @return list<string>
     */
    private function packetBlockers(
        array $allowedFiles,
        string $testCommand,
        bool $hasComputedEvidence,
        bool $qualityBarMet,
    ): array {
        $blockers = [];

        if ($allowedFiles === []) {
            $blockers[] = 'allowed_files_missing';
        }

        if ($testCommand === '') {
            $blockers[] = 'test_command_missing';
        }

        if (! $hasComputedEvidence) {
            $blockers[] = 'computed_evidence_missing';
        }

        if (! $qualityBarMet) {
            $blockers[] = 'quality_bar_unmet';
        }

        return $blockers;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $trimmed = trim($item);

                if ($trimmed !== '') {
                    $result[] = $trimmed;
                }
            }
        }

        return array_values($result);
    }
}
