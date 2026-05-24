<?php

namespace App\Services\Ai\Product;

use App\Models\AtlasProductDeliveryRuntimeReceipt;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AtlasProductDeliveryEvidenceReplayLabService
{
    public const SCHEMA_VERSION = 'atlas.product_delivery.evidence_replay_lab.v1';

    public function __construct(
        private readonly AtlasAutonomousProductDeliveryRuntimeService $deliveryRuntime = new AtlasAutonomousProductDeliveryRuntimeService,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function replay(array $options = []): array
    {
        $scenarios = $this->scenarioResults($options);
        $receiptReplay = $this->receiptReplay((int) ($options['receipt_limit'] ?? 25));
        $blockers = $this->blockers($scenarios, $receiptReplay);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'mode' => 'provider_free_read_only',
            'scenario_count' => count($scenarios),
            'passed_scenario_count' => count(array_filter(
                $scenarios,
                static fn (array $scenario): bool => ($scenario['status'] ?? null) === 'passed',
            )),
            'scenarios' => $scenarios,
            'receipt_replay' => $receiptReplay,
            'blockers' => $blockers,
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
                'replay_is_not_execution' => true,
                'external_benchmark_run' => false,
            ],
        ];
        $payload['replay_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $options
     * @return list<array<string,mixed>>
     */
    private function scenarioResults(array $options): array
    {
        $workspace = (string) ($options['workspace'] ?? 'atlas-server');
        $contextRefs = $this->strings($options['context_refs'] ?? [
            'docs/engineering-knowledge-base/atlas-execution-doctrine-product-delivery-system.md',
        ]);
        $evidenceRefs = $this->strings($options['evidence_refs'] ?? [
            'AEDPDS gate evidence recorded for deterministic replay',
        ]);
        $scenarioDefs = [
            [
                'id' => 'ecommerce_routes_to_forge_and_plans_repair_without_proof',
                'request' => 'cria um ecommerce completo com pagamentos e webhooks',
                'workspace' => $workspace,
                'operator_approved' => true,
                'context_refs' => $contextRefs,
                'ux_expectations' => ['checkout journey expectation'],
                'evidence_refs' => $evidenceRefs,
                'evidence' => [],
                'expect' => [
                    'route' => 'atlas_forge',
                    'delivery_status' => 'ready_for_delivery',
                    'effective_status' => 'ready_for_delivery',
                    'proof_status' => 'blocked',
                    'enforcement_status' => 'allowed',
                    'repair_status' => 'repair_required',
                    'repair_plan_status' => 'planned',
                    'twin_status' => 'simulated',
                ],
            ],
            [
                'id' => 'ecommerce_with_evidence_is_ready_for_delivery',
                'request' => 'cria um ecommerce completo com pagamentos e webhooks',
                'workspace' => $workspace,
                'operator_approved' => true,
                'context_refs' => $contextRefs,
                'ux_expectations' => ['checkout journey expectation'],
                'evidence_refs' => $evidenceRefs,
                'evidence' => [
                    'tests' => ['focused_tests passed', 'contract tests passed', 'security regression tests passed'],
                    'security' => ['abuse cases reviewed'],
                    'acceptance_mapping' => ['tests mapped to acceptance'],
                    'outcome' => ['outcome memory candidate recorded'],
                ],
                'expect' => [
                    'route' => 'atlas_forge',
                    'delivery_status' => 'ready_for_delivery',
                    'effective_status' => 'ready_for_delivery',
                    'proof_status' => 'ready',
                    'enforcement_status' => 'allowed',
                    'repair_status' => 'not_required',
                    'repair_plan_status' => 'not_required',
                    'twin_status' => 'simulated',
                ],
            ],
            [
                'id' => 'login_bug_routes_to_dev_with_repair_plan',
                'request' => 'estou com um bug na tela de login',
                'workspace' => $workspace,
                'operator_approved' => true,
                'context_refs' => $contextRefs,
                'ux_expectations' => ['login visual regression expectation'],
                'evidence_refs' => $evidenceRefs,
                'evidence' => [],
                'expect' => [
                    'route' => 'atlas_dev',
                    'delivery_status' => 'ready_for_delivery',
                    'effective_status' => 'ready_for_delivery',
                    'proof_status' => 'blocked',
                    'enforcement_status' => 'allowed',
                    'repair_status' => 'repair_required',
                    'repair_plan_status' => 'planned',
                    'twin_status' => 'simulated',
                ],
            ],
        ];

        return array_map(fn (array $scenario): array => $this->runScenario($scenario), $scenarioDefs);
    }

    /**
     * @param  array<string,mixed>  $scenario
     * @return array<string,mixed>
     */
    private function runScenario(array $scenario): array
    {
        $delivery = $this->deliveryRuntime->plan([
            'human_request' => $scenario['request'] ?? '',
            'workspace' => $scenario['workspace'] ?? 'atlas-server',
            'operator_approved' => (bool) ($scenario['operator_approved'] ?? false),
            'context_refs' => $scenario['context_refs'] ?? [],
            'ux_expectations' => $scenario['ux_expectations'] ?? [],
            'evidence_refs' => $scenario['evidence_refs'] ?? [],
            'evidence' => $scenario['evidence'] ?? [],
        ]);
        $actual = [
            'route' => $delivery['route'] ?? null,
            'delivery_status' => $delivery['status'] ?? null,
            'effective_status' => $this->effectiveStatus($delivery),
            'proof_status' => data_get($delivery, 'proof_preview.status'),
            'enforcement_status' => data_get($delivery, 'enforcement.status'),
            'repair_status' => data_get($delivery, 'repair_bridge.status'),
            'repair_plan_status' => data_get($delivery, 'multi_step_repair_plan.status'),
            'twin_status' => data_get($delivery, 'product_twin_simulation.status'),
        ];
        $expect = is_array($scenario['expect'] ?? null) ? $scenario['expect'] : [];
        $mismatches = [];
        foreach ($expect as $key => $expected) {
            if (($actual[$key] ?? null) !== $expected) {
                $mismatches[] = [
                    'field' => $key,
                    'expected' => $expected,
                    'actual' => $actual[$key] ?? null,
                ];
            }
        }

        return [
            'id' => (string) ($scenario['id'] ?? 'unknown'),
            'status' => $mismatches === [] ? 'passed' : 'failed',
            'request_hash' => MissionCanonicalHash::sha256((string) ($scenario['request'] ?? '')),
            'actual' => $actual,
            'expected' => $expect,
            'mismatches' => $mismatches,
            'delivery_hash' => (string) ($delivery['delivery_hash'] ?? ''),
            'proof_hash' => (string) data_get($delivery, 'proof_preview.proof_hash', ''),
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function receiptReplay(int $limit): array
    {
        try {
            $receiptsTableExists = Schema::hasTable('atlas_product_delivery_runtime_receipts');
        } catch (Throwable $exception) {
            return [
                'status' => 'not_available',
                'reason' => 'runtime_receipts_database_unavailable',
                'items' => [],
                'unsafe_write_receipt_count' => 0,
                'error_class' => $exception::class,
            ];
        }

        if (! $receiptsTableExists) {
            return [
                'status' => 'not_available',
                'reason' => 'runtime_receipts_table_missing',
                'items' => [],
                'unsafe_write_receipt_count' => 0,
            ];
        }

        try {
            $records = AtlasProductDeliveryRuntimeReceipt::query()
                ->latest('created_at')
                ->limit(max(1, min($limit, 100)))
                ->get();
        } catch (Throwable $exception) {
            return [
                'status' => 'not_available',
                'reason' => 'runtime_receipts_query_failed',
                'items' => [],
                'unsafe_write_receipt_count' => 0,
                'error_class' => $exception::class,
            ];
        }
        $items = $records->map(fn (AtlasProductDeliveryRuntimeReceipt $record): array => [
            'receipt_hash' => (string) $record->receipt_hash,
            'receipt_type' => (string) $record->receipt_type,
            'status' => (string) $record->status,
            'route' => $record->route,
            'writes' => (bool) $record->writes,
            'approval_present' => (bool) data_get($record->payload, 'patch_proposal_gate.operator_decision.approval')
                || (bool) data_get($record->payload, 'operator_decision.approval'),
        ])->values()->all();
        $unsafeWriteReceiptCount = count(array_filter(
            $items,
            static fn (array $item): bool => ($item['writes'] ?? false) === true
                && ($item['approval_present'] ?? false) !== true,
        ));

        return [
            'status' => $unsafeWriteReceiptCount === 0 ? 'ready' : 'blocked',
            'total' => count($items),
            'items' => $items,
            'unsafe_write_receipt_count' => $unsafeWriteReceiptCount,
        ];
    }

    /**
     * @param  array<string,mixed>  $delivery
     */
    private function effectiveStatus(array $delivery): string
    {
        if (data_get($delivery, 'enforcement.provider_execution_allowed') === false) {
            return 'blocked_needs_evidence';
        }

        return (string) ($delivery['status'] ?? 'unknown');
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): ?string => is_scalar($item) ? trim((string) $item) : null,
            $value,
        ), static fn (?string $item): bool => $item !== null && $item !== ''));
    }

    /**
     * @param  list<array<string,mixed>>  $scenarios
     * @param  array<string,mixed>  $receiptReplay
     * @return list<array<string,mixed>>
     */
    private function blockers(array $scenarios, array $receiptReplay): array
    {
        $blockers = [];
        foreach ($scenarios as $scenario) {
            if (($scenario['status'] ?? null) !== 'passed') {
                $blockers[] = [
                    'id' => 'scenario_replay_failed',
                    'scenario_id' => $scenario['id'] ?? 'unknown',
                ];
            }
        }
        if (($receiptReplay['unsafe_write_receipt_count'] ?? 0) > 0) {
            $blockers[] = [
                'id' => 'unsafe_write_receipts_detected',
                'count' => (int) $receiptReplay['unsafe_write_receipt_count'],
            ];
        }

        return $blockers;
    }
}
