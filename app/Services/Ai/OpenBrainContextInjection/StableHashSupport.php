<?php

declare(strict_types=1);

namespace App\Services\Ai\OpenBrainContextInjection;

/**
 * Stable hash projection helpers for Open Brain injection (full-pass peel).
 */
final class StableHashSupport
{
    /**
     * @param  array<string, mixed>  $pack
     * @return array<string, mixed>
     */
    public static function stableContextPackForHash(array $pack): array
    {
        if (is_array($pack['manifest'] ?? null)) {
            unset($pack['manifest']['created_at'], $pack['manifest']['expires_at']);
        }

        return $pack;
    }

    /**
     * @param  array<string, mixed>  $selfReflection
     * @return array<string, mixed>
     */
    public static function stableSelfReflectionForHash(array $selfReflection): array
    {
        unset($selfReflection['assessed_at']);

        return $selfReflection;
    }

    /**
     * @param  array<string, mixed>  $operatorContext
     * @return array<string, mixed>
     */
    public static function stableOperatorContextForHash(array $operatorContext): array
    {
        $operatorIdHash = is_string($operatorContext['operator_id'] ?? null)
            ? hash('sha256', (string) $operatorContext['operator_id'])
            : ($operatorContext['operator_id_hash'] ?? null);

        unset($operatorContext['operator_id']);

        if (is_string($operatorContext['operator_id_hash'] ?? null)) {
            return $operatorContext;
        }

        return $operatorContext + [
            'operator_id_hash' => $operatorIdHash,
        ];
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return array<string, mixed>
     */
    public static function stableContextDeliveryPolicyForHash(array $policy): array
    {
        return [
            'schema_version' => (string) ($policy['schema_version'] ?? ''),
            'source' => (string) ($policy['source'] ?? ''),
            'delivery_mode' => (string) ($policy['delivery_mode'] ?? ''),
            'initial_context_token_budget' => (int) ($policy['initial_context_token_budget'] ?? 0),
            'expansion_token_reserve' => (int) ($policy['expansion_token_reserve'] ?? 0),
            'initial_ref_limit' => (int) ($policy['initial_ref_limit'] ?? 0),
            'initial_source_types' => TextNormalizeSupport::sortedStrings((array) ($policy['initial_source_types'] ?? [])),
            'deferred_source_types' => TextNormalizeSupport::sortedStrings((array) ($policy['deferred_source_types'] ?? [])),
            'guarded_required_source_types' => TextNormalizeSupport::sortedStrings((array) ($policy['guarded_required_source_types'] ?? [])),
            'expansion_triggers' => TextNormalizeSupport::sortedStrings((array) ($policy['expansion_triggers'] ?? [])),
            'quality_gate_hint' => (string) ($policy['quality_gate_hint'] ?? ''),
        ];
    }
}
