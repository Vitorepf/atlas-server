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

    public const LIMIT_CONFIG_KEY = 'atlas.compaction.recovery_sample_watchdog_limit';

    public const DAYS_CONFIG_KEY = 'atlas.compaction.recovery_sample_watchdog_days';

    public const MIN_RECEIPTS_CONFIG_KEY = 'atlas.compaction.recovery_sample_min_receipts';

    public const STATUS_OK = 'ok';

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_INSUFFICIENT_SAMPLE = 'insufficient_sample';

    public function __construct(private CompactionRecoverySampler $sampler) {}

    public function id(): string
    {
        return self::CHECK_ID;
    }

    public function run(): AtlasWatchdogCheckResult
    {
        $payload = $this->sampler->sample(
            limit: (int) (AiValueNormalizer::finiteFloatOrNull(config(self::LIMIT_CONFIG_KEY, self::DEFAULT_LIMIT)) ?? self::DEFAULT_LIMIT),
            days: (int) (AiValueNormalizer::finiteFloatOrNull(config(self::DAYS_CONFIG_KEY, self::DEFAULT_DAYS)) ?? self::DEFAULT_DAYS),
            minimumSamples: (int) (AiValueNormalizer::finiteFloatOrNull(config(self::MIN_RECEIPTS_CONFIG_KEY, self::DEFAULT_MIN_RECEIPTS)) ?? self::DEFAULT_MIN_RECEIPTS),
            recordEvidence: false,
        );

        $status = AiValueNormalizer::trimmedStringOrNull($payload['status'] ?? null) ?? self::STATUS_UNKNOWN;
        if ($status === self::STATUS_OK) {
            return AtlasWatchdogCheckResult::ok($payload);
        }

        if ($status === self::STATUS_INSUFFICIENT_SAMPLE) {
            return AtlasWatchdogCheckResult::skipped($payload);
        }

        return AtlasWatchdogCheckResult::alert($payload, [
            'code' => 'compaction_recovery_rate_below_floor',
            'message' => 'MAXF-02 recovery sample could not prove compaction fidelity.',
            'recovery_rate' => $payload['recovery_rate'] ?? null,
        ]);
    }
}
