<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

final class LocalPrereasoningEligibilityClassifier
{
    private const SCHEMA_VERSION = 'atlas.token_economy.local_prereasoning_eligibility.v1';

    /**
     * Classify whether a task can be resolved with local prereasoning,
     * avoiding a provider call.
     *
     * Schema wrapper around the shared local prereasoning policy.
     *
     * @return array{
     *     schema_version: string,
     *     can_resolve_locally: bool,
     *     provider_call_avoidable: bool,
     *     allowed_operations: list<string>,
     *     saved_tokens_estimate: int,
     *     reason: string
     * }
     */
    public function classify(string $taskType, int $estimatedTokens): array
    {
        $policy = LocalPrereasoningPolicy::classify($taskType, $estimatedTokens);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'can_resolve_locally' => $policy['can_resolve_locally'],
            'provider_call_avoidable' => $policy['provider_call_avoidable'],
            'allowed_operations' => $policy['allowed_operations'],
            'saved_tokens_estimate' => $policy['saved_tokens_estimate'],
            'reason' => $policy['reason'],
        ];
    }
}
