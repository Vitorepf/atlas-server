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
 * ENQUEUE REQUIRED EVIDENCE (all must be present):
 *   target_uniqueness, collision_check, malformed_sweep_clean,
 *   allowed_files_exist, runnable_acceptance_present
 *
 * ENQUEUE FORBIDDEN EVIDENCE (any triggers rejection):
 *   malformed_sweep, malformed_sweep_failed, collision_detected,
 *   duplicate_target, missing_allowed_files, no_runnable_acceptance
 *
 * Non-enqueue decisions (defer/consolidate/reject) are always accepted because
 * they are conservative actions that reduce queue pressure, not increase it.
 *
 * Every accepted record includes: queue_snapshot, leverage_rationale,
 * expected_downstream_value, risk, validation_evidence, decision_reason, batch_id.
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

    private const REQUIRED_ENQUEUE_EVIDENCE = [
        'target_uniqueness',
        'collision_check',
        'malformed_sweep_clean',
        'allowed_files_exist',
        'runnable_acceptance_present',
    ];

    private const FORBIDDEN_ENQUEUE_EVIDENCE = [
        'malformed_sweep',
        'malformed_sweep_failed',
        'collision_detected',
        'duplicate_target',
        'missing_allowed_files',
        'no_runnable_acceptance',
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function record(array $input): array
    {
        $decision               = (string) ($input['decision']                ?? '');
        $batchId                = (string) ($input['batch_id']                ?? 'unknown');
        $candidateCount         = max(0, (int) ($input['candidate_count']     ?? 0));
        $queueDepth             = max(0, (int) ($input['queue_depth']         ?? 0));
        $queuePressure          = (string) ($input['queue_pressure']          ?? 'low');
        $leverageRationale      = (string) ($input['leverage_rationale']      ?? '');
        $expectedDownstreamValue = (string) ($input['expected_downstream_value'] ?? '');
        $riskLevel              = (string) ($input['risk_level']              ?? 'low');
        $decisionReason         = (string) ($input['decision_reason']         ?? '');
        $evidence               = (array)  ($input['validation_evidence']     ?? []);

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
            'batch_id'                 => $batchId,
            'decision'                 => $decision,
            'candidate_count'          => $candidateCount,
            'queue_snapshot'           => [
                'queue_depth'    => $queueDepth,
                'queue_pressure' => $queuePressure,
            ],
            'leverage_rationale'       => $leverageRationale,
            'expected_downstream_value' => $expectedDownstreamValue,
            'risk'                     => $riskLevel,
            'validation_evidence'      => $evidence,
            'decision_reason'          => $decisionReason,
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
