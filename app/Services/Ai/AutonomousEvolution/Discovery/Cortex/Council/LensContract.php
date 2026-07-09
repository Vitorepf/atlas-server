<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council;

/**
 * The contract every Cortex Council lens implements. A lens is a deterministic, provider-safe perspective:
 * given a {@see CortexSubject}, it returns its own {@see LensObservation} — a bag of facts plus optional
 * disagreement_signals naming WHY it dissents. The lens never scores, never weighs other lenses, never reads
 * outside its perspective. The registry binds id ⇒ lens; the (future) triangulator composes the council
 * verdict by intersecting the observations as FACTS.
 */
interface LensContract
{
    /** A stable, opaque, lowercase-kebab id (e.g. "security", "performance", "drift_risk"). */
    public function id(): string;

    /** A human-readable name shown to the operator in dashboards/dumps. */
    public function name(): string;

    /** Observe the subject from this lens's perspective; pure, deterministic, no side effects. */
    public function observe(CortexSubject $subject): LensObservation;
}
