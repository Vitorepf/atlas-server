<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiProviderHealthResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'status' => $this->status,
            'checked_at' => $this->checked_at?->toJSON(),
            'last_success_at' => $this->last_success_at?->toJSON(),
            'last_failure_at' => $this->last_failure_at?->toJSON(),
            'total_jobs_24h' => $this->total_jobs_24h,
            'failed_jobs_24h' => $this->failed_jobs_24h,
            'p50_latency_ms' => $this->p50_latency_ms,
            'operational_pain_score' => $this->operational_pain_score,
            'message' => $this->message,
            'metadata' => Metadata::forResponse($this->metadata),
            'created_at' => $this->created_at?->toJSON(),
        ];
    }
}
