<?php

namespace App\Services\Ai\ControlPlane;

/**
 * Canonical component statuses returned per-runtime by the Atlas Control Plane.
 * Used by every per-runtime service when reporting its own readiness/snapshot.
 */
final class AtlasControlPlaneStatus
{
    /** Runtime tables/models/services not yet present in the workspace. */
    public const MISSING = 'missing';

    /** Runtime is wired and responding normally. */
    public const READY = 'ready';

    /** Runtime is partially wired (some tables present, others missing). */
    public const DEGRADED = 'degraded';

    /** Runtime is wired but reporting blockers/violations that prevent normal use. */
    public const BLOCKED = 'blocked';

    public const ALLOWED = [
        self::MISSING,
        self::READY,
        self::DEGRADED,
        self::BLOCKED,
    ];

    /**
     * Reduce a set of component statuses to a single overall status using the
     * worst-case rule: blocked > degraded > missing > ready.
     *
     * @param  array<int,string>  $statuses
     */
    public static function reduce(array $statuses): string
    {
        if (in_array(self::BLOCKED, $statuses, true)) {
            return self::BLOCKED;
        }
        if (in_array(self::DEGRADED, $statuses, true)) {
            return self::DEGRADED;
        }
        if (in_array(self::MISSING, $statuses, true)) {
            return self::DEGRADED;
        }

        return self::READY;
    }
}
