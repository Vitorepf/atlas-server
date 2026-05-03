<?php

namespace App\Http\Resources;

use App\Support\AiAttachmentPayload;
use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiJobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trace_id' => $this->trace_id,
            'client_id' => $this->client_id,
            'kind' => $this->kind,
            'status' => $this->status,
            'awaiting_user_choice' => $this->status === 'awaiting_user_choice',
            'choice_options' => data_get($this->metadata, 'choice_options'),
            'provider_choice_state' => data_get($this->metadata, 'provider_choice_state'),
            'provider_choice_error_code' => data_get($this->metadata, 'provider_choice_error_code'),
            'provider_reset_at' => data_get($this->metadata, 'provider_reset_at'),
            'reset_hint' => data_get($this->metadata, 'reset_hint'),
            'priority' => $this->priority,
            'agent_slug' => $this->agent_slug,
            'provider' => $this->provider,
            'model' => $this->model,
            'input_text' => $this->input_text,
            'context_refs' => Metadata::listForResponse($this->context_refs),
            'payload' => Metadata::forResponse(AiAttachmentPayload::sanitizePayload($this->payload)),
            'atlas_decide_execution' => Metadata::forResponse($this->atlasDecideExecutionForResponse()),
            'atlas_decide_stage' => data_get($this->metadata, 'atlas_decide_stage')
                ?: data_get($this->payload, 'atlas_decide_execution.atlas_decide_stage'),
            'dependency_state' => data_get($this->metadata, 'dependency_state')
                ?: data_get($this->payload, 'atlas_decide_execution.dependency_state'),
            'result_text' => $this->result_text,
            'result_json' => Metadata::forResponse($this->result_json),
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'available_at' => $this->available_at?->toJSON(),
            'reserved_at' => $this->reserved_at?->toJSON(),
            'started_at' => $this->started_at?->toJSON(),
            'finished_at' => $this->finished_at?->toJSON(),
            'attempts' => $this->attempts,
            'max_attempts' => $this->max_attempts,
            'timeout_seconds' => $this->timeout_seconds,
            'worker_id' => $this->worker_id,
            'metadata' => Metadata::forResponse($this->metadata),
            'trace' => $this->whenLoaded('trace', fn () => new AiTraceResource($this->trace)),
            'attempt_history' => $this->whenLoaded('attemptHistory', fn () => AiJobAttemptResource::collection($this->attemptHistory)->resolve()),
            'stream_events' => $this->whenLoaded('streamEvents', fn () => AiStreamEventResource::collection($this->streamEvents)->resolve()),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function atlasDecideExecutionForResponse(): array
    {
        $metadataExecution = data_get($this->metadata, 'atlas_decide_execution');
        $payloadExecution = data_get($this->payload, 'atlas_decide_execution');

        if (is_array($metadataExecution)) {
            return $metadataExecution;
        }

        return is_array($payloadExecution) ? $payloadExecution : [];
    }
}
