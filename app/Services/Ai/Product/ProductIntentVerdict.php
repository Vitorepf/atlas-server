<?php

declare(strict_types=1);

namespace App\Services\Ai\Product;

final readonly class ProductIntentVerdict
{
    public function __construct(
        public string $status,
        public string $problem,
        public string $user,
        public string $value,
        public string $metric,
        public string $observationWindow,
        public array $sourceRefs,
        public array $constraints,
        public array $nonGoals,
        public array $hypotheses,
        public array $uncertainties,
        public array $alternatives,
        public array $falsifiers,
        public array $sideEffects,
        public array $acceptance,
        public array $releasePolicy,
        public array $outcomePolicy,
        public ?string $worldSnapshotHash,
        public array $blockingReasons,
        public string $intentHash,
        public array $productTruth,
        public ?string $falsificationHash = null,
        public ?string $providerProbeHash = null,
        public string $schemaVersion = 'atlas.product_intent_verdict.v1',
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion, 'status' => $this->status,
            'problem' => $this->problem, 'user' => $this->user, 'value' => $this->value,
            'metric' => $this->metric, 'observation_window' => $this->observationWindow,
            'source_refs' => $this->sourceRefs, 'constraints' => $this->constraints, 'non_goals' => $this->nonGoals,
            'hypotheses' => $this->hypotheses, 'uncertainties' => $this->uncertainties,
            'alternatives' => $this->alternatives, 'falsifiers' => $this->falsifiers,
            'side_effects' => $this->sideEffects, 'acceptance' => $this->acceptance,
            'release_policy' => $this->releasePolicy, 'outcome_policy' => $this->outcomePolicy,
            'world_snapshot_hash' => $this->worldSnapshotHash, 'blocking_reasons' => $this->blockingReasons,
            'intent_hash' => $this->intentHash, 'product_truth' => $this->productTruth,
            'falsification_hash' => $this->falsificationHash,
            'provider_probe_hash' => $this->providerProbeHash,
        ];
    }
}
