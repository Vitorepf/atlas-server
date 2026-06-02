<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

final class LocalPrereasoningEligibilityClassifier
{
    private const SCHEMA_VERSION = 'atlas.token_economy.local_prereasoning_eligibility.v1';

    private const LOCALLY_RESOLVABLE_TASK_TYPES = ['count', 'diff', 'parse', 'classify', 'validate'];

    private const ALLOWED_OPERATIONS = ['diff', 'count', 'parse', 'validate', 'hash'];

    private const SAVED_TOKENS_FLOOR = 500;

    /**
     * Classify whether a task can be resolved with local prereasoning,
     * avoiding a provider call.
     *
     * Mirrors AtlasTokenEconomyRuntimeService::localPrereasoning.
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
        // Mirror AtlasTokenEconomyRuntimeService::localPrereasoning byte-for-byte:
        // the source lowercases the raw task type WITHOUT trimming, so a
        // whitespace-padded keyword (e.g. " count ") stays unrecognised and is
        // not avoidable, honouring R5 (whitespace -> not avoidable / fail-closed).
        $normalized = strtolower($taskType);

        if ($normalized === '') {
            $normalized = 'general';
        }

        $canResolveLocally = in_array($normalized, self::LOCALLY_RESOLVABLE_TASK_TYPES, true);

        $savedTokensEstimate = $canResolveLocally
            ? max(self::SAVED_TOKENS_FLOOR, $estimatedTokens)
            : 0;

        $reason = $canResolveLocally
            ? 'local_prereasoning_can_resolve'
            : 'requires_provider_reasoning';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'can_resolve_locally' => $canResolveLocally,
            'provider_call_avoidable' => $canResolveLocally,
            'allowed_operations' => self::ALLOWED_OPERATIONS,
            'saved_tokens_estimate' => $savedTokensEstimate,
            'reason' => $reason,
        ];
    }
}
