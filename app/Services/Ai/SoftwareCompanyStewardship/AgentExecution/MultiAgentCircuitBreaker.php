<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AgentExecution;

use App\Services\Ai\Mission\MissionCanonicalHash;

/** Pure state machine for bounded Workcell retries and candidate competition. */
final class MultiAgentCircuitBreaker
{
    public const SCHEMA = 'atlas.agent_execution.circuit_breaker.v1';

    /** @return array<string,mixed> */
    public function evaluate(array $facts): array
    {
        $failureFingerprint = trim((string) ($facts['failure_fingerprint'] ?? ''));
        $sameFailureCount = max(0, (int) ($facts['same_failure_count'] ?? 0));
        $evidenceDeltaRounds = max(0, (int) ($facts['evidence_delta_rounds'] ?? 0));
        $executionRequested = (bool) ($facts['execution_requested'] ?? false);
        $providerAvailable = (bool) ($facts['provider_available'] ?? true);
        $irreversible = (bool) ($facts['irreversible_requested'] ?? false);
        $ledgerConsistent = ($facts['ledger_consistent'] ?? true) === true;
        $authorityValid = ($facts['authority_valid'] ?? true) === true;
        $frontierImproved = $facts['frontier_improved'] ?? null;
        $candidateId = trim((string) ($facts['candidate_id'] ?? ''));

        $status = 'clear';
        $reason = 'no_breaker_triggered';
        if ($irreversible || ! $ledgerConsistent || ! $authorityValid) {
            $status = 'hard_stop';
            $reason = $irreversible ? 'irreversibility_outside_envelope' : (! $ledgerConsistent ? 'ledger_inconsistent' : 'authority_invalid');
        } elseif ($executionRequested && ! $providerAvailable) {
            $status = 'durable_pause';
            $reason = 'all_eligible_providers_unavailable';
        } elseif ($sameFailureCount >= 3 && $failureFingerprint !== '') {
            $status = 'replan';
            $reason = 'same_failure_fingerprint_three_times';
        } elseif ($evidenceDeltaRounds >= 2) {
            $status = 'change_approach';
            $reason = 'two_rounds_without_evidence_delta';
        } elseif ($candidateId !== '' && $frontierImproved === false) {
            $status = 'end_candidate_line';
            $reason = 'candidate_no_frontier_improvement';
        }

        $decision = [
            'schema' => self::SCHEMA,
            'status' => $status,
            'triggered' => $status !== 'clear',
            'terminal_for_current_attempt' => $status !== 'clear',
            'reason' => $reason,
            'failure_fingerprint' => $failureFingerprint !== '' ? $failureFingerprint : null,
            'same_failure_count' => $sameFailureCount,
            'evidence_delta_rounds' => $evidenceDeltaRounds,
            'candidate_id' => $candidateId !== '' ? $candidateId : null,
            'next_action' => match ($status) {
                'replan' => 'replan_slice_or_failure_hypothesis',
                'change_approach' => 'select_distinct_approach_and_rejudge',
                'durable_pause' => 'persist_pause_and_resume_when_provider_returns',
                'hard_stop' => 'quarantine_artifact_and_require_authorized_recovery',
                'end_candidate_line' => 'end_candidate_without_ending_mission',
                default => 'continue_bounded_workcell',
            },
        ];
        $decision['decision_hash'] = MissionCanonicalHash::sha256($decision);

        return $decision;
    }
}
