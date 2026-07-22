<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\LiveCycle\FactPassing;

use InvalidArgumentException;

final class AtlasLoopPhaseBoundaryFactValidator
{
    public function __construct(
        private readonly ?AtlasLoopPhaseBoundaryFactSchemaRegistry $registry = null,
    ) {}

    public function validate(string $boundary, array $fact): AtlasLoopPhaseBoundaryFactValidationResult
    {
        $registry = $this->registry ?? new AtlasLoopPhaseBoundaryFactSchemaRegistry;

        try {
            $schema = $registry->schemaFor($boundary);
        } catch (InvalidArgumentException) {
            return new AtlasLoopPhaseBoundaryFactValidationResult(
                ok: false,
                missingKeys: [],
                typeMismatches: [],
                unknownKeys: [],
                reason: 'unknown_boundary',
            );
        }

        $fieldTypes = array_merge(
            $schema['field_types'] ?? [],
            $schema['provenance_fields'] ?? [],
        );
        $requiredKeys = array_values(array_unique(array_merge(
            $schema['required_keys'] ?? [],
            array_keys($schema['provenance_fields'] ?? []),
        )));

        $missingKeys = [];
        foreach ($requiredKeys as $requiredKey) {
            if (! array_key_exists($requiredKey, $fact)) {
                $missingKeys[] = $requiredKey;
            }
        }

        $typeMismatches = [];
        foreach ($fieldTypes as $key => $expectedType) {
            if (! array_key_exists($key, $fact)) {
                continue;
            }

            $actualType = $this->actualType($fact[$key]);
            if ($actualType !== $expectedType) {
                $typeMismatches[] = [
                    'key' => (string) $key,
                    'expected_type' => (string) $expectedType,
                    'actual_type' => $actualType,
                ];
            }
        }

        $unknownKeys = [];
        foreach (array_keys($fact) as $key) {
            if (! array_key_exists((string) $key, $fieldTypes)) {
                $unknownKeys[] = (string) $key;
            }
        }

        return new AtlasLoopPhaseBoundaryFactValidationResult(
            ok: $missingKeys === [] && $typeMismatches === [] && $unknownKeys === [],
            missingKeys: $missingKeys,
            typeMismatches: $typeMismatches,
            unknownKeys: $unknownKeys,
            reason: null,
        );
    }

    private function actualType(mixed $value): string
    {
        return match (true) {
            is_array($value) => 'array',
            is_bool($value) => 'bool',
            is_float($value) => 'float',
            is_int($value) => 'int',
            is_null($value) => 'null',
            is_string($value) => 'string',
            default => get_debug_type($value),
        };
    }
}
