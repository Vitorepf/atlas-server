<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\Compounding\CausalLearningCandidate;
use App\Services\Ai\Compounding\CausalLearningGate;
use App\Services\Ai\Compounding\CausalLearningPromotion;
use App\Services\Ai\Compounding\CausalLearningPromotionService;

/** Applies the late-regression decision through the causal gate and promotion owner. */
final class AtlasExternalBrainLateRegressionCoordinator
{
    public const SCHEMA = 'atlas.external_brain.late_regression_coordinator.v1';

    public function __construct(
        private readonly ?CausalLearningGate $gate = null,
        private readonly ?CausalLearningPromotionService $promotions = null,
        private readonly ?AtlasExternalBrainLateRegressionResponse $response = null,
    ) {}

    /** @param array<string,mixed> $observation @return array<string,mixed> */
    public function handle(CausalLearningCandidate $candidate, CausalLearningPromotion $promotion, array $observation): array
    {
        $decision = ($this->gate ?? new CausalLearningGate)->adjudicateLateRegression($candidate, $observation);
        if ($decision['status'] !== 'revoke') {
            return [
                'schema' => self::SCHEMA,
                'status' => $decision['status'],
                'decision' => $decision,
                'promotion' => $promotion,
                'response' => ['status' => 'no_mutation', 'route_rollback' => ['required' => false], 'claim_mutated_here' => false],
            ];
        }

        $intent = ($this->response ?? new AtlasExternalBrainLateRegressionResponse)->respond($promotion, $observation + ['evidence_refs' => $decision['evidence_refs']]);
        $revoked = ($this->promotions ?? new CausalLearningPromotionService)->revoke($promotion, $decision['reason']);

        return ['schema' => self::SCHEMA, 'status' => 'revoked', 'decision' => $decision, 'promotion' => $revoked, 'response' => $intent];
    }
}
