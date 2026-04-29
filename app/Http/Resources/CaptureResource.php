<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CaptureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'kind' => $this->kind,
            'domain' => $this->domain,
            'content_text' => $this->content_text,
            'content_file_path' => $this->content_file_path,
            'content_duration_ms' => $this->content_duration_ms,
            'content_size_bytes' => $this->content_size_bytes,
            'content_sha256' => $this->content_sha256,
            'content_mime_type' => $this->content_mime_type,
            'transcription_status' => $this->transcription_status,
            'transcription_engine' => $this->transcription_engine,
            'transcription_error' => $this->transcription_error,
            'captured_at' => $this->captured_at?->toJSON(),
            'captured_timezone' => $this->captured_timezone,
            'captured_lat' => $this->captured_lat,
            'captured_lng' => $this->captured_lng,
            'pre_capture_digital_context' => Metadata::forResponse($this->pre_capture_digital_context),
            'metadata' => Metadata::forResponse($this->metadata),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
            'deleted_at' => $this->deleted_at?->toJSON(),
        ];
    }
}
