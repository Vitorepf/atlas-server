<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\WireFormat;

/**
 * Validates a concrete cross-primitive message payload against its declared schemaId in
 * {@see AtlasLoopInterPrimitiveMessageSchemaRegistry}.
 *
 * Enforces:
 *   - required-field presence
 *   - declared type per field (scalar / array / object)
 *   - no-extra-fields invariant
 *   - nullable contract
 *   - stable byte-shape ordering for repeatable hashing
 *
 * Fail-closed: unknown schemaId, missing required, type mismatch, or extra field ⇒ ok=false.
 * Pure: no IO, no provider calls, deterministic.
 */
final class AtlasLoopInterPrimitiveMessageValidator
{
    public function __construct(private readonly AtlasLoopInterPrimitiveMessageSchemaRegistry $registry) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public function validate(string $schemaId, array $payload): AtlasLoopInterPrimitiveMessageValidationResult
    {
        try {
            $schema = $this->registry->get($schemaId);
        } catch (UnknownSchemaException $e) {
            return new AtlasLoopInterPrimitiveMessageValidationResult(
                false,
                [['field' => 'schema_id', 'kind' => 'unknown_schema_id', 'detail' => $schemaId]],
                [],
            );
        }

        $errors = [];
        $required = (array) $schema['required_fields'];
        $byteShape = array_values((array) $schema['byte_shape']);

        // 1. Required-field presence + type + nullable contract.
        foreach ($required as $field => $rule) {
            $type = (string) ($rule['type'] ?? '');
            $nullable = (bool) ($rule['nullable'] ?? false);
            if (! array_key_exists($field, $payload)) {
                $errors[] = ['field' => (string) $field, 'kind' => 'missing_required', 'detail' => 'field_required'];

                continue;
            }
            $value = $payload[$field];
            if ($value === null) {
                if (! $nullable) {
                    $errors[] = ['field' => (string) $field, 'kind' => 'null_not_allowed', 'detail' => 'nullable=false'];
                }

                continue;
            }
            if (! $this->matchesType($value, $type)) {
                $errors[] = ['field' => (string) $field, 'kind' => 'type_mismatch', 'detail' => 'expected:'.$type];
            }
        }

        // 2. No extra fields.
        foreach ($payload as $key => $_) {
            if (! array_key_exists((string) $key, $required)) {
                $errors[] = ['field' => (string) $key, 'kind' => 'extra_field', 'detail' => 'not_in_schema'];
            }
        }

        if ($errors !== []) {
            return new AtlasLoopInterPrimitiveMessageValidationResult(false, $errors, []);
        }

        // 3. Build the normalized payload in byte-shape order — guarantees stable hashing.
        $normalized = [];
        foreach ($byteShape as $field) {
            $normalized[$field] = $payload[$field] ?? null;
        }

        return new AtlasLoopInterPrimitiveMessageValidationResult(true, [], $normalized);
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            AtlasLoopInterPrimitiveMessageSchemaRegistry::TYPE_SCALAR => is_scalar($value),
            AtlasLoopInterPrimitiveMessageSchemaRegistry::TYPE_ARRAY => is_array($value) && array_is_list($value),
            AtlasLoopInterPrimitiveMessageSchemaRegistry::TYPE_OBJECT => is_array($value) && (! array_is_list($value) || $value === []),
            default => false,
        };
    }
}
