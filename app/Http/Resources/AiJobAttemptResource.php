<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiJobAttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ai_job_id' => $this->ai_job_id,
            'attempt_number' => $this->attempt_number,
            'worker_id' => $this->worker_id,
            'provider' => $this->provider,
            'model' => $this->model,
            'command' => Metadata::forResponse($this->command),
            'command_hash' => $this->command_hash,
            'prompt_hash' => $this->prompt_hash,
            'response_hash' => $this->response_hash,
            'status' => $this->status,
            'exit_code' => $this->exit_code,
            'duration_ms' => $this->duration_ms,
            'output_text' => $this->output_text,
            'stdout_excerpt' => $this->stdout_excerpt,
            'stderr_excerpt' => $this->stderr_excerpt,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'started_at' => $this->started_at?->toJSON(),
            'finished_at' => $this->finished_at?->toJSON(),
            'metadata' => Metadata::forResponse($this->metadata),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
