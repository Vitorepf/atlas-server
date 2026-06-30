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

    private const KNOWN_OUTCOME_KINDS = [
        self::OUTCOME_VERIFICATION,
        self::OUTCOME_MERGE,
        self::OUTCOME_GIVE_BACK,
        self::OUTCOME_QUEUE_REPAIR,
        self::OUTCOME_QUARANTINE,
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
        ];
    }
}
