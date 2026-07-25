<?php

declare(strict_types=1);

namespace App\Services\Ai\OperatorIntelligence\Support;

use Illuminate\Support\Str;

/**
 * Pure profile-key / automation / validity mapping for operator profile registry (full-pass peel).
 */
final class OperatorProfileRegistrySupport
{
    public static function profileKey(string $taxonomyItemId, ?string $explicitKey = null): string
    {
        $key = is_string($explicitKey) ? trim($explicitKey) : '';
        if ($key !== '') {
            return Str::limit($key, 160, '');
        }

        return strtolower(str_replace('-', '_', $taxonomyItemId)).'.operator_profile';
    }

    /**
     * @param  list<string>  $allowedLevels
     */
    public static function automationLevel(
        ?string $explicitLevel,
        bool $autoApplyEligible,
        bool $autoApplyEnabled,
        string $defaultLevel,
        array $allowedLevels,
    ): string {
        if ($explicitLevel !== null && in_array($explicitLevel, $allowedLevels, true)) {
            return $explicitLevel;
        }

        if ($autoApplyEligible && $autoApplyEnabled) {
            return 'auto_apply_reversible';
        }

        return $defaultLevel;
    }

    /**
     * Map validity hint → [validity_kind, hours_until_expiry|null].
     * Caller applies wall-clock from hours; null hours = no auto-expiry.
     *
     * @return array{0:string,1:int|null}
     */
    public static function validityFromHint(string $hint): array
    {
        return match ($hint) {
            'momentary' => ['temporary', 1],
            'scoped' => ['session', 8],
            default => ['permanent', null],
        };
    }
}
