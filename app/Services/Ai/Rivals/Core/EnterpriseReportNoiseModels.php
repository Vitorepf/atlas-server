<?php

declare(strict_types=1);

namespace App\Services\Ai\Rivals\Core;

/** Model IDs treated as harness noise in enterprise report dashboards. */
final class EnterpriseReportNoiseModels
{
    /** @var list<string> */
    public const IDS = ['local_fake_model', 'mockllm', 'harness_null', 'harness_golden'];

    public static function isNoise(string $modelId): bool
    {
        return in_array($modelId, self::IDS, true);
    }
}
