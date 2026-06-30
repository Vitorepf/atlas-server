<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure decision record builder for brain enqueue operations. Explains and
 * validates why a candidate batch was enqueued, deferred, consolidated,
 * or rejected, leaving an auditable trail instead of silently mutating queue state.
 *
 * Valid decisions: enqueue | defer | consolidate | reject
 *
 * AC2: enqueue is refused when:
 *   - validation_evidence does NOT include 'target_uniqueness'
 *   - validation_evidence includes 'malformed_sweep'
 *   - validation_evidence does NOT include 'collision_check'
 *
 * Non-enqueue decisions (defer/consolidate/reject) are always accepted because
 * they are conservative actions that reduce queue pressure, not increase it.
 *
 * AC1: every accepted record includes queue_snapshot, leverage_rationale,
 *      risk, and validation_evidence.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainEnqueueDecisionRecord
{
    public const SCHEMA = 'atlas.external_brain.enqueue_decision_record.v1';

    public const DECISION_ENQUEUE     = 'enqueue';
    public const DECISION_DEFER       = 'defer';
    public const DECISION_CONSOLIDATE = 'consolidate';
    public const DECISION_REJECT      = 'reject';

    private const REQUIRED_ENQUEUE_EVIDENCE   = ['target_uniqueness', 'collision_check'];
    private const FORBIDDEN_ENQUEUE_EVIDENCE  = ['malformed_sweep'];

    /**
     * @param  array{
     *   decision?: string,
     *   batch_id?: string,
     *   candidate_count?: int,
     *   queue_depth?: int,
     *   queue_pressure?: string,
     *   leverage_rationale?: string,
     *   risk_level?: string,
     *   validation_evidence?: list<string>,
     * }  $input
     * @return array{schema:string, accepted:bool, decision:string, rejection_reason:string|null, record:array<string,mixed>|null}
     */
    public function record(array $input): array
    {
        $decision          = (string) ($input['decision']           ?? '');
        $batchId           = (string) ($input['batch_id']           ?? 'unknown');
        $candidateCount    = max(0, (int) ($input['candidate_count'] ?? 0));
        $queueDepth        = max(0, (int) ($input['queue_depth']    ?? 0));
        $queuePressure     = (string) ($input['queue_pressure']     ?? 'low');
        $leverageRationale = (string) ($input['leverage_rationale'] ?? '');
        $riskLevel         = (string) ($input['risk_level']         ?? 'low');
        $evidence          = (array)  ($input['validation_evidence'] ?? []);

        $rejectionReason = $this->validateDecision($decision, $evidence);

        if ($rejectionReason !== null) {
            return [
                'schema'           => self::SCHEMA,
                'accepted'         => false,
                'decision'         => $decision,
                'rejection_reason' => $rejectionReason,
                'record'           => null,
            ];
        }

        $record = [
            'batch_id'           => $batchId,
            'decision'           => $decision,
            'candidate_count'    => $candidateCount,
            'queue_snapshot'     => [
                'queue_depth'    => $queueDepth,
                'queue_pressure' => $queuePressure,
            ],
            'leverage_rationale' => $leverageRationale,
            'risk'               => $riskLevel,
            'validation_evidence' => $evidence,
        ];

        return [
            'schema'           => self::SCHEMA,
            'accepted'         => true,
            'decision'         => $decision,
            'rejection_reason' => null,
            'record'           => $record,
        ];
    }

    private function validateDecision(string $decision, array $evidence): ?string
    {
        if ($decision !== self::DECISION_ENQUEUE) {
            // Conservative decisions are always accepted.
            return null;
        }

        foreach (self::FORBIDDEN_ENQUEUE_EVIDENCE as $forbidden) {
            if (in_array($forbidden, $evidence, true)) {
                return "enqueue_refused:forbidden_evidence_present:{$forbidden}";
            }
        }

        foreach (self::REQUIRED_ENQUEUE_EVIDENCE as $required) {
            if (! in_array($required, $evidence, true)) {
                return "enqueue_refused:missing_required_evidence:{$required}";
            }
        }

        return null;
    }
}
