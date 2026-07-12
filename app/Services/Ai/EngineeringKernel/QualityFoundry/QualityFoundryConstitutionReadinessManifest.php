<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\QualityFoundry;

use App\Services\Ai\EngineeringKernel\CanonicalKernelPayload;

/** Readiness projection for Constitution parity/mutation evidence; never a claim issuer. */
final class QualityFoundryConstitutionReadinessManifest
{
    public const SCHEMA = 'atlas.quality_foundry.constitution_readiness_manifest.v1';

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function evaluate(array $input): array
    {
        $refs = array_values(array_unique(array_filter(array_map('strval', (array) ($input['live_evidence_refs'] ?? [])), static fn (string $ref): bool => trim($ref) !== '')));
        $survivors = array_values(array_unique(array_filter(array_map('strval', (array) ($input['mutation_survivors'] ?? [])), static fn (string $mutant): bool => trim($mutant) !== '')));
        $blockers = [];
        if ($refs === []) $blockers[] = 'live_evidence_refs_missing';
        if (($input['parity_proven'] ?? false) !== true) $blockers[] = 'constitution_parity_missing';
        if (($input['mutation_suite_executed'] ?? false) !== true) $blockers[] = 'mutation_suite_missing';
        if ($survivors !== []) $blockers[] = 'mutation_survivors_present';
        if (($input['claim_authority'] ?? 'rivals') !== 'rivals') $blockers[] = 'claim_authority_not_rivals';
        $ready = $blockers === [];
        $payload = [
            'schema' => self::SCHEMA,
            'status' => $ready ? 'implemented_not_cutover_ready' : 'blocked',
            'parity_proven' => ($input['parity_proven'] ?? false) === true,
            'mutation_suite_executed' => ($input['mutation_suite_executed'] ?? false) === true,
            'mutation_survivors' => $survivors,
            'live_evidence_refs' => $refs,
            'blockers' => $blockers,
            'claim_eligible' => false,
            'comparative_claims_allowed' => false,
            'mutates_claims_or_constitution' => false,
        ];
        $payload['readiness_hash'] = CanonicalKernelPayload::hash($payload);

        return $payload;
    }
}
