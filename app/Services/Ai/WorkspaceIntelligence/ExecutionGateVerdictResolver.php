<?php

declare(strict_types=1);

namespace App\Services\Ai\WorkspaceIntelligence;

final class ExecutionGateVerdictResolver
{
    /**
     * Resolve the execution-gate verdict from the mutative intent, the
     * outstanding blockers and the runtime readiness flag.
     *
     * Rules (mirrors AtlasWorkspaceIntelligenceExecutionGateService:80):
     *  1. Mutative requests are allowed only when there are no blockers.
     *  2. Non-mutative (conversation) requests are always allowed.
     *  3. A disallowed verdict reports status 'blocked'.
     *  4. An allowed verdict reports 'ready' when the runtime is ready,
     *     otherwise 'limited'.
     *
     * @param  list<string>  $blockers
     * @return array{allowed: bool, status: string}
     */
    public function resolve(bool $mutative, array $blockers, bool $runtimeReady): array
    {
        $allowed = $mutative ? $blockers === [] : true;

        $status = ! $allowed
            ? 'blocked'
            : ($runtimeReady ? 'ready' : 'limited');

        return [
            'allowed' => $allowed,
            'status' => $status,
        ];
    }
}
