<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfImprovement\Support;

/**
 * Pure ledger projection helpers for self-improvement runtime (full-pass peel).
 */
final class SelfImprovementProjectionSupport
{
    /**
     * @param  array<string, mixed>  $finding
     * @return array<string, mixed>
     */
    public static function ledgerFindingProjection(array $finding): array
    {
        $metadata = (array) ($finding['metadata'] ?? []);

        return [
            'title' => $finding['title'] ?? null,
            'category' => $finding['category'] ?? null,
            'dedupe_key' => $finding['dedupe_key'] ?? null,
            'confidence' => $finding['confidence'] ?? null,
            'source_ref_count' => count((array) ($finding['source_refs'] ?? [])),
            'schema_version' => $metadata['schema_version'] ?? null,
            'review_signal' => (array) ($metadata['review_signal'] ?? []),
            'source_types' => collect((array) ($finding['source_refs'] ?? []))
                ->map(fn (array $source): ?string => is_string($source['type'] ?? null) ? $source['type'] : null)
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $health
     * @return array<string, mixed>
     */
    public static function scheduleHealthLedgerProjection(array $health): array
    {
        return [
            'schema_version' => $health['schema_version'] ?? null,
            'status' => $health['status'] ?? null,
            'health_status' => data_get($health, 'health.status'),
            'issues' => array_values((array) data_get($health, 'health.issues', [])),
            'enabled' => (bool) ($health['enabled'] ?? false),
            'schedulable' => (bool) ($health['schedulable'] ?? false),
            'scheduler_registration' => (array) ($health['scheduler_registration'] ?? []),
            'flow_count' => (int) ($health['flow_count'] ?? 0),
            'cadence_counts' => (array) ($health['cadence_counts'] ?? []),
            'invalid_flow_count' => (int) ($health['invalid_flow_count'] ?? 0),
            'defaulted' => (bool) ($health['defaulted'] ?? false),
            'emit' => (bool) ($health['emit'] ?? false),
            'plan_hash' => $health['plan_hash'] ?? null,
            'plan_hash_algorithm' => $health['plan_hash_algorithm'] ?? null,
            'time' => $health['time'] ?? null,
            'timezone' => $health['timezone'] ?? null,
            'next_run_at' => $health['next_run_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $operationsById
     * @param  array<string, string>  $expectedEndpoints
     * @return array<string, array{field: string, expected: string, actual: mixed}>
     */
    public static function missingArchitectureOperationEndpoints(
        array $operationsById,
        array $expectedEndpoints,
        string $field,
    ): array {
        $missing = [];

        foreach ($expectedEndpoints as $operationId => $expectedEndpoint) {
            $actualEndpoint = data_get($operationsById, $operationId.'.'.$field);

            if ($actualEndpoint !== $expectedEndpoint) {
                $missing[$operationId] = [
                    'field' => $field,
                    'expected' => $expectedEndpoint,
                    'actual' => $actualEndpoint,
                ];
            }
        }

        return $missing;
    }
}
