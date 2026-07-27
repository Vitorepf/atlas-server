<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Models\AtlasProgrammingWorkItem;
use App\Models\AtlasProject;
use App\Services\Ai\DualCore\CanonicalRouteDecisionEnvelope;
use App\Services\Ai\Programming\Governance\ProgrammingGovernanceService;

/**
 * Application service for Obra ↔ Programming WorkItem binding.
 *
 * Extracted so Forge FastPath / cert flows do not import Http Controllers (ASDD D4).
 * Controller remains a thin HTTP adapter over this service.
 */
final class ProgrammingWorkItemBindingService
{
    public function __construct(
        private readonly ProgrammingGovernanceService $governance,
    ) {}

    /**
     * Bind an existing WorkItem or create one from project intent.
     *
     * @return array{status:string,created?:bool,work_item?:AtlasProgrammingWorkItem,error?:string,http_status:int,payload:array<string,mixed>}
     */
    public function bindOrCreate(AtlasProject $project, ?string $intent = null, ?string $owner = null, ?string $type = null, ?string $mode = null, ?string $risk = null): array
    {
        $existing = ProgrammingWorkItemContractSupport::existingWorkItem($project);
        if ($existing) {
            $payload = $this->responsePayload($project, $existing, created: false);

            return [
                'status' => 'bound',
                'created' => false,
                'work_item' => $existing,
                'http_status' => 200,
                'payload' => $payload,
            ];
        }

        $resolvedIntent = ProgrammingWorkItemContractSupport::intentFor($project, $intent);
        if ($resolvedIntent === '') {
            return [
                'status' => 'blocked',
                'error' => 'programming_intent_required',
                'http_status' => 422,
                'payload' => [
                    'schema_version' => 'atlas.code.programming_work_item_binding_response.v1',
                    'work_id' => (string) $project->getKey(),
                    'created' => false,
                    'status' => 'blocked',
                    'error' => 'programming_intent_required',
                    'route_decision' => CanonicalRouteDecisionEnvelope::emit(
                        route: 'programming',
                        reason: 'programming_work_item_binding_service',
                    ),
                ],
            ];
        }

        $workspace = trim((string) data_get($project->metadata, 'workspace_path', ''));
        $snapshot = $this->governance->intake($resolvedIntent, array_filter([
            'owner' => $owner ?? 'atlas-code',
            'type' => $type,
            'mode' => $mode,
            'risk' => $risk,
            'workspace' => $workspace !== '' ? $workspace : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));

        $workItem = AtlasProgrammingWorkItem::query()->findOrFail((string) $snapshot['id']);
        $workItem->forceFill([
            'metadata_json' => ProgrammingWorkItemContractSupport::workItemMetadata($project, (array) $workItem->metadata_json),
        ])->save();

        ProgrammingWorkItemContractSupport::rememberBinding($project, $workItem);
        $workItem = $workItem->refresh();
        $payload = $this->responsePayload($project->refresh(), $workItem, created: true);

        return [
            'status' => 'bound',
            'created' => true,
            'work_item' => $workItem,
            'http_status' => 201,
            'payload' => $payload,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function responsePayload(
        AtlasProject $project,
        AtlasProgrammingWorkItem $workItem,
        bool $created,
    ): array {
        $snapshot = $this->governance->snapshot($workItem);

        return [
            'schema_version' => 'atlas.code.programming_work_item_binding_response.v1',
            'work_id' => (string) $project->getKey(),
            'created' => $created,
            'status' => 'bound',
            'binding' => [
                'schema_version' => 'atlas.code.programming_work_item_binding.v1',
                'surface_id' => 'atlas_code',
                'flow_id' => 'programming.forge',
                'work_item_id' => (string) $workItem->id,
                'work_item_code' => (string) $workItem->code,
                'source_authority' => 'AtlasProject.metadata.programming_work_item_id',
            ],
            'work_item' => $snapshot,
            'programming_governance' => [
                'schema_version' => 'atlas.code.programming_governance_snapshot.v1',
                'source_authority' => 'atlas_programming_work_items',
                'work_item' => $snapshot,
                'spec' => $snapshot['spec'] === [] ? null : $snapshot['spec'],
                'plan' => $snapshot['plan'] === [] ? null : $snapshot['plan'],
                'tasks' => $snapshot['tasks'],
                'gate_runs' => [],
                'reviews' => [],
                'evidence_refs' => $snapshot['evidence_refs'],
                'artifacts' => [],
                'degraded' => false,
                'degraded_reason' => null,
            ],
        ];
    }
}
