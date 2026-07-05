<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MultiAgentLoopCertification;

use App\Services\Ai\SelfConstruction\TerminalLoopProof\TerminalLoopProofCanonicalizer;
use App\Services\Ai\SelfConstruction\TerminalLoopProof\TerminalLoopProofDigestInterpreter;
use App\Services\Ai\SelfConstruction\TerminalLoopProof\TerminalLoopProofReadinessMatrixBuilder;

/**
 * Composes the six multi-agent-loop certification organs into a single
 * certification verdict:
 *
 *   - TerminalLoopProofCanonicalizer::stableHash()
 *   - TerminalLoopProofDigestInterpreter::digestSummary()
 *   - TerminalLoopProofReadinessMatrixBuilder::operationalReadinessMatrix()
 *   - AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::hashInvariantMatrix()
 *   - AgentControlPlaneMultiAgentLoopCanonicalInvariantMatrixBuilder::build()
 *   - AgentControlPlaneTerminalLoopHealthDigestPresenceProbe::probe()
 *
 * All dependencies are pure static methods — the constructor accepts no
 * required arguments (nullable injection) so callers can instantiate
 * freely without a container.
 */
final class AtlasMultiAgentLoopCertificationRunner
{
    /**
     * @param  array<string, mixed>  $proof  Terminal-loop proof with keys:
     *   proof_payload            array   The raw proof payload (canonicalized for hash-drift check)
     *   expected_proof_hash      string  Expected canonical proof hash (empty = skip hash check)
     *   health_digest            ?array  Terminal-loop health digest
     *   invariants               array   Invariant flags (string => bool)
     *   hashes                   array   Evidence hashes (string => string)
     *   cycle_evidence           array   Cycle evidence list
     *   target_min               int     Target minimum claimable tasks
     *   cycles                   int     Number of certification cycles
     * @return array{
     *   certified: bool,
     *   proof: string,
     *   digest: array,
     *   readiness_matrix: array,
     *   certification_hash: string,
     *   invariant_matrix: array,
     *   health_digest: array,
     *   reasons: list<string>,
     * }
     */
    public function run(array $proof): array
    {
        $proofPayload = (array) ($proof['proof_payload'] ?? []);
        $expectedHash = (string) ($proof['expected_proof_hash'] ?? '');
        $healthDigest = isset($proof['health_digest']) && is_array($proof['health_digest'])
            ? $proof['health_digest']
            : [];
        $invariants = (array) ($proof['invariants'] ?? []);
        $hashes = (array) ($proof['hashes'] ?? []);
        $cycleEvidence = (array) ($proof['cycle_evidence'] ?? []);
        $targetMin = max(1, (int) ($proof['target_min'] ?? 1));
        $cycles = max(1, (int) ($proof['cycles'] ?? 1));

        // ── 1. Canonicalize proof payload → stable hash ────────────────
        $proofHash = TerminalLoopProofCanonicalizer::stableHash($proofPayload);

        // ── 2. Interpret health digest → structured summary ────────────
        $digest = TerminalLoopProofDigestInterpreter::digestSummary($healthDigest);

        // ── 3. Build operational readiness matrix ──────────────────────
        $readinessMatrix = TerminalLoopProofReadinessMatrixBuilder::operationalReadinessMatrix($invariants, $hashes);

        // ── 4. Build canonical invariant matrix ────────────────────────
        $invariantMatrix = AgentControlPlaneMultiAgentLoopCanonicalInvariantMatrixBuilder::build(
            $invariants,
            $cycleEvidence,
            $targetMin,
            $cycles,
        );

        // ── 5. Hash the invariant matrix → certification hash ──────────
        $certificationHash = AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::hashInvariantMatrix($invariantMatrix);

        // ── 6. Probe health-digest presence ────────────────────────────
        $healthDigestResult = AgentControlPlaneTerminalLoopHealthDigestPresenceProbe::probe([
            'terminal_loop_health_digest' => $healthDigest,
        ]);

        // ── 7. Certification verdict ───────────────────────────────────
        $reasons = [];

        $hashMatch = $expectedHash === '' || $expectedHash === $proofHash;
        if (! $hashMatch) {
            $reasons[] = 'proof_hash_drift';
        }

        if (! $healthDigestResult['present']) {
            $reasons[] = 'health_digest_missing';
        }

        $certified = $hashMatch && $healthDigestResult['present'];

        return [
            'certified' => $certified,
            'proof' => $proofHash,
            'digest' => $digest,
            'readiness_matrix' => $readinessMatrix,
            'certification_hash' => $certificationHash,
            'invariant_matrix' => $invariantMatrix,
            'health_digest' => $healthDigestResult,
            'reasons' => $reasons,
        ];
    }
}
