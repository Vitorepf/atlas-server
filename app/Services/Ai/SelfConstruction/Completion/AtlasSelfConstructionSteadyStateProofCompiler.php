<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Completion;

/**
 * Compiles proof requirements for steady-state autonomy from queue,
 * learning, recovery, originator and verification signals.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasSelfConstructionSteadyStateProofCompiler
{
    public const SCHEMA = 'atlas.self_construction.steady_state_proof_compiler.v1';

    public const PROOF_QUEUE = 'queue_steady_state_proof';
    public const PROOF_LEARNING = 'learning_steady_state_proof';
    public const PROOF_RECOVERY = 'recovery_steady_state_proof';
    public const PROOF_ORIGINATOR = 'originator_steady_state_proof';
    public const PROOF_VERIFICATION = 'verification_steady_state_proof';

    public const ALL_PROOFS = [
        self::PROOF_QUEUE,
        self::PROOF_LEARNING,
        self::PROOF_RECOVERY,
        self::PROOF_ORIGINATOR,
        self::PROOF_VERIFICATION,
    ];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function compile(array $input): array
    {
        $missing = [];
        $present = [];

        foreach (self::ALL_PROOFS as $proofId) {
            $value = $input[$proofId] ?? null;
            if ($value === null || $value === '' || $value === false) {
                $missing[] = $proofId;
            } else {
                $present[] = $proofId;
            }
        }

        $ready = $missing === [];

        return [
            'schema' => self::SCHEMA,
            'ready' => $ready,
            'present_proofs' => $present,
            'missing_proofs' => $missing,
            'missing_proof_count' => count($missing),
            'all_required_proofs' => self::ALL_PROOFS,
        ];
    }
}
