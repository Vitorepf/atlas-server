<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Spec;

/**
 * Engineering Kernel value: the state of the cross-family shadow-spec advisor (Tier 2).
 *
 * Owns: naming whether an independent re-derivation of intent DIVERGED, AGREED, was UNAVAILABLE
 * (no reachable 2nd provider family — the operator's routine reality), or was NOT_REQUIRED (the
 * deterministic floor already discriminated, no ambiguity heuristic fired).
 * Must never own: freeze authority. Divergence is one-directional — it can only raise CONTEST,
 * never grant freeze credit. AGREED grants ZERO credit (anti correlated-hallucination). UNAVAILABLE
 * is a first-class HOLD, never a silent fail-open.
 */
enum DivergenceStatus: string
{
    case Diverged = 'diverged';
    case Agreed = 'agreed';
    case Unavailable = 'unavailable';
    case NotRequired = 'not_required';
}
