<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Support\Elevations;

/**
 * The tri-state mode governing each Atlas Dev verification elevation (E1-E6).
 *
 *   OFF      => the elevation is a no-op. It surfaces nothing: no honesty
 *               flag, no block. The pipeline is byte-identical to the
 *               pre-mission baseline.
 *   ADVISORY => the elevation surfaces ONLY via an honesty flag. The
 *               CompletionStateGate auto-downgrades PASSED -> needs_review
 *               when a flag is present (the CompletionDecision ctor forbids
 *               passed+flags, so the flag can never coexist with a green
 *               completion). Advisory never produces STATUS_FAILED (gate) or
 *               STATUS_ESCALATE (critic) for the flag alone.
 *   HARD     => the elevation blocks via a sanctioned channel: STATUS_FAILED
 *               (gate) or STATUS_ESCALATE (critic). Hard never settles for an
 *               honesty flag.
 *
 * This is the safe default for landed code: an unknown/missing/invalid config
 * value resolves to ADVISORY (never silently OFF, never accidentally HARD),
 * so a misconfigured flag can neither silently disable an elevation nor
 * hard-block the pipeline.
 */
enum ElevationMode: string
{
    case OFF = 'off';
    case ADVISORY = 'advisory';
    case HARD = 'hard';

    /**
     * The documented safe default for landed code.
     */
    public const SAFE_DEFAULT = self::ADVISORY;

    /**
     * Resolve an arbitrary raw config value to a valid mode. Any value that is
     * not exactly off/advisory/hard (case-sensitive) collapses to the safe
     * default (advisory), so an invalid/missing flag can never crash the
     * pipeline nor silently disable an elevation.
     *
     * @param  mixed  $raw  the raw value read from config('atlas_dev.elevations.<eN>.mode')
     */
    public static function resolve(mixed $raw): self
    {
        if (! is_string($raw)) {
            return self::SAFE_DEFAULT;
        }

        $mode = self::tryFrom($raw);

        return $mode ?? self::SAFE_DEFAULT;
    }
}
