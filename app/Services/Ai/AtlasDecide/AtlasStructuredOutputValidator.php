<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

use stdClass;

/**
 * Provider-boundary structured-output gate.
 *
 * Today an AiProviderResult.output (and the resolver outcome's 'output') is an
 * UNCHECKED string — a provider arm can return anything and the conductor trusts
 * its shape. This is the genuine capability gap the Dynamic Workflows dissection
 * surfaced: the native feature forces a validated StructuredOutput at the
 * tool-call layer; Atlas had no equivalent at the provider boundary.
 *
 * This validator closes that gap with a small, dependency-free JSON-Schema subset
 * (type, required, properties, additionalProperties, items, enum) — the keywords
 * the conductor actually needs to pin an arm's output shape. The output is decoded
 * WITHOUT associative coercion so a JSON object (`{}`) and a JSON array (`[]`) stay
 * distinguishable at every level (assoc decode collapses both to PHP `[]`, which
 * would let an empty object satisfy an array-typed gate — a fail-open hole).
 */
final class AtlasStructuredOutputValidator
{
    /**
     * Validate a raw provider output string against a JSON-Schema subset.
     *
     * @param  array<string,mixed>  $schema
     * @return array{valid:bool,errors:list<string>,value:mixed}
     */
    public function validate(string $output, array $schema): array
    {
        $trimmed = trim($output);
        if ($trimmed === '') {
            return ['valid' => false, 'errors' => ['output_empty'], 'value' => null];
        }

        // Decode WITHOUT assoc: JSON objects -> stdClass, JSON arrays -> list.
        $decoded = json_decode($trimmed);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['valid' => false, 'errors' => ['output_not_json: '.json_last_error_msg()], 'value' => null];
        }

        $errors = $this->check($decoded, $schema, '$');

        return ['valid' => $errors === [], 'errors' => $errors, 'value' => $this->toAssoc($decoded)];
    }

    /**
     * @param  array<string,mixed>  $schema
     * @return list<string>
     */
    private function check(mixed $value, array $schema, string $path): array
    {
        $errors = [];

        if (isset($schema['type']) && ! $this->typeMatches($value, $schema['type'])) {
            $errors[] = $path.': expected '.$this->typeLabel($schema['type']).', got '.$this->typeOf($value);

            // A wrong type makes deeper checks meaningless.
            return $errors;
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && ! $this->inEnum($value, $schema['enum'])) {
            $errors[] = $path.': value not in enum';
        }

        if (($schema['type'] ?? null) === 'object' && $value instanceof stdClass) {
            $errors = array_merge($errors, $this->checkObject($value, $schema, $path));
        }

        if (($schema['type'] ?? null) === 'array' && is_array($value) && isset($schema['items']) && is_array($schema['items'])) {
            foreach (array_values($value) as $i => $item) {
                $errors = array_merge($errors, $this->check($item, $schema['items'], $path.'['.$i.']'));
            }
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $schema
     * @return list<string>
     */
    private function checkObject(stdClass $value, array $schema, string $path): array
    {
        $errors = [];
        $vars = get_object_vars($value);

        foreach ((array) ($schema['required'] ?? []) as $req) {
            if (! is_string($req)) {
                continue;
            }
            if (! array_key_exists($req, $vars)) {
                $errors[] = $path.': missing required "'.$req.'"';
            }
        }

        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        foreach ($properties as $key => $propSchema) {
            if (array_key_exists((string) $key, $vars) && is_array($propSchema)) {
                $errors = array_merge($errors, $this->check($vars[$key], $propSchema, $path.'.'.$key));
            }
        }

        if (($schema['additionalProperties'] ?? null) === false) {
            foreach (array_keys($vars) as $key) {
                if (! array_key_exists((string) $key, $properties)) {
                    $errors[] = $path.': unexpected property "'.$key.'"';
                }
            }
        }

        return $errors;
    }

    private function typeMatches(mixed $value, mixed $type): bool
    {
        // JSON Schema allows a union of types, e.g. ["string","null"].
        if (is_array($type)) {
            foreach ($type as $member) {
                if (is_string($member) && $this->typeMatches($value, $member)) {
                    return true;
                }
            }

            return false;
        }

        if (! is_string($type)) {
            return true; // unknown/garbage type spec -> do not block
        }

        return match ($type) {
            'object' => $value instanceof stdClass,
            'array' => is_array($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => true,
        };
    }

    /**
     * @param  array<mixed>  $enum
     */
    private function inEnum(mixed $value, array $enum): bool
    {
        foreach ($enum as $candidate) {
            if ($candidate === $value) {
                return true;
            }

            // JSON has one number type: compare two real numbers by value so an int
            // decode is not spuriously rejected by a float-declared enum (and vice
            // versa). Numeric strings are NOT coerced — only int|float vs int|float.
            if ((is_int($candidate) || is_float($candidate)) && (is_int($value) || is_float($value)) && (float) $candidate === (float) $value) {
                return true;
            }
        }

        return false;
    }

    private function typeLabel(mixed $type): string
    {
        return is_array($type) ? implode('|', array_map('strval', $type)) : (string) $type;
    }

    private function typeOf(mixed $value): string
    {
        return match (true) {
            $value instanceof stdClass => 'object',
            is_array($value) => 'array',
            is_string($value) => 'string',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_bool($value) => 'boolean',
            $value === null => 'null',
            default => 'unknown',
        };
    }

    private function toAssoc(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $out = [];
            foreach (get_object_vars($value) as $k => $v) {
                $out[$k] = $this->toAssoc($v);
            }

            return $out;
        }

        if (is_array($value)) {
            return array_map(fn (mixed $v): mixed => $this->toAssoc($v), $value);
        }

        return $value;
    }
}
