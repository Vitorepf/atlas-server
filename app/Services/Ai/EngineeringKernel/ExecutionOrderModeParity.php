<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

/**
 * Canonical, read-only parity contract for the three Quality Foundry modes.
 *
 * Mode adapters may differ in operator, duration and topology metadata, but
 * they cannot lower the shared product/spec/world/market/evidence contract or
 * return a different terminal decision for the same failure fixture.
 */
final class ExecutionOrderModeParity
{
    public const SCHEMA_VERSION = 'atlas.execution_order.mode_parity.v1';

    /** @var list<string> */
    private const MODES = ['dev', 'forge', 'autonomos'];

    /** @var list<string> */
    private const COMMON_FIELDS = [
        'risk_class', 'complexity_band', 'product_intent_verdict_hash', 'spec_hash',
        'world_model_snapshot_hash', 'market_decision_hash', 'allowed_scope',
        'forbidden_scope', 'role_roster', 'tool_permissions', 'evidence_policy',
        'release_policy', 'rollback_policy', 'outcome_policy', 'budget_posture',
    ];

    /**
     * @param  array<string,ExecutionOrder|array<string,mixed>>  $orders
     * @return array<string,mixed>
     */
    public function compareOrders(array $orders): array
    {
        $parsed = [];
        $errors = [];
        foreach ($orders as $mode => $order) {
            try {
                $parsed[$mode] = $order instanceof ExecutionOrder ? $order : ExecutionOrder::fromArray($order);
            } catch (\Throwable $e) {
                $errors[$mode] = 'order_invalid:'.$e::class;
            }
        }

        ksort($parsed);
        ksort($errors);

        $missingModes = array_values(array_diff(self::MODES, array_keys($parsed)));
        $unexpectedModes = array_values(array_diff(array_keys($parsed), self::MODES));
        $mismatches = [];
        $projections = [];
        foreach (self::COMMON_FIELDS as $field) {
            $values = [];
            foreach ($parsed as $mode => $order) {
                $value = $this->projectionValue($order, $field);
                if ($field === 'market_decision_hash' && $value === null) {
                    $value = 'missing';
                }
                $values[$mode] = $value;
            }
            $projections[$field] = $values;
            if (count(array_unique(array_map($this->canonicalValue(...), $values))) > 1) {
                $mismatches[$field] = $values;
            }
        }

        $authorityHashes = [];
        foreach ($parsed as $mode => $order) {
            $authorityHashes[$mode] = (string) ($order->authorityEnvelope['authority_hash'] ?? '');
        }
        if (count(array_filter($authorityHashes, static fn (string $hash): bool => $hash === '')) > 0) {
            $mismatches['authority_hash'] = $authorityHashes;
        } elseif (count(array_unique($authorityHashes)) > 1) {
            $mismatches['authority_hash'] = $authorityHashes;
        }

        $parity = $errors === [] && $missingModes === [] && $unexpectedModes === [] && $mismatches === [];
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'parity' => $parity,
            'required_modes' => self::MODES,
            'present_modes' => array_keys($parsed),
            'missing_modes' => $missingModes,
            'unexpected_modes' => $unexpectedModes,
            'errors' => $errors,
            'mismatches' => $mismatches,
            'common_projections' => $projections,
            'allowed_variance' => ['mode', 'duration_regime', 'work_topology', 'operator_contract', 'provider_route', 'volatile receipt ids'],
        ];
        $payload['parity_hash'] = CanonicalKernelPayload::hash($payload);

        return $payload;
    }

    /**
     * @param  array<string,array<string,mixed>>  $outcomes
     * @return array<string,mixed>
     */
    public function compareTerminalOutcomes(array $outcomes): array
    {
        $fields = ['applicability', 'disposition', 'verdict', 'authorization', 'canary', 'status', 'claim_eligible'];
        $mismatches = [];
        foreach ($fields as $field) {
            $values = [];
            foreach ($outcomes as $mode => $outcome) {
                $values[$mode] = $outcome[$field] ?? null;
            }
            if (count(array_unique(array_map($this->canonicalValue(...), $values))) > 1) {
                $mismatches[$field] = $values;
            }
        }

        $normalizedOutcomes = $outcomes;
        ksort($normalizedOutcomes);
        $presentModes = array_keys($normalizedOutcomes);
        sort($presentModes);
        $requiredModes = self::MODES;
        sort($requiredModes);
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'parity' => $mismatches === [] && $presentModes === $requiredModes,
            'required_modes' => self::MODES,
            'outcomes' => $normalizedOutcomes,
            'mismatches' => $mismatches,
            'claim_eligible_all_false' => array_reduce($outcomes, static fn (bool $ok, array $outcome): bool => $ok && ($outcome['claim_eligible'] ?? false) === false, true),
        ];
        $payload['parity_hash'] = CanonicalKernelPayload::hash($payload);

        return $payload;
    }

    private function projectionValue(ExecutionOrder $order, string $field): mixed
    {
        return match ($field) {
            'risk_class' => $order->riskClass,
            'complexity_band' => $order->complexityBand,
            'product_intent_verdict_hash' => $order->productIntentVerdictHash,
            'spec_hash' => $order->specHash,
            'world_model_snapshot_hash' => $order->worldModelSnapshotHash,
            'market_decision_hash' => $order->marketDecisionHash,
            'allowed_scope' => $order->allowedScope,
            'forbidden_scope' => $order->forbiddenScope,
            'role_roster' => $order->roleRoster,
            'tool_permissions' => $order->toolPermissions,
            'evidence_policy' => $this->withoutVolatileKeys($order->evidencePolicy),
            'release_policy' => $this->withoutVolatileKeys($order->releasePolicy),
            'rollback_policy' => $this->withoutVolatileKeys($order->rollbackPolicy),
            'outcome_policy' => $this->withoutVolatileKeys($order->outcomePolicy),
            'budget_posture' => $order->budgetPosture,
            default => null,
        };
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function withoutVolatileKeys(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (in_array($key, ['run_id', 'delivery_id', 'idempotency_key', 'experiment_ref'], true)
                || str_ends_with((string) $key, '_event_id')) {
                continue;
            }
            $result[$key] = is_array($item) ? $this->withoutVolatileKeys($item) : $item;
        }
        ksort($result);

        return $result;
    }

    private function canonicalValue(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
