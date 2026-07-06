<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Verifies simplification candidates do not remove downstream proof,
 * learning or recovery capability.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasSelfConstructionSimplificationNoGapProofVerifier
{
    public const SCHEMA = 'atlas.self_construction.simplification_no_gap_proof_verifier.v1';

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    public function verify(array $candidate): array
    {
        $proofPreserved = (bool) ($candidate['downstream_proof_preserved'] ?? false);
        $learningPreserved = (bool) ($candidate['learning_capability_preserved'] ?? false);
        $recoveryPreserved = (bool) ($candidate['recovery_capability_preserved'] ?? false);

        $blockers = [];

        if (! $proofPreserved) {
            $blockers[] = 'missing_downstream_proof';
        }
        if (! $learningPreserved) {
            $blockers[] = 'missing_learning_capability';
        }
        if (! $recoveryPreserved) {
            $blockers[] = 'missing_recovery_capability';
        }

        $verified = $blockers === [];

        return [
            'schema' => self::SCHEMA,
            'verified' => $verified,
            'blockers' => $blockers,
            'proof_preserved' => $proofPreserved,
            'learning_preserved' => $learningPreserved,
            'recovery_preserved' => $recoveryPreserved,
        ];
    }
}
