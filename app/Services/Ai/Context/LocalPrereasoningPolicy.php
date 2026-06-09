<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

final class LocalPrereasoningPolicy
{
    private const LOCALLY_RESOLVABLE_TASK_TYPES = ['count', 'diff', 'parse', 'classify', 'validate'];

    private const ALLOWED_OPERATIONS = ['diff', 'count', 'parse', 'validate', 'hash'];

    private const SAVED_TOKENS_FLOOR = 500;

    /**
     * @return array{
     *     task_type: string,
     *     can_resolve_locally: bool,
     *     provider_call_avoidable: bool,
     *     saved_tokens_estimate: int,
     *     allowed_operations: list<string>,
     *     reason: string
     * }
     */
    public static function classify(string $taskType, int $estimatedTokens): array
    {
        $normalized = strtolower($taskType);
        $resolvedTaskType = $normalized === '' ? 'general' : $normalized;
        $canResolveLocally = in_array($normalized, self::LOCALLY_RESOLVABLE_TASK_TYPES, true);

        return [
            'task_type' => $resolvedTaskType,
            'can_resolve_locally' => $canResolveLocally,
            'provider_call_avoidable' => $canResolveLocally,
            'saved_tokens_estimate' => $canResolveLocally
                ? max(self::SAVED_TOKENS_FLOOR, $estimatedTokens)
                : 0,
            'allowed_operations' => self::ALLOWED_OPERATIONS,
            'reason' => $canResolveLocally
                ? 'local_prereasoning_can_resolve'
                : 'requires_provider_reasoning',
        ];
    }
}
