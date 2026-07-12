<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Forge\Execution;

use App\Services\Ai\EngineeringKernel\CanonicalKernelPayload;
use InvalidArgumentException;

final readonly class ForgeCommissioning
{
    public const RELEASE_POLICY_CANONICAL_COMMIT_WITH_CANARY = 'canonical_commit_with_canary';

    public const INTERRUPTION_POLICY_PAUSE_DRAIN_RESUME = 'pause_drain_resume';

    private function __construct(
        public string $prompt, public string $workspace, public string $authorityHash,
        public string $productIntentHash, public string $specHash, public string $worldModelSnapshotHash,
        public string $releasePolicy, public string $interruptionPolicy, public string $riskClass,
        public string $topology, public ?string $marketDecisionHash, public string $commissioningHash,
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $fields = ['prompt', 'workspace', 'authority_hash', 'product_intent_hash', 'spec_hash', 'world_model_snapshot_hash', 'release_policy', 'interruption_policy', 'risk_class', 'topology'];
        foreach ($fields as $field) {
            if (! is_string($data[$field] ?? null) || trim($data[$field]) === '') {
                throw new InvalidArgumentException('forge_commissioning_'.$field.'_required');
            }
        }
        foreach (['authority_hash', 'product_intent_hash', 'spec_hash', 'world_model_snapshot_hash'] as $field) {
            if (preg_match('/^[a-f0-9]{64}$/', $data[$field]) !== 1) {
                throw new InvalidArgumentException('forge_commissioning_'.$field.'_invalid');
            }
        }
        if (! in_array($data['risk_class'], ['R0', 'R1', 'R2', 'R3', 'R4', 'R5'], true)
            || $data['topology'] !== 'DAG') {
            throw new InvalidArgumentException('forge_commissioning_topology_or_risk_invalid');
        }
        if ($data['release_policy'] !== self::RELEASE_POLICY_CANONICAL_COMMIT_WITH_CANARY) {
            throw new InvalidArgumentException('forge_commissioning_release_policy_invalid');
        }
        if ($data['interruption_policy'] !== self::INTERRUPTION_POLICY_PAUSE_DRAIN_RESUME) {
            throw new InvalidArgumentException('forge_commissioning_interruption_policy_invalid');
        }
        $canonical = [
            'prompt' => trim($data['prompt']), 'workspace' => trim($data['workspace']), 'authority_hash' => $data['authority_hash'],
            'product_intent_hash' => $data['product_intent_hash'], 'spec_hash' => $data['spec_hash'],
            'world_model_snapshot_hash' => $data['world_model_snapshot_hash'], 'release_policy' => trim($data['release_policy']),
            'interruption_policy' => trim($data['interruption_policy']), 'risk_class' => $data['risk_class'], 'topology' => $data['topology'],
        ];
        $marketDecisionHash = $data['market_decision_hash'] ?? null;
        if ($marketDecisionHash !== null && preg_match('/^[a-f0-9]{64}$/', (string) $marketDecisionHash) !== 1) {
            throw new InvalidArgumentException('forge_commissioning_market_decision_hash_invalid');
        }
        if ($marketDecisionHash !== null) {
            $canonical['market_decision_hash'] = $marketDecisionHash;
        }

        return new self(
            $canonical['prompt'], $canonical['workspace'], $canonical['authority_hash'], $canonical['product_intent_hash'],
            $canonical['spec_hash'], $canonical['world_model_snapshot_hash'], $canonical['release_policy'],
            $canonical['interruption_policy'], $canonical['risk_class'], $canonical['topology'],
            $marketDecisionHash, CanonicalKernelPayload::hash($canonical),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['prompt' => $this->prompt, 'workspace' => $this->workspace, 'authority_hash' => $this->authorityHash,
            'product_intent_hash' => $this->productIntentHash, 'spec_hash' => $this->specHash,
            'world_model_snapshot_hash' => $this->worldModelSnapshotHash, 'release_policy' => $this->releasePolicy,
            'interruption_policy' => $this->interruptionPolicy, 'risk_class' => $this->riskClass, 'topology' => $this->topology,
            'commissioning_hash' => $this->commissioningHash] + ($this->marketDecisionHash === null ? [] : ['market_decision_hash' => $this->marketDecisionHash]);
    }
}
