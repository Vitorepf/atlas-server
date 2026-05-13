<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AtlasTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'priority' => $this->priority,
            'domain' => $this->domain,
            'source_capture_id' => $this->source_capture_id,
            'project_id' => $this->project_id,
            'project_step_id' => $this->project_step_id,
            'routine_id' => $this->routine_id,
            'routine_occurrence_date' => $this->routine_occurrence_date?->toDateString(),
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project?->id,
                'title' => $this->project?->title,
                'status' => $this->project?->status,
                'domain' => $this->project?->domain,
                'project_type' => $this->project?->project_type,
            ]),
            'routine' => $this->whenLoaded('routine', fn () => [
                'id' => $this->routine?->id,
                'title' => $this->routine?->title,
                'status' => $this->routine?->status,
                'domain' => $this->routine?->domain,
                'frequency' => $this->routine?->frequency,
            ]),
            'project_step' => $this->whenLoaded('projectStep', fn () => [
                'id' => $this->projectStep?->id,
                'title' => $this->projectStep?->title,
                'status' => $this->projectStep?->status,
                'step_order' => $this->projectStep?->step_order,
                'step_type' => $this->projectStep?->step_type,
            ]),
            'due_at' => $this->due_at?->toJSON(),
            'planned_for_date' => $this->planned_for_date?->toDateString(),
            'planned_start_at' => $this->planned_start_at?->toJSON(),
            'planned_end_at' => $this->planned_end_at?->toJSON(),
            'estimated_minutes' => $this->estimated_minutes,
            'energy_required' => $this->energy_required,
            'urgency_score' => $this->urgency_score,
            'impact_score' => $this->impact_score,
            'effort_score' => $this->effort_score,
            'priority_score' => $this->priority_score,
            'planning_status' => $this->planning_status,
            'completed_at' => $this->completed_at?->toJSON(),
            'execution_mode' => $this->execution_mode,
            'friction_level' => $this->friction_level,
            'emotional_resistance' => $this->emotional_resistance,
            'clarity_level' => $this->clarity_level,
            'starter_step' => $this->starter_step,
            'minimum_viable_action' => $this->minimum_viable_action,
            'if_then_plan' => $this->if_then_plan,
            'reward_hint' => $this->reward_hint,
            'failure_reason_last' => $this->failure_reason_last,
            'attempt_count' => $this->attempt_count,
            'recovery_count' => $this->recovery_count,
            'metadata' => Metadata::forResponse($this->metadata),
            'safety' => $this->safetySummary(),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
            'deleted_at' => $this->deleted_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function safetySummary(): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];

        return [
            'schema_version' => 'atlas.task_orchestration.task_safety.v1',
            'read_model_only' => true,
            'provider_dispatch_allowed' => false,
            'runtime_execution_allowed' => false,
            'policy_mutation_allowed' => false,
            'auto_complete_allowed' => false,
            'source_capture_linked' => $this->source_capture_id !== null,
            'has_engineering_contract' => data_get($metadata, 'engineering_contract') !== null,
            'has_latest_engineering_run' => data_get($metadata, 'latest_engineering_run') !== null,
            'has_schedule' => $this->planned_for_date !== null || $this->planned_start_at !== null || $this->due_at !== null,
            'status' => $this->status,
            'planning_status' => $this->planning_status,
            'priority' => $this->priority,
        ];
    }
}
