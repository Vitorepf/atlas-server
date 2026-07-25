<?php

declare(strict_types=1);

namespace App\Services\Ai\AiWorkerSupport;

/**
 * Pure job-kind / dry-run / privacy predicates for AiWorker (full-pass peel).
 * No DI, no models — callers pass already-extracted scalars/arrays.
 */
final class AiWorkerJobPredicatesSupport
{
    /**
     * Provider never left the process for these decision-receipt / policy short-circuits.
     *
     * @var list<string>
     */
    public const PROVIDER_NOT_CALLED_ERROR_CODES = [
        'decision_receipt_dry_run',
        'decision_receipt_expired',
        'decision_receipt_hash_mismatch',
        'decision_receipt_invalid',
        'decision_receipt_model_mismatch',
        'decision_receipt_provider_mismatch',
        'decision_receipt_v3_invalid',
        'decision_receipt_v3_hash_mismatch',
        'decision_receipt_v3_non_authoritative',
        'decision_receipt_v3_authority_signature_mismatch',
        'decision_receipt_v3_authority_context_unavailable',
        'decision_receipt_v2_v3_shadow_contradiction',
        'decision_receipt_transport_copy_invalid',
        'decision_receipt_transport_copy_contradiction',
        'decision_receipt_legacy_nested_v3_transport_refused',
        'decision_receipt_cutover_v2_only_refused',
        'decision_receipt_unknown_version',
        'permission_denied',
        'policy_violation',
    ];

    public static function providerWasCalled(?string $errorCode): bool
    {
        return ! in_array($errorCode, self::PROVIDER_NOT_CALLED_ERROR_CODES, true);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $metadata
     */
    public static function isCouncilJob(string $kind, array $payload = [], array $metadata = []): bool
    {
        return $kind === 'council'
            || data_get($payload, 'execution_policy') === 'dual_review'
            || data_get($metadata, 'execution_policy') === 'dual_review';
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $payload
     */
    public static function isAtlasScoutJob(array $metadata = [], array $payload = []): bool
    {
        return data_get($metadata, 'atlas_decide_stage') === 'context_scout'
            || data_get($payload, 'atlas_decide_execution.atlas_decide_stage') === 'context_scout';
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    public static function isAtlasPrimaryExecutorJob(array $metadata = []): bool
    {
        return data_get($metadata, 'atlas_decide_stage') === 'primary_executor'
            && data_get($metadata, 'dependency_state') === 'pending'
            && is_string(data_get($metadata, 'dependency_job_id'));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    public static function privacyFromJob(array $payload = [], array $metadata = []): array
    {
        $privacy = data_get($payload, 'privacy', data_get($metadata, 'privacy'));

        return is_array($privacy) ? $privacy : [];
    }
}
