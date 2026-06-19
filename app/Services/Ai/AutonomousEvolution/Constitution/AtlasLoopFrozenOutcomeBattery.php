<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Constitution;

/**
 * LOOP-OS · FASE 3 · SLICE 5 — the FROZEN OUTCOME BATTERY (pétreo / FORBIDDEN under Constitution/).
 *
 * Brings the SELECTOR/objective inside the Constitution. The cert battery proves a candidate judge still
 * REFUTES known-bad code; this proves a candidate SELECTOR still ranks correctly — specifically it pins the
 * canon's immutable property "a behaviour-preserving REFACTOR is ZERO improvement" so no selector version can
 * erode it: every frozen pair is a (refactor, leap) where the ONE correct ranking is leap > refactor. A
 * selector that ranks a refactor at or above a genuine leap is REJECTED.
 *
 * The pairs are constructed AGAINST THE REAL EV MATH (a leap carries higher panel value AND touches the
 * binding system axis ⇒ higher bottleneck-relief ⇒ higher EV; a refactor is behaviour-preserving, low value,
 * touches nothing), so the correct ranking is not an invented number — it is what the real
 * {@see \App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopExpectedValueDecider} produces (the slice
 * test proves this). HONEST BOUNDARY (R3, same as the cert battery): the no-blinder proof against a
 * self-modifying SELECTOR runs the CANDIDATE selector's bytes through the {@see AtlasLoopBatteryRunner}
 * subprocess; this class is the FROZEN data + the deterministic ranking checker the runner drives.
 */
final class AtlasLoopFrozenOutcomeBattery
{
    public const SCHEMA_VERSION = 'atlas.loop.frozen_outcome_battery.v1';

    /**
     * Frozen (refactor, leap) ranking pairs. correct == 'leap' ALWAYS. Each side is a real EV candidate
     * shape {candidateId, class, value (0..100), touches_axes, node_count}; the accompanying axis_values make
     * the binding axis the one the leap touches, so the real EV decider ranks the leap above the refactor.
     *
     * @return list<array{id:string, refactor:array<string,mixed>, leap:array<string,mixed>, axis_values:array<string,float>}>
     */
    public function pairs(): array
    {
        return [
            [
                'id' => 'wired-leap-beats-orphan-refactor',
                'refactor' => ['candidateId' => 'refactor', 'class' => 'refactor', 'value' => 35.0, 'touches_axes' => [], 'node_count' => 1],
                'leap' => ['candidateId' => 'leap', 'class' => 'feature', 'value' => 85.0, 'touches_axes' => ['wired'], 'node_count' => 1],
                'axis_values' => ['wired' => 0.1, 'real_target' => 0.95, 'non_trivial' => 0.95, 'compounding' => 0.95, 'safety' => 0.95],
            ],
            [
                'id' => 'compounding-leap-beats-behavior-preserving-refactor',
                'refactor' => ['candidateId' => 'refactor', 'class' => 'refactor', 'value' => 40.0, 'touches_axes' => ['real_target'], 'node_count' => 1],
                'leap' => ['candidateId' => 'leap', 'class' => 'feature', 'value' => 90.0, 'touches_axes' => ['compounding'], 'node_count' => 1],
                'axis_values' => ['wired' => 0.95, 'real_target' => 0.9, 'non_trivial' => 0.95, 'compounding' => 0.1, 'safety' => 0.95],
            ],
            [
                'id' => 'nontrivial-leap-beats-trivial-refactor',
                'refactor' => ['candidateId' => 'refactor', 'class' => 'refactor', 'value' => 30.0, 'touches_axes' => [], 'node_count' => 1],
                'leap' => ['candidateId' => 'leap', 'class' => 'refactor', 'value' => 75.0, 'touches_axes' => ['non_trivial'], 'node_count' => 1],
                'axis_values' => ['wired' => 0.95, 'real_target' => 0.95, 'non_trivial' => 0.1, 'compounding' => 0.95, 'safety' => 0.95],
            ],
        ];
    }

    /** Byte-reproducible attestation hash of the frozen pairs. */
    public function rootHash(): string
    {
        return hash('sha256', json_encode($this->pairs(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Check a selector's ranking against every frozen pair. $ranker is given (refactor, leap, axis_values)
     * and returns the candidateId it ranks HIGHER. A correct selector returns the leap's id every time; any
     * pair where it does not is a VIOLATION (a refactor ranked >= a leap ⇒ the zero-improvement property
     * eroded). Returns the list of violated pair ids (empty ⇒ the selector upholds the property).
     *
     * @param  callable(array<string,mixed>, array<string,mixed>, array<string,float>):string  $ranker
     * @return list<string>
     */
    public function violations(callable $ranker): array
    {
        $violations = [];
        foreach ($this->pairs() as $pair) {
            $winner = (string) $ranker($pair['refactor'], $pair['leap'], $pair['axis_values']);
            if ($winner !== (string) $pair['leap']['candidateId']) {
                $violations[] = $pair['id'];
            }
        }

        return $violations;
    }

    /** A selector version is REJECTED iff it violates any frozen ranking (ranks a refactor >= a leap). */
    public function rejectsSelector(callable $ranker): bool
    {
        return $this->violations($ranker) !== [];
    }
}
