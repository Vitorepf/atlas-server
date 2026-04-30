<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use App\Support\ProjectExecutionHealth;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AtlasProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'domain' => $this->domain,
            'source_capture_id' => $this->source_capture_id,
            'goal' => $this->goal,
            'next_action' => $this->next_action,
            'project_type' => $this->project_type,
            'desired_outcome' => $this->desired_outcome,
            'minimum_viable_outcome' => $this->minimum_viable_outcome,
            'definition_of_done' => $this->definition_of_done,
            'why_now' => $this->why_now,
            'deadline_at' => $this->deadline_at?->toJSON(),
            'deadline_kind' => $this->deadline_kind,
            'priority' => $this->priority,
            'energy_profile' => $this->energy_profile,
            'avoidance_reason' => $this->avoidance_reason,
            'active_next_task_id' => $this->active_next_task_id,
            'active_next_task' => $this->whenLoaded('activeNextTask', fn () => new AtlasTaskResource($this->activeNextTask)),
            'current_step_id' => $this->current_step_id,
            'current_step' => $this->whenLoaded('currentStep', fn () => new AtlasProjectStepResource($this->currentStep)),
            'tasks_count' => $this->whenCounted('tasks'),
            'steps_count' => $this->whenCounted('steps'),
            'execution_health' => ProjectExecutionHealth::for($this->resource),
            'last_touched_at' => $this->last_touched_at?->toJSON(),
            'next_review_at' => $this->next_review_at?->toJSON(),
            'completed_at' => $this->completed_at?->toJSON(),
            'paused_until' => $this->paused_until?->toJSON(),
            'metadata' => Metadata::forResponse($this->metadata),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
            'deleted_at' => $this->deleted_at?->toJSON(),
        ];
    }

}
