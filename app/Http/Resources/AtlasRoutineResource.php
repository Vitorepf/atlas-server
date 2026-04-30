<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AtlasRoutineResource extends JsonResource
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
            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project?->id,
                'title' => $this->project?->title,
                'status' => $this->project?->status,
                'domain' => $this->project?->domain,
                'project_type' => $this->project?->project_type,
            ]),
            'frequency' => $this->frequency,
            'weekdays' => $this->weekdays ?? [],
            'timezone' => $this->timezone,
            'preferred_time' => $this->preferred_time,
            'estimated_minutes' => $this->estimated_minutes,
            'energy_required' => $this->energy_required,
            'priority' => $this->priority,
            'execution_mode' => $this->execution_mode,
            'friction_level' => $this->friction_level,
            'emotional_resistance' => $this->emotional_resistance,
            'clarity_level' => $this->clarity_level,
            'starter_step' => $this->starter_step,
            'minimum_viable_action' => $this->minimum_viable_action,
            'if_then_plan' => $this->if_then_plan,
            'reward_hint' => $this->reward_hint,
            'next_occurrence_date' => $this->next_occurrence_date?->toDateString(),
            'last_generated_for_date' => $this->last_generated_for_date?->toDateString(),
            'tasks_count' => $this->whenCounted('tasks'),
            'metadata' => Metadata::forResponse($this->metadata),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
            'deleted_at' => $this->deleted_at?->toJSON(),
        ];
    }
}
