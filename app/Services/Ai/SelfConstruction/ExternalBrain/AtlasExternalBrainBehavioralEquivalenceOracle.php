<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Fail-closed proof gate: before a simplification muscle is allowed to merge/delete/replace one
 * circuit with another, this oracle decides whether the BEFORE and AFTER behavior contracts are
 * equivalent. A merge is approved only when every dimension matches exactly — outputs, errors,
 * side-effect contracts, and required evidence. Any missing contract, unknown side effect, or
 * output divergence fails CLOSED (not_equivalent), never open. Visual similarity is never proof;
 * only exact contract equality is.
 *
 * Behavior contract shape (both `before` and `after`):
 *   outputs?:            mixed          — the observable return value/shape
 *   errors?:             list<string>   — error/exception classes the circuit can raise
 *   side_effects?:        list<string>   — declared side effects (e.g. 'writes_db', 'none')
 *   required_evidence?:  list<string>   — evidence keys the circuit's caller must supply
 *
 * A side effect literally labelled 'unknown' (in either contract) always fails closed — an
 * unknown side effect is a proof gap, not a match.
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainBehavioralEquivalenceOracle
{
    public const SCHEMA = 'atlas.external_brain.behavioral_equivalence_oracle.v1';

    public const VERDICT_EQUIVALENT     = 'equivalent';
    public const VERDICT_NOT_EQUIVALENT = 'not_equivalent';

    /**
     * @param  array{before?: array<string,mixed>, after?: array<string,mixed>}  $facts
     * @return array{schema:string, verdict:string, merge_approved:bool, divergences:list<string>}
     */
    public function evaluate(array $facts): array
    {
        $before = $facts['before'] ?? null;
        $after  = $facts['after'] ?? null;

        if (! is_array($before) || $before === []) {
            return $this->notEquivalent(['missing_before_contract']);
        }
        if (! is_array($after) || $after === []) {
            return $this->notEquivalent(['missing_after_contract']);
        }

        $divergences = [];

        $beforeSideEffects = $this->stringList($before['side_effects'] ?? null);
        $afterSideEffects  = $this->stringList($after['side_effects'] ?? null);
        if (in_array('unknown', $beforeSideEffects, true) || in_array('unknown', $afterSideEffects, true)) {
            $divergences[] = 'unknown_side_effect';
        }

        if (! $this->normalizedEquals($before['outputs'] ?? null, $after['outputs'] ?? null)) {
            $divergences[] = 'output_divergence';
        }

        $beforeErrors = $this->stringList($before['errors'] ?? null);
        $afterErrors  = $this->stringList($after['errors'] ?? null);
        if (! $this->setsEqual($beforeErrors, $afterErrors)) {
            $divergences[] = 'error_contract_divergence';
        }

        if (! $this->setsEqual($beforeSideEffects, $afterSideEffects)) {
            $divergences[] = 'side_effect_contract_divergence';
        }

        $beforeEvidence = $this->stringList($before['required_evidence'] ?? null);
        $afterEvidence  = $this->stringList($after['required_evidence'] ?? null);
        if (! $this->setsEqual($beforeEvidence, $afterEvidence)) {
            $divergences[] = 'required_evidence_divergence';
        }

        if ($divergences !== []) {
            return $this->notEquivalent(array_values(array_unique($divergences)));
        }

        return [
            'schema'         => self::SCHEMA,
            'verdict'        => self::VERDICT_EQUIVALENT,
            'merge_approved' => true,
            'divergences'    => [],
        ];
    }

    /** @return array{schema:string, verdict:string, merge_approved:bool, divergences:list<string>} */
    private function notEquivalent(array $divergences): array
    {
        return [
            'schema'         => self::SCHEMA,
            'verdict'        => self::VERDICT_NOT_EQUIVALENT,
            'merge_approved' => false,
            'divergences'    => $divergences,
        ];
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $value), static fn (string $v): bool => $v !== ''));
    }

    /** @param  list<string>  $a  @param  list<string>  $b */
    private function setsEqual(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }

    private function normalizedEquals(mixed $a, mixed $b): bool
    {
        return json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            === json_encode($b, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
