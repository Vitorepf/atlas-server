<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Execution;

use App\Services\Ai\EngineeringKernel\CanonicalKernelPayload;
use InvalidArgumentException;

final readonly class DevIntent
{
    private function __construct(
        public string $rawGoal,
        public string $workspace,
        public string $operatorId,
        public string $productIntentHash,
        public string $specHash,
        public string $worldModelSnapshotHash,
        public string $authorityHash,
        public string $riskClass,
        public string $durationRegime,
        public string $topology,
        public bool $mutate,
        public array $constraints,
        public ?string $marketDecisionHash,
        public string $intentHash,
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $required = ['raw_goal', 'workspace', 'operator_id', 'product_intent_hash', 'spec_hash', 'world_model_snapshot_hash', 'authority_hash', 'risk_class', 'duration_regime', 'topology'];
        foreach ($required as $field) {
            if (! is_string($data[$field] ?? null) || trim($data[$field]) === '') {
                throw new InvalidArgumentException('dev_intent_'.$field.'_required');
            }
        }
        foreach (['product_intent_hash', 'spec_hash', 'world_model_snapshot_hash', 'authority_hash'] as $field) {
            if (preg_match('/^[a-f0-9]{64}$/', (string) $data[$field]) !== 1) {
                throw new InvalidArgumentException('dev_intent_'.$field.'_invalid');
            }
        }
        if (! in_array($data['risk_class'], ['R0', 'R1', 'R2', 'R3', 'R4', 'R5'], true)) {
            throw new InvalidArgumentException('dev_intent_risk_class_invalid');
        }

        $canonical = [
            'raw_goal' => trim($data['raw_goal']), 'workspace' => trim($data['workspace']), 'operator_id' => trim($data['operator_id']),
            'product_intent_hash' => $data['product_intent_hash'], 'spec_hash' => $data['spec_hash'],
            'world_model_snapshot_hash' => $data['world_model_snapshot_hash'], 'authority_hash' => $data['authority_hash'],
            'risk_class' => $data['risk_class'], 'duration_regime' => trim($data['duration_regime']),
            'topology' => trim($data['topology']), 'mutate' => (bool) ($data['mutate'] ?? false),
            'constraints' => array_values((array) ($data['constraints'] ?? [])),
        ];
        $marketDecisionHash = $data['market_decision_hash'] ?? null;
        if ($marketDecisionHash !== null && preg_match('/^[a-f0-9]{64}$/', (string) $marketDecisionHash) !== 1) {
            throw new InvalidArgumentException('dev_intent_market_decision_hash_invalid');
        }
        if ($marketDecisionHash !== null) {
            $canonical['market_decision_hash'] = $marketDecisionHash;
        }

        return new self(
            $canonical['raw_goal'], $canonical['workspace'], $canonical['operator_id'],
            $canonical['product_intent_hash'], $canonical['spec_hash'], $canonical['world_model_snapshot_hash'],
            $canonical['authority_hash'], $canonical['risk_class'], $canonical['duration_regime'],
            $canonical['topology'], $canonical['mutate'], $canonical['constraints'], $marketDecisionHash, CanonicalKernelPayload::hash($canonical),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $payload = [
            'raw_goal' => $this->rawGoal, 'workspace' => $this->workspace, 'operator_id' => $this->operatorId,
            'product_intent_hash' => $this->productIntentHash, 'spec_hash' => $this->specHash,
            'world_model_snapshot_hash' => $this->worldModelSnapshotHash, 'authority_hash' => $this->authorityHash,
            'risk_class' => $this->riskClass, 'duration_regime' => $this->durationRegime,
            'topology' => $this->topology, 'mutate' => $this->mutate, 'constraints' => $this->constraints, 'intent_hash' => $this->intentHash,
        ];
        if ($this->marketDecisionHash !== null) {
            $payload['market_decision_hash'] = $this->marketDecisionHash;
        }

        return $payload;
    }
}
