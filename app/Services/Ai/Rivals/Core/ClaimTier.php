<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Adapters\AtlasBenchSuiteAdapter;
use App\Services\Ai\Rivals\Adapters\EliteRealitySuiteAdapter;
use App\Services\Ai\Rivals\Adapters\LocalFakeSuiteAdapter;
use InvalidArgumentException;

/**
 * Claim tier is an input contract, never an inference made after seeing scores.
 *
 * pipeline_valid is orthogonal to this tier: harness runs may be perfectly
 * reproducible while remaining permanently ineligible for market claims.
 */
final class ClaimTier
{
    public const HARNESS = 'harness';

    public const DIAGNOSTIC = 'diagnostic';

    public const PRODUCTION = 'production';

    public const PUBLIC = 'public';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::HARNESS,
            self::DIAGNOSTIC,
            self::PRODUCTION,
            self::PUBLIC,
        ];
    }

    public static function assert(string $tier): string
    {
        if (! in_array($tier, self::all(), true)) {
            throw new InvalidArgumentException("rivals_invalid_claim_tier:{$tier}");
        }

        return $tier;
    }

    /** @param array<int, array<string, mixed>> $arms */
    public static function forPlan(string $suiteId, array $arms, ?string $requested = null): string
    {
        if ($requested !== null) {
            $requested = self::assert($requested);
        }

        $models = new ModelRegistry;
        $hasHarnessArm = collect($arms)->contains(
            fn (array $arm): bool => $models->isHarnessOnly((string) ($arm['model_id'] ?? ''))
        );

        $intrinsic = match (true) {
            $suiteId === LocalFakeSuiteAdapter::SUITE_ID => self::HARNESS,
            in_array($suiteId, [
                AtlasBenchSuiteAdapter::SUITE_ID,
                EliteRealitySuiteAdapter::SUITE_ID,
            ], true) && $hasHarnessArm => self::DIAGNOSTIC,
            $hasHarnessArm => self::HARNESS,
            default => self::PRODUCTION,
        };

        if ($requested === null) {
            return $intrinsic;
        }

        if (in_array($intrinsic, [self::HARNESS, self::DIAGNOSTIC], true)
            && in_array($requested, [self::PRODUCTION, self::PUBLIC], true)) {
            throw new InvalidArgumentException(
                "rivals_claim_tier_escalation_forbidden:{$intrinsic}->{$requested}"
            );
        }

        return $requested;
    }

    /** @param array<string, mixed> $receipt */
    public static function forLegacyReceipt(array $receipt): string
    {
        if (($receipt['harness_only'] ?? false) === true) {
            return self::HARNESS;
        }

        $modelId = explode('@', (string) ($receipt['arm_id'] ?? ''), 2)[0];

        return (new ModelRegistry)->isHarnessOnly($modelId)
            ? self::HARNESS
            : self::PRODUCTION;
    }

    public static function permitsInternalClaim(string $tier): bool
    {
        return in_array($tier, [self::PRODUCTION, self::PUBLIC], true);
    }

    public static function permitsPublicClaim(string $tier): bool
    {
        return $tier === self::PUBLIC;
    }
}
