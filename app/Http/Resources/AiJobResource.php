<?php

namespace App\Http\Resources;

use App\Models\AiYoutubeIngestion;
use App\Services\Ai\YoutubeCanonicalProjection;
use App\Support\AiAttachmentPayload;
use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Schema;

class AiJobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $sanitizedPayload = AiAttachmentPayload::sanitizePayload($this->payload);
        $sanitizedPayload = $this->withFreshYoutubeIngestion($sanitizedPayload);

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
            'payload' => Metadata::forResponse($sanitizedPayload),
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

    /**
     * Re-resolve `youtube_ingestion.videos[]` from the live
     * `ai_youtube_ingestions` table so the client sees the CURRENT
     * canonical 3-status state (ingestion / transcript / translation), not
     * the snapshot frozen at POST time. This is the convergence path the
     * mobile/desktop polling cadence relies on. See
     * docs/rich-input/youtube-canon.md.
     *
     * If the table is absent (fresh install / migration not yet run), the
     * payload is returned untouched.
     */
    private function withFreshYoutubeIngestion(mixed $payload): mixed
    {
        if (! is_array($payload)) {
            return $payload;
        }

        $videos = data_get($payload, 'youtube_ingestion.videos');
        if (! is_array($videos) || $videos === []) {
            return $payload;
        }

        if (! Schema::hasTable('ai_youtube_ingestions')) {
            return $payload;
        }

        $videoIds = collect($videos)
            ->filter(fn (mixed $v): bool => is_array($v))
            ->map(fn (array $v): ?string => is_string($v['video_id'] ?? null) ? $v['video_id'] : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($videoIds === []) {
            return $payload;
        }

        $records = AiYoutubeIngestion::query()->whereIn('video_id', $videoIds)->get()->keyBy('video_id');
        if ($records->isEmpty()) {
            return $payload;
        }

        $projection = new YoutubeCanonicalProjection;
        $fresh = [];
        foreach ($videos as $video) {
            if (! is_array($video)) {
                continue;
            }
            $videoId = is_string($video['video_id'] ?? null) ? $video['video_id'] : null;
            $record = $videoId !== null ? $records->get($videoId) : null;
            if ($record instanceof AiYoutubeIngestion) {
                $fresh[] = $projection->projectFromRecord($record);

                continue;
            }
            $fresh[] = $projection->project($video);
        }

        $payload['youtube_ingestion']['videos'] = $fresh;

        return $payload;
    }
}
