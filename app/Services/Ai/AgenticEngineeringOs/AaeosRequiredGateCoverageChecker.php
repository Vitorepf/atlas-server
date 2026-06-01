<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

final class AaeosRequiredGateCoverageChecker
{
    private const SCHEMA_VERSION = 'atlas.aaeos.phase.v1';

    /**
     * Pure set arithmetic over gates.{required,passed} of schema atlas.aaeos.phase.v1.
     *
     * @param  array<mixed>  $requiredGates
     * @param  array<mixed>  $passedGates
     * @return array{coverage: string, missing: list<string>, satisfied: bool}
     */
    public function check(array $requiredGates, array $passedGates): array
    {
        $required = $this->normalize($requiredGates);
        $passed = $this->normalize($passedGates);

        if ($required === []) {
            return [
                'coverage' => 'no_gate',
                'missing' => [],
                'satisfied' => true,
            ];
        }

        $passedLookup = array_fill_keys($passed, true);

        $missing = [];
        foreach ($required as $gate) {
            if (! isset($passedLookup[$gate])) {
                $missing[] = $gate;
            }
        }

        if ($missing !== []) {
            return [
                'coverage' => 'incomplete',
                'missing' => $missing,
                'satisfied' => false,
            ];
        }

        return [
            'coverage' => 'complete',
            'missing' => [],
            'satisfied' => true,
        ];
    }

    /**
     * Trim non-empty string entries, drop non-string and blank values, keep first-seen order.
     *
     * @param  array<mixed>  $gates
     * @return list<string>
     */
    private function normalize(array $gates): array
    {
        $seen = [];
        $normalized = [];

        foreach ($gates as $gate) {
            if (! is_string($gate)) {
                continue;
            }

            $trimmed = trim($gate);
            if ($trimmed === '') {
                continue;
            }

            if (isset($seen[$trimmed])) {
                continue;
            }

            $seen[$trimmed] = true;
            $normalized[] = $trimmed;
        }

        return $normalized;
    }
}
