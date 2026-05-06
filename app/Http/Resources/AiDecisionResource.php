<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiDecisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trace_id' => $this->trace_id,
            'router_decision_id' => $this->router_decision_id,
            'policy_version' => $this->policy_version,
            'decision_mode' => $this->decision_mode,
            'route_mode' => $this->route_mode,
            'task_type' => $this->task_type,
            'risk_level' => $this->risk_level,
            'context_strategy' => $this->context_strategy,
            'execution_strategy' => $this->execution_strategy,
            'selected_provider' => $this->selected_provider,
            'selected_model' => $this->selected_model,
            'fallback_provider' => $this->fallback_provider,
            'operator_requested_provider' => $this->operator_requested_provider,
            'requested_provider' => $this->requested_provider,
            'was_overridden' => (bool) $this->was_overridden,
            'confidence_score' => $this->confidence_score,
            'selection_explanation' => Metadata::forResponse(data_get($this->signals, 'selection_explanation')),
            'kernel_contracts' => Metadata::forResponse(data_get($this->signals, 'kernel_contracts')),
            'signals' => Metadata::forResponse($this->signals),
            'candidates' => Metadata::listForResponse($this->candidates),
            'constraints' => Metadata::forResponse($this->constraints),
            'metrics_snapshot' => Metadata::forResponse($this->metrics_snapshot),
            'task_profile' => Metadata::forResponse($this->task_profile),
            'execution_graph' => Metadata::forResponse($this->execution_graph),
            'reason' => $this->reason,
            'trace' => $this->whenLoaded('trace', fn () => $this->trace ? new AiTraceResource($this->trace) : null),
            'router_decision' => $this->whenLoaded('routerDecision', fn () => $this->routerDecision ? [
                'id' => $this->routerDecision->id,
                'mode' => $this->routerDecision->mode,
                'selected_provider' => $this->routerDecision->selected_provider,
                'fallback_provider' => $this->routerDecision->fallback_provider,
                'signals' => Metadata::forResponse($this->routerDecision->signals),
                'reason' => $this->routerDecision->reason,
                'was_overridden' => (bool) $this->routerDecision->was_overridden,
                'created_at' => $this->routerDecision->created_at?->toJSON(),
            ] : null),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
