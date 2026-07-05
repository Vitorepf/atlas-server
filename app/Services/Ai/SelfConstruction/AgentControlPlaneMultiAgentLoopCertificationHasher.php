<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

/**
 * JSON / SERIALIZATION + CANONICAL HASH helpers extracted from the
 * god-class {@see AgentControlPlaneMultiAgentLoopCertificationService}.
 *
 * Owns every pure JSON-and-hash helper (loadJson, encodeJson,
 * normalizeForHash, stableHash). The runtime service delegates each
 * method to this collaborator through thin byte-identical delegators.
 */
final class AgentControlPlaneMultiAgentLoopCertificationHasher
{
public function loadJson($disk, string $path): ?array
    {
        return MultiAgentLoopCertification\AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::loadJson($disk, $path);
    }


public function encodeJson(array $payload): string
    {
        return MultiAgentLoopCertification\AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::encodeJson($payload);
    }


public function normalizeForHash(array $payload): array
    {
        return MultiAgentLoopCertification\AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::normalizeForHash($payload);
    }


public function stableHash(array $payload): string
    {
        return MultiAgentLoopCertification\AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::stableHash($payload);
    }

    /**
     * Builds a stable identity hash for a piece of multi-agent loop
     * certification evidence from task_id, worker_id, allowed_files, the
     * proof command, and outcome_class — the meaningful fields that prove
     * "this worker did this proof for this task with this result". Field
     * ORDER never affects the hash (allowed_files is sorted before
     * hashing, and the field set itself is recursively key-sorted by
     * normalizeForHash), so two semantically identical evidence records
     * always collide to the same identity hash regardless of how they were
     * assembled.
     *
     * Volatile fields (timestamps, lease ids, run ids) are NEVER included
     * in the identity hash — they change between runs without changing the
     * substantive proof.
     *
     * @param  array<string, mixed>  $evidence  { task_id?: string,
     *   worker_id?: string, allowed_files?: list<string>,
     *   proof_command?: string, outcome_class?: string }
     */
    public function evidenceIdentityHash(array $evidence): string
    {
        $allowedFiles = array_values(array_map('strval', (array) ($evidence['allowed_files'] ?? [])));
        sort($allowedFiles);

        return $this->stableHash($this->normalizeForHash([
            'task_id' => (string) ($evidence['task_id'] ?? ''),
            'worker_id' => (string) ($evidence['worker_id'] ?? ''),
            'allowed_files' => $allowedFiles,
            'proof_command' => (string) ($evidence['proof_command'] ?? ''),
            'outcome_class' => (string) ($evidence['outcome_class'] ?? ''),
        ]));
    }

    /**
     * Builds a SUBSTANTIVE identity hash that ignores worker_id — used to
     * detect duplicated proof payloads across different workers or runs.
     * Two evidence records with the same task, scope, proof command and
     * outcome but different worker_ids produce the same substantive hash,
     * revealing that the same proof was submitted twice under different
     * worker identities.
     *
     * @param  array<string, mixed>  $evidence
     */
    public function substantiveIdentityHash(array $evidence): string
    {
        $allowedFiles = array_values(array_map('strval', (array) ($evidence['allowed_files'] ?? [])));
        sort($allowedFiles);

        return $this->stableHash($this->normalizeForHash([
            'task_id' => (string) ($evidence['task_id'] ?? ''),
            'allowed_files' => $allowedFiles,
            'proof_command' => (string) ($evidence['proof_command'] ?? ''),
            'outcome_class' => (string) ($evidence['outcome_class'] ?? ''),
        ]));
    }

    /**
     * Classifies a piece of evidence against previously seen identity
     * hashes (from this same certification run), flagging duplicate or
     * tampered proof bundles before they are counted twice.
     *
     * duplicate_evidence=true   when the computed identity hash already
     *                           appears in $seenIdentityHashes — the same
     *                           (task, worker, scope, proof, outcome) tuple
     *                           was already counted.
     * tamper_suspected=true     when the evidence carries an
     *                           expected_identity_hash field that does NOT
     *                           match the freshly computed identity hash —
     *                           the recorded claim and the actual content
     *                           disagree.
     *
     * @param  array<string, mixed>  $evidence  same shape as
     *   {@see evidenceIdentityHash()} plus an optional expected_identity_hash.
     * @param  list<string>  $seenIdentityHashes  identity hashes already
     *   accepted in this certification run.
     * @return array{identity_hash: string, duplicate_evidence: bool, tamper_suspected: bool}
     */
    public function classifyEvidence(array $evidence, array $seenIdentityHashes = [], array $seenSubstantiveHashes = []): array
    {
        $identityHash = $this->evidenceIdentityHash($evidence);
        $substantiveHash = $this->substantiveIdentityHash($evidence);

        $expectedIdentityHash = trim((string) ($evidence['expected_identity_hash'] ?? ''));
        $tamperSuspected = $expectedIdentityHash !== '' && $expectedIdentityHash !== $identityHash;

        $duplicateEvidence = in_array($identityHash, $seenIdentityHashes, true);
        $duplicateIdentity = in_array($substantiveHash, $seenSubstantiveHashes, true) && ! $duplicateEvidence;

        return [
            'identity_hash' => $identityHash,
            'substantive_identity_hash' => $substantiveHash,
            'duplicate_evidence' => $duplicateEvidence,
            'duplicate_identity' => $duplicateIdentity,
            'tamper_suspected' => $tamperSuspected,
        ];
    }

}
