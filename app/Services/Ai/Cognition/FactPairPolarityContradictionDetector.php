<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

/**
 * Pure polarity-contradiction detector for a pair of atomic facts.
 *
 * Each fact is {subject:string, predicate:string, negated:bool, value:scalar|null}.
 * Subject and predicate are compared case-insensitively after trimming, so the
 * same claim phrased with different casing/whitespace still pairs up. The four
 * ordered rules classify the relationship between the two facts without any
 * I/O, state, or external dependency.
 */
final class FactPairPolarityContradictionDetector
{
    /**
     * @param  array{subject?:mixed, predicate?:mixed, negated?:mixed, value?:mixed}  $factA
     * @param  array{subject?:mixed, predicate?:mixed, negated?:mixed, value?:mixed}  $factB
     * @return array{contradicts:bool, kind:string}
     */
    public function detect(array $factA, array $factB): array
    {
        $sameSubject = $this->normalizeKey($factA, 'subject') === $this->normalizeKey($factB, 'subject');
        $samePredicate = $this->normalizeKey($factA, 'predicate') === $this->normalizeKey($factB, 'predicate');

        // (1) Different subject OR predicate -> the facts are not about the same claim.
        if (! $sameSubject || ! $samePredicate) {
            return $this->result(false, 'unrelated');
        }

        $negatedA = $this->negated($factA);
        $negatedB = $this->negated($factB);

        // (2) Same claim asserted with opposite polarity -> hard negation contradiction.
        if ($negatedA !== $negatedB) {
            return $this->result(true, 'hard_negation_contradiction');
        }

        $valueA = $this->value($factA);
        $valueB = $this->value($factB);

        // (3) Same claim, same polarity, both values present but not loosely equal -> value conflict.
        if ($valueA !== null && $valueB !== null && ! $this->looselyEqual($valueA, $valueB)) {
            return $this->result(true, 'value_conflict');
        }

        // (4) Otherwise the pair is consistent.
        return $this->result(false, 'none');
    }

    /**
     * @param  array{subject?:mixed, predicate?:mixed, negated?:mixed, value?:mixed}  $fact
     */
    private function normalizeKey(array $fact, string $key): string
    {
        return strtolower(trim((string) ($fact[$key] ?? '')));
    }

    /**
     * @param  array{subject?:mixed, predicate?:mixed, negated?:mixed, value?:mixed}  $fact
     */
    private function negated(array $fact): bool
    {
        return ($fact['negated'] ?? false) === true;
    }

    /**
     * @param  array{subject?:mixed, predicate?:mixed, negated?:mixed, value?:mixed}  $fact
     */
    private function value(array $fact): int|float|string|bool|null
    {
        $value = $fact['value'] ?? null;

        return is_scalar($value) ? $value : null;
    }

    private function looselyEqual(int|float|string|bool $valueA, int|float|string|bool $valueB): bool
    {
        return $valueA == $valueB;
    }

    /**
     * @return array{contradicts:bool, kind:string}
     */
    private function result(bool $contradicts, string $kind): array
    {
        return [
            'contradicts' => $contradicts,
            'kind' => $kind,
        ];
    }
}
