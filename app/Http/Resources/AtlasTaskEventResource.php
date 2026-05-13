<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AtlasTaskEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'event_type' => $this->event_type,
            'source' => $this->source,
            'payload' => Metadata::forResponse($this->payload),
            'safety' => $this->safetySummary(),
            'occurred_at' => $this->occurred_at?->toJSON(),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function safetySummary(): array
    {
        $payload = is_array($this->payload) ? $this->payload : [];
        $receipt = is_array(data_get($payload, 'orchestration_receipt'))
            ? data_get($payload, 'orchestration_receipt')
            : [];

        return [
            'schema_version' => 'atlas.task_orchestration.event_safety.v1',
            'audit_trail_event' => true,
            'payload_api_only' => true,
            'provider_dispatch_allowed' => false,
            'runtime_execution_allowed' => false,
            'policy_mutation_allowed' => false,
            'raw_provider_output_exposed' => false,
            'has_event_sequence' => data_get($payload, 'event_sequence') !== null,
            'has_previous_event_hash' => data_get($payload, 'previous_event_hash') !== null,
            'has_event_hash' => data_get($payload, 'event_hash') !== null,
            'has_local_orchestration_receipt' => $receipt !== [],
            'local_receipt_schema_version' => data_get($receipt, 'schema_version'),
            'local_receipt_complete' => $receipt !== [] && ! $this->hasIncompleteReceipt($receipt),
            'unsafe_local_receipt' => $this->hasUnsafeReceipt($receipt),
            'receipt_provider_dispatch_allowed' => data_get($receipt, 'provider_dispatch_allowed') === true,
            'receipt_runtime_execution_allowed' => data_get($receipt, 'runtime_execution_allowed') === true,
            'receipt_agent_control_plane_allowed' => data_get($receipt, 'agent_control_plane_allowed') === true,
            'receipt_policy_mutation_allowed' => data_get($receipt, 'policy_mutation_allowed') === true,
            'receipt_auto_completion_allowed' => data_get($receipt, 'auto_completion_allowed') === true,
            'receipt_operator_review_required_for_external_execution' => data_get($receipt, 'operator_review_required_for_external_execution') === true,
            'event_type' => $this->event_type,
            'source' => $this->source,
        ];
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function hasUnsafeReceipt(array $receipt): bool
    {
        if ($receipt === []) {
            return false;
        }

        return data_get($receipt, 'provider_dispatch_allowed') === true
            || data_get($receipt, 'runtime_execution_allowed') === true
            || data_get($receipt, 'agent_control_plane_allowed') === true
            || data_get($receipt, 'policy_mutation_allowed') === true
            || data_get($receipt, 'auto_completion_allowed') === true
            || data_get($receipt, 'operator_review_required_for_external_execution') === false;
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function hasIncompleteReceipt(array $receipt): bool
    {
        foreach ([
            'provider_dispatch_allowed',
            'runtime_execution_allowed',
            'agent_control_plane_allowed',
            'policy_mutation_allowed',
            'auto_completion_allowed',
            'operator_review_required_for_external_execution',
        ] as $field) {
            if (! array_key_exists($field, $receipt)) {
                return true;
            }
        }

        return false;
    }
}
