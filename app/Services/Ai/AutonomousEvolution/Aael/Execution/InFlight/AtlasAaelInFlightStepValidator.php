<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Execution\InFlight;

final class AtlasAaelInFlightStepValidator
{
    public const SCHEMA = 'atlas.aael.inflight_step_validator.v1';

    /**
     * @param  array<string,mixed>  $priorStepReceipt
     * @param  array<string,mixed>  $worldSnapshot
     * @return array{
     *   schema_version:string,
     *   passed:bool,
     *   facts:list<array{
     *     invariant_id:string,
     *     holds:bool,
     *     observed_value:mixed,
     *     declared_value:mixed,
     *     delta:mixed,
     *     step_index:int,
     *     reason:?string
     *   }>,
     *   reason:array<string,mixed>|null
     * }
     */
    public function validate(array $priorStepReceipt, array $worldSnapshot, int $stepIndex): array
    {
        $declared = $this->normalizeInvariantMap($priorStepReceipt['declared_invariants'] ?? null);
        $observed = $this->normalizeInvariantMap($worldSnapshot['invariants'] ?? null);

        if ($declared === []) {
            return [
                'schema_version' => self::SCHEMA,
                'passed' => false,
                'facts' => [[
                    'invariant_id' => '__missing_declared_invariants__',
                    'holds' => false,
                    'observed_value' => null,
                    'declared_value' => null,
                    'delta' => null,
                    'step_index' => $stepIndex,
                    'reason' => 'missing_declared_invariants',
                ]],
                'reason' => [
                    'code' => 'missing_declared_invariants',
                    'step_index' => $stepIndex,
                ],
            ];
        }

        $facts = [];
        foreach ($declared as $invariantId => $declaredValue) {
            $hasObserved = array_key_exists($invariantId, $observed);
            $observedValue = $hasObserved ? $observed[$invariantId] : null;
            $comparable = $this->isComparable($declaredValue) && $this->isComparable($observedValue);
            $holds = $hasObserved && $comparable && $observedValue === $declaredValue;
            $reason = match (true) {
                ! $hasObserved => 'missing_invariant',
                ! $comparable => 'undecidable_invariant',
                ! $holds => 'invariant_delta',
                default => null,
            };

            $facts[] = [
                'invariant_id' => $invariantId,
                'holds' => $holds,
                'observed_value' => $observedValue,
                'declared_value' => $declaredValue,
                'delta' => $holds ? null : ['declared' => $declaredValue, 'observed' => $observedValue],
                'step_index' => $stepIndex,
                'reason' => $reason,
            ];
        }

        $failedFacts = array_values(array_filter($facts, static fn (array $fact): bool => $fact['holds'] !== true));

        return [
            'schema_version' => self::SCHEMA,
            'passed' => $failedFacts === [],
            'facts' => $facts,
            'reason' => $failedFacts === [] ? null : [
                'code' => 'in_flight_invariant_failed',
                'step_index' => $stepIndex,
                'failed_invariant_ids' => array_values(array_map(
                    static fn (array $fact): string => (string) $fact['invariant_id'],
                    $failedFacts,
                )),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeInvariantMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        if (array_is_list($value)) {
            $normalized = [];
            foreach ($value as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $invariantId = (string) ($row['invariant_id'] ?? '');
                if ($invariantId === '') {
                    continue;
                }

                $normalized[$invariantId] = $row['declared_value'] ?? $row['value'] ?? null;
            }

            return $normalized;
        }

        return $value;
    }

    private function isComparable(mixed $value): bool
    {
        return is_scalar($value) || $value === null;
    }
}
