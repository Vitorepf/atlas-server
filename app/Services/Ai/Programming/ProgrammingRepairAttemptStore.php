<?php

namespace App\Services\Ai\Programming;

class ProgrammingRepairAttemptStore
{
    public function __construct(
        private readonly ProgrammingStageReceiptStore $stageReceipts,
    ) {}

    /**
     * @param  array<string,mixed>  $failurePacket
     * @param  array<string,mixed>  $patchManifest
     * @param  array<string,mixed>  $testManifest
     * @return array<string,mixed>
     */
    public function receipt(string $planId, ?string $parentPlanId, int $attempt, string $status, array $failurePacket, array $patchManifest, array $testManifest, bool $persist = false): array
    {
        $output = [
            'repair_attempt_schema' => 'atlas.programming.repair_attempt.receipt.v1',
            'failure_packet_hash' => hash('sha256', json_encode($failurePacket, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            'patch_manifest_hash' => hash('sha256', json_encode($patchManifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            'test_manifest_hash' => hash('sha256', json_encode($testManifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            'patch_manifest_schema' => $patchManifest['schema_version'] ?? null,
            'test_manifest_schema' => $testManifest['schema_version'] ?? null,
        ];

        $receipt = $this->stageReceipts->make(
            planId: $planId,
            parentPlanId: $parentPlanId,
            stage: 'repair',
            attempt: $attempt,
            status: $status,
            input: ['failure_packet' => $failurePacket],
            output: $output,
            evidenceRefs: array_values(array_filter([
                is_string($failurePacket['failure_hash'] ?? null) ? 'failure:'.$failurePacket['failure_hash'] : null,
                is_string($patchManifest['action_id'] ?? null) ? 'manifest:'.$patchManifest['action_id'] : null,
                is_string($testManifest['action_id'] ?? null) ? 'manifest:'.$testManifest['action_id'] : null,
            ])),
            persist: $persist,
        );

        return array_merge($receipt, [
            'repair_attempt' => $output,
        ]);
    }
}
