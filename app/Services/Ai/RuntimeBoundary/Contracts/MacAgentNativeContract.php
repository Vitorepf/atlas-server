<?php

declare(strict_types=1);

namespace App\Services\Ai\RuntimeBoundary\Contracts;

use App\Services\Ai\RuntimeBoundary\FutureRuntimeInvocationContract;

/**
 * Mac Agent native — PHP-side runtime invocation contract.
 *
 * Block: mac_agent_native · Runtime: swift_native_mac
 * Per `atlas-native-mac-agent.md`.
 */
final class MacAgentNativeContract extends FutureRuntimeInvocationContract
{
    protected function blockId(): string
    {
        return 'mac_agent_native';
    }

    protected function targetRuntime(): string
    {
        return 'swift_native_mac';
    }

    protected function blockValidation(array $payload): array
    {
        $errors = [];
        $capability = $payload['capability'] ?? null;
        if (! in_array($capability, ['power_helper', 'wake_detection', 'background_check', 'voice_edge', 'apple_context'], true)) {
            $errors[] = 'capability must be one of [power_helper, wake_detection, background_check, voice_edge, apple_context]';
        }
        if (($payload['governed_by_decision_receipt'] ?? null) !== true) {
            $errors[] = 'governed_by_decision_receipt must be true (Laravel kernel decides; Swift edge executes)';
        }
        if (($payload['voice_runtime_first_surface'] ?? null) === true) {
            $errors[] = 'mac agent must NOT be the first voice surface (mobile-first canon)';
        }

        return $errors;
    }
}
