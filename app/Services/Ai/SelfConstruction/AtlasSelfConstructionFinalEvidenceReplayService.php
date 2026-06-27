<?php

namespace App\Services\Ai\SelfConstruction;



use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
use Carbon\CarbonImmutable;

final class AtlasSelfConstructionFinalEvidenceReplayService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    use KsortsArraysByReference;


    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    public const SCHEMA_VERSION = 'atlas.self_construction.final_evidence_replay.v1';

    public const MODE = 'read_only_final_evidence_replay';

    /** @return array<string, mixed> */
    public function replay(array $bundle): array
    {
        $violations = [];
        if ((string) ($bundle['schema_version'] ?? '') !== AtlasSelfConstructionFinalEvidenceBundleService::SCHEMA_VERSION) {
            $violations[] = ['code' => 'final_bundle_schema_invalid'];
        }
        if ((string) data_get($bundle, 'bundle_identity.bundle_hash', '') !== $this->bundleHash($bundle)) {
            $violations[] = ['code' => 'final_bundle_hash_mismatch'];
        }
        foreach ([
            'runtime_gap_matrix',
            'completion_audit_status',
            'completion_operator_action_packet',
            'completion_audit_blocker_explainer',
        ] as $component) {
            if (! (bool) data_get($bundle, "component_registry.{$component}.available", false)) {
                $violations[] = ['code' => 'required_final_bundle_component_missing', 'component' => $component];
            }
        }
        foreach (['no_execution', 'no_provider_call', 'no_token_spend', 'no_dispatch', 'no_adapter_execution', 'no_self_programming'] as $flag) {
            if (! (bool) data_get($bundle, "safety_invariants.{$flag}", false)) {
                $violations[] = ['code' => 'final_bundle_safety_invariant_false', 'flag' => $flag];
            }
        }
        if ((bool) data_get($bundle, 'machine_status.completion_claim_allowed', false) !== (bool) data_get($bundle, 'final_readiness_map.final_completion_allowed', false)) {
            $violations[] = ['code' => 'completion_claim_allowed_misaligned_with_final_readiness'];
        }
        if ((bool) data_get($bundle, 'final_readiness_map.final_completion_allowed', false) && (array) data_get($bundle, 'evidence_dependencies.failed_criteria', []) !== []) {
            $violations[] = ['code' => 'final_completion_allowed_with_failed_criteria'];
        }

        $status = $violations === [] ? 'passed' : 'blocked';
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'replayed_at' => CarbonImmutable::now()->toIso8601String(),
            'bundle_hash' => (string) data_get($bundle, 'bundle_identity.bundle_hash', ''),
            'expected_bundle_hash' => $this->bundleHash($bundle),
            'replay_green' => $status === 'passed',
            'violation_count' => count($violations),
            'violations' => $violations,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
        ];
        $payload['replay_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @param array<string, mixed> $bundle */
    private function bundleHash(array $bundle): string
    {
        unset($bundle['generated_at']);
        unset($bundle['assessed_at'], $bundle['audited_at']);
        unset($bundle['bundle_identity']['bundle_hash'], $bundle['bundle_identity']['bundle_id']);
        unset($bundle['machine_status']['final_bundle_hash']);
        unset($bundle['promotion_receipt_preimage']['receipt_id']);
        unset($bundle['runtime_promotion_receipt_template']['receipt_id']);
        unset($bundle['human_completion_receipt_template']['receipt_id']);

        return $this->stableHash($bundle);
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['replayed_at'], $payload['replay_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
}
