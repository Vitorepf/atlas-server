<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Support;

/**
 * Shared response envelope for AgentAutomaticDispatchScheduler OneShotTick invokers.
 *
 * Full-pass density: near-identical invokers all return a status + invoked flags +
 * result projection + fixed deny flags (no process/provider/dispatch until a later
 * release slice). Keep behavior byte-stable for readiness contracts.
 */
final class OneShotTickInvokerEnvelope
{
    /**
     * Fixed deny flags shared by one-shot scheduler tick invokers that prepare
     * work without allowing live process/provider/dispatch side effects.
     *
     * @var array<string, bool>
     */
    public const DENY_FLAGS = [
        'external_process_started' => false,
        'provider_started' => false,
        'adapter_execution_allowed' => false,
        'token_spend_allowed' => false,
        'self_programming_allowed' => false,
        'dispatch_allowed' => false,
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function withDenyFlags(array $payload, string $nextRequiredSlice): array
    {
        return array_merge($payload, self::DENY_FLAGS, [
            'next_required_slice' => $nextRequiredSlice,
        ]);
    }

    /**
     * Build a prepared invoker payload: status, capability_invoked/count/result,
     * projected result keys, deny flags, next slice.
     *
     * @param  list<string>  $resultKeys  Keys projected via data_get($result, key)
     * @param  array<string, mixed>  $extra  Extra top-level fields (win over projections)
     * @return array<string, mixed>
     */
    public static function prepared(
        string $status,
        string $capabilitySnake,
        array $result,
        string $nextRequiredSlice,
        array $resultKeys = [],
        array $extra = [],
    ): array {
        $payload = [
            'status' => $status,
            $capabilitySnake.'_invoked' => true,
            $capabilitySnake.'_invocation_count' => 1,
            $capabilitySnake.'_result' => $result,
        ];

        foreach ($resultKeys as $key) {
            $payload[$key] = data_get($result, $key);
        }

        foreach ($extra as $key => $value) {
            $payload[$key] = $value;
        }

        return self::withDenyFlags($payload, $nextRequiredSlice);
    }
}
