<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control;

/**
 * Admission outcomes for human-out-of-loop era.
 */
final class AaeosAdmissionVerdict
{
    /** Run without waiting on a human. */
    public const AUTO = 'auto';

    /** Run, but surface a non-blocking notify. */
    public const AUTO_NOTIFY = 'auto_notify';

    /** Stop for sovereign decision (irreversible / legal / ambiguous objective). */
    public const HALT_SOVEREIGN = 'halt_sovereign';

    /** @var list<string> */
    public const ALL = [
        self::AUTO,
        self::AUTO_NOTIFY,
        self::HALT_SOVEREIGN,
    ];

    public static function isValid(string $verdict): bool
    {
        return in_array($verdict, self::ALL, true);
    }

    public static function allowsExecution(string $verdict): bool
    {
        return $verdict === self::AUTO || $verdict === self::AUTO_NOTIFY;
    }
}
