<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

use App\Models\AiJob;
use App\Services\Ai\AiProviderResult;

/**
 * Atlas Swarm Auto-Failover Service — Patamar 4 A4.
 *
 * When the F2 production resolver flag is ON and an AiWorker job's primary
 * provider returns ok=false, this service fires a swarm dispatch (K=2:
 * recommended + runner-up) via `AtlasSwarmConductorService` and executes it
 * via `AtlasSwarmExecutorService` (which is wired to the production
 * resolver). The winning arm's outcome is translated back into an
 * `AiProviderResult` so the caller can substitute it for the original
 * failure — closing the loop end-to-end.
 *
 * The service is provider-safe: no benchmark/rivals/superiority claims.
 * Every failover envelope is recorded by the executor's existing JSONL.
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-swarm-auto-failover.md
 */
final class AtlasSwarmAutoFailoverService
{
    public const FAIL_FLAG = 'atlas.patamar4.swarm_production_resolver_enabled';

    public const TRIGGER_FLAG = 'atlas.patamar4.swarm_auto_failover_enabled';

    /** @var \Closure(array<string,mixed>): array<string,mixed>|null */
    private ?\Closure $dispatchResolver = null;

    public function __construct(
        private readonly AtlasSwarmConductorService $conductor,
        private readonly AtlasSwarmExecutorService $executor,
    ) {}

    /**
     * Override the dispatch resolver — used by unit tests to bypass the
     * final AtlasSwarmConductorService class. Default behaviour delegates
     * to `$this->conductor->dispatch($work)`.
     */
    public function setDispatchResolverForTesting(?\Closure $resolver): void
    {
        $this->dispatchResolver = $resolver;
    }

    /**
     * Observe a primary provider result and, when conditions are met,
     * synthesize an alternate AiProviderResult via swarm executor.
     *
     * Returns null to mean "no failover performed — keep original result".
     */
    public function observeProviderFailure(AiJob $job, AiProviderResult $result): ?AiProviderResult
    {
        if ($result->ok) {
            return null;
        }
        // Both flags must be true. The trigger flag is independent so the
        // operator can keep the production resolver active for explicit
        // swarm calls while disabling auto-failover.
        if (! (bool) config(self::TRIGGER_FLAG, false)) {
            return null;
        }
        if (! (bool) config(self::FAIL_FLAG, false)) {
            return null;
        }

        $work = [
            'task_category' => (string) ($job->kind ?? 'unspecified'),
            'role' => (string) ($job->agent_slug ?? 'primary'),
            'framework' => null,
            'privacy_class' => 'normal',
        ];
        try {
            $dispatch = $this->dispatchResolver !== null
                ? ($this->dispatchResolver)($work)
                : $this->conductor->dispatch($work);
        } catch (\Throwable $e) {
            return null;
        }

        if (empty($dispatch['arms'])) {
            return null;
        }

        try {
            $envelope = $this->executor->execute($dispatch, [
                'task_category' => (string) ($job->kind ?? 'unspecified'),
                'role' => (string) ($job->agent_slug ?? 'primary'),
                'framework' => null,
                'privacy_class' => 'normal',
                'input' => (string) ($job->prompt ?? $job->input_text ?? ''),
            ]);
        } catch (\Throwable $e) {
            return null;
        }

        $winnerArmId = (string) ($envelope['winner']['arm_id'] ?? '');
        if ($winnerArmId === '') {
            return null;
        }
        $winnerOutcome = null;
        foreach (($envelope['outcomes'] ?? []) as $outcome) {
            if (($outcome['arm_id'] ?? '') === $winnerArmId) {
                $winnerOutcome = $outcome;
                break;
            }
        }
        if ($winnerOutcome === null) {
            return null;
        }

        return new AiProviderResult(
            ok: ($winnerOutcome['result'] ?? '') === AtlasSwarmExecutorService::STATUS_SUCCESS,
            output: 'swarm_failover_winner_arm:'.$winnerArmId,
            command: [],
            exitCode: 0,
            durationMs: (int) ($winnerOutcome['latency_ms'] ?? 0),
            stdout: '',
            stderr: '',
            errorCode: ($winnerOutcome['result'] ?? '') === AtlasSwarmExecutorService::STATUS_SUCCESS ? null : 'swarm_failover_no_success',
            errorMessage: null,
            metadata: [
                'swarm_failover' => true,
                'original_error_code' => $result->errorCode,
                'winner_arm_id' => $winnerArmId,
                'winner_provider' => $winnerOutcome['provider'] ?? null,
                'winner_model' => $winnerOutcome['model'] ?? null,
                'winner_quality_score' => $winnerOutcome['quality_score'] ?? null,
                'envelope_hash' => $envelope['execution_hash'] ?? null,
                'arm_count' => (int) ($envelope['arm_count'] ?? 0),
            ],
        );
    }

    /**
     * @return array<string,bool>
     */
    public function claimPolicy(): array
    {
        return [
            'benchmark_claim_allowed' => false,
            'rivals_claim_allowed' => false,
            'superiority_claim_allowed' => false,
            'external_rivals_certification_touched' => false,
            'cognitive_immune_law_enforced' => true,
            'provider_safe_only_enforced' => true,
        ];
    }
}
