<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ContinuousRuntime;

/**
 * Pure integration. Turns verification, merge, give-back and queue-repair OUTCOMES into Learning
 * Transfer + Receipts + Compounding input FACTS, separating REUSABLE lesson candidates from one-off
 * noise.
 *
 * Output: {schema_version, learning_inputs:list<{class, outcome_kind, evidence_hash, reusable}>,
 *           receipt_inputs:list<{kind, task_packet_id, lease_id, evidence_hash}>,
 *           compounding_inputs:list<{organ, task_class, cycle_id, evidence_hash, outcome}>,
 *           required_promotion_evidence_hashes:list<string>}
 *
 * A learning input is REUSABLE only when the same {class, evidence_hash} pair appears with
 * occurrence_count >= 2, OR when the outcome carries `learning_required=true`. One-offs are kept in
 * learning_inputs with reusable=false so the Learning Transfer organ can still see them but won't
 * promote them.
 */
final class AtlasSelfConstructionContinuousRuntimeLearningIntegration
{
    public const SCHEMA = 'atlas.continuous_runtime.learning_integration.v1';

    public const OUTCOME_VERIFICATION = 'verification';

    public const OUTCOME_MERGE = 'merge';

    public const OUTCOME_GIVE_BACK = 'give_back';

    public const OUTCOME_QUEUE_REPAIR = 'queue_repair';

    public const OUTCOME_QUARANTINE = 'quarantine';

    public const OUTCOME_RETRY = 'retry';

    private const KNOWN_OUTCOME_KINDS = [
        self::OUTCOME_VERIFICATION,
        self::OUTCOME_MERGE,
        self::OUTCOME_GIVE_BACK,
        self::OUTCOME_QUEUE_REPAIR,
        self::OUTCOME_QUARANTINE,
        self::OUTCOME_RETRY,
    ];

    /** outcome_kind (+ 'failed' outcome text for verification) => [affected_policy, next_cycle_effect] */
    private const LEARNING_UPDATE_EFFECTS = [
        self::OUTCOME_VERIFICATION.':failed' => ['prompt_contract_correction', 'flag_prompt_contract_for_revision'],
        self::OUTCOME_VERIFICATION => ['prompt_contract_confidence', 'reinforce_current_prompt_contract'],
        self::OUTCOME_MERGE => ['task_fabric_yield', 'reinforce_task_fabric_supply_for_class'],
        self::OUTCOME_GIVE_BACK => ['routing_affinity_demotion', 'demote_worker_affinity_for_class'],
        self::OUTCOME_QUARANTINE => ['task_fabric_retirement', 'block_task_class_until_repaired'],
        self::OUTCOME_QUEUE_REPAIR => ['task_fabric_repair', 'apply_repair_and_requeue'],
        self::OUTCOME_RETRY => ['routing_retry_pressure', 'monitor_retry_rate_before_next_dispatch'],
    ];

    /**
     * @param  list<array<string,mixed>>  $outcomes  list of {kind, task_packet_id, lease_id, evidence_hash,
     *                                                          organ, task_class, cycle_id, class?, occurrence_count?,
     *                                                          learning_required?, outcome?}
     * @return array<string,mixed>
     */
    public function integrate(array $outcomes): array
    {
        $learning = [];
        $receipt = [];
        $compounding = [];
        $requiredHashes = [];

        foreach ($outcomes as $o) {
            if (! is_array($o)) {
                continue;
            }
            if ((bool) ($o['stale'] ?? false)) {
                continue;
            }
            $kind = (string) ($o['kind'] ?? '');
            $taskId = (string) ($o['task_packet_id'] ?? '');
            $leaseId = (string) ($o['lease_id'] ?? '');
            $evidenceHash = (string) ($o['evidence_hash'] ?? '');
            $organ = (string) ($o['organ'] ?? 'unknown_organ');
            $taskClass = (string) ($o['task_class'] ?? '');
            $cycleId = (string) ($o['cycle_id'] ?? '');
            $class = (string) ($o['class'] ?? '');
            $occurrenceCount = (int) ($o['occurrence_count'] ?? 1);
            $learningRequired = (bool) ($o['learning_required'] ?? false);
            $outcome = (string) ($o['outcome'] ?? '');

            $poisonSignature = (bool) ($o['poison_signature'] ?? false);
            $unknownKind = $kind !== '' && ! in_array($kind, self::KNOWN_OUTCOME_KINDS, true);
            $reusable = $class !== '' && ($learningRequired || $occurrenceCount >= 2)
                && $evidenceHash !== '' && ! $unknownKind && ! $poisonSignature;

            if ($class !== '') {
                $promotionBlockers = [];
                if ($evidenceHash === '') {
                    $promotionBlockers[] = 'empty_evidence_hash';
                }
                if ($unknownKind) {
                    $promotionBlockers[] = 'unknown_outcome_kind:'.$kind;
                }
                if ($poisonSignature) {
                    $promotionBlockers[] = 'poison_signature';
                }
                $learning[] = [
                    'class'              => $class,
                    'outcome_kind'       => $kind,
                    'evidence_hash'      => $evidenceHash,
                    'reusable'           => $reusable,
                    'promotion_blockers' => $promotionBlockers,
                ];
            }

            $receipt[] = [
                'kind' => $kind,
                'task_packet_id' => $taskId,
                'lease_id' => $leaseId,
                'evidence_hash' => $evidenceHash,
            ];

            $workerQuality = is_array($o['worker_quality'] ?? null) ? $o['worker_quality'] : null;
            $compounding[] = [
                'organ'          => $organ,
                'task_class'     => $taskClass,
                'cycle_id'       => $cycleId,
                'evidence_hash'  => $evidenceHash,
                'outcome'        => $outcome,
                'worker_quality' => $workerQuality,
            ];

            if ($kind === self::OUTCOME_MERGE && $evidenceHash !== '') {
                $requiredHashes[] = $evidenceHash;
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'learning_inputs' => $learning,
            'receipt_inputs' => $receipt,
            'compounding_inputs' => $compounding,
            'required_promotion_evidence_hashes' => array_values(array_unique($requiredHashes)),
            'next_cycle_strategy' => $this->buildNextCycleStrategy($outcomes),
            'learning_updates' => $this->buildLearningUpdates($outcomes),
        ];
    }

    /**
     * Maps each non-stale outcome into a routing / task-fabric / prompt-contract learning signal:
     * {outcome_kind, affected_policy, evidence_refs, confidence, next_cycle_effect}. An outcome with
     * no evidence_hash never updates a policy — insufficient evidence never becomes a learning signal.
     *
     * @param  list<array<string,mixed>>  $outcomes
     * @return list<array{outcome_kind:string, affected_policy:?string, evidence_refs:list<string>, confidence:float, next_cycle_effect:string}>
     */
    private function buildLearningUpdates(array $outcomes): array
    {
        $updates = [];
        foreach ($outcomes as $o) {
            if (! is_array($o) || (bool) ($o['stale'] ?? false)) {
                continue;
            }

            $kind = (string) ($o['kind'] ?? '');
            $evidenceHash = (string) ($o['evidence_hash'] ?? '');
            $outcomeText = strtolower((string) ($o['outcome'] ?? ''));
            $occurrenceCount = max(1, (int) ($o['occurrence_count'] ?? 1));
            $hasEvidence = $evidenceHash !== '';

            if (! $hasEvidence) {
                $updates[] = [
                    'outcome_kind' => $kind,
                    'affected_policy' => null,
                    'evidence_refs' => [],
                    'confidence' => 0.0,
                    'next_cycle_effect' => 'insufficient_evidence_no_update_applied',
                ];

                continue;
            }

            $effectKey = $kind === self::OUTCOME_VERIFICATION && $outcomeText === 'failed'
                ? self::OUTCOME_VERIFICATION.':failed'
                : $kind;
            [$affectedPolicy, $nextCycleEffect] = self::LEARNING_UPDATE_EFFECTS[$effectKey] ?? ['unclassified_policy', 'manual_review_required'];

            $updates[] = [
                'outcome_kind' => $kind,
                'affected_policy' => $affectedPolicy,
                'evidence_refs' => [$evidenceHash],
                'confidence' => round(min(1.0, 0.5 + 0.1 * $occurrenceCount), 4),
                'next_cycle_effect' => $nextCycleEffect,
            ];
        }

        return $updates;
    }

    /** @param list<array<string,mixed>> $outcomes */
    private function buildNextCycleStrategy(array $outcomes): array
    {
        $totalNonStale = 0;
        $provenSuccessCount = 0;
        $giveBackCauses = [];
        $regressionSignals = [];
        $successClasses = [];
        $failClasses = [];

        foreach ($outcomes as $o) {
            if (! is_array($o) || (bool) ($o['stale'] ?? false)) {
                continue;
            }
            $totalNonStale++;
            $kind = (string) ($o['kind'] ?? '');
            $evidenceHash = (string) ($o['evidence_hash'] ?? '');
            $taskClass = (string) ($o['task_class'] ?? '');
            $isProxy = (bool) ($o['proxy_success'] ?? false);

            // Proven success: merge + real evidence + not proxy-flagged.
            if ($kind === self::OUTCOME_MERGE && $evidenceHash !== '' && ! $isProxy) {
                $provenSuccessCount++;
                if ($taskClass !== '') {
                    $successClasses[$taskClass] = true;
                }
            }

            if ($kind === self::OUTCOME_GIVE_BACK) {
                $cause = (string) ($o['class'] ?? 'unknown');
                $giveBackCauses[$cause] = ($giveBackCauses[$cause] ?? 0) + 1;
                if ($taskClass !== '') {
                    $failClasses[$taskClass] = true;
                }
            }

            if ($kind === self::OUTCOME_QUARANTINE && $taskClass !== '') {
                $failClasses[$taskClass] = true;
            }

            if ((bool) ($o['regression'] ?? false) && $evidenceHash !== '') {
                $regressionSignals[] = $evidenceHash;
            }
        }

        // Capability gaps: task_classes that only appeared in failures, never in proven successes.
        $capabilityGaps = array_values(array_filter(
            array_keys($failClasses),
            static fn (string $tc): bool => ! isset($successClasses[$tc])
        ));
        sort($capabilityGaps, SORT_STRING);

        arsort($giveBackCauses);
        $giveBackCausesFormatted = [];
        foreach ($giveBackCauses as $cause => $count) {
            $giveBackCausesFormatted[] = ['cause' => $cause, 'count' => $count];
        }

        $successYield = $totalNonStale > 0 ? round($provenSuccessCount / $totalNonStale, 4) : 0.0;

        return [
            'success_yield'              => $successYield,
            'give_back_causes'           => $giveBackCausesFormatted,
            'regression_signals'         => array_values(array_unique($regressionSignals)),
            'capability_gaps'            => $capabilityGaps,
            'next_cycle_confidence_score' => $successYield,
        ];
    }
}
