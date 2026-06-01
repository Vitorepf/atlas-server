<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Sdd;

/**
 * Pure precedence classifier for the canonical SDD output state.
 *
 * Resolves the single verdict via the doctrine precedence ladder
 * policy > clarification > spike > ready, applying severity thresholding so
 * only high-severity blocking issues can demand clarification. Zero
 * dependencies, no I/O: every returned field is computed from the inputs.
 */
final class SpecOutputStateClassifier
{
    private const SCHEMA_VERSION = 'atlas.sdd_output_state_classification.v1';

    private const BLOCKING_AMBIGUITY = 'blocking_ambiguity';

    private const EXPLORATION = 'exploration';

    private const POLICY_DOMAINS = ['security', 'design', 'architecture'];

    /**
     * @param  list<array{field?: string, severity?: string, reason?: string}>  $issues
     * @param  string  $confidenceClass  one of high|medium|low|blocking_ambiguity
     * @param  list<array{domain?: string, reason?: string}>  $policyConflicts
     * @param  string|null  $intentKind  optional exploration|spike|implementation
     * @return array{
     *     schema_version: string,
     *     state: string,
     *     dominant_reason: string,
     *     plan_permitted: bool,
     *     precedence_rank: int,
     *     signals: array{
     *         policy_conflict_count: int,
     *         blocking_issue_count: int,
     *         is_blocking_ambiguity: bool,
     *         is_exploration_only: bool,
     *         core_unresolved: bool
     *     }
     * }
     */
    public function classify(
        array $issues,
        string $confidenceClass,
        array $policyConflicts,
        ?string $intentKind = null,
    ): array {
        $policyHits = $this->policyHits($policyConflicts);
        $blockingIssues = $this->blockingIssues($issues);

        $policyConflictCount = count($policyHits);
        $blockingIssueCount = count($blockingIssues);

        $isBlockingAmbiguity = $confidenceClass === self::BLOCKING_AMBIGUITY;
        $isExplorationOnly = $intentKind === self::EXPLORATION;

        if ($policyConflictCount > 0) {
            $state = 'blocked_by_policy';
            $dominantReason = 'policy_conflict:'.$this->describePolicyHit($policyHits[0]);
            $planPermitted = false;
            $precedenceRank = 1;
        } elseif ($blockingIssueCount > 0) {
            $state = 'needs_clarification';
            $dominantReason = 'clarification_required:'.$this->describeBlockingIssue($blockingIssues[0]);
            $planPermitted = false;
            $precedenceRank = 2;
        } elseif ($isExplorationOnly && $isBlockingAmbiguity) {
            $state = 'spike_only';
            $dominantReason = 'spike_only:exploration_under_blocking_ambiguity';
            $planPermitted = false;
            $precedenceRank = 3;
        } else {
            $state = 'ready_for_plan';
            $dominantReason = 'ready_for_plan:no_blocking_signal';
            $planPermitted = true;
            $precedenceRank = 4;
        }

        $coreUnresolved = $policyConflictCount > 0 || $blockingIssueCount > 0;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'state' => $state,
            'dominant_reason' => $dominantReason,
            'plan_permitted' => $planPermitted,
            'precedence_rank' => $precedenceRank,
            'signals' => [
                'policy_conflict_count' => $policyConflictCount,
                'blocking_issue_count' => $blockingIssueCount,
                'is_blocking_ambiguity' => $isBlockingAmbiguity,
                'is_exploration_only' => $isExplorationOnly,
                'core_unresolved' => $coreUnresolved,
            ],
        ];
    }

    /**
     * @param  list<array{domain?: string, reason?: string}>  $policyConflicts
     * @return list<array{domain: string, reason: string}>
     */
    private function policyHits(array $policyConflicts): array
    {
        $hits = [];

        foreach ($policyConflicts as $conflict) {
            if (! is_array($conflict)) {
                continue;
            }

            $domain = $this->stringField($conflict, 'domain');

            if (! in_array($domain, self::POLICY_DOMAINS, true)) {
                continue;
            }

            $hits[] = [
                'domain' => $domain,
                'reason' => $this->stringField($conflict, 'reason'),
            ];
        }

        return $hits;
    }

    /**
     * Severity thresholding: only high-severity blocking issues qualify.
     *
     * @param  list<array{field?: string, severity?: string, reason?: string}>  $issues
     * @return list<array{field: string, severity: string, reason: string}>
     */
    private function blockingIssues(array $issues): array
    {
        $blocking = [];

        foreach ($issues as $issue) {
            if (! is_array($issue)) {
                continue;
            }

            $field = $this->stringField($issue, 'field');
            $severity = $this->stringField($issue, 'severity');
            $reason = $this->stringField($issue, 'reason');

            if ($severity !== 'high') {
                continue;
            }

            $isBlockingAmbiguity = $reason === self::BLOCKING_AMBIGUITY;
            $isMissingTarget = $field === 'target_file' && $reason === 'missing_target';

            if (! $isBlockingAmbiguity && ! $isMissingTarget) {
                continue;
            }

            $blocking[] = [
                'field' => $field,
                'severity' => $severity,
                'reason' => $reason,
            ];
        }

        return $blocking;
    }

    /**
     * @param  array{domain: string, reason: string}  $hit
     */
    private function describePolicyHit(array $hit): string
    {
        $reason = $hit['reason'] !== '' ? $hit['reason'] : 'unspecified';

        return $hit['domain'].':'.$reason;
    }

    /**
     * @param  array{field: string, severity: string, reason: string}  $issue
     */
    private function describeBlockingIssue(array $issue): string
    {
        $field = $issue['field'] !== '' ? $issue['field'] : 'unspecified';

        return $field.':'.$issue['reason'];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stringField(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
