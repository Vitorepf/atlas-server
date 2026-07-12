<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

/**
 * Read-only verifier for the candidate's content-addressed evidence bindings.
 * The caller supplies artifacts read from the canonical ledger/read model; this
 * class never creates or mutates evidence.
 */
final class CausalLearningEvidenceBindingVerifier
{
    /** @return array{admitted:bool,missing:list<string>,mismatched:list<string>,invalid:list<string>} */
    public function verify(CausalLearningCandidate $candidate, array $artifacts): array
    {
        $byId = [];
        foreach ($artifacts as $artifact) {
            if (! is_array($artifact) || ! is_string($artifact['artifact_id'] ?? null)) {
                continue;
            }
            $byId[$artifact['artifact_id']] = $artifact;
        }

        $missing = [];
        $mismatched = [];
        $invalid = [];
        foreach ($candidate->data['binding_refs'] as $binding => $ref) {
            $artifactId = (string) $ref['artifact_id'];
            $artifact = $byId[$artifactId] ?? null;
            if ($artifact === null) {
                $missing[] = $binding;
                continue;
            }
            if (($artifact['integrity_valid'] ?? false) !== true) {
                $invalid[] = $binding;
                continue;
            }
            if (! is_string($artifact['hash'] ?? null) || ! hash_equals($ref['hash'], $artifact['hash'])) {
                $mismatched[] = $binding;
            }
        }

        return [
            'admitted' => $missing === [] && $mismatched === [] && $invalid === [],
            'missing' => $missing,
            'mismatched' => $mismatched,
            'invalid' => $invalid,
        ];
    }
}
