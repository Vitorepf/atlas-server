<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog\Checks;

use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;
use App\Services\Ai\LongHorizon\CompactionRecoverySampler;

final readonly class CompactionRecoverySampleWatchdogCheck implements AtlasWatchdogCheck
{
    public function __construct(private CompactionRecoverySampler $sampler) {}

    public function id(): string
    {
        return 'maxf-02.compaction_recovery_sample';
    }

    public function run(): AtlasWatchdogCheckResult
    {
        $payload = $this->sampler->sample(
            limit: (int) config('atlas.compaction.recovery_sample_watchdog_limit', 50),
            days: (int) config('atlas.compaction.recovery_sample_watchdog_days', 14),
            minimumSamples: (int) config('atlas.compaction.recovery_sample_min_receipts', 20),
            recordEvidence: false,
        );

        $status = (string) ($payload['status'] ?? 'unknown');
        if ($status === 'ok') {
            return AtlasWatchdogCheckResult::ok($payload);
        }

        if ($status === 'insufficient_sample') {
            return AtlasWatchdogCheckResult::skipped($payload);
        }

        return AtlasWatchdogCheckResult::alert($payload, [
            'code' => 'compaction_recovery_rate_below_floor',
            'message' => 'MAXF-02 recovery sample could not prove compaction fidelity.',
            'recovery_rate' => $payload['recovery_rate'] ?? null,
        ]);
    }
}
