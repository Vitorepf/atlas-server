<?php

namespace App\Support;

class BehaviorLifecycle
{
    public const ACTIVE = 'active';

    public const BASELINE = 'baseline';

    public const PAUSED = 'paused';

    public const DORMANT = 'dormant';

    public const EXPERIMENT = 'experiment';

    public const MANUAL_ONLY = 'manual_only';

    public const STATUSES = [
        self::ACTIVE,
        self::BASELINE,
        self::PAUSED,
        self::DORMANT,
        self::EXPERIMENT,
        self::MANUAL_ONLY,
    ];

    public const PROMPTABLE = [
        self::ACTIVE,
        self::EXPERIMENT,
    ];

    public static function allowed(): array
    {
        return self::STATUSES;
    }

    public static function promptable(): array
    {
        return self::PROMPTABLE;
    }

    public static function canonicalize(?string $status): string
    {
        $status = trim((string) $status);

        return in_array($status, self::STATUSES, true) ? $status : self::ACTIVE;
    }

    public static function isPromptable(?string $status): bool
    {
        return in_array(self::canonicalize($status), self::PROMPTABLE, true);
    }
}
