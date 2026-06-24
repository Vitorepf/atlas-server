<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SchemaFuzz;

use InvalidArgumentException;

final class AtlasLoopSchemaFuzzPayloadGenerator
{
    /**
     * @return list<array{
     *     payload_id:string,
     *     seed:int,
     *     schema_name:string,
     *     boundary_kind:string,
     *     expected_violation_facet:string,
     *     payload:array<string,mixed>
     * }>
     */
    public function variants(int $seed, string $schemaName): array
    {
        $descriptor = $this->descriptor($schemaName);

        return [
            $this->variant($seed, $schemaName, 'missing_required', 'required', $this->missingRequiredPayload($descriptor)),
            $this->variant($seed, $schemaName, 'null_vs_absent', 'nullable_presence', $this->nullVsAbsentPayload($descriptor)),
            $this->variant($seed, $schemaName, 'type_mismatch', 'type', $this->typeMismatchPayload($descriptor)),
            $this->variant($seed, $schemaName, 'oversize', 'max_length', $this->oversizePayload($seed, $descriptor)),
            $this->variant($seed, $schemaName, 'numeric_extreme', 'numeric_bounds', $this->numericExtremePayload($descriptor)),
            $this->variant($seed, $schemaName, 'unicode_control', 'encoding', $this->unicodeControlPayload($seed, $descriptor)),
            $this->variant($seed, $schemaName, 'recursion_limit', 'max_depth', $this->recursionLimitPayload($seed, $descriptor)),
            $this->variant($seed, $schemaName, 'additional_properties', 'additional_properties', $this->additionalPropertiesPayload($seed, $descriptor)),
        ];
    }

    /**
     * @return list<string>
     */
    public function schemaNames(): array
    {
        return array_keys($this->descriptors());
    }

    /**
     * @return array{
     *     required:list<string>,
     *     nullable:string,
     *     string:string,
     *     integer:string,
     *     array:string,
     *     object:string,
     *     recursion:string,
     *     additional:string,
     *     max_length:int,
     *     base:array<string,mixed>
     * }
     */
    private function descriptor(string $schemaName): array
    {
        $descriptor = $this->descriptors()[$schemaName] ?? null;
        if ($descriptor === null) {
            throw new InvalidArgumentException(sprintf('Unknown schema descriptor [%s].', $schemaName));
        }

        return $descriptor;
    }

    /**
     * @return array<string, array{
     *     required:list<string>,
     *     nullable:string,
     *     string:string,
     *     integer:string,
     *     array:string,
     *     object:string,
     *     recursion:string,
     *     additional:string,
     *     max_length:int,
     *     base:array<string,mixed>
     * }>
     */
    private function descriptors(): array
    {
        return [
            'decision_receipt' => [
                'required' => ['receipt_id', 'decision_kind', 'occurred_at'],
                'nullable' => 'operator_note',
                'string' => 'decision_kind',
                'integer' => 'attempt_count',
                'array' => 'artifacts',
                'object' => 'context',
                'recursion' => 'context',
                'additional' => 'unexpected_decision_field',
                'max_length' => 64,
                'base' => [
                    'receipt_id' => 'dr-0001',
                    'decision_kind' => 'accept',
                    'occurred_at' => '2026-06-24T00:00:00Z',
                    'attempt_count' => 1,
                    'operator_note' => 'looks good',
                    'artifacts' => ['task-envelope', 'judge-output'],
                    'context' => ['campaign_id' => 'cmp-1'],
                ],
            ],
            'task_envelope' => [
                'required' => ['task_id', 'objective', 'priority'],
                'nullable' => 'target_path',
                'string' => 'objective',
                'integer' => 'priority',
                'array' => 'allowed_files',
                'object' => 'payload',
                'recursion' => 'payload',
                'additional' => 'unexpected_task_field',
                'max_length' => 120,
                'base' => [
                    'task_id' => 'task-0001',
                    'objective' => 'Reduce complexity in the hot path',
                    'priority' => 100,
                    'target_path' => 'app/Services/Ai/Foo.php',
                    'allowed_files' => ['app/Services/Ai/Foo.php'],
                    'payload' => ['kind' => 'refactor'],
                ],
            ],
            'attempt_ledger_record' => [
                'required' => ['attempt_id', 'status', 'duration_ms'],
                'nullable' => 'error_message',
                'string' => 'status',
                'integer' => 'duration_ms',
                'array' => 'receipts',
                'object' => 'metrics',
                'recursion' => 'metrics',
                'additional' => 'unexpected_attempt_field',
                'max_length' => 80,
                'base' => [
                    'attempt_id' => 'attempt-0001',
                    'status' => 'passed',
                    'duration_ms' => 42,
                    'error_message' => null,
                    'receipts' => ['receipt-1'],
                    'metrics' => ['assertions' => 3],
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{
     *     payload_id:string,
     *     seed:int,
     *     schema_name:string,
     *     boundary_kind:string,
     *     expected_violation_facet:string,
     *     payload:array<string,mixed>
     * }
     */
    private function variant(int $seed, string $schemaName, string $boundaryKind, string $facet, array $payload): array
    {
        return [
            'payload_id' => substr(hash('sha256', $seed.'|'.$schemaName.'|'.$boundaryKind), 0, 16),
            'seed' => $seed,
            'schema_name' => $schemaName,
            'boundary_kind' => $boundaryKind,
            'expected_violation_facet' => $facet,
            'payload' => $payload,
        ];
    }

    /**
     * @param  array{
     *     required:list<string>,
     *     base:array<string,mixed>
     * }  $descriptor
     * @return array<string,mixed>
     */
    private function missingRequiredPayload(array $descriptor): array
    {
        $payload = $descriptor['base'];
        unset($payload[$descriptor['required'][0]]);

        return $payload;
    }

    /**
     * @param  array{
     *     nullable:string,
     *     base:array<string,mixed>
     * }  $descriptor
     * @return array<string,mixed>
     */
    private function nullVsAbsentPayload(array $descriptor): array
    {
        $payload = $descriptor['base'];
        $nullable = $descriptor['nullable'];
        $payload[$nullable] = null;
        $payload[$nullable.'_absent_probe'] = '__absent__';

        return $payload;
    }

    /**
     * @param  array{
     *     string:string,
     *     integer:string,
     *     array:string,
     *     base:array<string,mixed>
     * }  $descriptor
     * @return array<string,mixed>
     */
    private function typeMismatchPayload(array $descriptor): array
    {
        $payload = $descriptor['base'];
        $payload[$descriptor['string']] = 404;
        $payload[$descriptor['integer']] = 'forty-two';
        $payload[$descriptor['array']] = 7;

        return $payload;
    }

    /**
     * @param  array{
     *     string:string,
     *     max_length:int,
     *     base:array<string,mixed>
     * }  $descriptor
     * @return array<string,mixed>
     */
    private function oversizePayload(int $seed, array $descriptor): array
    {
        $payload = $descriptor['base'];
        $length = $descriptor['max_length'] + (($seed % 2 === 0) ? 1 : 2);
        $payload[$descriptor['string']] = str_repeat('X', $length);
        $payload['_boundary_length'] = $length;

        return $payload;
    }

    /**
     * @param  array{
     *     integer:string,
     *     base:array<string,mixed>
     * }  $descriptor
     * @return array<string,mixed>
     */
    private function numericExtremePayload(array $descriptor): array
    {
        $payload = $descriptor['base'];
        $payload[$descriptor['integer']] = PHP_INT_MAX;
        $payload[$descriptor['integer'].'_zero'] = 0;
        $payload[$descriptor['integer'].'_negative'] = -1;

        return $payload;
    }

    /**
     * @param  array{
     *     string:string,
     *     base:array<string,mixed>
     * }  $descriptor
     * @return array<string,mixed>
     */
    private function unicodeControlPayload(int $seed, array $descriptor): array
    {
        $payload = $descriptor['base'];
        $payload[$descriptor['string']] = sprintf("utf8-seed-%d-\u{2603}\n\t\u{0007}", $seed);

        return $payload;
    }

    /**
     * @param  array{
     *     recursion:string,
     *     base:array<string,mixed>
     * }  $descriptor
     * @return array<string,mixed>
     */
    private function recursionLimitPayload(int $seed, array $descriptor): array
    {
        $payload = $descriptor['base'];
        $depth = 4 + ($seed % 2);
        $payload[$descriptor['recursion']] = $this->nestedProbe($depth);

        return $payload;
    }

    /**
     * @param  array{
     *     additional:string,
     *     object:string,
     *     base:array<string,mixed>
     * }  $descriptor
     * @return array<string,mixed>
     */
    private function additionalPropertiesPayload(int $seed, array $descriptor): array
    {
        $payload = $descriptor['base'];
        $payload[$descriptor['additional']] = 'rogue-'.$seed;
        $payload[$descriptor['object']] = array_replace(
            is_array($payload[$descriptor['object']] ?? null) ? $payload[$descriptor['object']] : [],
            ['unexpected_nested' => true, 'duplicate_key' => 'collapsed-final'],
        );

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function nestedProbe(int $depth): array
    {
        $node = ['depth' => 0, 'terminal' => true];
        for ($level = 1; $level <= $depth; $level++) {
            $node = [
                'depth' => $level,
                'child' => $node,
            ];
        }

        return $node;
    }
}
