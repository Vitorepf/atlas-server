<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

use App\Services\Ai\Support\AiValueNormalizer;

final class AaeosRequiredGateCoverageChecker
{
    public const SCHEMA_VERSION = 'atlas.aaeos.phase.v1';

    public const COVERAGE_NO_GATE = 'no_gate';

    public const COVERAGE_INCOMPLETE = 'incomplete';

    public const COVERAGE_COMPLETE = 'complete';

    public const FIELD_MISSING = 'missing';
    public const FIELD_COVERAGE = 'coverage';
    public const FIELD_SATISFIED = 'satisfied';
    public const FIELD_EXTRA_PASSED_GATES = 'extra_passed_gates';

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
                self::FIELD_COVERAGE => self::COVERAGE_NO_GATE,
                self::FIELD_MISSING => [],
                self::FIELD_SATISFIED => true,
                self::FIELD_EXTRA_PASSED_GATES => $extraPassed,
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
                self::FIELD_COVERAGE => self::COVERAGE_INCOMPLETE,
                self::FIELD_MISSING => $missing,
                self::FIELD_SATISFIED => false,
                self::FIELD_EXTRA_PASSED_GATES => $extraPassed,
            ];
        }

        return [
            self::FIELD_COVERAGE => self::COVERAGE_COMPLETE,
            self::FIELD_MISSING => [],
            self::FIELD_SATISFIED => true,
            self::FIELD_EXTRA_PASSED_GATES => $extraPassed,
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
