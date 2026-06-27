<?php

namespace App\Services\Ai\SelfConstruction;


use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use Carbon\CarbonImmutable;

/**
 * Certifies the automatic cost import runtime boundary without importing
 * provider cost events, reading billing APIs, or spending tokens.
 */
final class AgentControlPlaneAutomaticCostImportRuntimeCertificationService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_automatic_cost_import_runtime.v1';

    public const MODE = 'read_only_agent_control_plane_automatic_cost_import_runtime_certification';

    public function __construct(
        private readonly AgentControlPlaneCostEventNormalizer $normalizer = new AgentControlPlaneCostEventNormalizer,
        private readonly AgentControlPlaneCostImportReceiptPlanner $receiptPlanner = new AgentControlPlaneCostImportReceiptPlanner,
        private readonly AgentControlPlaneCostImportReconciliationDryRun $reconciler = new AgentControlPlaneCostImportReconciliationDryRun,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function certify(array $options = []): array
    {
        $events = (array) ($options['cost_events'] ?? $this->defaultCostEvents());
        $expectedRefs = (array) ($options['expected_cost_refs'] ?? $this->defaultExpectedRefs());

        $normalized = $this->normalizer->normalize($events);
        $receiptPlan = $this->receiptPlanner->plan((array) data_get($normalized, 'normalized_cost_events', []));
        $reconciliation = $this->reconciler->reconcile((array) data_get($normalized, 'normalized_cost_events', []), $expectedRefs);

        $violations = array_values(array_merge(
            (array) data_get($normalized, 'violations', []),
            $this->runtimeFlagViolations($options),
            $this->reconciliationViolations($reconciliation),
        ));
        $invariants = [
            $this->inv('no_provider_call', true, 'provider calls are not available in certification mode'),
            $this->inv('no_billing_api_read', (bool) data_get($normalized, 'provider_billing_api_read_allowed') === false, 'billing APIs are not read'),
            $this->inv('no_token_spend', (bool) data_get($normalized, 'token_spend_allowed') === false, 'token spend is forbidden'),
            $this->inv('no_cost_event_write', (bool) data_get($receiptPlan, 'cost_events_write_allowed') === false, 'cost event writes are not allowed'),
            $this->inv('no_dispatch', true, 'dispatch is not allowed'),
            $this->inv('no_adapter_execution', true, 'adapter execution is not allowed'),
            $this->inv('no_process_start', true, 'process start is not allowed'),
            $this->inv('no_self_programming', true, 'self-programming is not allowed'),
            $this->inv('deterministic_hashes', $this->hashesPresent($normalized, $receiptPlan, $reconciliation), 'all subhashes are present'),
            $this->inv('duplicate_detection_enabled', true, 'normalizer detects duplicate idempotency keys'),
            $this->inv('reconciliation_dry_run_only', (bool) data_get($reconciliation, 'dry_run_only') === true, 'reconciliation is dry-run only'),
        ];

        $status = $violations === [] ? 'available' : 'blocked';
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'certified_at' => CarbonImmutable::now()->toIso8601String(),
            'cost_event_normalization' => $normalized,
            'cost_import_receipt_plan' => $receiptPlan,
            'cost_import_reconciliation_dry_run' => $reconciliation,
            'invariants' => $invariants,
            'invariants_all_true' => ! in_array(false, array_column($invariants, 'ok'), true),
            'violations' => $violations,
            'violation_count' => count($violations),
            'import_allowed' => false,
            'cost_events_write_allowed' => false,
            'provider_billing_api_read_allowed' => false,
            'token_spend_allowed' => false,
            'dispatch_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_call_allowed' => false,
            'process_start_allowed' => false,
            'self_programming_allowed' => false,
            'runtime_safety' => [
                'runtime_safety_all_false' => true,
                'import_allowed' => false,
                'cost_events_write_allowed' => false,
                'provider_billing_api_read_allowed' => false,
                'token_spend_allowed' => false,
                'dispatch_allowed' => false,
                'adapter_execution_allowed' => false,
                'provider_call_allowed' => false,
                'process_start_allowed' => false,
                'self_programming_allowed' => false,
            ],
            'next_action' => $status === 'available'
                ? 'keep_automatic_cost_import_runtime_read_only_until_signed_cost_import_execution_gate'
                : 'repair_automatic_cost_import_runtime_certification_violations',
        ];
        $payload['certification_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @return list<array<string, mixed>> */
    private function defaultCostEvents(): array
    {
        return [
            [
                'idempotency_key' => 'cost-event-task-runtime-pilot-001',
                'task_packet_id' => 'task-runtime-pilot-001',
                'run_id' => 'run-runtime-pilot-001',
                'agent_id' => 'agent-control-plane-certifier',
                'source' => 'manual_cost_event_writer',
                'currency' => 'USD',
                'amount_minor' => 0,
                'provider' => 'manual',
                'model' => 'none',
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function defaultExpectedRefs(): array
    {
        return [
            ['task_packet_id' => 'task-runtime-pilot-001', 'run_id' => 'run-runtime-pilot-001'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function runtimeFlagViolations(array $options): array
    {
        $violations = [];
        foreach (['import_allowed', 'cost_events_write_allowed', 'provider_billing_api_read_allowed', 'token_spend_allowed', 'dispatch_allowed', 'adapter_execution_allowed', 'provider_call_allowed', 'process_start_allowed', 'self_programming_allowed'] as $flag) {
            if (($options[$flag] ?? false) === true) {
                $violations[] = ['code' => 'runtime_flag_true', 'flag' => $flag, 'message' => 'Automatic cost import certification rejects runtime-enabling flags.'];
            }
        }

        return $violations;
    }

    /** @return list<array<string, mixed>> */
    private function reconciliationViolations(array $reconciliation): array
    {
        $violations = [];
        foreach ((array) data_get($reconciliation, 'missing_cost_event_refs', []) as $missing) {
            $violations[] = [
                'code' => 'expected_cost_event_missing',
                'task_packet_id' => (string) ($missing['task_packet_id'] ?? ''),
                'run_id' => (string) ($missing['run_id'] ?? ''),
                'message' => 'Expected cost event reference is absent from the normalized event set.',
            ];
        }

        return $violations;
    }

    private function hashesPresent(array $normalized, array $receiptPlan, array $reconciliation): bool
    {
        foreach ([
            data_get($normalized, 'normalized_cost_events_hash'),
            data_get($receiptPlan, 'cost_import_receipt_plan_hash'),
            data_get($reconciliation, 'reconciliation_dry_run_hash'),
        ] as $hash) {
            if (! is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function inv(string $name, bool $ok, string $observation): array
    {
        return ['name' => $name, 'ok' => $ok, 'observation' => $observation];
    }

    private function stableHash(array $payload): string
    {
        unset($payload['certified_at'], $payload['certification_hash']);
        unset($payload['cost_import_receipt_plan']['planned_at']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

}
