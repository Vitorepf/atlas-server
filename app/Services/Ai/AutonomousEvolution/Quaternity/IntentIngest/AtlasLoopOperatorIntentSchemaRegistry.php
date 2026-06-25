<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest;

/**
 * Canonical schema registry for operator-intent payloads. Holds the v1 schema and a forward-compat path so
 * future versions can register without breaking v1 callers. Immutable after construction (no public mutator).
 *
 *   v1 fields: {schema, id, ts, verb, object, constraints, source_message_id, source}
 */
final class AtlasLoopOperatorIntentSchemaRegistry
{
    public const V1 = 'operator.intent.v1';

    private const VALID_SOURCES = ['chat', 'goal', 'cli'];

    /** @var array<string, array{required:array<string,string>, optional:array<string,string>, source:list<string>, verbs:list<string>}> */
    private readonly array $schemas;

    /** @var list<string>  ordered (semver) version list */
    private readonly array $versions;

    /**
     * @param  array<string, array{required:array<string,string>, optional:array<string,string>, source:list<string>, verbs:list<string>}>|null  $extraSchemas  reserved for forward-compat callers; v1 is always present
     */
    public function __construct(?array $extraSchemas = null)
    {
        $verbs = array_map(static fn (OperatorIntentVerb $v): string => $v->value, OperatorIntentVerb::cases());
        $schemas = [
            self::V1 => [
                'required' => [
                    'schema' => 'string',
                    'id' => 'string',
                    'ts' => 'int',
                    'verb' => 'string',
                    'object' => 'string',
                    'constraints' => 'list<string>',
                    'source_message_id' => 'string',
                    'source' => 'string',
                ],
                'optional' => [],
                'source' => self::VALID_SOURCES,
                'verbs' => $verbs,
            ],
        ];
        if (is_array($extraSchemas)) {
            foreach ($extraSchemas as $version => $shape) {
                $schemas[(string) $version] = $shape;
            }
        }
        $this->schemas = $schemas;

        $versions = array_keys($schemas);
        usort($versions, static fn (string $a, string $b): int => strnatcmp($a, $b));
        $this->versions = $versions;
    }

    public function currentVersion(): string
    {
        return self::V1;
    }

    /**
     * @return list<string>
     */
    public function versioned(): array
    {
        return $this->versions;
    }

    /**
     * Strict-by-default validation. Unknown fields are rejected unless they're declared optional for the schema.
     *
     * @param  array<string,mixed>  $payload
     */
    public function validate(array $payload, ?string $version = null): SchemaValidationResult
    {
        $version = $version ?? $this->currentVersion();
        $shape = $this->schemas[$version] ?? null;
        if ($shape === null) {
            return new SchemaValidationResult(false, [
                new SchemaViolation(SchemaViolation::KIND_MISSING_FIELD, 'schema', 'unknown_schema_version:'.$version),
            ], $version);
        }

        $violations = [];

        $known = array_keys($shape['required']) + array_keys($shape['optional']);
        $known = array_flip(array_values($known));

        foreach ($shape['required'] as $field => $type) {
            if (! array_key_exists($field, $payload)) {
                $violations[] = new SchemaViolation(SchemaViolation::KIND_MISSING_FIELD, $field);

                continue;
            }
            if (! $this->matchesType($payload[$field], $type)) {
                $violations[] = new SchemaViolation(SchemaViolation::KIND_WRONG_TYPE, $field, 'expected:'.$type);
            }
        }

        foreach ($payload as $field => $value) {
            if (! isset($known[(string) $field])) {
                $violations[] = new SchemaViolation(SchemaViolation::KIND_UNKNOWN_FIELD, (string) $field);
            }
        }

        if (isset($payload['verb']) && is_string($payload['verb']) && ! in_array($payload['verb'], $shape['verbs'], true)) {
            $violations[] = new SchemaViolation(SchemaViolation::KIND_ENUM_VIOLATION, 'verb', 'expected_one_of:'.implode('|', $shape['verbs']));
        }
        if (isset($payload['source']) && is_string($payload['source']) && ! in_array($payload['source'], $shape['source'], true)) {
            $violations[] = new SchemaViolation(SchemaViolation::KIND_ENUM_VIOLATION, 'source', 'expected_one_of:'.implode('|', $shape['source']));
        }

        return new SchemaValidationResult($violations === [], $violations, $version);
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'int' => is_int($value),
            'list<string>' => is_array($value) && array_is_list($value) && array_reduce($value, static fn (bool $carry, mixed $v): bool => $carry && is_string($v), true),
            default => true,
        };
    }
}
