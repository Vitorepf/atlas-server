<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HealthSnapshotResource extends JsonResource
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
            'readiness_score' => $this->readiness_score,
            'current_score' => $this->current_score,
            'body_score' => $this->body_score,
            'mind_score' => $this->mind_score,
            'drive_score' => $this->drive_score,
            'sleep_score' => $this->sleep_score,
            'autonomic_score' => $this->autonomic_score,
            'load_score' => $this->load_score,
            'subjective_score' => $this->subjective_score,
            'stability_score' => $this->stability_score,
            'confidence' => $this->confidence,
            'sleep_duration_hours' => $this->sleep_duration_hours,
            'sleep_efficiency' => $this->sleep_efficiency,
            'hrv_ms' => $this->hrv_ms,
            'resting_heart_rate_bpm' => $this->resting_heart_rate_bpm,
            'respiratory_rate' => $this->respiratory_rate,
            'wrist_temperature_c' => $this->wrist_temperature_c,
            'active_energy_kcal' => $this->active_energy_kcal,
            'basal_energy_kcal' => $this->basal_energy_kcal,
            'exercise_minutes' => $this->exercise_minutes,
            'stand_minutes' => $this->stand_minutes,
            'steps' => $this->steps,
            'walking_running_distance_m' => $this->walking_running_distance_m,
            'vo2max' => $this->vo2max,
            'body_mass_kg' => $this->body_mass_kg,
            'body_fat_percentage' => $this->body_fat_percentage,
            'lean_body_mass_kg' => $this->lean_body_mass_kg,
            'muscle_mass_percentage' => $this->muscle_mass_percentage,
            'body_mass_index' => $this->body_mass_index,
            'waist_circumference_cm' => $this->waist_circumference_cm,
            'energy_level' => $this->energy_level,
            'mood_level' => $this->mood_level,
            'state' => $this->state,
            'metrics' => Metadata::forResponse($this->metrics),
            'readiness' => Metadata::forResponse($this->readiness),
            'sleep' => Metadata::forResponse($this->sleep),
            'recovery' => Metadata::forResponse($this->recovery),
            'load' => Metadata::forResponse($this->load),
            'subjective' => Metadata::forResponse($this->subjective),
            'body' => Metadata::forResponse($this->body),
            'metadata' => Metadata::forResponse($this->metadata),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
            'deleted_at' => $this->deleted_at?->toJSON(),
        ];
    }
}
