<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AtlasProjectPlanProposalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'source_capture_id' => $this->source_capture_id,
            'status' => $this->status,
            'proposed_title' => $this->proposed_title,
            'planner_version' => $this->planner_version,
            'input_hash' => $this->input_hash,
            'project_type' => $this->project_type,
            'avoidance_profile' => $this->avoidance_profile,
            'desired_outcome' => $this->desired_outcome,
            'definition_of_done' => $this->definition_of_done,
            'minimum_useful_result' => $this->minimum_useful_result,
            'first_milestone' => $this->first_milestone,
            'first_next_action' => $this->first_next_action,
            'estimated_energy' => $this->estimated_energy,
            'estimated_duration_minutes' => $this->estimated_duration_minutes,
            'priority_suggestion' => $this->priority_suggestion,
            'confidence' => $this->confidence,
            'phases' => Metadata::listForResponse($this->phases_json),
            'steps' => Metadata::listForResponse($this->steps_json),
            'risks' => Metadata::listForResponse($this->risks_json),
            'questions' => Metadata::listForResponse($this->questions_json),
            'rationale' => $this->rationale,
            'metadata' => Metadata::forResponse($this->metadata),
            'accepted_at' => $this->accepted_at?->toJSON(),
            'rejected_at' => $this->rejected_at?->toJSON(),
            'project' => $this->whenLoaded('project', fn () => $this->project ? [
                'id' => $this->project->id,
                'title' => $this->project->title,
                'status' => $this->project->status,
                'domain' => $this->project->domain,
            ] : null),
            'source_capture' => $this->whenLoaded('sourceCapture', fn () => $this->sourceCapture ? [
                'id' => $this->sourceCapture->id,
                'kind' => $this->sourceCapture->kind,
                'domain' => $this->sourceCapture->domain,
                'captured_at' => $this->sourceCapture->captured_at?->toJSON(),
            ] : null),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
