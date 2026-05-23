<?php

namespace App\Services\Ai\Product;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\AtlasDev\Repair\DevRepairLoopService;
use App\Services\Ai\Programming\Forge\ForgeWorkPacketExecutionCycleCanon;

class AtlasProductDeliveryRepairBridgeService
{
    public const SCHEMA_VERSION = 'atlas.product_delivery.repair_bridge.v1';

    public function __construct(
        private readonly DevRepairLoopService $devRepairLoop,
    ) {}

    /**
     * @param  array<string,mixed>  $delivery
     * @param  array<string,mixed>  $proof
     * @return array<string,mixed>
     */
    public function plan(array $delivery, array $proof): array
    {
        if (($proof['status'] ?? null) === 'ready') {
            return $this->payload($delivery, $proof, 'not_required', null, [
                'reason' => 'proof_ready',
            ]);
        }

        $route = (string) ($delivery['route'] ?? data_get($delivery, 'product_truth.execution_decomposition.route', 'atlas_dev'));
        $failurePacket = $this->failurePacket($delivery, $proof);
        $repair = $route === 'atlas_forge'
            ? $this->forgeRepairPacket($delivery, $proof, $failurePacket)
            : $this->devRepairReceipt($delivery, $failurePacket);

        return $this->payload($delivery, $proof, 'repair_required', $route, $repair);
    }

    /**
     * @param  array<string,mixed>  $delivery
     * @param  array<string,mixed>  $proof
     * @param  array<string,mixed>  $repair
     * @return array<string,mixed>
     */
    private function payload(array $delivery, array $proof, string $status, ?string $route, array $repair): array
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'route' => $route,
            'delivery_hash' => (string) ($delivery['delivery_hash'] ?? ''),
            'proof_hash' => (string) ($proof['proof_hash'] ?? ''),
            'repair' => $repair,
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
                'auto_mutates_code' => false,
            ],
        ];
        $payload['repair_bridge_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $delivery
     * @param  array<string,mixed>  $proof
     * @return array<string,mixed>
     */
    private function failurePacket(array $delivery, array $proof): array
    {
        $blockers = array_values(array_filter(array_map(
            static fn (mixed $blocker): ?string => is_array($blocker) && isset($blocker['id']) ? (string) $blocker['id'] : null,
            (array) ($proof['critical_blockers'] ?? []),
        )));
        $repairs = $this->stringList($proof['required_repairs'] ?? []);
        $message = trim(implode('; ', array_values(array_unique(array_merge($blockers, $repairs)))));

        return [
            'gate' => 'apfpr',
            'command' => 'php artisan atlas:product-proof:challenge --json',
            'exit_code' => 1,
            'primary_error_excerpt' => $message !== '' ? $message : 'APFPR blocked product delivery.',
            'failing_test' => in_array('missing_test_evidence', $blockers, true) ? 'product_delivery_proof_tests' : null,
            'changed_files' => $this->stringList(data_get($delivery, 'delivery_plan.scope_guard.allowed_files', [])),
            'failure_type' => 'product_delivery_proof_blocked',
            'failed_commands' => $this->stringList(data_get($delivery, 'delivery_plan.tests', [])),
            'failure_hash' => MissionCanonicalHash::sha256([
                'delivery_hash' => $delivery['delivery_hash'] ?? '',
                'proof_hash' => $proof['proof_hash'] ?? '',
                'blockers' => $blockers,
                'repairs' => $repairs,
            ]),
        ];
    }

    /**
     * @param  array<string,mixed>  $delivery
     * @param  array<string,mixed>  $failurePacket
     * @return array<string,mixed>
     */
    private function devRepairReceipt(array $delivery, array $failurePacket): array
    {
        return $this->devRepairLoop->evaluate([
            'run_id' => 'aedpds:'.substr((string) ($delivery['delivery_hash'] ?? 'unknown'), 0, 16),
            'task_contract_hash' => (string) ($delivery['delivery_hash'] ?? 'unknown'),
            'risk_level' => $this->riskLevel($delivery),
            'attempt_count' => 0,
            'max_attempts' => 2,
            'failure_packet' => $failurePacket,
            'changed_files' => $this->stringList($failurePacket['changed_files'] ?? []),
            'retrieval_plan' => [
                'context_sufficiency_gate' => ['status' => 'ready'],
                'test_impact' => [
                    'recommended_commands' => $this->stringList(data_get($delivery, 'delivery_plan.tests', [])),
                ],
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $delivery
     * @param  array<string,mixed>  $proof
     * @param  array<string,mixed>  $failurePacket
     * @return array<string,mixed>
     */
    private function forgeRepairPacket(array $delivery, array $proof, array $failurePacket): array
    {
        $repairs = $this->stringList($proof['required_repairs'] ?? []);
        $repairHint = in_array('add_or_run_focused_tests', $repairs, true)
            ? ForgeWorkPacketExecutionCycleCanon::REPAIR_HINT_ADD_TESTS
            : ForgeWorkPacketExecutionCycleCanon::REPAIR_HINT_RETRY_WITH_FRESH_CONTEXT;
        $packet = [
            'schema_version' => 'atlas.forge.apfpr_repair_packet.v1',
            'status' => 'planned',
            'repair_hint' => $repairHint,
            'failure_class' => 'product_delivery_proof_failure',
            'failure_excerpt' => (string) ($failurePacket['primary_error_excerpt'] ?? 'APFPR blocked Forge delivery.'),
            'next_packet_required' => true,
            'required_repairs' => $repairs,
            'required_evidence' => [
                'focused_tests',
                'acceptance_mapping',
                'proof_rerun',
                'outcome_memory',
            ],
            'target_route' => 'atlas_forge',
            'delivery_hash' => (string) ($delivery['delivery_hash'] ?? ''),
            'proof_hash' => (string) ($proof['proof_hash'] ?? ''),
        ];
        $packet['repair_packet_hash'] = MissionCanonicalHash::sha256($packet);

        return $packet;
    }

    /**
     * @param  array<string,mixed>  $delivery
     */
    private function riskLevel(array $delivery): string
    {
        $required = $this->stringList(data_get($delivery, 'product_truth.execution_lenses.required', []));

        return array_intersect($required, ['security_driven', 'performance_driven', 'add']) !== []
            ? 'R3'
            : 'R2';
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): ?string => is_scalar($item) && trim((string) $item) !== '' ? trim((string) $item) : null,
            $value,
        )));
    }
}
