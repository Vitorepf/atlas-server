<?php

declare(strict_types=1);

namespace App\Services\Ai\LongHorizon\Causal;

use App\Models\AiAtlasDecisionReceipt;
use App\Models\AiAtlasRouterDecision;
use App\Models\AiForgeIntake;
use App\Models\AiMission;
use App\Models\AiMissionCertification;
use App\Models\AiMissionEvent;
use App\Models\AiMissionEvidenceRef;
use App\Models\AiObjective;
use App\Models\AiWorkOrder;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\DatabaseTableAvailability;
use InvalidArgumentException;

/**
 * TEOS-I2 · Causal Decision Graph Lite (read-model).
 *
 * Compõe um grafo causal navegável on-demand sobre fontes existentes:
 *   - Mission Foundation: AiMission, AiObjective, AiWorkOrder, AiMissionEvent,
 *     AiMissionEvidenceRef, AiMissionCertification
 *   - Router Runtime: AiAtlasRouterDecision, AiAtlasDecisionReceipt
 *   - Forge intake: AiForgeIntake, AiForgeWorkPacket, AiForgeMilestone
 *
 * Hard rules canon:
 *   - **SEM tabela nova.** Read-only sobre tabelas existentes.
 *   - **SEM provider call.** Nenhuma chamada externa.
 *   - **SEM raw text leak.** NUNCA inclui `raw_prompt`, `payload` completo,
 *     `decision_reason` em texto, `evidence_ref` content. Apenas IDs, UUIDs,
 *     types, status, hashes (SHA-256).
 *   - **Determinístico.** Mesmo estado DB → mesmo `graph_hash`.
 *   - **Anti-loop.** Edges são DAG por construção (sem ciclos auto-induzidos).
 *
 * Schema: atlas.long_horizon.causal_graph_lite.v1
 * Scopes aceitos: mission, work_order, obra, forge_obra
 */
final class LongHorizonCausalDecisionGraphService
{
    public function __construct() {}

    /**
     * Build the causal graph for a given scope.
     *
     * @return array<string,mixed>
     *
     * @throws InvalidArgumentException quando scope_type não está em CAUSAL_GRAPH_LITE_ALLOWED_SCOPES
     */
    public function build(string $scopeType, string $scopeId): array
    {
        if (! in_array($scopeType, AtlasLongHorizonCanon::CAUSAL_GRAPH_LITE_ALLOWED_SCOPES, true)) {
            throw new InvalidArgumentException(sprintf(
                'scope_type [%s] not supported by Causal Graph Lite. Allowed: %s',
                $scopeType,
                implode('|', AtlasLongHorizonCanon::CAUSAL_GRAPH_LITE_ALLOWED_SCOPES),
            ));
        }

        $scopeId = trim($scopeId);
        if ($scopeId === '') {
            throw new InvalidArgumentException('scope_id cannot be empty.');
        }

        $builder = new CausalGraphBuilder($scopeType, $scopeId);

        match ($scopeType) {
            AtlasLongHorizonCanon::SCOPE_TYPE_MISSION => $this->buildForMission($builder, $scopeId),
            AtlasLongHorizonCanon::SCOPE_TYPE_WORK_ORDER => $this->buildForWorkOrder($builder, $scopeId),
            AtlasLongHorizonCanon::SCOPE_TYPE_OBRA,
            AtlasLongHorizonCanon::SCOPE_TYPE_FORGE_OBRA => $this->buildForForgeIntake($builder, $scopeId),
            default => null, // unreachable; guarded above
        };

        return $this->finalize($builder, $scopeType, $scopeId);
    }

    /* ============================================================ */
    /* Scope builders */
    /* ============================================================ */

    private function buildForMission(CausalGraphBuilder $builder, string $missionUuid): void
    {
        if (! $this->missionTablesPresent()) {
            $builder->addGap('mission_tables_absent', 'AiMission/AiObjective/AiWorkOrder tables not present');

            return;
        }

        $mission = AiMission::query()->where('uuid', $missionUuid)->first();
        if ($mission === null) {
            $builder->addGap('mission_not_found', "mission [{$missionUuid}] not found");

            return;
        }

        $this->expandMission($builder, $mission);
    }

    private function buildForWorkOrder(CausalGraphBuilder $builder, string $workOrderUuid): void
    {
        if (! $this->missionTablesPresent()) {
            $builder->addGap('mission_tables_absent', 'AiMission/AiObjective/AiWorkOrder tables not present');

            return;
        }

        $workOrder = AiWorkOrder::query()->where('uuid', $workOrderUuid)->first();
        if ($workOrder === null) {
            $builder->addGap('work_order_not_found', "work_order [{$workOrderUuid}] not found");

            return;
        }

        $mission = AiMission::query()->find($workOrder->mission_id);
        if ($mission !== null) {
            $this->expandMission($builder, $mission, focusWorkOrderId: $workOrder->id);
        } else {
            $this->addWorkOrderNode($builder, $workOrder);
            $this->addEvidenceForWorkOrder($builder, $workOrder);
        }
    }

    private function buildForForgeIntake(CausalGraphBuilder $builder, string $intakeUuid): void
    {
        if (! DatabaseTableAvailability::has('ai_forge_intakes')) {
            $builder->addGap('forge_tables_absent', 'AiForgeIntake table not present');

            return;
        }

        $intake = AiForgeIntake::query()->where('uuid', $intakeUuid)->first();
        if ($intake === null) {
            $builder->addGap('forge_intake_not_found', "forge_intake [{$intakeUuid}] not found");

            return;
        }

        $this->expandForgeIntake($builder, $intake);
    }

    /* ============================================================ */
    /* Mission expansion */
    /* ============================================================ */

    private function expandMission(CausalGraphBuilder $builder, AiMission $mission, ?string $focusWorkOrderId = null): void
    {
        // Root mission_step node represents the mission itself.
        $missionNodeId = $builder->addNode(
            AtlasLongHorizonCanon::CAUSAL_NODE_MISSION_STEP,
            "mission:{$mission->uuid}",
            [
                'mission_uuid' => $mission->uuid,
                'mission_type' => $mission->mission_type,
                'status' => $mission->status,
                'risk_level' => $mission->risk_level,
                'autonomy_level' => $mission->autonomy_level,
            ],
        );

        // Mission blocker (if blocked).
        if ($mission->status === 'blocked' && ! empty($mission->blocker_reason)) {
            $blockerNodeId = $builder->addNode(
                AtlasLongHorizonCanon::CAUSAL_NODE_BLOCKER,
                "mission_blocker:{$mission->uuid}",
                [
                    'kind' => 'mission_blocker',
                    'mission_uuid' => $mission->uuid,
                    // blocker_reason é texto livre; truncamos e classificamos como hash para evitar leak
                    'blocker_signature_hash' => hash('sha256', (string) $mission->blocker_reason),
                ],
            );
            $builder->addEdge(AtlasLongHorizonCanon::CAUSAL_EDGE_BLOCKED_BY, $missionNodeId, $blockerNodeId);
            $builder->addBlocker($blockerNodeId);
        }

        // Objectives.
        $objectives = $mission->objectives()->orderBy('priority')->orderBy('created_at')->get();
        $objectiveNodeByModelId = [];
        foreach ($objectives as $objective) {
            $objNodeId = $builder->addNode(
                AtlasLongHorizonCanon::CAUSAL_NODE_MISSION_STEP,
                "objective:{$objective->uuid}",
                [
                    'objective_uuid' => $objective->uuid,
                    'priority' => $objective->priority,
                    'objective_type' => $objective->objective_type,
                    'status' => $objective->status,
                ],
            );
            $builder->addEdge(AtlasLongHorizonCanon::CAUSAL_EDGE_DEPENDS_ON, $objNodeId, $missionNodeId);
            $objectiveNodeByModelId[(string) $objective->id] = $objNodeId;
        }

        // Work orders.
        $workOrdersQuery = $mission->workOrders()->orderBy('created_at');
        if ($focusWorkOrderId !== null) {
            $workOrdersQuery->where('id', $focusWorkOrderId);
        }
        $workOrders = $workOrdersQuery->get();

        $workOrderNodeByModelId = [];
        foreach ($workOrders as $workOrder) {
            $woNodeId = $this->addWorkOrderNode($builder, $workOrder);
            $workOrderNodeByModelId[(string) $workOrder->id] = $woNodeId;

            $parentObjectiveNodeId = $objectiveNodeByModelId[(string) $workOrder->objective_id] ?? null;
            if ($parentObjectiveNodeId !== null) {
                $builder->addEdge(AtlasLongHorizonCanon::CAUSAL_EDGE_DEPENDS_ON, $woNodeId, $parentObjectiveNodeId);
            } else {
                $builder->addEdge(AtlasLongHorizonCanon::CAUSAL_EDGE_DEPENDS_ON, $woNodeId, $missionNodeId);
            }
        }

        // Router decisions (decision nodes) — incluem mission_id direto.
        if (DatabaseTableAvailability::has('ai_atlas_router_decisions')) {
            $routerDecisions = AiAtlasRouterDecision::query()
                ->where('mission_id', $mission->id)
                ->orderBy('created_at')
                ->get();

            $previousDecisionNodeId = null;
            foreach ($routerDecisions as $decision) {
                $decisionNodeId = $builder->addNode(
                    AtlasLongHorizonCanon::CAUSAL_NODE_DECISION,
                    "router_decision:{$decision->uuid}",
                    [
                        'router_decision_uuid' => $decision->uuid,
                        'primary_domain' => $decision->primary_domain,
                        'routing_mode' => $decision->routing_mode,
                        'status' => $decision->status,
                        'receipt_hash' => $decision->receipt_hash,
                    ],
                );

                $linkedWoNodeId = $workOrderNodeByModelId[(string) $decision->work_order_id] ?? null;
                if ($linkedWoNodeId !== null) {
                    $builder->addEdge(AtlasLongHorizonCanon::CAUSAL_EDGE_DEPENDS_ON, $linkedWoNodeId, $decisionNodeId);
                } else {
                    $builder->addEdge(AtlasLongHorizonCanon::CAUSAL_EDGE_DEPENDS_ON, $missionNodeId, $decisionNodeId);
                }

                if ($previousDecisionNodeId !== null) {
                    $builder->addEdge(AtlasLongHorizonCanon::CAUSAL_EDGE_CAUSED, $previousDecisionNodeId, $decisionNodeId);
                }
                $previousDecisionNodeId = $decisionNodeId;

                // Decision receipts (router_decision_id FK).
                if (DatabaseTableAvailability::has('ai_atlas_decision_receipts')) {
                    $receipts = AiAtlasDecisionReceipt::query()
                        ->where('router_decision_id', $decision->id)
                        ->get();
                    foreach ($receipts as $receipt) {
                        $receiptNodeId = $builder->addNode(
                            AtlasLongHorizonCanon::CAUSAL_NODE_RECEIPT,
                            "router_receipt:{$receipt->uuid}",
                            [
                                'receipt_uuid' => $receipt->uuid,
                                'receipt_type' => $receipt->receipt_type,
                                'receipt_hash' => $receipt->receipt_hash,
                            ],
                        );
                        $builder->addEdge(AtlasLongHorizonCanon::CAUSAL_EDGE_VERIFIED_BY, $decisionNodeId, $receiptNodeId);
                    }
                }
            }
        }

        // Mission events: transitions, follow_through cycles, certify_failed.
        foreach ($mission->events()->orderBy('created_at')->get() as $event) {
            $this->mapMissionEvent($builder, $event, $missionNodeId);
        }

        // Evidence refs (mission scope + per work_order).
        foreach ($mission->evidenceRefs()->orderBy('created_at')->get() as $evidence) {
            $this->addEvidenceNode(
                $builder,
                $evidence,
                $workOrderNodeByModelId[(string) $evidence->work_order_id] ?? $missionNodeId,
            );
        }

        // Latest certification.
        $latestCert = $mission->latestCertification()->first();
        if ($latestCert instanceof AiMissionCertification) {
            $certNodeId = $builder->addNode(
                AtlasLongHorizonCanon::CAUSAL_NODE_CERTIFICATION,
                "certification:{$latestCert->uuid}",
                [
                    'certification_uuid' => $latestCert->uuid,
                    'status' => $latestCert->status,
                    'certification_hash' => $latestCert->certification_hash,
                    'missing_count' => count((array) $latestCert->missing_requirements),
                ],
            );
            $builder->addEdge(AtlasLongHorizonCanon::CAUSAL_EDGE_VERIFIED_BY, $missionNodeId, $certNodeId);
        }
    }

    private function mapMissionEvent(CausalGraphBuilder $builder, AiMissionEvent $event, string $missionNodeId): void
    {
        $eventType = (string) $event->event_type;
        $statusAfter = (string) ($event->status_after ?? '');

        // Repair detection: transition→repairing OR follow_through.cycle.repaired
        if (($eventType === 'mission.transition' && $statusAfter === 'repairing')
            || $eventType === 'follow_through.cycle.repaired') {
            $nodeId = $builder->addNode(
                AtlasLongHorizonCanon::CAUSAL_NODE_REPAIR,
                "repair_event:{$event->uuid}",
                [
                    'event_uuid' => $event->uuid,
                    'event_type' => $eventType,
                    'actor_type' => $event->actor_type,
                    'receipt_hash' => $event->receipt_hash,
                ],
            );
            $builder->addEdge(AtlasLongHorizonCanon::CAUSAL_EDGE_REPAIRED_BY, $missionNodeId, $nodeId);

            return;
        }

        // Blocker via cycle event.
        if ($eventType === 'follow_through.cycle.blocked'
            || ($eventType === 'mission.transition' && $statusAfter === 'blocked')) {
            $nodeId = $builder->addNode(
                AtlasLongHorizonCanon::CAUSAL_NODE_BLOCKER,
                "blocker_event:{$event->uuid}",
                [
                    'event_uuid' => $event->uuid,
                    'event_type' => $eventType,
                    'actor_type' => $event->actor_type,
                ],
            );
            $builder->addEdge(AtlasLongHorizonCanon::CAUSAL_EDGE_BLOCKED_BY, $missionNodeId, $nodeId);
            $builder->addBlocker($nodeId);

            return;
        }

        // Cycle decision events are not separate nodes (covered by router decisions);
        // we skip them silently to avoid graph noise.
    }

    /* ============================================================ */
    /* Work order + evidence */
    /* ============================================================ */

    private function addWorkOrderNode(CausalGraphBuilder $builder, AiWorkOrder $workOrder): string
    {
        return $builder->addNode(
            AtlasLongHorizonCanon::CAUSAL_NODE_WORK_PACKET,
            "work_order:{$workOrder->uuid}",
            [
                'work_order_uuid' => $workOrder->uuid,
                'objective_id' => $workOrder->objective_id,
                'domain_runtime' => $workOrder->domain_runtime,
                'flow_profile' => $workOrder->flow_profile,
                'status' => $workOrder->status,
                'receipt_hash' => $workOrder->receipt_hash,
            ],
        );
    }

    private function addEvidenceForWorkOrder(CausalGraphBuilder $builder, AiWorkOrder $workOrder): void
    {
        $woNodeId = "node:work_packet:work_order:{$workOrder->uuid}";
        if (! DatabaseTableAvailability::has('ai_mission_evidence_refs')) {
            return;
        }
        $evidences = AiMissionEvidenceRef::query()
            ->where('work_order_id', $workOrder->id)
            ->orderBy('created_at')
            ->get();
        foreach ($evidences as $evidence) {
            $this->addEvidenceNode($builder, $evidence, $woNodeId);
        }
    }

    private function addEvidenceNode(CausalGraphBuilder $builder, AiMissionEvidenceRef $evidence, string $parentNodeId): void
    {
        $evidenceType = (string) $evidence->evidence_type;
        $kind = match ($evidenceType) {
            'test' => AtlasLongHorizonCanon::CAUSAL_NODE_TEST,
            'doc', 'source', 'screenshot', 'diff', 'artifact' => AtlasLongHorizonCanon::CAUSAL_NODE_FILE_REF,
            'receipt' => AtlasLongHorizonCanon::CAUSAL_NODE_RECEIPT,
            'blocker' => AtlasLongHorizonCanon::CAUSAL_NODE_BLOCKER,
            'certification' => AtlasLongHorizonCanon::CAUSAL_NODE_CERTIFICATION,
            default => AtlasLongHorizonCanon::CAUSAL_NODE_FILE_REF,
        };

        $nodeId = $builder->addNode(
            $kind,
            "evidence:{$evidence->uuid}",
            [
                'evidence_uuid' => $evidence->uuid,
                'evidence_type' => $evidenceType,
                // `evidence_ref` (pointer canon) é safe: é URI/ID/comando, não texto raw
                'evidence_ref' => $evidence->evidence_ref,
                'evidence_hash' => $evidence->evidence_hash,
            ],
        );
        $builder->addEdge(AtlasLongHorizonCanon::CAUSAL_EDGE_VERIFIED_BY, $parentNodeId, $nodeId);
        $builder->addEvidenceRef($evidence->evidence_ref);

        if ($evidenceType === 'blocker') {
            $builder->addBlocker($nodeId);
        }
    }

    /* ============================================================ */
    /* Forge intake expansion */
    /* ============================================================ */

    private function expandForgeIntake(CausalGraphBuilder $builder, AiForgeIntake $intake): void
    {
        $intakeNodeId = $builder->addNode(
            AtlasLongHorizonCanon::CAUSAL_NODE_WORK_PACKET,
            "forge_intake:{$intake->uuid}",
            [
                'forge_intake_uuid' => $intake->uuid,
                'recommended_forge_mode' => $intake->recommended_forge_mode,
                'mission_id' => $intake->mission_id,
                'status' => $intake->status,
                'risk_band' => $intake->risk_band,
                'intake_hash' => $intake->intake_hash,
            ],
        );

        if ($intake->status === 'blocked' && ! empty($intake->blocker_reason)) {
            $blockerNodeId = $builder->addNode(
                AtlasLongHorizonCanon::CAUSAL_NODE_BLOCKER,
                "forge_intake_blocker:{$intake->uuid}",
                [
                    'kind' => 'forge_intake_blocker',
                    'forge_intake_uuid' => $intake->uuid,
                    'blocker_signature_hash' => hash('sha256', (string) $intake->blocker_reason),
                ],
            );
            $builder->addEdge(AtlasLongHorizonCanon::CAUSAL_EDGE_BLOCKED_BY, $intakeNodeId, $blockerNodeId);
            $builder->addBlocker($blockerNodeId);
        }

        // Cross-link to mission step when intake carries mission_id.
        if (! empty($intake->mission_id) && $this->missionTablesPresent()) {
            $linkedMission = AiMission::query()->find($intake->mission_id);
            if ($linkedMission !== null) {
                $missionStepId = $builder->addNode(
                    AtlasLongHorizonCanon::CAUSAL_NODE_MISSION_STEP,
                    "mission:{$linkedMission->uuid}",
                    [
                        'mission_uuid' => $linkedMission->uuid,
                        'mission_type' => $linkedMission->mission_type,
                        'status' => $linkedMission->status,
                    ],
                );
                $builder->addEdge(AtlasLongHorizonCanon::CAUSAL_EDGE_DEPENDS_ON, $intakeNodeId, $missionStepId);
            }
        }

        // Work packets.
        foreach ($intake->workPackets as $packet) {
            $packetNodeId = $builder->addNode(
                AtlasLongHorizonCanon::CAUSAL_NODE_WORK_PACKET,
                "forge_packet:{$packet->uuid}",
                [
                    'work_packet_uuid' => $packet->uuid,
                    'packet_position' => $packet->packet_position,
                    'status' => $packet->status,
                    'risk_band' => $packet->risk_band,
                    'packet_hash' => $packet->packet_hash,
                ],
            );
            $builder->addEdge(AtlasLongHorizonCanon::CAUSAL_EDGE_DEPENDS_ON, $packetNodeId, $intakeNodeId);
        }

        // Milestones.
        foreach ($intake->milestones as $milestone) {
            $milestoneNodeId = $builder->addNode(
                AtlasLongHorizonCanon::CAUSAL_NODE_MISSION_STEP,
                "forge_milestone:{$milestone->uuid}",
                [
                    'milestone_uuid' => $milestone->uuid,
                    'position' => $milestone->position,
                    'status' => $milestone->status,
                    'milestone_hash' => $milestone->milestone_hash,
                ],
            );
            $builder->addEdge(AtlasLongHorizonCanon::CAUSAL_EDGE_DEPENDS_ON, $milestoneNodeId, $intakeNodeId);

            if ($milestone->status === 'blocked' && ! empty($milestone->blocker_reason)) {
                $blockerNodeId = $builder->addNode(
                    AtlasLongHorizonCanon::CAUSAL_NODE_BLOCKER,
                    "forge_milestone_blocker:{$milestone->uuid}",
                    [
                        'kind' => 'milestone_blocker',
                        'milestone_uuid' => $milestone->uuid,
                        'blocker_signature_hash' => hash('sha256', (string) $milestone->blocker_reason),
                    ],
                );
                $builder->addEdge(AtlasLongHorizonCanon::CAUSAL_EDGE_BLOCKED_BY, $milestoneNodeId, $blockerNodeId);
                $builder->addBlocker($blockerNodeId);
            }
        }
    }

    /* ============================================================ */
    /* Finalize payload + hash */
    /* ============================================================ */

    /**
     * @return array<string,mixed>
     */
    private function finalize(CausalGraphBuilder $builder, string $scopeType, string $scopeId): array
    {
        $payload = [
            'schema_version' => AtlasLongHorizonCanon::CAUSAL_GRAPH_LITE_SCHEMA_VERSION,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'nodes' => $builder->nodesArray(),
            'edges' => $builder->edgesArray(),
            'gaps' => $builder->gaps(),
            'blockers' => $builder->blockers(),
            'evidence_refs' => $builder->evidenceRefs(),
            'summary' => [
                'nodes_count' => count($builder->nodesArray()),
                'edges_count' => count($builder->edgesArray()),
                'gaps_count' => count($builder->gaps()),
                'blockers_count' => count($builder->blockers()),
                'evidence_refs_count' => count($builder->evidenceRefs()),
            ],
        ];

        $payload['graph_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    private function missionTablesPresent(): bool
    {
        return DatabaseTableAvailability::all([
            'ai_missions',
            'ai_objectives',
            'ai_work_orders',
        ]);
    }
}
