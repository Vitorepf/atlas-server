<?php

namespace App\Http\Resources;

use App\Support\AiAttachmentPayload;
use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiTraceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trace_key' => $this->trace_key,
            'thread_id' => $this->thread_id,
            'session_id' => $this->session_id,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'status' => $this->status,
            'operator_input' => $this->operator_input,
            'intent' => $this->intent,
            'agent_slug' => $this->agent_slug,
            'provider' => $this->provider,
            'model' => $this->model,
            'skill_versions' => Metadata::forResponse($this->skill_versions),
            'context_refs' => Metadata::listForResponse($this->context_refs),
            'prompt_hash' => $this->prompt_hash,
            'response_hash' => $this->response_hash,
            'response_text' => $this->response_text,
            'latency_ms' => $this->latency_ms,
            'stream_url' => url("/api/ai/interactions/{$this->id}/stream"),
            'feedback_score' => $this->feedback_score,
            'feedback_action' => $this->feedback_action,
            'feedback_comment' => $this->feedback_comment,
            'completed_at' => $this->completed_at?->toJSON(),
            'metadata' => Metadata::forResponse($this->metadata),
            'atlas_decide_execution' => Metadata::forResponse(data_get($this->metadata, 'atlas_decide_execution')),
            'atlas_dev_runtime' => $this->atlasDevRuntimeForResponse(),
            'attachments' => $this->publicAttachments(),
            'thread' => $this->whenLoaded('thread', fn () => $this->thread ? new AiThreadResource($this->thread) : null),
            'session' => $this->whenLoaded('session', fn () => $this->session ? new AiSessionResource($this->session) : null),
            'job' => $this->whenLoaded('job', fn () => new AiJobResource($this->job)),
            'jobs' => $this->whenLoaded('jobs', fn () => AiJobResource::collection($this->jobs)->resolve()),
            'router_decision' => $this->whenLoaded('routerDecision', fn () => $this->routerDecision ? $this->routerDecisionForResponse() : null),
            'atlas_decision' => $this->whenLoaded('atlasDecision', fn () => $this->atlasDecision ? $this->atlasDecisionForResponse() : null),
            'decision_receipt' => $this->decisionReceiptForResponse(),
            'stream_events' => $this->whenLoaded('streamEvents', fn () => AiStreamEventResource::collection($this->streamEvents)->resolve()),
            'quality_evaluation' => $this->whenLoaded('qualityEvaluation', fn () => $this->qualityEvaluation ? new AiQualityEvaluationResource($this->qualityEvaluation) : null),
            'quality_actions' => $this->whenLoaded('qualityActions', fn () => AiQualityActionResource::collection($this->qualityActions)->resolve()),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function atlasDecisionForResponse(): array
    {
        return [
            'id' => $this->atlasDecision->id,
            'trace_id' => $this->atlasDecision->trace_id,
            'router_decision_id' => $this->atlasDecision->router_decision_id,
            'policy_version' => $this->atlasDecision->policy_version,
            'decision_mode' => $this->atlasDecision->decision_mode,
            'route_mode' => $this->atlasDecision->route_mode,
            'task_type' => $this->atlasDecision->task_type,
            'risk_level' => $this->atlasDecision->risk_level,
            'context_strategy' => $this->atlasDecision->context_strategy,
            'execution_strategy' => $this->atlasDecision->execution_strategy,
            'selected_provider' => $this->atlasDecision->selected_provider,
            'selected_model' => $this->atlasDecision->selected_model,
            'fallback_provider' => $this->atlasDecision->fallback_provider,
            'operator_requested_provider' => $this->atlasDecision->operator_requested_provider,
            'requested_provider' => $this->atlasDecision->requested_provider,
            'was_overridden' => (bool) $this->atlasDecision->was_overridden,
            'confidence_score' => $this->atlasDecision->confidence_score,
            'signals' => Metadata::forResponse($this->atlasDecision->signals),
            'candidates' => Metadata::listForResponse($this->atlasDecision->candidates),
            'constraints' => Metadata::forResponse($this->atlasDecision->constraints),
            'metrics_snapshot' => Metadata::forResponse($this->atlasDecision->metrics_snapshot),
            'task_profile' => Metadata::forResponse($this->atlasDecision->task_profile),
            'execution_graph' => Metadata::forResponse($this->atlasDecision->execution_graph),
            'reason' => $this->atlasDecision->reason,
            'created_at' => $this->atlasDecision->created_at?->toJSON(),
            'updated_at' => $this->atlasDecision->updated_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function routerDecisionForResponse(): array
    {
        return [
            'id' => $this->routerDecision->id,
            'mode' => $this->routerDecision->mode,
            'selected_provider' => $this->routerDecision->selected_provider,
            'fallback_provider' => $this->routerDecision->fallback_provider,
            'signals' => Metadata::forResponse($this->routerDecision->signals),
            'reason' => $this->routerDecision->reason,
            'was_overridden' => (bool) $this->routerDecision->was_overridden,
            'created_at' => $this->routerDecision->created_at?->toJSON(),
            'updated_at' => $this->routerDecision->updated_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decisionReceiptForResponse(): ?array
    {
        $metadataReceipt = is_array(data_get($this->metadata, 'decision_receipt'))
            ? data_get($this->metadata, 'decision_receipt')
            : [];
        $atlasDecision = $this->resource->relationLoaded('atlasDecision') ? $this->atlasDecision : null;
        $routerDecision = $this->resource->relationLoaded('routerDecision') ? $this->routerDecision : null;

        if ($atlasDecision) {
            return array_merge(Metadata::forResponse($metadataReceipt), [
                'decision_id' => $atlasDecision->id,
                'trace_id' => $this->id,
                'router_decision_id' => $atlasDecision->router_decision_id,
                'policy_version' => $atlasDecision->policy_version,
                'decision_mode' => $atlasDecision->decision_mode,
                'route_mode' => $atlasDecision->route_mode,
                'task_type' => $atlasDecision->task_type,
                'risk_level' => $atlasDecision->risk_level,
                'context_strategy' => $atlasDecision->context_strategy,
                'execution_strategy' => $atlasDecision->execution_strategy,
                'selected_provider' => $atlasDecision->selected_provider,
                'selected_model' => $atlasDecision->selected_model,
                'fallback_provider' => $atlasDecision->fallback_provider,
                'operator_requested_provider' => $atlasDecision->operator_requested_provider,
                'requested_provider' => $atlasDecision->requested_provider,
                'was_overridden' => (bool) $atlasDecision->was_overridden,
                'confidence_score' => $atlasDecision->confidence_score,
                'reason' => $atlasDecision->reason,
                'signals' => Metadata::forResponse($atlasDecision->signals),
                'candidates' => Metadata::listForResponse($atlasDecision->candidates),
                'constraints' => Metadata::forResponse($atlasDecision->constraints),
                'metrics_snapshot' => Metadata::forResponse($atlasDecision->metrics_snapshot),
                'task_profile' => Metadata::forResponse($atlasDecision->task_profile),
                'execution_graph' => Metadata::forResponse($atlasDecision->execution_graph),
            ]);
        }

        if (! $routerDecision) {
            return $metadataReceipt === [] ? null : Metadata::forResponse($metadataReceipt);
        }

        return array_merge(Metadata::forResponse($metadataReceipt), [
            'decision_id' => $routerDecision->id,
            'trace_id' => $this->id,
            'decision_mode' => data_get($routerDecision->signals, 'decision_mode') ?: data_get($metadataReceipt, 'decision_mode') ?: 'atlas_decide',
            'selected_provider' => $routerDecision->selected_provider,
            'fallback_provider' => $routerDecision->fallback_provider,
            'operator_requested_provider' => data_get($routerDecision->signals, 'operator_requested_provider') ?: data_get($metadataReceipt, 'operator_requested_provider') ?: 'auto',
            'requested_provider' => data_get($routerDecision->signals, 'requested_provider') ?: data_get($metadataReceipt, 'requested_provider'),
            'was_overridden' => (bool) $routerDecision->was_overridden,
            'reason' => $routerDecision->reason,
            'signals' => Metadata::forResponse($routerDecision->signals),
        ]);
    }

    /**
     * Slice atlas.dev_runtime.v1 — quando a interação passou pelo
     * AtlasDevRuntimeService o slice fica em job.payload.atlas_dev_runtime e
     * descreve flow, workspace, decision_mode, expected_artifacts e estado
     * do Open Brain para o cliente (mobile/desktop) renderizar.
     *
     * @return array<string,mixed>|null
     */
    private function atlasDevRuntimeForResponse(): ?array
    {
        $slice = $this->atlasDevRuntimeFromJobs();
        if (! is_array($slice)) {
            $slice = data_get($this->metadata, 'atlas_dev_runtime');
        }

        if (! is_array($slice) || $slice === []) {
            return null;
        }

        $openBrainStatus = is_string(data_get($this->metadata, 'open_brain_injection.status'))
            ? (string) data_get($this->metadata, 'open_brain_injection.status')
            : null;
        if ($openBrainStatus !== null) {
            $slice['open_brain_status'] = $openBrainStatus;
        }

        return Metadata::forResponse($slice);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function atlasDevRuntimeFromJobs(): ?array
    {
        if ($this->resource->relationLoaded('job') && $this->job) {
            $slice = data_get($this->job->payload, 'atlas_dev_runtime');
            if (is_array($slice) && $slice !== []) {
                return $slice;
            }
        }

        if ($this->resource->relationLoaded('jobs') && $this->jobs) {
            foreach ($this->jobs as $job) {
                $slice = data_get($job->payload, 'atlas_dev_runtime');
                if (is_array($slice) && $slice !== []) {
                    return $slice;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function publicAttachments(): array
    {
        $attachments = [];

        if ($this->resource->relationLoaded('job') && $this->job) {
            $attachments = [
                ...$attachments,
                ...AiAttachmentPayload::publicAttachmentsFromPayload($this->job->payload),
            ];
        }

        if ($this->resource->relationLoaded('jobs') && $this->jobs) {
            foreach ($this->jobs as $job) {
                $attachments = [
                    ...$attachments,
                    ...AiAttachmentPayload::publicAttachmentsFromPayload($job->payload),
                ];
            }
        }

        $byId = [];
        foreach ($attachments as $attachment) {
            $id = is_string($attachment['id'] ?? null) ? $attachment['id'] : null;
            if (! $id || isset($byId[$id])) {
                continue;
            }

            $attachment['content_url'] = "/ai/interactions/{$this->id}/attachments/{$id}/content";
            $renderedPageCount = is_numeric($attachment['pdf_rendered_page_count'] ?? null)
                ? (int) $attachment['pdf_rendered_page_count']
                : (is_numeric($attachment['office_rendered_page_count'] ?? null)
                    ? (int) $attachment['office_rendered_page_count']
                    : 0);
            if ($renderedPageCount > 0) {
                $attachment['preview_pages'] = collect(range(1, min($renderedPageCount, 12)))
                    ->map(fn (int $page): array => [
                        'page' => $page,
                        'url' => "/ai/interactions/{$this->id}/attachments/{$id}/pages/{$page}",
                    ])
                    ->values()
                    ->all();
            }

            $byId[$id] = $attachment;
        }

        return array_values($byId);
    }
}
