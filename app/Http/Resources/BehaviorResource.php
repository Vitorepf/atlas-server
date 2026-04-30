<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BehaviorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'category' => $this->category,
            'input_type' => $this->input_type,
            'question_text' => $this->question_text,
            'default_value' => $this->default_value,
            'parent_factor' => $this->parent_factor,
            'factor_condition' => $this->factor_condition,
            'target_outcomes' => $this->target_outcomes ?? [],
            'expected_lag' => $this->expected_lag,
            'expected_direction' => $this->expected_direction,
            'granularity_level' => $this->granularity_level,
            'sensitivity_level' => $this->sensitivity_level,
            'derived_from' => Metadata::forResponse($this->derived_from),
            'operator_confirmed' => $this->operator_confirmed,
            'created_by' => $this->created_by,
            'source_capture_ids' => Metadata::forResponse($this->source_capture_ids),
            'activation_rules' => Metadata::forResponse($this->activation_rules),
            'lifecycle_status' => $this->lifecycle_status ?? 'active',
            'paused_until' => $this->paused_until?->toJSON(),
            'last_prompted_at' => $this->last_prompted_at?->toDateString(),
            'prompt_cadence_days' => $this->prompt_cadence_days ?? 1,
            'auto_suppress_reason' => $this->auto_suppress_reason,
            'show_in_morning_briefing' => $this->show_in_morning_briefing,
            'priority_score' => $this->priority_score,
            'streak_yes' => $this->streak_yes,
            'streak_no' => $this->streak_no,
            'total_yes_count' => $this->total_yes_count,
            'total_no_count' => $this->total_no_count,
            'relational_privacy' => $this->relational_privacy,
            'activated_at' => $this->activated_at?->toJSON(),
            'archived_at' => $this->archived_at?->toJSON(),
            'promoted_to_object_type' => $this->promoted_to_object_type,
            'promoted_to_object_id' => $this->promoted_to_object_id,
            'metadata' => Metadata::forResponse($this->metadata),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
            'deleted_at' => $this->deleted_at?->toJSON(),
        ];
    }
}
