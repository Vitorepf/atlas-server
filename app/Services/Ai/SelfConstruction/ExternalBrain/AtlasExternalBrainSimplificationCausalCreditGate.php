<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\Compounding\CausalLearningCandidate;
use App\Services\Ai\Compounding\CausalLearningGate;

/**
 * Routes simplification credit through the canonical causal gate.
 *
 * The simplification verifier proves consumer/equivalence/rollback safety; the
 * CausalLearningGate separately proves assignment, real outcome and reversible
 * promotion. A line-count reduction never earns credit by itself.
 */
final class AtlasExternalBrainSimplificationCausalCreditGate
{
    public const SCHEMA = 'atlas.external_brain.simplification_causal_credit.v1';

    public function __construct(
        private readonly AtlasExternalBrainSimplificationCandidateVerifier $verifier = new AtlasExternalBrainSimplificationCandidateVerifier,
        private readonly CausalLearningGate $causalGate = new CausalLearningGate,
    ) {}

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    public function adjudicate(array $candidate): array
    {
        $proof = $this->verifier->verify($candidate);
        if (($proof['approved'] ?? false) !== true) {
            return $this->envelope($proof, 'blocked', 'simplification_proof_incomplete');
        }

        $causalData = $candidate['causal_candidate'] ?? null;
        if (! is_array($causalData)) {
            return $this->envelope($proof, 'hold', 'causal_candidate_missing');
        }

        try {
            $causalCandidate = CausalLearningCandidate::fromArray($causalData);
        } catch (\Throwable $exception) {
            return $this->envelope($proof, 'hold', 'causal_candidate_invalid:'.$exception->getMessage());
        }

        $verdict = $this->causalGate->adjudicate($causalCandidate);
        $eligible = $verdict->verdict === 'promote_reversible';

        return [
            'schema' => self::SCHEMA,
            'candidate_id' => $proof['candidate_id'],
            'credit_eligible' => $eligible,
            'causal_verdict' => $verdict->verdict,
            'reason' => $verdict->reason,
            'decision_hash' => $verdict->decisionHash,
            'proof' => $proof,
            'credited_reduction' => $eligible ? (int) $proof['net_reduction_score'] : 0,
        ];
    }

    /** @param array<string,mixed> $proof @return array<string,mixed> */
    private function envelope(array $proof, string $verdict, string $reason): array
    {
        return [
            'schema' => self::SCHEMA,
            'candidate_id' => $proof['candidate_id'] ?? null,
            'credit_eligible' => false,
            'causal_verdict' => $verdict,
            'reason' => $reason,
            'decision_hash' => null,
            'proof' => $proof,
            'credited_reduction' => 0,
        ];
    }
}
