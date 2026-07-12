<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

/**
 * Read-only parity gate for the Product -> Spec -> Workcell -> Kernel chain.
 * Mode adapters may vary in operator, duration and topology, never in frozen
 * truth, risk depth, evidence floor or terminal semantics.
 */
final class QualityFoundryModeParityService
{
    public const SCHEMA = 'atlas.quality_foundry.mode_parity.v1';

    /** @var list<string> */
    private const MODES = ['dev', 'forge', 'autonomos'];

    /**
     * @param  array<string,array<string,mixed>>  $modeReceipts
     * @return array<string,mixed>
     */
    public function compare(array $modeReceipts): array
    {
        $missingModes = array_values(array_diff(self::MODES, array_keys($modeReceipts)));
        $unexpectedModes = array_values(array_diff(array_keys($modeReceipts), self::MODES));
        $mismatches = [];
        $commonFields = [
            'product_intent_verdict_hash', 'spec_hash', 'world_model_snapshot_hash',
            'market_decision_hash',
            'risk_class', 'required_depth', 'allowed_scope', 'forbidden_scope',
            'role_roster', 'evidence_policy',
        ];
        foreach ($commonFields as $field) {
            $values = [];
            foreach (self::MODES as $mode) {
                $values[$mode] = $this->value($modeReceipts[$mode] ?? [], $field);
            }
            if ($field === 'market_decision_hash' && in_array(null, $values, true)) {
                $mismatches[$field] = array_map(static fn (mixed $value): mixed => $value ?? 'missing', $values);
                continue;
            }
            if (count(array_unique(array_map($this->canonical(...), $values))) > 1) {
                $mismatches[$field] = $values;
            }
        }

        $orders = [];
        foreach (self::MODES as $mode) {
            if (isset($modeReceipts[$mode]['execution_order'])) {
                $orders[$mode] = $modeReceipts[$mode]['execution_order'];
            }
        }
        $orderParity = $orders === [] ? null : (new ExecutionOrderModeParity)->compareOrders($orders);
        if (is_array($orderParity) && ($orderParity['parity'] ?? false) !== true) {
            $mismatches['execution_order'] = array_replace(
                (array) ($orderParity['mismatches'] ?? []),
                (array) ($orderParity['errors'] ?? []),
            ) ?: ['parity_failed'];
        }

        $terminalOutcomes = [];
        foreach (self::MODES as $mode) {
            if (isset($modeReceipts[$mode]['terminal_outcome']) && is_array($modeReceipts[$mode]['terminal_outcome'])) {
                $terminalOutcomes[$mode] = $modeReceipts[$mode]['terminal_outcome'];
            }
        }
        $terminalParity = $terminalOutcomes === [] ? null : (new ExecutionOrderModeParity)->compareTerminalOutcomes($terminalOutcomes);
        if (is_array($terminalParity) && ($terminalParity['parity'] ?? false) !== true) {
            $mismatches['terminal_outcome'] = $terminalParity['mismatches'] ?? ['parity_failed'];
        }

        $roleCounts = [];
        foreach (self::MODES as $mode) {
            $roleCounts[$mode] = count((array) ($modeReceipts[$mode]['role_roster'] ?? []));
        }
        if (count(array_unique($roleCounts)) > 1 || (count($roleCounts) === 3 && reset($roleCounts) !== 22)) {
            $mismatches['role_count'] = $roleCounts;
        }

        $parity = $missingModes === [] && $unexpectedModes === [] && $mismatches === [];
        $payload = [
            'schema_version' => self::SCHEMA,
            'parity' => $parity,
            'enforcement_allowed' => $parity,
            'required_modes' => self::MODES,
            'present_modes' => array_values(array_keys($modeReceipts)),
            'missing_modes' => $missingModes,
            'unexpected_modes' => $unexpectedModes,
            'mismatches' => $mismatches,
            'allowed_variance' => ['mode', 'duration_regime', 'work_topology', 'operator_contract', 'provider_route', 'volatile receipt ids'],
            'order_parity' => $orderParity,
            'terminal_parity' => $terminalParity,
        ];
        $payload['parity_hash'] = CanonicalKernelPayload::hash($payload);

        return $payload;
    }

    /**
     * Reconciles a crash between decision/order persistence and settlement.
     * This is deliberately read-only: an existing provider invocation is never retried,
     * and a missing terminal outcome remains held for the owning ledger to settle.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function reconcileInterruptedDecision(array $input): array
    {
        foreach (['workspace_id', 'idempotency_key'] as $field) {
            if (trim((string) ($input[$field] ?? '')) === '') {
                return ['schema' => self::SCHEMA, 'status' => 'held', 'blockers' => ['reconciliation_'.$field.'_required'], 'claim_eligible' => false];
            }
        }
        foreach (['decision_hash', 'order_hash'] as $field) {
            if (! is_string($input[$field] ?? null) || preg_match('/^[a-f0-9]{64}$/', $input[$field]) !== 1) {
                return ['schema' => self::SCHEMA, 'status' => 'held', 'blockers' => ['reconciliation_'.$field.'_invalid'], 'claim_eligible' => false];
            }
        }

        $terminal = $input['terminal_outcome'] ?? null;
        $base = [
            'schema' => self::SCHEMA, 'workspace_id' => (string) $input['workspace_id'],
            'idempotency_key' => (string) $input['idempotency_key'],
            'decision_hash' => $input['decision_hash'], 'order_hash' => $input['order_hash'],
            'provider_invocations' => array_values((array) ($input['provider_invocations'] ?? [])),
            'claim_eligible' => false,
        ];
        if (is_array($terminal) && $terminal !== []) {
            $result = array_merge($base, [
                'status' => 'replayed', 'terminal_outcome' => $terminal,
                'events_added' => 0, 'provider_invocations_added' => 0,
            ]);
            $result['reconciliation_hash'] = CanonicalKernelPayload::hash($result);

            return $result;
        }

        $result = array_merge($base, [
            'status' => 'held', 'blockers' => ['terminal_outcome_required'],
            'events_added' => 0, 'provider_invocations_added' => 0,
        ]);
        $result['reconciliation_hash'] = CanonicalKernelPayload::hash($result);

        return $result;
    }

    private function value(array $receipt, string $field): mixed
    {
        if (array_key_exists($field, $receipt)) {
            return $receipt[$field];
        }
        if (isset($receipt['workcell_admission']) && is_array($receipt['workcell_admission'])) {
            $value = $receipt['workcell_admission'][$field] ?? null;
            if ($value !== null) {
                return $value;
            }
        }
        if (isset($receipt['execution_order']) && is_array($receipt['execution_order'])) {
            return $receipt['execution_order'][$field] ?? null;
        }

        return null;
    }

    private function canonical(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
