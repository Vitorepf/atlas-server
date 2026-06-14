<?php

declare(strict_types=1);

namespace App\Services\Ai\WorkspaceIntelligence;

final class ExecutionGateBlockerCollector
{
    /**
     * Minimum certified artifacts required before mutative execution is allowed.
     */
    private const MIN_ARTIFACTS = 10;

    /**
     * Collect execution-gate blockers from a workspace contracts payload.
     *
     * Each rule appends its blocker string in the documented order whenever the
     * corresponding readiness signal fails. Missing keys default to the failing
     * case (booleans default to false, artifact_count defaults to 0), which keeps
     * the gate conservative: absence of evidence is treated as a blocker.
     *
     * @param  array<string, mixed>  $contracts
     * @return list<string>
     */
    public function collect(array $contracts): array
    {
        $runtimeReady = ($contracts['runtime_ready'] ?? false) === true;
        $contractsCertified = ($contracts['contracts_certified'] ?? false) === true;
        $artifactCount = $this->intValue($contracts, 'artifact_count');
        $shadowReady = ($contracts['shadow_ready'] ?? false) === true;
        $brainReady = ($contracts['brain_ready'] ?? false) === true;

        $blockers = [];

        if (! $runtimeReady) {
            $blockers[] = 'workspace_not_ready';
        }

        if (! $contractsCertified) {
            $blockers[] = 'workspace_contracts_not_certified';
        }

        if ($artifactCount < self::MIN_ARTIFACTS) {
            $blockers[] = 'workspace_artifacts_incomplete';
        }

        if (! $shadowReady) {
            $blockers[] = 'artifact_shadow_execution_blocked';
        }

        if (! $brainReady) {
            $blockers[] = 'workspace_next_session_brain_not_ready';
        }

        return array_values($blockers);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function intValue(array $payload, string $key): int
    {
        $value = $payload[$key] ?? 0;

        if (is_string($value) && preg_match('/^\s*[+-]?\d+\s*$/', $value) !== 1) {
            return 0;
        }

        if (is_float($value) && floor($value) !== $value) {
            return 0;
        }

        return is_int($value) ? $value : (int) $value;
    }
}
