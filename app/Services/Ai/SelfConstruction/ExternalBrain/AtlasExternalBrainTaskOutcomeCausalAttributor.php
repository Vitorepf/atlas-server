<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure causal attributor. Explains WHY a task outcome happened by separating
 * spec quality, worker fit, queue state, evidence strength, and complexity signals.
 *
 * PRIMARY CAUSE hierarchy (first match wins):
 *   scope_failure           — spec.forbidden_files_detected OR spec.missing_allowed_files
 *   poor_spec_quality       — spec quality_score < 0.4 OR missing acceptance_criteria
 *   poisoned_acceptance     — spec.contradictory_acceptance AND outcome=give_back
 *   ambiguous_contract      — outcome.contradictory_evidence
 *   worker_mismatch         — spec is adequate BUT task_class is in worker avoid_task_classes
 *   shallow_evidence        — outcome=success but evidence is shallow or evidence_strength < 0.4
 *   implementation_complexity — high complexity AND give_back or failed_gate outcome
 *   worker_capability_gap   — worker quality_score < 0.5 AND non-success outcome
 *   queue_contention        — high queue contention AND give_back
 *   good_execution          — success with evidence and adequate spec
 *   unknown                 — cannot determine
 *
 * AC2 key distinction: a good spec (quality≥0.4, has AC) that produces give_back
 * due to worker task-class mismatch → 'worker_mismatch', not 'poor_spec_quality'.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainTaskOutcomeCausalAttributor
{
    public const SCHEMA = 'atlas.external_brain.task_outcome_causal_attributor.v1';

    public const CAUSE_SCOPE_FAILURE          = 'scope_failure';
    public const CAUSE_POOR_SPEC             = 'poor_spec_quality';
    public const CAUSE_POISONED_ACCEPTANCE   = 'poisoned_acceptance';
    public const CAUSE_AMBIGUOUS_CONTRACT    = 'ambiguous_contract';
    public const CAUSE_WORKER_MISMATCH       = 'worker_mismatch';
    public const CAUSE_SHALLOW_EVIDENCE      = 'shallow_evidence';
    public const CAUSE_COMPLEXITY            = 'implementation_complexity';
    public const CAUSE_WORKER_CAPABILITY_GAP = 'worker_capability_gap';
    public const CAUSE_QUEUE_CONTENTION      = 'queue_contention';
    public const CAUSE_GOOD_EXECUTION           = 'good_execution';
    public const CAUSE_ROUTING_FAMILY_MISMATCH = 'routing_family_mismatch';
    public const CAUSE_UNKNOWN                 = 'unknown';

    public const ROUTING_SIGNAL_POSITIVE = 'positive';
    public const ROUTING_SIGNAL_NEGATIVE = 'negative';
    public const ROUTING_SIGNAL_NEUTRAL  = 'neutral';

    public const ADJUSTMENT_BLOCK_CHAIN          = 'block_chain';
    public const ADJUSTMENT_RESPEC_BEFORE_RETRY  = 'respec_before_retry';
    public const ADJUSTMENT_SPLIT_BEFORE_RETRY   = 'split_before_retry';
    public const ADJUSTMENT_REROUTE_WORKER       = 'reroute_worker';
    public const ADJUSTMENT_STRENGTHEN_EVIDENCE  = 'strengthen_evidence';
    public const ADJUSTMENT_CONTINUE_CHAIN       = 'continue_chain';
    public const ADJUSTMENT_PROMOTE_FAMILY       = 'promote_family';
    public const ADJUSTMENT_INVESTIGATE          = 'investigate';

    private const SPEC_QUALITY_THRESHOLD       = 0.4;
    private const EVIDENCE_WEAK_THRESHOLD      = 0.4;
    private const WORKER_QUALITY_THRESHOLD     = 0.5;
    private const DEFAULT_GIVE_BACK_THRESHOLD  = 2;
    private const DEFAULT_SUCCESS_THRESHOLD    = 2;

    /**
     * @param  array{
     *   spec?: array{quality_score?:float, scope_shape?:string, evidence_strength?:float, has_acceptance_criteria?:bool, complexity?:string},
     *   worker?: array{quality_score?:float, best_task_classes?:list<string>, avoid_task_classes?:list<string>, task_class?:string},
     *   queue?: array{wait_time_s?:float, contention_level?:string},
     *   outcome?: array{result?:string, had_evidence?:bool, shallow_success?:bool},
     * }  $input
     * @return array{schema:string, primary_cause:string, contributing_causes:list<string>, confidence:string, recommended_originator_adjustment:string, attribution_id:string}
     */
    public function attribute(array $input): array
    {
        $spec   = is_array($input['spec']    ?? null) ? $input['spec']    : [];
        $worker = is_array($input['worker']  ?? null) ? $input['worker']  : [];
        $queue  = is_array($input['queue']   ?? null) ? $input['queue']   : [];
        $outcomeMap = is_array($input['outcome'] ?? null) ? $input['outcome'] : [];

        $specQuality             = (float) ($spec['quality_score']           ?? 1.0);
        $hasAC                   = (bool)  ($spec['has_acceptance_criteria'] ?? true);
        $evidenceSt              = (float) ($spec['evidence_strength']       ?? 1.0);
        $complexity              = (string) ($spec['complexity']             ?? 'low');
        $forbiddenFilesDetected  = (bool)  ($spec['forbidden_files_detected'] ?? false);
        $missingAllowedFiles     = (bool)  ($spec['missing_allowed_files']    ?? false);
        $contradictoryAcceptance = (bool)  ($spec['contradictory_acceptance'] ?? false);

        $workerQuality      = (float) ($worker['quality_score']        ?? 1.0);
        $taskClass          = (string) ($worker['task_class']           ?? '');
        $avoidClasses       = (array)  ($worker['avoid_task_classes']   ?? []);
        $bestClasses        = (array)  ($worker['best_task_classes']    ?? []);
        $repeatedGiveBack   = (int)   ($worker['repeated_give_back_count'] ?? 0);
        $repeatedSuccess    = (int)   ($worker['repeated_success_count']   ?? 0);
        $giveBackThreshold  = (int)   ($worker['give_back_threshold']      ?? self::DEFAULT_GIVE_BACK_THRESHOLD);
        $successThreshold   = (int)   ($worker['success_threshold']        ?? self::DEFAULT_SUCCESS_THRESHOLD);

        $contention   = (string) ($queue['contention_level'] ?? 'low');

        $result               = (string) ($outcomeMap['result']                ?? 'success');
        $hadEvidence          = (bool)   ($outcomeMap['had_evidence']          ?? true);
        $shallowSuccess       = (bool)   ($outcomeMap['shallow_success']       ?? false);
        $contradictoryEvidence = (bool)  ($outcomeMap['contradictory_evidence'] ?? false);

        $isSuccess   = $result === 'success';
        $isGiveBack  = $result === 'give_back';
        $isFailedGate = $result === 'failed_gate';
        $poorSpec            = $specQuality < self::SPEC_QUALITY_THRESHOLD || ! $hasAC;
        $workerMismatch      = $taskClass !== '' && in_array($taskClass, $avoidClasses, true);
        $weakEvidence        = $evidenceSt < self::EVIDENCE_WEAK_THRESHOLD || ! $hadEvidence;
        $routingFamilyMismatch = $isGiveBack
            && ! $workerMismatch
            && $workerQuality >= self::WORKER_QUALITY_THRESHOLD
            && $repeatedGiveBack >= $giveBackThreshold;

        $contributing = [];

        // ── Primary cause hierarchy ───────────────────────────────────────────

        // 0. Scope failure — forbidden or missing files; actionable originator fix
        if ($forbiddenFilesDetected || $missingAllowedFiles) {
            $primaryCause = self::CAUSE_SCOPE_FAILURE;
            if ($forbiddenFilesDetected) {
                $contributing[] = 'forbidden_files_detected:true';
            }
            if ($missingAllowedFiles) {
                $contributing[] = 'missing_allowed_files:true';
            }
        }
        // 1. Poor spec — checked first; overrides everything except mismatch when spec is actually good
        elseif ($poorSpec) {
            $primaryCause = self::CAUSE_POOR_SPEC;
            $contributing[] = 'spec_quality:'.$specQuality;
            if (! $hasAC) {
                $contributing[] = 'missing_acceptance_criteria';
            }
            if ($isGiveBack) {
                $contributing[] = 'outcome:give_back';
            }
        }
        // 2a. Poisoned acceptance — contradictory AC produced give_back
        elseif ($contradictoryAcceptance && $isGiveBack) {
            $primaryCause = self::CAUSE_POISONED_ACCEPTANCE;
            $contributing[] = 'contradictory_acceptance:true';
            $contributing[] = 'outcome:give_back';
        }
        // 2b. Ambiguous contract — contradictory evidence regardless of outcome
        elseif ($contradictoryEvidence) {
            $primaryCause = self::CAUSE_AMBIGUOUS_CONTRACT;
            $contributing[] = 'contradictory_evidence:true';
            $contributing[] = 'outcome:'.$result;
        }
        // 3. Worker mismatch — spec is adequate but wrong worker for this task class
        elseif ($workerMismatch) {
            $primaryCause = self::CAUSE_WORKER_MISMATCH;
            $contributing[] = 'task_class:'.$taskClass;
            $contributing[] = 'worker_avoid_class_matched';
            if ($isGiveBack) {
                $contributing[] = 'outcome:give_back';
            }
        }
        // 2.5. AC1: routing family mismatch — capable worker repeatedly gives back wrong task family
        elseif ($routingFamilyMismatch) {
            $primaryCause = self::CAUSE_ROUTING_FAMILY_MISMATCH;
            $contributing[] = 'capable_worker_repeated_give_back';
            $contributing[] = 'repeated_give_back_count:'.$repeatedGiveBack;
            $contributing[] = 'worker_quality:'.$workerQuality;
        }
        // 3. Shallow evidence on success
        elseif ($isSuccess && ($shallowSuccess || $weakEvidence)) {
            $primaryCause = self::CAUSE_SHALLOW_EVIDENCE;
            $contributing[] = 'evidence_strength:'.$evidenceSt;
            if ($shallowSuccess) {
                $contributing[] = 'shallow_success:true';
            }
            if (! $hadEvidence) {
                $contributing[] = 'no_evidence';
            }
        }
        // 4. High complexity caused give_back or failed_gate
        elseif ($complexity === 'high' && ($isGiveBack || $isFailedGate)) {
            $primaryCause = self::CAUSE_COMPLEXITY;
            $contributing[] = 'complexity:high';
            $contributing[] = 'outcome:'.$result;
        }
        // 5. Worker capability gap
        elseif ($workerQuality < self::WORKER_QUALITY_THRESHOLD && ! $isSuccess) {
            $primaryCause = self::CAUSE_WORKER_CAPABILITY_GAP;
            $contributing[] = 'worker_quality:'.$workerQuality;
            $contributing[] = 'outcome:'.$result;
        }
        // 6. Queue contention
        elseif ($contention === 'high' && $isGiveBack) {
            $primaryCause = self::CAUSE_QUEUE_CONTENTION;
            $contributing[] = 'contention:high';
            $contributing[] = 'outcome:give_back';
        }
        // 7. Good execution
        elseif ($isSuccess && ! $shallowSuccess && ! $weakEvidence) {
            $primaryCause = self::CAUSE_GOOD_EXECUTION;
            if ($bestClasses !== [] && in_array($taskClass, $bestClasses, true)) {
                $contributing[] = 'worker_best_class_matched';
            }
            $contributing[] = 'evidence_strength:'.$evidenceSt;
            // AC2: positive routing signal when worker repeatedly succeeds on this task family.
            if ($repeatedSuccess >= $successThreshold) {
                $contributing[] = 'repeated_success_count:'.$repeatedSuccess;
            }
        }
        else {
            $primaryCause = self::CAUSE_UNKNOWN;
            $contributing[] = 'outcome:'.$result;
        }

        // AC1/AC2: routing_signal — negative for mismatch causes, positive for good execution.
        $routingSignal = match(true) {
            in_array($primaryCause, [self::CAUSE_ROUTING_FAMILY_MISMATCH, self::CAUSE_WORKER_MISMATCH], true) => self::ROUTING_SIGNAL_NEGATIVE,
            $primaryCause === self::CAUSE_GOOD_EXECUTION => self::ROUTING_SIGNAL_POSITIVE,
            default => self::ROUTING_SIGNAL_NEUTRAL,
        };

        $confidence  = $this->computeConfidence($primaryCause, $poorSpec, $workerMismatch, $isSuccess);
        $adjustment  = $this->recommendedAdjustment($primaryCause);
        $attributionId = hash('sha256', $primaryCause.'|'.implode(',', $contributing));

        $taskPacketId = isset($input['task_packet_id']) ? (string) $input['task_packet_id'] : null;
        $family = isset($input['family']) ? (string) $input['family'] : ($taskClass !== '' ? $taskClass : null);

        return [
            'schema'                          => self::SCHEMA,
            'primary_cause'                   => $primaryCause,
            'contributing_causes'             => $contributing,
            'confidence'                      => $confidence,
            'routing_signal'                  => $routingSignal,
            'recommended_originator_adjustment' => $adjustment,
            'attribution_id'                  => $attributionId,
            'graph_adjustment'                => [
                'action'         => $this->graphAdjustmentAction($primaryCause, $repeatedSuccess, $successThreshold),
                'task_packet_id' => $taskPacketId,
                'family'         => $family,
                'reason'         => $primaryCause,
            ],
        ];
    }

    /**
     * Task-graph/Task-fabric-ready action: tells the planner whether to block_chain,
     * respec_before_retry, split_before_retry, reroute_worker, strengthen_evidence, continue_chain,
     * promote_family, or investigate. worker_mismatch / routing_family_mismatch ALWAYS resolve to
     * reroute_worker — a worker/routing problem must never be mislabelled as a spec-quality issue.
     */
    private function graphAdjustmentAction(string $primaryCause, int $repeatedSuccess, int $successThreshold): string
    {
        return match ($primaryCause) {
            self::CAUSE_SCOPE_FAILURE => self::ADJUSTMENT_BLOCK_CHAIN,
            self::CAUSE_POISONED_ACCEPTANCE => self::ADJUSTMENT_RESPEC_BEFORE_RETRY,
            self::CAUSE_POOR_SPEC, self::CAUSE_COMPLEXITY => self::ADJUSTMENT_SPLIT_BEFORE_RETRY,
            self::CAUSE_WORKER_MISMATCH, self::CAUSE_ROUTING_FAMILY_MISMATCH, self::CAUSE_WORKER_CAPABILITY_GAP => self::ADJUSTMENT_REROUTE_WORKER,
            self::CAUSE_SHALLOW_EVIDENCE => self::ADJUSTMENT_STRENGTHEN_EVIDENCE,
            self::CAUSE_GOOD_EXECUTION => $repeatedSuccess >= $successThreshold
                ? self::ADJUSTMENT_PROMOTE_FAMILY
                : self::ADJUSTMENT_CONTINUE_CHAIN,
            default => self::ADJUSTMENT_INVESTIGATE,
        };
    }

    private function computeConfidence(string $primaryCause, bool $poorSpec, bool $workerMismatch, bool $isSuccess): string
    {
        if (in_array($primaryCause, [self::CAUSE_SCOPE_FAILURE, self::CAUSE_POOR_SPEC, self::CAUSE_WORKER_MISMATCH, self::CAUSE_ROUTING_FAMILY_MISMATCH, self::CAUSE_GOOD_EXECUTION], true)) {
            return 'high';
        }
        if (in_array($primaryCause, [self::CAUSE_POISONED_ACCEPTANCE, self::CAUSE_AMBIGUOUS_CONTRACT], true)) {
            return 'medium';
        }
        if ($primaryCause === self::CAUSE_UNKNOWN) {
            return 'low';
        }

        return 'medium';
    }

    private function recommendedAdjustment(string $primaryCause): string
    {
        return match ($primaryCause) {
            self::CAUSE_SCOPE_FAILURE           => 'fix_scope_in_originator',
            self::CAUSE_POOR_SPEC               => 'improve_spec_quality',
            self::CAUSE_POISONED_ACCEPTANCE     => 'resolve_contradictory_acceptance',
            self::CAUSE_AMBIGUOUS_CONTRACT      => 'clarify_acceptance_criteria',
            self::CAUSE_WORKER_MISMATCH         => 'route_to_better_worker',
            self::CAUSE_ROUTING_FAMILY_MISMATCH => 'reassign_to_better_task_family',
            self::CAUSE_SHALLOW_EVIDENCE        => 'strengthen_evidence_requirement',
            self::CAUSE_COMPLEXITY              => 'split_into_smaller_tasks',
            self::CAUSE_WORKER_CAPABILITY_GAP   => 'route_to_better_worker',
            self::CAUSE_QUEUE_CONTENTION        => 'reduce_contention',
            self::CAUSE_GOOD_EXECUTION          => 'continue_current_approach',
            default                             => 'investigate',
        };
    }
}
