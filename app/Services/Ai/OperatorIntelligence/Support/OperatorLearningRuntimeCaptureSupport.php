<?php

declare(strict_types=1);

namespace App\Services\Ai\OperatorIntelligence\Support;

/**
 * Pure intake helpers for operator-learning runtime capture (full-pass peel).
 */
final class OperatorLearningRuntimeCaptureSupport
{
    /**
     * Prefer payload.operator_text (what the operator typed) over surface-prefixed input_text.
     *
     * @param  array<string,mixed>  $options
     */
    public static function operatorWords(string $input, array $options): string
    {
        $written = data_get($options, 'payload.operator_text');

        return is_string($written) && trim($written) !== '' ? trim($written) : $input;
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public static function resolveOperatorId(array $options, string $default = 'default'): string
    {
        $operatorId = data_get($options, 'payload.operator_id')
            ?: data_get($options, 'payload.operator.id')
            ?: data_get($options, 'operator_id')
            ?: $default;

        return is_string($operatorId) && trim($operatorId) !== ''
            ? trim($operatorId)
            : $default;
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    public static function receipt(array $result): array
    {
        return [
            'schema_version' => 'atlas.operator_learning_runtime_capture.v1',
            'status' => 'captured',
            'signal_id' => data_get($result, 'signal.id'),
            'candidate_id' => data_get($result, 'candidate.id'),
            'candidate_status' => data_get($result, 'candidate.status'),
            'taxonomy_item_id' => data_get($result, 'signal.taxonomy_item_id'),
            'automation' => data_get($result, 'automation'),
        ];
    }

    /**
     * Pure gate on source type (no config/DB).
     *
     * @param  list<string>  $allowedSourceTypes
     * @return array{available:bool,reason:string|null,missing_tables:list<string>}
     */
    public static function sourceTypeGate(string $sourceType, array $allowedSourceTypes): array
    {
        if (! in_array($sourceType, $allowedSourceTypes, true)) {
            return ['available' => false, 'reason' => 'source_type_not_allowed', 'missing_tables' => []];
        }

        return ['available' => true, 'reason' => null, 'missing_tables' => []];
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public static function scopeIdFromOptions(array $options, ?string $threadId, string $scopeType): ?string
    {
        if ($scopeType === 'global') {
            return null;
        }

        $workspace = data_get($options, 'payload.workspace');
        if (is_string($workspace) && $workspace !== '') {
            return $workspace;
        }

        $projectId = data_get($options, 'payload.project_id');
        if (is_string($projectId) && $projectId !== '') {
            return $projectId;
        }

        return $threadId !== null && $threadId !== '' ? $threadId : null;
    }
}
