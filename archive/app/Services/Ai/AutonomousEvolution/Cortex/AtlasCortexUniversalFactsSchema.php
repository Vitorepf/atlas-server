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

    /** Violation codes returned in the errors list — consumed by the brain and Self-Construction OS. */
    public const VIOLATION_MISSING_FIELD       = 'missing_field';
    public const VIOLATION_UNSAFE_EVIDENCE_REF = 'unsafe_evidence_ref';
    public const VIOLATION_STALE_FACT          = 'stale_fact';
    public const VIOLATION_SCALAR_ONLY_SCORE   = 'scalar_only_score';

    /** Default freshness window: 48 hours — a captured_at older than this triggers stale_fact. */
    public const FRESHNESS_THRESHOLD_SECONDS = 172_800;

    /**
     * @param  int  $nowUnix  Override "now" for freshness checks (0 = use time()). Injectable for tests.
     */
    public function __construct(private readonly int $nowUnix = 0) {}

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
        // Self-Construction / brain fields
        'source_workspace' => 'string',
        'evidence_refs' => 'array_any',
        'captured_at' => 'string',
    ];

    /** Provider-unsafe patterns that must never appear inside an evidence_ref string. */
    private const UNSAFE_REF_PATTERNS = [
        '/sk-ant-[A-Za-z0-9_\-]{8,}/',
        '/sk-[A-Za-z0-9_\-]{16,}/',
        '/Bearer\s+[A-Za-z0-9._\-]{8,}/i',
        '/\/Users\/[^\/]+\//',
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
     * Normalize a FACTS payload: apply defaults for optional fields and sort keys deterministically.
     * Returns ['facts' => normalized, 'violations' => list of error strings].
     *
     * @param  array<string,mixed>  $facts
     * @return array{facts:array<string,mixed>, violations:list<string>}
     */
    public function normalize(array $facts): array
    {
        $violations = $this->validate($facts);

        // Apply deterministic defaults for optional fields.
        $facts += [
            'source_workspace' => '',
            'evidence_refs'    => [],
            'captured_at'      => '',
            'schema_version'   => self::SCHEMA_ID,
        ];

        // Sort top-level keys alphabetically for byte-identical output across calls.
        ksort($facts, SORT_STRING);

        return ['facts' => $facts, 'violations' => $violations];
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
                $errors[] = self::VIOLATION_MISSING_FIELD.': '.$key;

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

        // (2b) evidence_refs: each ref must be a safe string (no provider keys, no home paths).
        if (isset($facts['evidence_refs']) && is_array($facts['evidence_refs'])) {
            foreach ($facts['evidence_refs'] as $idx => $ref) {
                $ref = (string) $ref;
                foreach (self::UNSAFE_REF_PATTERNS as $pattern) {
                    if (preg_match($pattern, $ref) === 1) {
                        $errors[] = self::VIOLATION_UNSAFE_EVIDENCE_REF.': evidence_refs['.$idx.'] contains provider-unsafe content';
                        break;
                    }
                }
            }
        }

        // (2c) captured_at freshness: if present and parseable, must not exceed the staleness threshold.
        if (isset($facts['captured_at']) && is_string($facts['captured_at']) && $facts['captured_at'] !== '') {
            $ts = strtotime($facts['captured_at']);
            if ($ts !== false) {
                $now = $this->nowUnix !== 0 ? $this->nowUnix : time();
                if (($now - $ts) > self::FRESHNESS_THRESHOLD_SECONDS) {
                    $errors[] = self::VIOLATION_STALE_FACT.': captured_at='.$facts['captured_at'].' exceeds freshness threshold of '.self::FRESHNESS_THRESHOLD_SECONDS.'s';
                }
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
                // (3b-pre) scalar_only_score: reject units whose every value is numeric with no structural key.
                if (! empty($unit)) {
                    $structuralKeys = array_filter(array_keys($unit), static fn (mixed $k): bool => ! is_numeric($unit[$k]));
                    $allNumeric     = array_reduce($unit, static fn (bool $carry, mixed $v): bool => $carry && is_numeric($v), true);
                    if ($allNumeric && empty($structuralKeys)) {
                        $errors[] = self::VIOLATION_SCALAR_ONLY_SCORE.": unit '{$unitId}' carries only numeric scalar values with no structural context — confidence must be accompanied by structural facts";
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
