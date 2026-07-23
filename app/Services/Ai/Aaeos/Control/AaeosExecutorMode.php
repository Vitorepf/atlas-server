<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control;

/**
 * Canonical executor modes under AAEOS (elite same-bar L0–L5).
 *
 * @see docs/engineering-knowledge-base/atlas-elite-executors-dev-forge-autonomos.md
 */
final class AaeosExecutorMode
{
    public const DEV = 'dev';

    public const FORGE = 'forge';

    public const AUTONOMOS = 'autonomos';

    /** @var list<string> */
    public const ALL = [
        self::DEV,
        self::FORGE,
        self::AUTONOMOS,
    ];

    public static function isValid(string $mode): bool
    {
        return in_array($mode, self::ALL, true);
    }
}
