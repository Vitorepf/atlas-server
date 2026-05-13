<?php

namespace App\Services\Ai\Programming;

class ProgrammingRepairExecutor
{
    /**
     * @param  array<string,mixed>  $failurePacket
     * @param  array<string,mixed>  $retrievalPlan
     * @return array<string,mixed>
     */
    public function attemptPlan(array $failurePacket, array $retrievalPlan, int $attempt, int $maxAttempts): array
    {
        $noProgress = $attempt > 1
            && (string) data_get($failurePacket, 'failure_hash') === (string) data_get($failurePacket, 'previous_failure_hash');

        return [
            'schema_version' => 'atlas.programming.repair_attempt.plan.v1',
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
            'status' => $noProgress ? 'blocked_no_progress' : ($attempt <= $maxAttempts ? 'planned' : 'blocked_max_attempts'),
            'failure_packet_hash' => hash('sha256', json_encode($failurePacket, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            'retrieval_plan_id' => data_get($retrievalPlan, 'retrieval_receipt.receipt_id'),
            'required_receipts' => [
                'failure_packet',
                'patch_manifest',
                'test_manifest',
                'patch_verifier_report',
            ],
            'next_action' => $noProgress || $attempt > $maxAttempts ? 'human_review' : 'patch_repair_then_retest',
        ];
    }
}
