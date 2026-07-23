<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\EngineeringKernel\CanonicalKernelPayload;

/**
 * Admission contract for a domain wave. A wave may change profile data, but it
 * must reuse provider/tool/Kernel contracts and may not introduce an executor.
 */
final class AtlasExternalBrainDomainWaveAdapterContract
{
    public const SCHEMA = 'atlas.external_brain.domain_wave_adapter_contract.v1';

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function validate(array $input): array
    {
        $blockers = [];
        foreach (['domain_id', 'profile_id', 'version', 'rollback_profile_version'] as $field) {
            if (trim((string) ($input[$field] ?? '')) === '') $blockers[] = $field.'_required';
        }
        if (($input['adapter_kind'] ?? '') !== 'profile_delta') $blockers[] = 'profile_delta_adapter_required';
        if (($input['executor_fork'] ?? false) === true) $blockers[] = 'domain_executor_fork_forbidden';
        foreach (['kernel_route_receipt', 'provider_contract_receipt', 'tool_contract_receipt'] as $receipt) {
            if (trim((string) ($input[$receipt] ?? '')) === '') $blockers[] = $receipt.'_required';
        }
        $evidenceRefs = array_values(array_unique(array_filter(array_map('strval', (array) ($input['evidence_refs'] ?? [])), static fn (string $ref): bool => trim($ref) !== '')));
        if ($evidenceRefs === []) $blockers[] = 'wave_evidence_refs_required';

        $payload = [
            'schema' => self::SCHEMA,
            'domain_id' => (string) ($input['domain_id'] ?? ''),
            'profile_id' => (string) ($input['profile_id'] ?? ''),
            'version' => (string) ($input['version'] ?? ''),
            'rollback_profile_version' => (string) ($input['rollback_profile_version'] ?? ''),
            'adapter_kind' => (string) ($input['adapter_kind'] ?? ''),
            'evidence_refs' => $evidenceRefs,
            'blockers' => array_values(array_unique($blockers)),
            'accepted' => $blockers === [],
            'executor_fork_allowed' => false,
            'mutates_claims_or_routes' => false,
        ];
        $payload['contract_hash'] = CanonicalKernelPayload::hash($payload);

        return $payload;
    }
}
