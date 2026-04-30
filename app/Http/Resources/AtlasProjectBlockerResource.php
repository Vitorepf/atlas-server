<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AtlasProjectBlockerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'task_id' => $this->task_id,
            'project_step_id' => $this->project_step_id,
            'unblock_task_id' => $this->unblock_task_id,
            'status' => $this->status,
            'severity' => $this->severity,
            'reason_code' => $this->reason_code,
            'description' => $this->description,
            'unblock_next_action' => $this->unblock_next_action,
            'waiting_on' => $this->waiting_on,
            'due_at' => $this->due_at?->toJSON(),
            'resolved_at' => $this->resolved_at?->toJSON(),
            'resolution_note' => $this->resolution_note,
            'created_from_event_id' => $this->created_from_event_id,
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project?->id,
                'title' => $this->project?->title,
                'status' => $this->project?->status,
                'domain' => $this->project?->domain,
            ]),
            'task' => $this->whenLoaded('task', fn () => [
                'id' => $this->task?->id,
                'title' => $this->task?->title,
                'status' => $this->task?->status,
                'planning_status' => $this->task?->planning_status,
            ]),
            'project_step' => $this->whenLoaded('projectStep', fn () => [
                'id' => $this->projectStep?->id,
                'title' => $this->projectStep?->title,
                'status' => $this->projectStep?->status,
                'step_order' => $this->projectStep?->step_order,
            ]),
            'unblock_task' => $this->whenLoaded('unblockTask', fn () => [
                'id' => $this->unblockTask?->id,
                'title' => $this->unblockTask?->title,
                'status' => $this->unblockTask?->status,
                'planning_status' => $this->unblockTask?->planning_status,
            ]),
            'metadata' => Metadata::forResponse($this->metadata),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
