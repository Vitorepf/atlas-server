<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ContinuousRuntime;

/**
 * Pure integration. Turns a claimable packet envelope into a BOUNDED native worker execution request +
 * the evidence-write expectation the verification path will check.
 *
 * Refuses packets that lack:
 *   - non-empty allowed_files (no scope ⇒ nothing to write)
 *   - non-empty required_evidence_kinds (no gates ⇒ no way to certify)
 *   - quality_facts.bite_proof OR quality_facts.acceptance_contract (no self-sufficient quality)
 *
 * Output:
 *   {schema_version, request:{task_packet_id, lease_id, allowed_files, required_evidence_kinds,
 *     write_expectation:{evidence_path, gate_outputs_required}}, accepted:bool, blockers:list<string>}
 *
 * Pure: NEVER claims the packet, dispatches the worker, or writes anything.
 */
final class AtlasSelfConstructionContinuousRuntimeWorkerIntegration
{
    public const SCHEMA = 'atlas.continuous_runtime.worker_integration.v1';

    /**
     * @param  array<string,mixed>  $packet  the claimable packet envelope
     * @return array<string,mixed>
     */
    public function integrate(array $packet): array
    {
        $taskId = (string) ($packet['task_packet_id'] ?? '');
        $leaseId = (string) ($packet['lease_id'] ?? '');
        $allowed = array_values((array) ($packet['allowed_files'] ?? []));
        $required = array_values((array) ($packet['required_evidence_kinds'] ?? []));
        $quality = (array) ($packet['quality_facts'] ?? []);

        $blockers = [];
        if ($taskId === '') {
            $blockers[] = 'task_packet_id_missing';
        }
        if ($leaseId === '') {
            $blockers[] = 'lease_id_missing';
        }
        if ($allowed === []) {
            $blockers[] = 'allowed_files_empty';
        }
        if ($required === []) {
            $blockers[] = 'required_evidence_kinds_empty';
        }
        $biteProof = (bool) ($quality['bite_proof'] ?? false);
        $hasAcceptance = is_array($quality['acceptance_contract'] ?? null) && $quality['acceptance_contract'] !== [];
        if (! $biteProof && ! $hasAcceptance) {
            $blockers[] = 'quality_facts_missing_self_sufficient_signal';
        }

        if ($blockers !== []) {
            return [
                'schema_version' => self::SCHEMA,
                'accepted' => false,
                'request' => null,
                'blockers' => array_values($blockers),
            ];
        }

        return [
            'schema_version' => self::SCHEMA,
            'accepted' => true,
            'request' => [
                'task_packet_id' => $taskId,
                'lease_id' => $leaseId,
                'allowed_files' => $allowed,
                'required_evidence_kinds' => $required,
                'write_expectation' => [
                    'evidence_path' => 'storage/atlas/self_construction/evidence/'.$taskId.'.jsonl',
                    'gate_outputs_required' => $required,
                ],
            ],
            'blockers' => [],
        ];
    }
}
