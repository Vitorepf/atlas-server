<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog\Checks;

use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;
use App\Services\Ai\LongHorizon\CompactionRecoverySampler;
use App\Services\Ai\Support\AiValueNormalizer;

final readonly class CompactionRecoverySampleWatchdogCheck implements AtlasWatchdogCheck
{
    public const CHECK_ID = 'maxf-02.compaction_recovery_sample';

    public const DEFAULT_LIMIT = 50;

    public const DEFAULT_DAYS = 14;

    public const DEFAULT_MIN_RECEIPTS = 20;

    public function __construct(private CompactionRecoverySampler $sampler) {}

    public function id(): string
    {
        return self::CHECK_ID;
    }

    public function run(): AtlasWatchdogCheckResult
    {
        $payload = $this->sampler->sample(
            limit: (int) config('atlas.compaction.recovery_sample_watchdog_limit', self::DEFAULT_LIMIT),
            days: (int) config('atlas.compaction.recovery_sample_watchdog_days', self::DEFAULT_DAYS),
            minimumSamples: (int) config('atlas.compaction.recovery_sample_min_receipts', self::DEFAULT_MIN_RECEIPTS),
            recordEvidence: false,
        );

        $status = AiValueNormalizer::trimmedStringOrNull($payload['status'] ?? null) ?? 'unknown';
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
