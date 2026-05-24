<?php

namespace App\Services\Ai\Product;

use App\Models\AtlasProductDeliveryRuntimeReceipt;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\Schema;

class AtlasProductDeliveryControlPlaneService
{
    public const SCHEMA_VERSION = 'atlas.product_delivery.control_plane.v1';

    public function __construct(
        private readonly AtlasAutonomousProductDeliveryRuntimeService $deliveryRuntime = new AtlasAutonomousProductDeliveryRuntimeService,
        private readonly AtlasProductDeliveryRiskGovernorService $riskGovernor = new AtlasProductDeliveryRiskGovernorService,
        private readonly AtlasProductDeliveryEvidenceReplayLabService $replayLab = new AtlasProductDeliveryEvidenceReplayLabService,
        private readonly AtlasProductDeliveryDoctrineFitnessService $doctrineFitness = new AtlasProductDeliveryDoctrineFitnessService,
        private readonly AtlasProductDeliveryProviderMemoryFeedService $providerMemory = new AtlasProductDeliveryProviderMemoryFeedService,
        private readonly AtlasProductDeliveryPolicyOptimizerService $policyOptimizer = new AtlasProductDeliveryPolicyOptimizerService,
        private readonly AtlasProductDeliveryCertificationService $certification = new AtlasProductDeliveryCertificationService,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function snapshot(array $options = []): array
    {
        $delivery = $this->deliveryRuntime->plan([
            'human_request' => $this->string($options['human_request'] ?? $options['request'] ?? null)
                ?? 'Atlas product delivery control plane snapshot',
            'workspace' => $this->string($options['workspace'] ?? null),
            'route' => $this->string($options['route'] ?? null),
            'provider_patch' => (bool) ($options['provider_patch'] ?? false),
            'operator_approved' => (bool) ($options['operator_approved'] ?? false),
            'evidence' => $this->map($options['evidence'] ?? []),
            'context_refs' => $this->list($options['context_refs'] ?? []),
            'canonical_docs' => $this->list($options['canonical_docs'] ?? []),
            'evidence_refs' => $this->list($options['evidence_refs'] ?? data_get($options, 'evidence.tests', [])),
            'ux_expectations' => $this->list($options['ux_expectations'] ?? []),
        ]);
        $replay = $this->replayLab->replay([
            'workspace' => $this->string($options['workspace'] ?? null) ?? 'atlas-server',
            'receipt_limit' => (int) ($options['receipt_limit'] ?? 25),
        ]);
        $fitness = $this->doctrineFitness->evaluate([
            'limit' => (int) ($options['fitness_limit'] ?? 100),
        ]);
        $providerMemory = $this->providerMemory->analyze([
            'limit' => (int) ($options['provider_memory_limit'] ?? 100),
            'route' => $this->string($options['route'] ?? null),
        ]);
        $policyOptimizer = $this->policyOptimizer->propose([
            'replay_report' => $replay,
            'doctrine_fitness' => $fitness,
            'provider_memory_feed' => $providerMemory,
        ]);
        $risk = $this->riskGovernor->evaluate(
            delivery: $delivery,
            proof: is_array($delivery['proof_preview'] ?? null) ? $delivery['proof_preview'] : [],
            simulation: is_array($delivery['product_twin_simulation'] ?? null) ? $delivery['product_twin_simulation'] : [],
            options: [
                'provider_patch' => (bool) ($options['provider_patch'] ?? false),
                'operator_approved' => (bool) ($options['operator_approved'] ?? false),
                'replay_report' => $replay,
                'doctrine_fitness' => $fitness,
                'provider_memory_feed' => $providerMemory,
                'receipt_limit' => (int) ($options['receipt_limit'] ?? 25),
            ],
        );
        $cert = $this->certification->certify();
        $blockers = $this->blockers($risk, $replay, $fitness, $cert);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $blockers === [] ? 'healthy' : 'blocked',
            'mode' => 'provider_free_read_only',
            'delivery' => [
                'schema_version' => $delivery['schema_version'] ?? null,
                'status' => $delivery['status'] ?? null,
                'route' => $delivery['route'] ?? null,
                'delivery_hash' => $delivery['delivery_hash'] ?? null,
                'proof_status' => data_get($delivery, 'proof_preview.status'),
                'twin_status' => data_get($delivery, 'product_twin_simulation.status'),
            ],
            'risk_governor' => [
                'status' => $risk['status'] ?? null,
                'risk_band' => $risk['risk_band'] ?? null,
                'risk_score' => $risk['risk_score'] ?? null,
                'max_autonomy_level' => data_get($risk, 'governor_decision.max_autonomy_level'),
                'required_gates' => $risk['required_gates'] ?? [],
                'required_approvals' => $risk['required_approvals'] ?? [],
                'blockers' => $risk['blockers'] ?? [],
                'hash' => $risk['risk_governor_hash'] ?? null,
            ],
            'replay' => [
                'status' => $replay['status'] ?? null,
                'scenario_count' => $replay['scenario_count'] ?? 0,
                'passed_scenario_count' => $replay['passed_scenario_count'] ?? 0,
                'unsafe_write_receipt_count' => data_get($replay, 'receipt_replay.unsafe_write_receipt_count', 0),
                'hash' => $replay['replay_hash'] ?? null,
            ],
            'doctrine_fitness' => [
                'status' => $fitness['status'] ?? null,
                'sample_size' => $fitness['sample_size'] ?? 0,
                'policy_proposal_count' => is_array($fitness['policy_proposals'] ?? null)
                    ? count($fitness['policy_proposals'])
                    : 0,
                'hash' => $fitness['fitness_hash'] ?? null,
            ],
            'provider_memory' => [
                'status' => $providerMemory['status'] ?? null,
                'receipt_count' => data_get($providerMemory, 'sample.receipt_count', 0),
                'outcome_count' => data_get($providerMemory, 'sample.outcome_count', 0),
                'provider_failure_count' => data_get($providerMemory, 'risk_signals.provider_failure_count', 0),
                'flake_count' => data_get($providerMemory, 'risk_signals.flake_count', 0),
                'cost_pressure' => data_get($providerMemory, 'risk_signals.cost_pressure', false),
                'hash' => $providerMemory['provider_memory_hash'] ?? null,
            ],
            'policy_optimizer' => [
                'status' => $policyOptimizer['status'] ?? null,
                'proposal_count' => $policyOptimizer['proposal_count'] ?? 0,
                'requires_aemor_judgment' => data_get($policyOptimizer, 'policy_change_contract.requires_aemor_judgment', false),
                'auto_apply_allowed' => data_get($policyOptimizer, 'policy_change_contract.auto_apply_allowed', false),
                'hash' => $policyOptimizer['policy_optimizer_hash'] ?? null,
            ],
            'runtime_receipts' => $this->receiptSummary((int) ($options['receipt_limit'] ?? 25)),
            'certification' => [
                'status' => $cert['status'] ?? null,
                'passed' => data_get($cert, 'summary.passed', 0),
                'total' => data_get($cert, 'summary.total', 0),
                'hash' => $cert['certification_hash'] ?? null,
            ],
            'blockers' => $blockers,
            'next_actions' => $this->nextActions($blockers, $risk, $replay, $fitness, $policyOptimizer),
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
                'control_plane_is_not_execution' => true,
                'external_superiority_claim' => false,
            ],
        ];
        $payload['control_plane_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $risk
     * @param  array<string,mixed>  $replay
     * @param  array<string,mixed>  $fitness
     * @param  array<string,mixed>  $certification
     * @return list<array<string,mixed>>
     */
    private function blockers(array $risk, array $replay, array $fitness, array $certification): array
    {
        $blockers = [];
        if (($certification['status'] ?? null) !== 'ready') {
            $blockers[] = ['id' => 'product_delivery_certification_not_ready', 'severity' => 'critical'];
        }
        if (($replay['status'] ?? null) === 'blocked') {
            $blockers[] = ['id' => 'evidence_replay_blocked', 'severity' => 'critical'];
        }
        if (($risk['status'] ?? null) === 'blocked') {
            $blockers[] = ['id' => 'risk_governor_blocked', 'severity' => 'critical'];
        }
        if (($fitness['status'] ?? null) === 'needs_more_evidence') {
            $blockers[] = ['id' => 'doctrine_fitness_needs_more_evidence', 'severity' => 'warn'];
        }

        return $blockers;
    }

    /**
     * @return array<string,mixed>
     */
    private function receiptSummary(int $limit): array
    {
        if (! Schema::hasTable('atlas_product_delivery_runtime_receipts')) {
            return [
                'status' => 'not_available',
                'sample_size' => 0,
                'writes' => 0,
                'approval_required' => 0,
            ];
        }

        $records = AtlasProductDeliveryRuntimeReceipt::query()
            ->latest('created_at')
            ->limit(max(1, min($limit, 100)))
            ->get();

        return [
            'status' => 'ready',
            'sample_size' => $records->count(),
            'writes' => $records->where('writes', true)->count(),
            'approval_required' => $records->filter(
                static fn (AtlasProductDeliveryRuntimeReceipt $record): bool => in_array((string) $record->status, ['needs_human_approval', 'blocked'], true)
                    || data_get($record->payload, 'approval_required') === true,
            )->count(),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $blockers
     * @param  array<string,mixed>  $risk
     * @param  array<string,mixed>  $replay
     * @param  array<string,mixed>  $fitness
     * @param  array<string,mixed>  $policyOptimizer
     * @return list<string>
     */
    private function nextActions(array $blockers, array $risk, array $replay, array $fitness, array $policyOptimizer): array
    {
        if ($blockers === []) {
            $actions = ['continue_with_required_gates', 'record_outcome_memory_after_execution'];
            if (($policyOptimizer['status'] ?? null) === 'proposal_ready') {
                $actions[] = 'review_policy_optimizer_proposals_before_policy_change';
            }

            return $actions;
        }

        $actions = [];
        if (($risk['status'] ?? null) === 'blocked') {
            $actions[] = 'resolve_risk_governor_blockers';
        }
        if (($replay['status'] ?? null) === 'blocked') {
            $actions[] = 'repair_replay_regression_before_autonomy';
        }
        if (($fitness['status'] ?? null) === 'needs_more_evidence') {
            $actions[] = 'collect_more_outcome_memory_before_policy_change';
        }
        if (($policyOptimizer['status'] ?? null) === 'proposal_ready') {
            $actions[] = 'review_policy_optimizer_proposals_before_policy_change';
        }

        return $actions === [] ? ['inspect_product_delivery_blockers'] : array_values(array_unique($actions));
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<mixed>
     */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
