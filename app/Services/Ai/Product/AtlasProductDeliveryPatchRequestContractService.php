<?php

namespace App\Services\Ai\Product;

use App\Services\Ai\Mission\MissionCanonicalHash;

class AtlasProductDeliveryPatchRequestContractService
{
    public const SCHEMA_VERSION = 'atlas.product_delivery.patch_request_contract.v1';

    /**
     * @param  array<string,mixed>  $delivery
     * @param  array<string,mixed>  $proof
     * @param  array<string,mixed>  $repairBridge
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function build(array $delivery, array $proof, array $repairBridge, array $options = []): array
    {
        $target = $this->target($options['target'] ?? null);
        $allowedFiles = $this->stringList(data_get($delivery, 'delivery_plan.scope_guard.allowed_files', []));
        $forbiddenFiles = $this->stringList(data_get($delivery, 'delivery_plan.scope_guard.forbidden_files', []));
        $blockers = $this->blockerIds($proof);
        $requiredRepairs = $this->stringList($proof['required_repairs'] ?? []);
        $requiredEvidence = $this->requiredEvidence($delivery, $proof, $repairBridge);
        $risk = $this->risk($delivery);
        $status = ($proof['status'] ?? null) === 'ready' ? 'not_required' : 'ready_for_patch_proposal';

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'target' => $target,
            'writes' => false,
            'delivery_hash' => (string) ($delivery['delivery_hash'] ?? ''),
            'proof_hash' => (string) ($proof['proof_hash'] ?? ''),
            'repair_bridge_hash' => (string) ($repairBridge['repair_bridge_hash'] ?? ''),
            'route' => (string) ($delivery['route'] ?? 'atlas_dev'),
            'risk' => $risk,
            'failure_summary' => [
                'proof_status' => (string) ($proof['status'] ?? 'unknown'),
                'critical_blockers' => $blockers,
                'required_repairs' => $requiredRepairs,
                'counterexamples' => $this->stringList($proof['counterexamples'] ?? []),
            ],
            'scope_contract' => [
                'allowed_files' => $allowedFiles,
                'forbidden_files' => $forbiddenFiles,
                'scope_hash' => MissionCanonicalHash::sha256([
                    'allowed_files' => $allowedFiles,
                    'forbidden_files' => $forbiddenFiles,
                ]),
            ],
            'acceptance_contract' => [
                'must_work' => $this->stringList(data_get($delivery, 'delivery_plan.acceptance', [])),
                'tests' => $this->stringList(data_get($delivery, 'delivery_plan.tests', [])),
                'required_evidence' => $requiredEvidence,
            ],
            'prompt_projection' => $this->promptProjection($delivery, $target, $blockers, $requiredRepairs, $allowedFiles, $forbiddenFiles, $requiredEvidence),
            'required_patch_manifest_schema' => [
                'schema_version' => 'atlas.product_delivery.patch_manifest.v1',
                'required_fields' => ['allowed_files', 'operations'],
                'operation_fields' => ['path', 'expected_sha256', 'content'],
                'rules' => [
                    'only_paths_in_allowed_files',
                    'no_empty_replacement_content',
                    'include_expected_sha256_when_file_exists',
                    'do_not_include_secrets_or_unrelated_files',
                ],
            ],
            'approval_policy' => [
                'patch_proposal_gate_required' => true,
                'provider_or_subagent_apply_requires_operator_approval' => true,
                'high_risk_apply_requires_operator_approval' => ($risk['risk_band'] ?? null) === 'high',
                'executor' => 'AtlasProductDeliveryMutativeRepairExecutorService',
            ],
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
                'auto_generated_patch' => false,
                'patch_request_only' => true,
            ],
        ];
        $payload['patch_request_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array{risk_band:string,high_risk_lenses:list<string>}
     */
    private function risk(array $delivery): array
    {
        $lenses = $this->stringList(data_get($delivery, 'delivery_plan.required_lenses', []));
        $highRisk = array_values(array_intersect($lenses, ['security_driven', 'add', 'performance_driven']));

        return [
            'risk_band' => $highRisk === [] ? 'standard' : 'high',
            'high_risk_lenses' => $highRisk,
        ];
    }

    /**
     * @return list<string>
     */
    private function blockerIds(array $proof): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $blocker): ?string => is_array($blocker) && is_scalar($blocker['id'] ?? null)
                ? trim((string) $blocker['id'])
                : null,
            is_array($proof['critical_blockers'] ?? null) ? $proof['critical_blockers'] : [],
        )));
    }

    /**
     * @return list<string>
     */
    private function requiredEvidence(array $delivery, array $proof, array $repairBridge): array
    {
        $fromRepair = $this->stringList(data_get($repairBridge, 'repair.required_evidence', []));
        $fromProof = $this->stringList($proof['required_repairs'] ?? []);
        $tests = $this->stringList(data_get($delivery, 'delivery_plan.tests', []));

        return array_values(array_unique(array_filter(array_merge(
            $fromRepair,
            $fromProof,
            $tests,
            ['proof_rerun', 'outcome_memory'],
        ))));
    }

    /**
     * @param  list<string>  $blockers
     * @param  list<string>  $requiredRepairs
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $forbiddenFiles
     * @param  list<string>  $requiredEvidence
     * @return array<string,mixed>
     */
    private function promptProjection(
        array $delivery,
        string $target,
        array $blockers,
        array $requiredRepairs,
        array $allowedFiles,
        array $forbiddenFiles,
        array $requiredEvidence,
    ): array {
        return [
            'schema_version' => 'atlas.product_delivery.patch_prompt_projection.v1',
            'target' => $target,
            'instruction' => 'Return only an atlas.product_delivery.patch_manifest.v1 JSON object. Do not apply files. Do not run commands.',
            'task' => (string) data_get($delivery, 'product_truth.product_intent.summary', 'Repair the blocked product delivery proof.'),
            'blockers_to_fix' => $blockers,
            'required_repairs' => $requiredRepairs,
            'allowed_files' => $allowedFiles,
            'forbidden_files' => $forbiddenFiles,
            'required_evidence_after_patch' => $requiredEvidence,
            'non_goals' => [
                'do_not_expand_scope',
                'do_not_modify_forbidden_files',
                'do_not_claim_tests_passed_without_evidence',
                'do_not_include_raw_secrets',
            ],
        ];
    }

    private function target(mixed $target): string
    {
        $target = is_scalar($target) ? trim((string) $target) : 'provider';

        return in_array($target, ['provider', 'subagent', 'human', 'forge_workcell'], true) ? $target : 'provider';
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): ?string => is_scalar($item) && trim((string) $item) !== '' ? trim((string) $item) : null,
            $value,
        )));
    }
}
