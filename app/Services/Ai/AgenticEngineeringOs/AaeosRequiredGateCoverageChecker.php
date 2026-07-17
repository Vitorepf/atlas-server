<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

use App\Services\Ai\Support\AiValueNormalizer;

final class AaeosRequiredGateCoverageChecker
{
    public const SCHEMA_VERSION = 'atlas.aaeos.phase.v1';

    /**
     * Pure set arithmetic over gates.{required,passed} of schema atlas.aaeos.phase.v1.
     *
     * @param  array<mixed>  $requiredGates
     * @param  array<mixed>  $passedGates
     * @return array{coverage: string, missing: list<string>, satisfied: bool, extra_passed_gates: list<string>}
     */
    public function check(array $requiredGates, array $passedGates): array
    {
        $required = $this->normalize($requiredGates);
        $passed = $this->normalize($passedGates);

        $requiredLookup = array_fill_keys($required, true);
        // Extra passed gates never hide a required-gate gap — they're reported
        // separately so a wide green suite can't be mistaken for real coverage.
        $extraPassed = array_values(array_filter(
            $passed,
            static fn (string $gate): bool => ! isset($requiredLookup[$gate]),
        ));

        if ($required === []) {
            return [
                'coverage' => 'no_gate',
                'missing' => [],
                'satisfied' => true,
                'extra_passed_gates' => $extraPassed,
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
                'extra_passed_gates' => $extraPassed,
            ];
        }

        return [
            'coverage' => 'complete',
            'missing' => [],
            'satisfied' => true,
            'extra_passed_gates' => $extraPassed,
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
            $trimmed = AiValueNormalizer::trimmedStringOrNull($gate);
            if ($trimmed === null) {
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
