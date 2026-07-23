<?php

namespace App\Services\Ai\SelfImprovement\Runtime;

/**
 * Provider cost-rate replay projection helpers family extracted VERBATIM from AtlasSelfImprovementRuntime
 * (GOD-DEBULK partial split). Scanner-pinned families remain on the facade;
 * the facade keeps same-signature delegators for every method here.
 */
class ProviderCostRateReplaySection
{
    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    public function providerCostRateReplaySourceRef(array $event): array
    {
        return [
            'type' => 'ledger_event',
            'id' => $event['event_id'] ?? null,
            'event_id' => $event['event_id'] ?? null,
            'envelope_id' => $event['envelope_id'] ?? null,
            'inbox_item_id' => $event['inbox_item_id'] ?? null,
            'action' => $event['action'] ?? null,
            'provider' => $event['provider_cost_rate_provider'] ?? null,
            'model' => $event['provider_cost_rate_model'] ?? null,
            'applied' => (bool) ($event['provider_cost_rate_applied'] ?? false),
            'input_microusd' => $event['provider_cost_rate_input_microusd'] ?? null,
            'output_microusd' => $event['provider_cost_rate_output_microusd'] ?? null,
            'input_microusd_per_1k' => $event['provider_cost_rate_input_microusd'] ?? null,
            'output_microusd_per_1k' => $event['provider_cost_rate_output_microusd'] ?? null,
            'currency' => $event['provider_cost_rate_currency'] ?? null,
            'effective_from' => $event['provider_cost_rate_effective_from'] ?? null,
            'effective_until' => $event['provider_cost_rate_effective_until'] ?? null,
            'rate_id' => $event['provider_cost_rate_id'] ?? null,
            'occurred_at' => $event['occurred_at'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    public function providerCostRateReplayPayloadEvent(array $event): array
    {
        return [
            'event_id' => $event['event_id'] ?? null,
            'inbox_item_id' => $event['inbox_item_id'] ?? null,
            'provider' => $event['provider_cost_rate_provider'] ?? null,
            'model' => $event['provider_cost_rate_model'] ?? null,
            'applied' => (bool) ($event['provider_cost_rate_applied'] ?? false),
            'input_microusd_per_1k' => $event['provider_cost_rate_input_microusd'] ?? null,
            'output_microusd_per_1k' => $event['provider_cost_rate_output_microusd'] ?? null,
            'currency' => $event['provider_cost_rate_currency'] ?? null,
            'effective_from' => $event['provider_cost_rate_effective_from'] ?? null,
            'effective_until' => $event['provider_cost_rate_effective_until'] ?? null,
            'rate_id' => $event['provider_cost_rate_id'] ?? null,
            'occurred_at' => $event['occurred_at'] ?? null,
        ];
    }
}
