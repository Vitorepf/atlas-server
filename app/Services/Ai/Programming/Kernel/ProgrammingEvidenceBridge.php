<?php

namespace App\Services\Ai\Programming\Kernel;

use App\Models\AiMission;
use App\Models\AiMissionEvidenceRef;
use App\Models\AiWorkOrder;
use App\Services\Ai\Evidence\ReceiptService as EvidenceReceiptService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Mission\MissionEvidenceService;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProgrammingEvidenceBridge
{
    public function __construct(private readonly Container $container) {}

    public function missionEvidenceAvailable(): bool
    {
        return Schema::hasTable('ai_mission_evidence_refs');
    }

    public function evidenceRuntimeAvailable(): bool
    {
        return Schema::hasTable('ai_receipts');
    }

    /**
     * Attach a programming evidence ref to a mission (Meta 1) and emit a tool_call
     * receipt to Evidence Runtime (Meta 4) when available. Tolerant: if Meta 4 is
     * unavailable, only the Meta 1 evidence ref is persisted and a local receipt
     * stub is returned.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function attach(AiMission $mission, string $evidenceType, string $evidenceRef, array $payload = [], ?AiWorkOrder $workOrder = null): array
    {
        $evidenceRecord = null;
        $missionEvidenceError = null;
        if ($this->missionEvidenceAvailable()) {
            try {
                $service = $this->container->make(MissionEvidenceService::class);
                $evidenceRecord = $service->attach($mission, [
                    'evidence_type' => $evidenceType,
                    'evidence_ref' => $evidenceRef,
                    'work_order_id' => $workOrder?->id,
                    'metadata' => $payload + ['source' => 'programming_adapter'],
                ]);
            } catch (Throwable $e) {
                $missionEvidenceError = $e->getMessage();
            }
        }

        $receiptResult = $this->emitEvidenceReceipt($mission, $evidenceType, $evidenceRecord, $workOrder, $payload);

        return [
            'mission_evidence_ref_id' => $evidenceRecord?->id,
            'mission_evidence_hash' => $evidenceRecord?->evidence_hash,
            'mission_evidence_error' => $missionEvidenceError,
            'evidence_runtime' => $receiptResult,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function emitEvidenceReceipt(AiMission $mission, string $evidenceType, ?AiMissionEvidenceRef $record, ?AiWorkOrder $workOrder, array $payload): array
    {
        if (! $this->evidenceRuntimeAvailable()) {
            return [
                'kind' => 'programming_adapter_local_receipt',
                'reason' => 'evidence_runtime_unavailable',
                'hash' => MissionCanonicalHash::sha256([
                    'evidence_type' => $evidenceType,
                    'mission_id' => $mission->id,
                    'work_order_id' => $workOrder?->id,
                    'mission_evidence_ref_id' => $record?->id,
                ]),
            ];
        }

        try {
            $receipt = $this->container->make(EvidenceReceiptService::class)->emit([
                'receipt_type' => 'tool_call',
                'status' => 'ok',
                'target_type' => 'work_order',
                'target_id' => $workOrder?->id ?? $mission->id,
                'mission_id' => $mission->id,
                'work_order_id' => $workOrder?->id,
                'actor_type' => 'atlas_programming_adapter',
                'action' => "programming.evidence.{$evidenceType}",
                'input_hash' => MissionCanonicalHash::sha256(['evidence_type' => $evidenceType, 'ref' => $payload['ref_short'] ?? null]),
                'output_hash' => $record?->evidence_hash,
                'evidence_refs' => $record !== null ? [['mission_evidence_ref_id' => $record->id]] : [],
            ]);

            return [
                'kind' => 'evidence_runtime_receipt',
                'receipt_id' => (string) $receipt->id,
                'receipt_hash' => (string) $receipt->receipt_hash,
            ];
        } catch (Throwable $e) {
            return [
                'kind' => 'programming_adapter_local_receipt',
                'reason' => 'evidence_runtime_exception:'.$e->getMessage(),
                'hash' => MissionCanonicalHash::sha256([
                    'evidence_type' => $evidenceType,
                    'mission_id' => $mission->id,
                    'mission_evidence_ref_id' => $record?->id,
                    'exception' => $e->getMessage(),
                ]),
            ];
        }
    }
}
