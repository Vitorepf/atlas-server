<?php

namespace App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence;

use App\Models\AtlasDevFailureCapsule;
use App\Models\AtlasDevTaskPacket;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;
use App\Services\Ai\Support\AiValueNormalizer;

class DevFailureCapsuleRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.dev.failure_capsule.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function build(array $input, ?array $packet = null): array
    {
        $runId = (string) ($input['run_id'] ?? $packet['run_id'] ?? 'unknown');
        $taskId = (string) ($input['task_id'] ?? $packet['task_id'] ?? 'unknown');
        $failureClass = $this->classify((string) ($input['failure_class'] ?? ''), (string) ($input['error'] ?? $input['error_excerpt'] ?? ''));

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $runId,
            'task_id' => $taskId,
            'failing_gate' => AiValueNormalizer::trimmedScalarStringOrNull($input['failing_gate'] ?? null) ?? 'unknown',
            'failure_class' => $failureClass,
            'error_excerpt' => $this->truncate((string) ($input['error_excerpt'] ?? $input['error'] ?? ''), 1200),
            'changed_files' => AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($input['changed_files'] ?? []),
            'suggested_repair' => AiValueNormalizer::trimmedScalarStringOrNull($input['suggested_repair'] ?? null) ?? $this->defaultRepair($failureClass),
            'retry_budget' => max(0, min(3, (int) ($input['retry_budget'] ?? 1))),
            'escalate_to_forge' => (bool) ($input['escalate_to_forge'] ?? in_array($failureClass, ['scope_violation', 'architecture_risk'], true)),
        ];
        $payload['failure_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public function persist(array $input, ?AtlasDevTaskPacket $taskPacket = null): AtlasDevFailureCapsule
    {
        $payload = $this->build($input, $taskPacket?->toArray());

        return AtlasDevFailureCapsule::query()->updateOrCreate(
            ['failure_hash' => $payload['failure_hash']],
            [
                'schema_version' => $payload['schema_version'],
                'uuid' => $payload['failure_hash'],
                'run_id' => $payload['run_id'],
                'task_id' => $payload['task_id'],
                'task_packet_id' => $taskPacket?->id,
                'failing_gate' => $payload['failing_gate'],
                'failure_class' => $payload['failure_class'],
                'error_excerpt' => $payload['error_excerpt'],
                'changed_files' => $payload['changed_files'],
                'suggested_repair' => $payload['suggested_repair'],
                'retry_budget' => $payload['retry_budget'],
                'escalate_to_forge' => $payload['escalate_to_forge'],
            ],
        );
    }

    private function classify(string $declared, string $error): string
    {
        if (in_array($declared, ['test_failure', 'type_error', 'scope_violation', 'missing_context', 'architecture_risk', 'unknown'], true)) {
            return $declared;
        }

        $error = strtolower($error);

        return match (true) {
            str_contains($error, 'scope') || str_contains($error, 'forbidden') => 'scope_violation',
            str_contains($error, 'context') || str_contains($error, 'rag') => 'missing_context',
            str_contains($error, 'type') || str_contains($error, 'phpstan') || str_contains($error, 'tsc') => 'type_error',
            str_contains($error, 'architecture') || str_contains($error, 'boundary') => 'architecture_risk',
            str_contains($error, 'failed') || str_contains($error, 'failure') || str_contains($error, 'assert') => 'test_failure',
            default => 'unknown',
        };
    }

    private function defaultRepair(string $failureClass): string
    {
        return match ($failureClass) {
            'scope_violation' => 'reduce patch to allowed files or escalate to Forge with explicit Obra scope',
            'missing_context' => 'rebuild context pack with owner docs and affected files before retry',
            'type_error' => 'fix type/static-analysis failure and rerun the narrow impacted gate',
            'architecture_risk' => 'pause Dev fast path and promote to Forge/Senior review',
            'test_failure' => 'use failure capsule to repair the minimal failing behavior and rerun selected tests',
            default => 'inspect failure capsule, add missing evidence and retry once',
        };
    }

    private function truncate(string $value, int $limit): string
    {
        $value = trim($value);

        return strlen($value) <= $limit ? $value : substr($value, 0, $limit - 3).'...';
    }

}
