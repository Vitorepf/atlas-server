<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

final class AreaFocusScopeProfileNormalizer
{
    public const BALANCED = 'balanced';

    public const FACTORY_MAX = 'factory_max';

    public static function normalize(mixed $value): string
    {
        return strtolower(trim((string) $value)) === self::FACTORY_MAX
            ? self::FACTORY_MAX
            : self::BALANCED;
    }
}
