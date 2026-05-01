<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiProviderCostRateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'model' => $this->model,
            'input_microusd_per_1k' => $this->input_microusd_per_1k,
            'output_microusd_per_1k' => $this->output_microusd_per_1k,
            'currency' => $this->currency,
            'effective_from' => $this->effective_from?->toJSON(),
            'effective_until' => $this->effective_until?->toJSON(),
            'metadata' => Metadata::forResponse($this->metadata),
            'created_at' => $this->created_at?->toJSON(),
        ];
    }
}
