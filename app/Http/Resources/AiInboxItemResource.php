<?php

namespace App\Http\Resources;

use App\Services\Ai\Mobile\AiInboxHumanPresentation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiInboxItemResource extends JsonResource
{
    public function toCompactArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'type' => $this->type,
            'category' => $this->category,
            'severity' => $this->severity,
            'status' => $this->status,
            'title' => $this->title,
            'summary' => $this->summary,
            'body' => null,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'initiator' => $this->initiator,
            'context_bundle_id' => $this->context_bundle_id,
            'dedupe_key' => null,
            'available_actions' => $this->available_actions ?? [],
            'response' => null,
            'payload' => [],
            'deep_link' => $this->deep_link,
            'push_policy' => [],
            'safety' => [
                'schema_version' => 'atlas.inbox_item.safety.v1',
                'authenticated_api_required' => true,
                'push_is_pointer_only' => true,
                'raw_context_exposed_in_push' => false,
                'raw_payload_exposed_in_push' => false,
                'body_exposed_in_push' => false,
                'auto_action_allowed' => false,
                'available_action_count' => is_array($this->available_actions) ? count($this->available_actions) : 0,
                'status' => $this->status,
                'severity' => $this->severity,
            ],
            'presentation' => null,
            'priority_score' => $this->priority_score,
            'confidence_score' => $this->confidence_score,
            'expires_at' => $this->expires_at?->toJSON(),
            'snoozed_until' => $this->snoozed_until?->toJSON(),
            'read_at' => $this->read_at?->toJSON(),
            'resolved_at' => $this->resolved_at?->toJSON(),
            'dismissed_at' => $this->dismissed_at?->toJSON(),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'type' => $this->type,
            'category' => $this->category,
            'severity' => $this->severity,
            'status' => $this->status,
            'title' => $this->title,
            'summary' => $this->summary,
            'body' => $this->body,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'initiator' => $this->initiator,
            'context_bundle_id' => $this->context_bundle_id,
            'dedupe_key' => $this->dedupe_key,
            'available_actions' => $this->available_actions ?? [],
            'response' => $this->response,
            'payload' => $this->payload ?? [],
            'deep_link' => $this->deep_link,
            'push_policy' => $this->push_policy ?? [],
            'safety' => $this->safetySummary(),
            'presentation' => app(AiInboxHumanPresentation::class)->forItem($this->resource),
            'priority_score' => $this->priority_score,
            'confidence_score' => $this->confidence_score,
            'expires_at' => $this->expires_at?->toJSON(),
            'snoozed_until' => $this->snoozed_until?->toJSON(),
            'read_at' => $this->read_at?->toJSON(),
            'resolved_at' => $this->resolved_at?->toJSON(),
            'dismissed_at' => $this->dismissed_at?->toJSON(),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
            'context_bundle' => $this->whenLoaded('contextBundle', fn () => $this->contextBundle ? [
                'id' => $this->contextBundle->id,
                'purpose' => $this->contextBundle->purpose,
                'title' => $this->contextBundle->title,
                'summary' => $this->contextBundle->summary,
                'source_refs' => $this->contextBundle->source_refs ?? [],
                'trace_refs' => $this->contextBundle->trace_refs ?? [],
                'job_refs' => $this->contextBundle->job_refs ?? [],
                'metric_refs' => $this->contextBundle->metric_refs ?? [],
                'file_refs' => $this->contextBundle->file_refs ?? [],
                'diff_refs' => $this->contextBundle->diff_refs ?? [],
            ] : null),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function safetySummary(): array
    {
        $pushPolicy = is_array($this->push_policy) ? $this->push_policy : [];
        $availableActions = is_array($this->available_actions) ? $this->available_actions : [];
        $pushSend = (string) ($pushPolicy['send'] ?? 'auto');
        $proactiveContract = is_array(data_get($this->payload ?? [], 'proactive_delivery_contract'))
            ? data_get($this->payload ?? [], 'proactive_delivery_contract')
            : [];

        return [
            'schema_version' => 'atlas.inbox_item.safety.v1',
            'authenticated_api_required' => true,
            'proactive_delivery_contract_schema' => data_get($proactiveContract, 'schema_version'),
            'proactive_delivery_contract_hash' => data_get($proactiveContract, 'contract_hash'),
            'has_proactive_delivery_contract' => $proactiveContract !== [],
            'presence_eclipse_contract_schema' => data_get($proactiveContract, 'presence_eclipse_governance.schema_version'),
            'manual_eclipse_supported' => (bool) data_get($proactiveContract, 'presence_eclipse_governance.manual_eclipse_supported', false),
            'proactive_push_opt_out_supported' => (bool) data_get($proactiveContract, 'presence_eclipse_governance.explicit_opt_out_supported', false),
            'push_is_pointer_only' => true,
            'push_delivery_requested' => $pushSend !== 'none',
            'push_send_mode' => $pushSend,
            'deep_link_only_delivery' => true,
            'context_bundle_api_only' => $this->context_bundle_id !== null,
            'raw_context_exposed_in_push' => false,
            'raw_payload_exposed_in_push' => false,
            'body_exposed_in_push' => false,
            'auto_action_allowed' => false,
            'available_action_count' => count($availableActions),
            'status' => $this->status,
            'severity' => $this->severity,
        ];
    }
}
