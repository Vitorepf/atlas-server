<?php

namespace App\Services\Ai\Product;

use App\Services\Ai\Mission\MissionCanonicalHash;

class AtlasProductDeliveryPolicyOptimizerService
{
    public const SCHEMA_VERSION = 'atlas.product_delivery.policy_optimizer.v1';

    public function __construct(
        private readonly AtlasProductDeliveryEvidenceReplayLabService $replayLab = new AtlasProductDeliveryEvidenceReplayLabService,
        private readonly AtlasProductDeliveryDoctrineFitnessService $doctrineFitness = new AtlasProductDeliveryDoctrineFitnessService,
        private readonly AtlasProductDeliveryProviderMemoryFeedService $providerMemory = new AtlasProductDeliveryProviderMemoryFeedService,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function propose(array $options = []): array
    {
        $replay = is_array($options['replay_report'] ?? null)
            ? $options['replay_report']
            : $this->replayLab->replay(['receipt_limit' => (int) ($options['receipt_limit'] ?? 25)]);
        $fitness = is_array($options['doctrine_fitness'] ?? null)
            ? $options['doctrine_fitness']
            : $this->doctrineFitness->evaluate(['limit' => (int) ($options['fitness_limit'] ?? 100)]);
        $providerMemory = is_array($options['provider_memory_feed'] ?? null)
            ? $options['provider_memory_feed']
            : $this->providerMemory->analyze(['limit' => (int) ($options['provider_memory_limit'] ?? 100)]);
        $proposals = $this->proposals($replay, $fitness, $providerMemory);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $proposals === [] ? 'watch' : 'proposal_ready',
            'mode' => 'provider_free_read_only',
            'proposal_count' => count($proposals),
            'proposals' => $proposals,
            'source_signals' => [
                'replay_status' => $replay['status'] ?? null,
                'fitness_status' => $fitness['status'] ?? null,
                'provider_memory_status' => $providerMemory['status'] ?? null,
                'provider_failure_count' => (int) data_get($providerMemory, 'risk_signals.provider_failure_count', 0),
                'flake_count' => (int) data_get($providerMemory, 'risk_signals.flake_count', 0),
                'cost_pressure' => (bool) data_get($providerMemory, 'risk_signals.cost_pressure', false),
            ],
            'policy_change_contract' => [
                'auto_apply_allowed' => false,
                'requires_aemor_judgment' => true,
                'requires_human_review' => true,
                'requires_replay_green' => true,
                'requires_rollback_plan' => true,
            ],
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
                'policy_optimizer_is_not_policy_mutation' => true,
            ],
        ];
        $payload['policy_optimizer_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function proposals(array $replay, array $fitness, array $providerMemory): array
    {
        $proposals = [];
        foreach ($this->list($fitness['policy_proposals'] ?? []) as $proposal) {
            if (is_array($proposal)) {
                $proposals[] = $this->proposal(
                    id: 'doctrine_fitness_'.(count($proposals) + 1),
                    kind: 'doctrine_fitness',
                    reason: (string) ($proposal['reason'] ?? $proposal['proposal_type'] ?? 'Doctrine fitness proposed review.'),
                    evidence: ['doctrine_fitness.policy_proposals'],
                    action: 'review_delivery_doctrine_lens_or_gate',
                    risk: 'medium',
                );
            }
        }
        if (($replay['status'] ?? null) === 'blocked') {
            $proposals[] = $this->proposal(
                id: 'replay_regression_guard',
                kind: 'replay_regression',
                reason: 'Evidence replay is blocked; autonomy must not increase until canonical scenarios pass.',
                evidence: ['evidence_replay.status=blocked'],
                action: 'tighten_replay_gate_before_policy_change',
                risk: 'high',
            );
        }
        if ((int) data_get($providerMemory, 'risk_signals.provider_failure_count', 0) > 0) {
            $proposals[] = $this->proposal(
                id: 'provider_failure_memory_gate',
                kind: 'provider_reliability',
                reason: 'Provider failure history exists for recent AEDPDS receipts.',
                evidence: ['provider_memory.provider_failure_count'],
                action: 'require_provider_memory_review_before_autonomy_increase',
                risk: 'medium',
            );
        }
        if ((int) data_get($providerMemory, 'risk_signals.flake_count', 0) > 0) {
            $proposals[] = $this->proposal(
                id: 'flake_triage_gate',
                kind: 'test_flake',
                reason: 'Recent receipts contain flaky test signals.',
                evidence: ['provider_memory.flake_count'],
                action: 'require_flake_triage_before_completion_or_release_gate',
                risk: 'medium',
            );
        }
        if ((bool) data_get($providerMemory, 'risk_signals.cost_pressure', false)) {
            $proposals[] = $this->proposal(
                id: 'cost_pressure_budget_gate',
                kind: 'cost_pressure',
                reason: 'Recent provider/runtime receipts exceed cost pressure threshold.',
                evidence: ['provider_memory.cost_pressure'],
                action: 'require_cost_budget_review_before_expensive_provider_path',
                risk: 'low',
            );
        }

        return array_values($proposals);
    }

    /**
     * @param  list<string>  $evidence
     * @return array<string,mixed>
     */
    private function proposal(string $id, string $kind, string $reason, array $evidence, string $action, string $risk): array
    {
        return [
            'schema_version' => 'atlas.product_delivery.policy_proposal.v1',
            'id' => $id,
            'kind' => $kind,
            'status' => 'requires_aemor_and_human_review',
            'risk' => $risk,
            'reason' => $reason,
            'evidence' => $evidence,
            'recommended_action' => $action,
        ];
    }

    /**
     * @return list<mixed>
     */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
