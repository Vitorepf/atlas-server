<?php

namespace App\Services\Ai\Evidence;

use App\Models\AiCertification;
use App\Models\AiEvidencePack;
use App\Models\AiMission;
use App\Models\AiMissionEvidenceRef;
use App\Models\AiWorkOrder;

/**
 * Bridges Mission Foundation (Meta 1) with Evidence/Certification Runtime (Meta 4).
 *
 * The Mission Foundation owns its own MissionCertificationService and lifecycle
 * guard; this adapter does not replace it. It mirrors the mission state into the
 * universal Evidence Runtime so cross-domain queries, control-plane snapshots and
 * anti-false-completion gates can rely on a single substrate.
 */
class MissionEvidenceAdapter
{
    public function __construct(
        private readonly EvidencePackService $evidencePacks,
        private readonly CertificationRuntimeService $certifications,
        private readonly AuditEventService $auditEvents,
    ) {}

    /**
     * Build a universal evidence pack for a mission target.
     */
    public function buildMissionPack(AiMission $mission): AiEvidencePack
    {
        $artifactRefs = [];
        $testRefs = [];
        $receiptRefs = [];
        $commandRefs = [];
        $sourceRefs = [];

        foreach ($mission->evidenceRefs as $ref) {
            /** @var AiMissionEvidenceRef $ref */
            $entry = [
                'kind' => $ref->evidence_type,
                'id' => $ref->id,
                'evidence_hash' => $ref->evidence_hash,
            ];
            $type = (string) $ref->evidence_type;
            match ($type) {
                'artifact', 'diff', 'screenshot' => $artifactRefs[] = $entry,
                'test' => $testRefs[] = $entry,
                'receipt', 'certification', 'blocker' => $receiptRefs[] = $entry,
                'command' => $commandRefs[] = $entry,
                'source', 'doc' => $sourceRefs[] = $entry,
                default => $receiptRefs[] = $entry,
            };
        }

        foreach ($mission->workOrders as $workOrder) {
            /** @var AiWorkOrder $workOrder */
            if ($workOrder->receipt_hash !== null) {
                $receiptRefs[] = [
                    'kind' => 'work_order_receipt',
                    'id' => $workOrder->id,
                    'receipt_hash' => $workOrder->receipt_hash,
                ];
            }
        }

        return $this->evidencePacks->build([
            'target_type' => EvidencePackService::TARGET_MISSION,
            'target_id' => (string) $mission->id,
            'mission_id' => $mission->id,
            'artifact_refs' => $artifactRefs,
            'source_refs' => $sourceRefs,
            'command_refs' => $commandRefs,
            'test_refs' => $testRefs,
            'receipt_refs' => $receiptRefs,
        ]);
    }

    /**
     * Issue a universal certification for a mission target.
     *
     * Anti-false-completion: if the mission has no evidence refs and no work orders
     * with receipt_hash, the certification will be `failed` (no evidence pack data)
     * or `blocked` (if open critical/high blockers exist).
     */
    public function certifyMission(AiMission $mission, ?string $evidencePackId = null): AiCertification
    {
        $providedRequirements = [];
        if ($mission->objectives()->count() > 0) {
            $providedRequirements[] = 'mission_has_objectives';
        }
        if ($mission->workOrders()->count() > 0) {
            $providedRequirements[] = 'mission_has_work_orders';
        }
        if ($mission->evidenceRefs()->count() > 0) {
            $providedRequirements[] = 'mission_has_evidence_refs';
        }
        $latest = $mission->latestCertification()->first();
        if ($latest !== null && $latest->status === 'passed') {
            $providedRequirements[] = 'mission_foundation_certification_passed';
        }

        return $this->certifications->certify([
            'target_type' => EvidencePackService::TARGET_MISSION,
            'target_id' => (string) $mission->id,
            'mission_id' => $mission->id,
            'evidence_pack_id' => $evidencePackId,
            'required_requirements' => [
                'mission_has_objectives',
                'mission_has_work_orders',
                'mission_has_evidence_refs',
                'mission_foundation_certification_passed',
            ],
            'provided_requirements' => $providedRequirements,
        ]);
    }

    /**
     * Returns true when the mission can be safely marked completed by external code.
     * Anti-false-completion check: relies on the universal certification gate.
     */
    public function canCompleteMission(AiMission $mission): bool
    {
        return $this->certifications->canComplete(
            EvidencePackService::TARGET_MISSION,
            (string) $mission->id,
        );
    }
}
