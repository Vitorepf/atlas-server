<?php

namespace App\Services\Ai\Product;

use App\Models\AtlasProductDeliveryOutcomeMemory;
use App\Models\AtlasProductDeliveryRuntimeReceipt;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\Schema;

class AtlasProductDeliveryProviderMemoryFeedService
{
    public const SCHEMA_VERSION = 'atlas.product_delivery.provider_cost_flake_memory.v1';

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function analyze(array $options = []): array
    {
        $limit = max(1, min((int) ($options['limit'] ?? 100), 500));
        $route = $this->string($options['route'] ?? null);
        $receipts = $this->receipts($limit, $route);
        $outcomes = $this->outcomes($limit, $route);
        $providers = $this->providerStats($receipts);
        $cost = $this->costStats($receipts);
        $flakes = $this->flakeStats($receipts);
        $outcomePressure = $this->outcomePressure($outcomes);
        $riskSignals = $this->riskSignals($providers, $cost, $flakes, $outcomePressure);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => ($receipts === [] && $outcomes === []) ? 'watch' : 'ready',
            'mode' => 'provider_free_read_only',
            'sample' => [
                'receipt_count' => count($receipts),
                'outcome_count' => count($outcomes),
                'route' => $route,
            ],
            'providers' => $providers,
            'cost' => $cost,
            'flakes' => $flakes,
            'outcome_pressure' => $outcomePressure,
            'risk_signals' => $riskSignals,
            'governor_options' => [
                'cost_pressure' => (bool) $riskSignals['cost_pressure'],
                'flake_count' => (int) $riskSignals['flake_count'],
                'provider_failure_count' => (int) $riskSignals['provider_failure_count'],
            ],
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
                'provider_memory_feed_is_not_routing' => true,
            ],
        ];
        $payload['provider_memory_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return list<AtlasProductDeliveryRuntimeReceipt>
     */
    private function receipts(int $limit, ?string $route): array
    {
        if (! Schema::hasTable('atlas_product_delivery_runtime_receipts')) {
            return [];
        }

        $query = AtlasProductDeliveryRuntimeReceipt::query()->latest('created_at')->limit($limit);
        if ($route !== null) {
            $query->where('route', $route);
        }

        return $query->get()->all();
    }

    /**
     * @return list<AtlasProductDeliveryOutcomeMemory>
     */
    private function outcomes(int $limit, ?string $route): array
    {
        if (! Schema::hasTable('atlas_product_delivery_outcome_memories')) {
            return [];
        }

        $query = AtlasProductDeliveryOutcomeMemory::query()->latest('created_at')->limit($limit);
        if ($route !== null) {
            $query->where('route', $route);
        }

        return $query->get()->all();
    }

    /**
     * @param  list<AtlasProductDeliveryRuntimeReceipt>  $receipts
     * @return list<array<string,mixed>>
     */
    private function providerStats(array $receipts): array
    {
        $groups = [];
        foreach ($receipts as $receipt) {
            $payload = is_array($receipt->payload) ? $receipt->payload : [];
            $provider = $this->provider($payload);
            $groups[$provider] ??= [
                'provider' => $provider,
                'receipt_count' => 0,
                'failure_count' => 0,
                'success_count' => 0,
                'approval_required_count' => 0,
            ];
            $groups[$provider]['receipt_count']++;
            if ($this->isFailure((string) $receipt->status, $payload)) {
                $groups[$provider]['failure_count']++;
            } else {
                $groups[$provider]['success_count']++;
            }
            if ((bool) data_get($payload, 'approval_required')
                || (bool) data_get($payload, 'operator_decision_contract.required')) {
                $groups[$provider]['approval_required_count']++;
            }
        }

        foreach ($groups as &$group) {
            $total = max((int) $group['receipt_count'], 1);
            $group['failure_rate'] = round((int) $group['failure_count'] / $total, 4);
            $group['reliability_score'] = round(1.0 - ((int) $group['failure_count'] / $total), 4);
        }
        unset($group);

        usort($groups, static fn (array $a, array $b): int => ($b['receipt_count'] <=> $a['receipt_count']) ?: strcmp((string) $a['provider'], (string) $b['provider']));

        return array_values($groups);
    }

    /**
     * @param  list<AtlasProductDeliveryRuntimeReceipt>  $receipts
     * @return array<string,mixed>
     */
    private function costStats(array $receipts): array
    {
        $total = 0.0;
        $tokenTotal = 0;
        foreach ($receipts as $receipt) {
            $payload = is_array($receipt->payload) ? $receipt->payload : [];
            $total += $this->money(data_get($payload, 'cost_usd'))
                ?? $this->money(data_get($payload, 'estimated_cost_usd'))
                ?? $this->money(data_get($payload, 'provider.cost_usd'))
                ?? 0.0;
            $tokenTotal += (int) data_get($payload, 'tokens.total', 0)
                + (int) data_get($payload, 'token_usage.total', 0);
        }

        return [
            'total_estimated_usd' => round($total, 6),
            'average_estimated_usd' => $receipts === [] ? 0.0 : round($total / count($receipts), 6),
            'token_total' => $tokenTotal,
            'cost_pressure' => $total >= 5.0 || ($receipts !== [] && ($total / count($receipts)) >= 1.0),
        ];
    }

    /**
     * @param  list<AtlasProductDeliveryRuntimeReceipt>  $receipts
     * @return array<string,mixed>
     */
    private function flakeStats(array $receipts): array
    {
        $count = 0;
        foreach ($receipts as $receipt) {
            $payload = is_array($receipt->payload) ? $receipt->payload : [];
            $count += (int) data_get($payload, 'flake_count', 0)
                + (int) data_get($payload, 'test_flake_count', 0)
                + (int) data_get($payload, 'test_results.flake_count', 0);
            if (str_contains(strtolower((string) $receipt->status), 'flake')) {
                $count++;
            }
        }

        return [
            'flake_count' => $count,
            'flake_pressure' => $count > 0,
        ];
    }

    /**
     * @param  list<AtlasProductDeliveryOutcomeMemory>  $outcomes
     * @return array<string,mixed>
     */
    private function outcomePressure(array $outcomes): array
    {
        $blocked = 0;
        $needsRepair = 0;
        $humanReview = 0;
        foreach ($outcomes as $outcome) {
            $status = (string) $outcome->outcome_status;
            if ($status === 'blocked') {
                $blocked++;
            }
            if ($status === 'needs_repair') {
                $needsRepair++;
            }
            if ((bool) $outcome->human_review_required) {
                $humanReview++;
            }
        }

        return [
            'blocked_count' => $blocked,
            'needs_repair_count' => $needsRepair,
            'human_review_required_count' => $humanReview,
            'pressure_score' => $outcomes === []
                ? 0.0
                : round(($blocked + $needsRepair + $humanReview) / count($outcomes), 4),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $providers
     * @param  array<string,mixed>  $cost
     * @param  array<string,mixed>  $flakes
     * @param  array<string,mixed>  $outcomePressure
     * @return array<string,mixed>
     */
    private function riskSignals(array $providers, array $cost, array $flakes, array $outcomePressure): array
    {
        $providerFailures = array_sum(array_map(
            static fn (array $provider): int => (int) $provider['failure_count'],
            $providers,
        ));

        return [
            'provider_failure_count' => $providerFailures,
            'provider_failure_pressure' => $providerFailures > 0,
            'cost_pressure' => (bool) ($cost['cost_pressure'] ?? false),
            'flake_count' => (int) ($flakes['flake_count'] ?? 0),
            'flake_pressure' => (bool) ($flakes['flake_pressure'] ?? false),
            'outcome_pressure_score' => (float) ($outcomePressure['pressure_score'] ?? 0.0),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function provider(array $payload): string
    {
        return $this->string(data_get($payload, 'provider'))
            ?? $this->string(data_get($payload, 'provider_id'))
            ?? $this->string(data_get($payload, 'runtime.provider'))
            ?? $this->string(data_get($payload, 'patch_request.provider'))
            ?? 'unknown';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function isFailure(string $status, array $payload): bool
    {
        $status = strtolower($status);

        return in_array($status, ['blocked', 'failed', 'error', 'needs_repair', 'needs_human_approval'], true)
            || data_get($payload, 'error') !== null
            || data_get($payload, 'failure') !== null;
    }

    private function money(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
