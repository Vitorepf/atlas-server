<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AtlasProjectStepResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'active_task_id' => $this->active_task_id,
            'active_task' => $this->whenLoaded('activeTask', fn () => new AtlasTaskResource($this->activeTask)),
            'step_order' => $this->step_order,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'step_type' => $this->step_type,
            'expected_output' => $this->expected_output,
            'acceptance_criteria' => $this->acceptance_criteria,
            'estimated_minutes' => $this->estimated_minutes,
            'energy_required' => $this->energy_required,
            'friction_level' => $this->friction_level,
            'metadata' => Metadata::forResponse($this->metadata),
            'started_at' => $this->started_at?->toJSON(),
            'completed_at' => $this->completed_at?->toJSON(),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
