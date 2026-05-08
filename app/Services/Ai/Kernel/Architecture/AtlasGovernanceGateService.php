<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasGovernanceGateService
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function strictBlocked(array $payload, bool $strict): bool
    {
        return $strict && ($payload['gate_status'] ?? null) === 'blocked';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function cliExitCode(array $payload, bool $strict): int
    {
        return $this->strictBlocked($payload, $strict) ? 1 : 0;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function httpStatus(array $payload, bool $strict): int
    {
        return $this->strictBlocked($payload, $strict) ? 409 : 200;
    }

    public function mcpError(string $tool): string
    {
        return match ($tool) {
            'atlas_session_bootstrap' => 'session_bootstrap_blocked_by_strict_gate',
            'atlas_feature_placement' => 'feature_placement_blocked_by_strict_gate',
            default => 'governance_blocked_by_strict_gate',
        };
    }
}
