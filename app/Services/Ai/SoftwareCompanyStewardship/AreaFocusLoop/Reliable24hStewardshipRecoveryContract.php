<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * AP-790 · minimal data contract for 24h stewardship recovery until consecutive
 * merged cycles are normal.
 */
final class Reliable24hStewardshipRecoveryContract
{
    public const SCHEMA = 'atlas.software_company_stewardship.ap790_24h_stewardship_recovery.v1';

    public const DEFAULT_TARGET_CONSECUTIVE_MERGES = 2;

    /** Only ledger rows matching this pair count toward recovery merges. */
    public const MERGE_ELIGIBILITY = [
        'ledger_outcome' => 'merged',
        'merge_performed' => true,
    ];

    private function __construct(
        public readonly string $areaId,
        public readonly string $focus,
        public readonly int $lastCycleIndex,
        public readonly int $mergesTotal,
        public readonly int $blockedInRow,
        public readonly int $consecutiveMergedCycles,
        public readonly int $targetConsecutiveMergedCycles,
    ) {}

    public static function defaults(
        string $areaId = 'agentic_engineering_os',
        string $focus = 'dev_forge',
    ): self {
        return new self(
            areaId: $areaId,
            focus: $focus,
            lastCycleIndex: 0,
            mergesTotal: 0,
            blockedInRow: 0,
            consecutiveMergedCycles: 0,
            targetConsecutiveMergedCycles: self::DEFAULT_TARGET_CONSECUTIVE_MERGES,
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
            lastCycleIndex: max(0, (int) ($input['last_cycle_index'] ?? 0)),
            mergesTotal: max(0, (int) ($input['merges_total'] ?? 0)),
            blockedInRow: max(0, (int) ($input['blocked_in_row'] ?? 0)),
            consecutiveMergedCycles: max(0, (int) ($input['consecutive_merged_cycles'] ?? 0)),
            targetConsecutiveMergedCycles: max(
                1,
                (int) ($input['target_consecutive_merged_cycles'] ?? self::DEFAULT_TARGET_CONSECUTIVE_MERGES),
            ),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'area_id' => $this->areaId,
            'focus' => $this->focus,
            'inputs' => [
                'last_cycle_index' => $this->lastCycleIndex,
                'merges_total' => $this->mergesTotal,
                'blocked_in_row' => $this->blockedInRow,
                'consecutive_merged_cycles' => $this->consecutiveMergedCycles,
                'target_consecutive_merged_cycles' => $this->targetConsecutiveMergedCycles,
            ],
            'merge_eligibility' => self::MERGE_ELIGIBILITY,
            'outputs' => [
                'recovery_normal' => $this->consecutiveMergedCycles >= $this->targetConsecutiveMergedCycles
                    && $this->blockedInRow === 0,
                'consecutive_merged_cycles' => $this->consecutiveMergedCycles,
                'blocked_in_row' => $this->blockedInRow,
            ],
        ];
    }
}
