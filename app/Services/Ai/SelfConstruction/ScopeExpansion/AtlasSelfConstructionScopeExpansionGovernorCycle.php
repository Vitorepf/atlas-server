<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ScopeExpansion;

use Throwable;

/**
 * Bounded orchestration shell for ONE Atlas-native scope-expansion tick.
 *
 * Composes (always, pure):
 *   1. {@see AtlasSelfConstructionScopeExpansionCandidateRanker}::rank — rejects evidence-free
 *      / human-/operator-/provider-dependent / scalar-only candidates.
 *   2. {@see AtlasSelfConstructionScopeExpansionReadinessGate}::evaluate per accepted candidate.
 *   3. {@see AtlasSelfConstructionScopeExpansionLaneAdmissionPlan}::plan per ready readiness.
 *
 * In APPLY mode (only when `options.apply === true`), and only for plans whose status is `ready`,
 * the cycle may invoke INJECTED Atlas-native callbacks (`options.action_callbacks`). Forbidden
 * action kinds (operator/human/external_provider/git/subprocess/file_mutation) are withheld with
 * an explicit reason — they NEVER fire even when a callback exists. Callback failures are isolated
 * into `blocked_actions` and never abort the cycle.
 *
 * Output: schema_version, status, dry_run, ranked_candidates, readiness, admission_plans,
 * admitted_count, withheld_count, applied_actions, blocked_actions, withheld_actions, receipts,
 * governor_cycle_hash.
 */
final class AtlasSelfConstructionScopeExpansionGovernorCycle
{
    public const SCHEMA = 'atlas.self_construction.scope_expansion_governor_cycle.v1';

    /** Action kinds that may NEVER fire in apply mode, even with an injected callback. */
    public const FORBIDDEN_ACTION_KINDS = [
        'operator_action',
        'human_action',
        'external_provider_call',
        'git',
        'subprocess',
        'file_mutation',
    ];

    /** @var \Closure(array,array,array):array<string,mixed> */
    private \Closure $admissionPlanFn;

    /**
     * @param  (\Closure(array,array,array):array<string,mixed>)|null  $admissionPlanOverride  test hook (e.g. inject pre-computed admission plans). Default delegates to the real planner.
     */
    public function __construct(
        private readonly ?AtlasSelfConstructionScopeExpansionCandidateRanker $ranker = null,
        private readonly ?AtlasSelfConstructionScopeExpansionReadinessGate $readinessGate = null,
        private readonly ?AtlasSelfConstructionScopeExpansionLaneAdmissionPlan $admissionPlanner = null,
        ?\Closure $admissionPlanOverride = null,
    ) {
        $this->admissionPlanFn = $admissionPlanOverride ?? function (array $candidate, array $readiness, array $laneFacts): array {
            $planner = $this->admissionPlanner ?? new AtlasSelfConstructionScopeExpansionLaneAdmissionPlan;

            return $planner->plan($candidate, $readiness, $laneFacts);
        };
    }

    /**
     * @param  array<string,mixed>  $facts   {candidates, risk_budget, readiness_facts?, lane_facts?}
     * @param  array<string,mixed>  $options {apply?:bool, action_callbacks?:array<string,callable>}
     * @return array<string,mixed>
     */
    public function run(array $facts, array $options = []): array
    {
        $apply = (bool) ($options['apply'] ?? false);
        $callbacks = is_array($options['action_callbacks'] ?? null) ? $options['action_callbacks'] : [];

        $ranker = $this->ranker ?? new AtlasSelfConstructionScopeExpansionCandidateRanker;
        $readinessGate = $this->readinessGate ?? new AtlasSelfConstructionScopeExpansionReadinessGate;

        $ranked = $ranker->rank([
            'candidates' => array_values((array) ($facts['candidates'] ?? [])),
            'risk_budget' => (array) ($facts['risk_budget'] ?? []),
        ]);
        $accepted = array_values((array) $ranked['accepted_candidates']);

        $readinessFactsMap = is_array($facts['readiness_facts'] ?? null) ? $facts['readiness_facts'] : [];
        $laneFactsMap = is_array($facts['lane_facts'] ?? null) ? $facts['lane_facts'] : [];

        $readinessByCandidate = [];
        $admissionPlans = [];
        $admittedCount = 0;

        foreach ($accepted as $candidate) {
            $cid = (string) ($candidate['scope_id'] ?? '');
            $rFacts = is_array($readinessFactsMap[$cid] ?? null) ? $readinessFactsMap[$cid] : [];
            $rVerdict = $readinessGate->evaluate($candidate, $rFacts);
            $readinessByCandidate[$cid] = $rVerdict;

            $lFacts = is_array($laneFactsMap[$cid] ?? null) ? $laneFactsMap[$cid] : [];
            $plan = ($this->admissionPlanFn)($candidate, $rVerdict, $lFacts);
            $admissionPlans[] = $plan;
            if ((string) ($plan['status'] ?? '') === AtlasSelfConstructionScopeExpansionLaneAdmissionPlan::STATUS_READY) {
                $admittedCount++;
            }
        }

        $appliedActions = [];
        $blockedActions = [];
        $withheldActions = [];

        foreach ($admissionPlans as $plan) {
            $status = (string) ($plan['status'] ?? '');
            $cid = (string) ($plan['candidate_id'] ?? '');
            $actions = array_values((array) ($plan['actions'] ?? []));

            foreach ($actions as $action) {
                if (! is_array($action)) {
                    continue;
                }
                $kind = (string) ($action['kind'] ?? '');

                if (in_array($kind, self::FORBIDDEN_ACTION_KINDS, true)) {
                    $withheldActions[] = [
                        'candidate_id' => $cid,
                        'kind' => $kind,
                        'reason' => 'forbidden_action_kind:'.$kind,
                    ];

                    continue;
                }

                if (! $apply) {
                    $withheldActions[] = [
                        'candidate_id' => $cid,
                        'kind' => $kind,
                        'reason' => 'dry_run',
                    ];

                    continue;
                }

                if ($status !== AtlasSelfConstructionScopeExpansionLaneAdmissionPlan::STATUS_READY) {
                    $withheldActions[] = [
                        'candidate_id' => $cid,
                        'kind' => $kind,
                        'reason' => 'admission_not_ready:'.$status,
                    ];

                    continue;
                }

                $callback = $callbacks[$kind] ?? null;
                if (! is_callable($callback)) {
                    $withheldActions[] = [
                        'candidate_id' => $cid,
                        'kind' => $kind,
                        'reason' => 'no_callback_supplied',
                    ];

                    continue;
                }

                try {
                    $result = $callback($action, $plan);
                    $appliedActions[] = [
                        'candidate_id' => $cid,
                        'kind' => $kind,
                        'result' => is_array($result) ? $result : ['ok' => true],
                    ];
                } catch (Throwable $e) {
                    $blockedActions[] = [
                        'candidate_id' => $cid,
                        'kind' => $kind,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        $expansionDecision = $accepted === [] ? 'no_op' : ($admittedCount > 0 ? 'selected' : 'blocked');

        $candidateEvidenceRefs = [];
        foreach ($accepted as $c) {
            foreach ((array) ($c['evidence_refs'] ?? []) as $ref) {
                $candidateEvidenceRefs[] = (string) $ref;
            }
        }

        $payload = [
            'schema_version' => self::SCHEMA,
            'status' => 'ok',
            'expansion_decision' => $expansionDecision,
            'dry_run' => ! $apply,
            'ranked_candidates' => $ranked,
            'readiness' => $readinessByCandidate,
            'admission_plans' => $admissionPlans,
            'admitted_count' => $admittedCount,
            'withheld_count' => count($withheldActions),
            'applied_actions' => $appliedActions,
            'blocked_actions' => $blockedActions,
            'withheld_actions' => $withheldActions,
            'receipts' => [
                'ranker_hash' => (string) ($ranked['ranker_hash'] ?? ''),
                'readiness_hashes' => array_map(
                    static fn (array $r): string => (string) ($r['readiness_hash'] ?? ''),
                    $readinessByCandidate,
                ),
                'candidate_evidence_refs' => array_values(array_unique($candidateEvidenceRefs)),
            ],
        ];
        $payload['governor_cycle_hash'] = $this->hash($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hash(array $payload): string
    {
        $copy = $payload;
        unset($copy['governor_cycle_hash']);
        $copy = $this->ksortDeep($copy);

        return hash('sha256', (string) json_encode($copy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<mixed,mixed>  $value
     * @return array<mixed,mixed>
     */
    private function ksortDeep(array $value): array
    {
        $isAssoc = $value !== [] && array_keys($value) !== range(0, count($value) - 1);
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = $this->ksortDeep($v);
            }
        }
        if ($isAssoc) {
            ksort($value);
        }

        return $value;
    }
}
