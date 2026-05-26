<?php

declare(strict_types=1);

namespace App\Services\Ai\Scheduling\Governance;

/**
 * Scheduler / background jobs — Stop Condition Gate.
 *
 * Closes the gap declared in `implemented-vs-scaffold-matrix.md` for the
 * Scheduler block:
 *
 *   "Jobs autonomos precisam stop conditions, evidence e proposal gates
 *    por fluxo."
 *
 * Every recurring job MUST consult this gate before each run. The gate
 * returns may_run=true ONLY when all canonical stop conditions are
 * satisfied. Provider-safe: emits aggregate counts/hashes, never job
 * payload content.
 */
final class ScheduledJobStopConditionGate
{
    public const SCHEMA_VERSION = 'atlas.scheduling.stop_condition_gate.v1';

    public const STOP_REASON_BUDGET_EXHAUSTED = 'budget_exhausted';

    public const STOP_REASON_CONSECUTIVE_FAILURES = 'consecutive_failures_threshold';

    public const STOP_REASON_DRIFT_SIGNATURE_REPEATED = 'drift_signature_repeated';

    public const STOP_REASON_PROPOSAL_PENDING = 'proposal_pending_review';

    public const STOP_REASON_OPERATOR_PAUSED = 'operator_paused_via_flag';

    public const STOP_REASON_PROVIDER_UNAVAILABLE = 'provider_dependency_unavailable';

    /**
     * @param  array{
     *   job_id?: string,
     *   runs_today?: int,
     *   daily_budget?: int,
     *   consecutive_failures?: int,
     *   failure_threshold?: int,
     *   last_failure_signature?: ?string,
     *   recent_signatures?: list<string>,
     *   pending_proposals?: int,
     *   operator_pause_flag?: bool,
     *   provider_available?: bool
     * }  $context
     * @return array{
     *   schema_version: string,
     *   may_run: bool,
     *   job_id: ?string,
     *   evaluated_at: string,
     *   stop_reasons: list<string>,
     *   checks: array<string,array{passed: bool, observed: mixed, limit: mixed}>,
     *   detail: string
     * }
     */
    public function evaluate(array $context): array
    {
        $budgetCheck = $this->checkBudget($context);
        $failureCheck = $this->checkConsecutiveFailures($context);
        $driftCheck = $this->checkDriftSignature($context);
        $proposalCheck = $this->checkProposalPending($context);
        $pauseCheck = $this->checkOperatorPause($context);
        $providerCheck = $this->checkProviderAvailable($context);

        $stops = [];
        if (! $budgetCheck['passed']) {
            $stops[] = self::STOP_REASON_BUDGET_EXHAUSTED;
        }
        if (! $failureCheck['passed']) {
            $stops[] = self::STOP_REASON_CONSECUTIVE_FAILURES;
        }
        if (! $driftCheck['passed']) {
            $stops[] = self::STOP_REASON_DRIFT_SIGNATURE_REPEATED;
        }
        if (! $proposalCheck['passed']) {
            $stops[] = self::STOP_REASON_PROPOSAL_PENDING;
        }
        if (! $pauseCheck['passed']) {
            $stops[] = self::STOP_REASON_OPERATOR_PAUSED;
        }
        if (! $providerCheck['passed']) {
            $stops[] = self::STOP_REASON_PROVIDER_UNAVAILABLE;
        }

        $mayRun = $stops === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'may_run' => $mayRun,
            'job_id' => isset($context['job_id']) && is_string($context['job_id']) ? $context['job_id'] : null,
            'evaluated_at' => now()->toAtomString(),
            'stop_reasons' => $stops,
            'checks' => [
                'daily_budget' => $budgetCheck,
                'consecutive_failures' => $failureCheck,
                'drift_signature' => $driftCheck,
                'proposal_pending' => $proposalCheck,
                'operator_pause' => $pauseCheck,
                'provider_available' => $providerCheck,
            ],
            'detail' => $mayRun
                ? 'All stop conditions clear; job may run.'
                : 'Blocked: '.implode(', ', $stops),
        ];
    }

    private function checkBudget(array $context): array
    {
        $runs = (int) ($context['runs_today'] ?? 0);
        $budget = (int) ($context['daily_budget'] ?? 24);

        return [
            'passed' => $runs < $budget,
            'observed' => $runs,
            'limit' => $budget,
        ];
    }

    private function checkConsecutiveFailures(array $context): array
    {
        $failures = (int) ($context['consecutive_failures'] ?? 0);
        $threshold = (int) ($context['failure_threshold'] ?? 3);

        return [
            'passed' => $failures < $threshold,
            'observed' => $failures,
            'limit' => $threshold,
        ];
    }

    private function checkDriftSignature(array $context): array
    {
        $last = $context['last_failure_signature'] ?? null;
        $recent = (array) ($context['recent_signatures'] ?? []);
        if (! is_string($last) || $last === '') {
            return ['passed' => true, 'observed' => null, 'limit' => 'unique'];
        }
        $repeats = count(array_filter($recent, static fn ($sig): bool => $sig === $last));

        return [
            'passed' => $repeats < 3,
            'observed' => $repeats,
            'limit' => 3,
        ];
    }

    private function checkProposalPending(array $context): array
    {
        $pending = (int) ($context['pending_proposals'] ?? 0);

        return [
            'passed' => $pending === 0,
            'observed' => $pending,
            'limit' => 0,
        ];
    }

    private function checkOperatorPause(array $context): array
    {
        $paused = (bool) ($context['operator_pause_flag'] ?? false);

        return [
            'passed' => ! $paused,
            'observed' => $paused,
            'limit' => false,
        ];
    }

    private function checkProviderAvailable(array $context): array
    {
        // Default to available when not declared — caller must explicitly
        // pass `provider_available => false` to block on this condition.
        $available = ! array_key_exists('provider_available', $context)
            || (bool) $context['provider_available'];

        return [
            'passed' => $available,
            'observed' => $available,
            'limit' => true,
        ];
    }
}
