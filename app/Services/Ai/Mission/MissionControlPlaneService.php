<?php

namespace App\Services\Ai\Mission;

use App\Models\AiMission;
use App\Models\AiMissionCertification;
use App\Models\AiMissionEvent;

class MissionControlPlaneService
{
    public const SCHEMA = 'atlas.ai.mission.control_plane.v1';

    public function __construct(private readonly ?MissionFollowThroughService $followThrough = null) {}

    /**
     * @return array<string,mixed>
     */
    public function snapshot(AiMission $mission): array
    {
        $mission->refresh();

        $objectives = $mission->objectives()->orderBy('priority')->get();
        $workOrders = $mission->workOrders()->orderBy('created_at')->get();
        $evidenceRefs = $mission->evidenceRefs()->orderBy('created_at')->get();
        $events = $mission->events()->latest('created_at')->limit(50)->get()->reverse()->values();
        $latestCertification = $mission->latestCertification()->first();

        $blockers = $mission->events()
            ->where('event_type', 'like', 'blocker.%')
            ->orWhere(function ($query) use ($mission): void {
                $query->where('mission_id', $mission->id)
                    ->where('event_type', 'mission.transition')
                    ->where('status_after', MissionLifecycleService::STATUS_BLOCKED);
            })
            ->latest('created_at')
            ->limit(20)
            ->get();

        return [
            'schema' => self::SCHEMA,
            'generated_at' => now()->toJSON(),
            'mission' => [
                'id' => $mission->id,
                'uuid' => $mission->uuid,
                'title' => $mission->title,
                'mission_type' => $mission->mission_type,
                'status' => $mission->status,
                'autonomy_level' => $mission->autonomy_level,
                'risk_level' => $mission->risk_level,
                'primary_domain' => $mission->primary_domain,
                'secondary_domains' => $mission->secondary_domains,
                'current_step' => $mission->current_step,
                'blocker_reason' => $mission->blocker_reason,
                'evidence_pack_hash' => $mission->evidence_pack_hash,
                'certification_hash' => $mission->certification_hash,
                'completed_at' => optional($mission->completed_at)->toJSON(),
                'created_at' => optional($mission->created_at)->toJSON(),
                'updated_at' => optional($mission->updated_at)->toJSON(),
                'counters' => [
                    'objectives' => $objectives->count(),
                    'work_orders' => $workOrders->count(),
                    'evidence_refs' => $evidenceRefs->count(),
                    'events' => $mission->events()->count(),
                    'certifications' => $mission->certifications()->count(),
                ],
            ],
            'definition_of_done' => $mission->definition_of_done,
            'objectives' => $objectives->map(fn ($o) => $this->serializeObjective($o))->all(),
            'work_orders' => $workOrders->map(fn ($w) => $this->serializeWorkOrder($w))->all(),
            'evidence_refs' => $evidenceRefs->map(fn ($e) => $this->serializeEvidence($e))->all(),
            'events' => $events->map(fn ($ev) => $this->serializeEvent($ev))->all(),
            'latest_certification' => $latestCertification ? $this->serializeCertification($latestCertification) : null,
            'blockers' => $blockers->map(fn ($b) => $this->serializeEvent($b))->all(),
            'next_action' => $this->nextAction($mission, $latestCertification, $evidenceRefs->count()),
            'readiness' => [
                'has_objectives' => $objectives->isNotEmpty(),
                'has_work_orders' => $workOrders->isNotEmpty(),
                'has_evidence' => $evidenceRefs->isNotEmpty(),
                'has_passed_certification' => $latestCertification !== null && $latestCertification->status === MissionCertificationService::STATUS_PASSED,
            ],
            'follow_through' => ($this->followThrough ?? app(MissionFollowThroughService::class))->snapshot($mission),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function serializeObjective($objective): array
    {
        return [
            'id' => $objective->id,
            'uuid' => $objective->uuid,
            'title' => $objective->title,
            'priority' => $objective->priority,
            'status' => $objective->status,
            'objective_type' => $objective->objective_type,
            'success_criteria_count' => count((array) $objective->success_criteria),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function serializeWorkOrder($workOrder): array
    {
        return [
            'id' => $workOrder->id,
            'uuid' => $workOrder->uuid,
            'title' => $workOrder->title,
            'status' => $workOrder->status,
            'objective_id' => $workOrder->objective_id,
            'receipt_hash' => $workOrder->receipt_hash,
            'expected_artifacts_count' => count((array) $workOrder->expected_artifacts),
            'expected_tests_count' => count((array) $workOrder->expected_tests),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function serializeEvidence($evidence): array
    {
        return [
            'id' => $evidence->id,
            'uuid' => $evidence->uuid,
            'evidence_type' => $evidence->evidence_type,
            'evidence_ref' => $evidence->evidence_ref,
            'evidence_hash' => $evidence->evidence_hash,
            'work_order_id' => $evidence->work_order_id,
            'created_at' => optional($evidence->created_at)->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function serializeEvent(AiMissionEvent $event): array
    {
        return [
            'id' => $event->id,
            'uuid' => $event->uuid,
            'event_type' => $event->event_type,
            'status_before' => $event->status_before,
            'status_after' => $event->status_after,
            'actor_type' => $event->actor_type,
            'payload' => $event->payload,
            'receipt_hash' => $event->receipt_hash,
            'created_at' => optional($event->created_at)->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function serializeCertification(AiMissionCertification $certification): array
    {
        return [
            'id' => $certification->id,
            'uuid' => $certification->uuid,
            'status' => $certification->status,
            'certification_hash' => $certification->certification_hash,
            'checked_requirements' => $certification->checked_requirements,
            'missing_requirements' => $certification->missing_requirements,
            'certified_at' => optional($certification->certified_at)->toJSON(),
        ];
    }

    private function nextAction(AiMission $mission, ?AiMissionCertification $latest, int $evidenceCount): ?string
    {
        return match ($mission->status) {
            MissionLifecycleService::STATUS_DRAFT => 'decompose mission into objectives and transition to planned',
            MissionLifecycleService::STATUS_PLANNED => 'plan work_orders and transition to running',
            MissionLifecycleService::STATUS_RUNNING => $evidenceCount > 0
                ? 'attach remaining evidence and request certification'
                : 'execute work_orders and attach evidence refs',
            MissionLifecycleService::STATUS_WAITING_APPROVAL => 'await human approval to continue execution',
            MissionLifecycleService::STATUS_BLOCKED => 'resolve blocker and transition to repairing: '.((string) ($mission->blocker_reason ?? 'unspecified')),
            MissionLifecycleService::STATUS_REPAIRING => 'apply repair and transition back to running',
            MissionLifecycleService::STATUS_CERTIFYING => $latest && $latest->status === MissionCertificationService::STATUS_PASSED
                ? 'transition to completed'
                : 'address missing certification requirements',
            MissionLifecycleService::STATUS_COMPLETED, MissionLifecycleService::STATUS_FAILED, MissionLifecycleService::STATUS_CANCELLED => null,
            default => null,
        };
    }
}
