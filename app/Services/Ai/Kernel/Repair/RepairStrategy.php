<?php

namespace App\Services\Ai\Kernel\Repair;

enum RepairStrategy: string
{
    case RetryProvider = 'retry_provider';
    case RefreshContext = 'refresh_context';
    case CollectEvidence = 'collect_evidence';
    case RepairOutput = 'repair_output';
    case RerunTool = 'rerun_tool';
    case RerunHarness = 'rerun_harness';
    case HumanReview = 'human_review';
    case None = 'none';

    /**
     * @return array<int,string>
     */
    public static function values(): array
    {
        return array_map(
            fn (self $strategy): string => $strategy->value,
            self::cases(),
        );
    }
}
