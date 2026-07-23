<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Fail-closed data-flow proof gate: behavioral equivalence alone can miss payload-shape drift —
 * two circuits can return the "same" high-level outcome while silently dropping a key, renaming
 * a transform, or breaking a required payload path. This checker compares the BEFORE and AFTER
 * data flow across four dimensions — input keys, output keys, transform names, and required
 * payload paths — and fails closed the moment any dimension diverges, naming the exact diff
 * entries so a caller sees precisely what drifted instead of a bare pass/fail marker.
 *
 * Input contract:
 *   before: array{
 *     input_keys?:              list<string>,
 *     output_keys?:             list<string>,
 *     transform_names?:         list<string>,
 *     required_payload_paths?:  list<string>,
 *   }
 *   after: (same shape)
 *
 * Pure PHP, deterministic, no I/O.
 * @unwired-until 2026-08-05 (Obra #7 W2: capability testada aguardando consumidor; triagem 2026-07-06)
 */
final class AtlasExternalBrainDataFlowEquivalenceChecker
{
    public const SCHEMA = 'atlas.external_brain.data_flow_equivalence_checker.v1';

    public const VERDICT_EQUIVALENT     = 'equivalent';
    public const VERDICT_NOT_EQUIVALENT = 'not_equivalent';

    private const DIMENSIONS = ['input_keys', 'output_keys', 'transform_names', 'required_payload_paths'];

    /**
     * @param  array{before?: array<string,mixed>, after?: array<string,mixed>}  $facts
     * @return array{schema:string, verdict:string, diff:list<array{dimension:string, missing:list<string>, added:list<string>}>}
     */
    public function evaluate(array $facts): array
    {
        $before = $facts['before'] ?? null;
        $after  = $facts['after'] ?? null;

        if (! is_array($before) || $before === []) {
            return $this->notEquivalent([['dimension' => 'before', 'missing' => ['before_payload_snapshot'], 'added' => []]]);
        }
        if (! is_array($after) || $after === []) {
            return $this->notEquivalent([['dimension' => 'after', 'missing' => ['after_payload_snapshot'], 'added' => []]]);
        }

        $diff = [];

        foreach (self::DIMENSIONS as $dimension) {
            $beforeSet = $this->stringSet($before[$dimension] ?? null);
            $afterSet  = $this->stringSet($after[$dimension] ?? null);

            $missing = array_values(array_diff($beforeSet, $afterSet));
            $added   = array_values(array_diff($afterSet, $beforeSet));

            if ($missing !== [] || $added !== []) {
                $diff[] = ['dimension' => $dimension, 'missing' => $missing, 'added' => $added];
            }
        }

        if ($diff !== []) {
            return $this->notEquivalent($diff);
        }

        return [
            'schema'  => self::SCHEMA,
            'verdict' => self::VERDICT_EQUIVALENT,
            'diff'    => [],
        ];
    }

    /** @param  list<array{dimension:string, missing:list<string>, added:list<string>}>  $diff */
    private function notEquivalent(array $diff): array
    {
        return [
            'schema'  => self::SCHEMA,
            'verdict' => self::VERDICT_NOT_EQUIVALENT,
            'diff'    => $diff,
        ];
    }

    /** @return list<string> */
    private function stringSet(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $set = array_values(array_unique(array_filter(array_map('strval', $value), static fn (string $v): bool => $v !== '')));
        sort($set);

        return $set;
    }
}
