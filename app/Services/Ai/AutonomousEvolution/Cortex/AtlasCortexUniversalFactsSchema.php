<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Cortex;

/**
 * The canonical, VERSIONED JSON schema for Atlas Cortex FACTS (schema id {@see SCHEMA_ID}). This class owns
 * the schema definition AND the validator. Validation is hand-written (no external JSON-schema library) so
 * the schema stays portable across non-Atlas repos.
 *
 * PÉTREO INVARIANT (byte-level enforcement): a unit object that carries a key matching `/^(score|rank|grade)$/i`
 * triggers a `never a score` validation error. This is the schema-level guard against a future change
 * smuggling a hidden scoring rig into the comprehension model.
 */
final class AtlasCortexUniversalFactsSchema
{
    public const SCHEMA_ID = 'atlas.cortex.facts.v1';

    /** Top-level required keys mirroring {@see \App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel::toArray()}. */
    private const REQUIRED_TOP_LEVEL = [
        'snapshot_id' => 'string',
        // `inventory` carries the per-file unit records — list of records OR map of fqcn=>record both valid.
        'inventory' => 'array_any',
        'orphans' => 'array_any',
        'clone_clusters' => 'array_any',
        'forbidden' => 'array_any',
        'doc_stated_gaps' => 'array_any',
    ];

    /** Optional aspirational keys — validated only when present so current and future-shaped payloads both pass. */
    private const OPTIONAL_TOP_LEVEL = [
        'scope_root' => 'string',
        'units' => 'array_any',
        'edges' => 'array_any',
        'doc_purposes' => 'array_any',
        'doc_purposes_provenance' => 'string',
        'doc_stated_gaps_provenance' => 'string',
        'schema_version' => 'string',
    ];

    private const FORBIDDEN_UNIT_KEY_PATTERN = '/^(score|rank|grade)$/i';

    /**
     * @return array<string,mixed>  JSON-schema-shaped definition (subset of JSON Schema Draft 7)
     */
    public function definition(): array
    {
        $required = [];
        $properties = [];
        foreach (self::REQUIRED_TOP_LEVEL as $key => $type) {
            $required[] = $key;
            $properties[$key] = ['type' => $type];
        }
        foreach (self::OPTIONAL_TOP_LEVEL as $key => $type) {
            $properties[$key] = ['type' => $type];
        }
        $properties['units']['additionalProperties'] = [
            'type' => 'object',
            'properties' => [
                'level_vector' => ['type' => 'array', 'items' => ['type' => 'boolean'], 'minItems' => 6, 'maxItems' => 6],
                'transitions' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]]],
            ],
            'forbidden_keys_pattern' => self::FORBIDDEN_UNIT_KEY_PATTERN,
        ];

        return [
            'schema_id' => self::SCHEMA_ID,
            'title' => 'Atlas Cortex Universal Facts',
            'type' => 'object',
            'required' => $required,
            'properties' => $properties,
            'pétreo_invariant' => 'never a score: any unit key matching '.self::FORBIDDEN_UNIT_KEY_PATTERN.' is a validation error.',
        ];
    }

    /**
     * Validate a FACTS payload. Returns an array of error strings; empty array = valid.
     *
     * @param  array<string,mixed>  $facts
     * @return list<string>
     */
    public function validate(array $facts): array
    {
        $errors = [];

        // (1) Required top-level keys present + typed correctly.
        foreach (self::REQUIRED_TOP_LEVEL as $key => $type) {
            if (! array_key_exists($key, $facts)) {
                $errors[] = "missing required top-level key: {$key}";

                continue;
            }
            $typeError = $this->typeError($facts[$key], $type);
            if ($typeError !== null) {
                $errors[] = "top-level key {$key} {$typeError}";
            }
        }

        // (2) Optional keys, when present, must still satisfy their declared type.
        foreach (self::OPTIONAL_TOP_LEVEL as $key => $type) {
            if (! array_key_exists($key, $facts)) {
                continue;
            }
            $typeError = $this->typeError($facts[$key], $type);
            if ($typeError !== null) {
                $errors[] = "optional top-level key {$key} {$typeError}";
            }
        }

        // (3) Per-unit pétreo invariant: NEVER a score / rank / grade key.
        if (isset($facts['units']) && is_array($facts['units'])) {
            foreach ($facts['units'] as $unitId => $unit) {
                if (! is_array($unit)) {
                    continue;
                }
                foreach ($unit as $key => $_) {
                    if (preg_match(self::FORBIDDEN_UNIT_KEY_PATTERN, (string) $key) === 1) {
                        $errors[] = "unit '{$unitId}' carries forbidden key '{$key}' — never a score: the comprehension model emits FACTS only, never a numeric verdict";
                    }
                }
                // (3a) level_vector must be a list of 6 booleans when present.
                if (isset($unit['level_vector'])) {
                    if (! is_array($unit['level_vector']) || count($unit['level_vector']) !== 6) {
                        $errors[] = "unit '{$unitId}' level_vector must be an array of exactly 6 booleans";
                    } else {
                        foreach ($unit['level_vector'] as $v) {
                            if (! is_bool($v)) {
                                $errors[] = "unit '{$unitId}' level_vector entries must all be booleans";
                                break;
                            }
                        }
                    }
                }
                // (3b) transitions must be a list of named transitions when present.
                if (isset($unit['transitions'])) {
                    if (! is_array($unit['transitions'])) {
                        $errors[] = "unit '{$unitId}' transitions must be an array";
                    } else {
                        foreach ($unit['transitions'] as $t) {
                            if (! is_array($t) || ! isset($t['name']) || ! is_string($t['name']) || trim($t['name']) === '') {
                                $errors[] = "unit '{$unitId}' transitions must all be objects with a non-empty string 'name'";
                                break;
                            }
                        }
                    }
                }
            }
        }

        return $errors;
    }

    private function typeError(mixed $value, string $declaredType): ?string
    {
        return match ($declaredType) {
            'string' => is_string($value) ? null : 'must be a string',
            'integer' => is_int($value) ? null : 'must be an integer',
            'boolean' => is_bool($value) ? null : 'must be a boolean',
            // 'array' = strictly a numeric-indexed list (empty array allowed).
            'array' => is_array($value) && (array_is_list($value) || $value === []) ? null : 'must be a JSON array (numeric-indexed list)',
            // 'object' = strictly an associative map (empty array allowed as the zero map).
            'object' => is_array($value) ? null : 'must be a JSON object (associative array)',
            // 'array_any' = either shape — used where the comprehension model uses either a list or a map.
            'array_any' => is_array($value) ? null : 'must be an array',
            default => null,
        };
    }
}
