<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DigitalActivitySnapshotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'source' => $this->source,
            'snapshot_date' => $this->snapshot_date?->toDateString(),
            'snapshot_timezone' => $this->snapshot_timezone,
            'computed_at' => $this->computed_at?->toJSON(),
            'signal_count' => $this->signal_count,
            'total_screen_time_min' => $this->total_screen_time_min,
            'pickups_count' => $this->pickups_count,
            'first_offensive_use_min_after_wake' => $this->first_offensive_use_min_after_wake,
            'deep_work_sessions_count' => $this->deep_work_sessions_count,
            'deep_work_total_min' => $this->deep_work_total_min,
            'notifications_received' => $this->notifications_received,
            'notifications_actioned' => $this->notifications_actioned,
            'curated_input_min' => $this->curated_input_min,
            'algorithmic_input_min' => $this->algorithmic_input_min,
            'intentional_entertainment_min' => $this->intentional_entertainment_min,
            'default_entertainment_min' => $this->default_entertainment_min,
            'communication_primary_min' => $this->communication_primary_min,
            'communication_shallow_min' => $this->communication_shallow_min,
            'market_min' => $this->market_min,
            'focus_mode_active_min' => Metadata::forResponse($this->focus_mode_active_min),
            'category_breakdown' => Metadata::forResponse($this->category_breakdown),
            'source_breakdown' => Metadata::forResponse($this->source_breakdown),
            'raw_rize_data' => Metadata::forResponse($this->raw_rize_data),
            'raw_screentime_data' => Metadata::forResponse($this->raw_screentime_data),
            'metadata' => Metadata::forResponse($this->metadata),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
            'deleted_at' => $this->deleted_at?->toJSON(),
        ];
    }
}
