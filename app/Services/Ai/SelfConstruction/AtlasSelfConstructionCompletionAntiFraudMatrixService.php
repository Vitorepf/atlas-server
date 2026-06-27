<?php

namespace App\Services\Ai\SelfConstruction;



use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
use Carbon\CarbonImmutable;

final class AtlasSelfConstructionCompletionAntiFraudMatrixService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    use KsortsArraysByReference;


    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    public const SCHEMA_VERSION = 'atlas.self_construction.completion_anti_fraud_matrix.v1';

    public const MODE = 'read_only_completion_anti_fraud_matrix';

    /** @return array<string, mixed> */
    public function evaluate(array $evidence): array
    {
        $checks = [
            'fake_human_signature' => $this->fakeHumanSignature($evidence),
            'fake_provider_smoke' => $this->fakeProviderSmoke($evidence),
            'runtime_autopromotion' => (bool) data_get($evidence, 'runtime_autopromotion', false),
            'completion_autopromotion' => (bool) data_get($evidence, 'completion_autopromoted', false),
            'token_spend_without_evidence' => (bool) data_get($evidence, 'token_spend_observed', false) && ! $this->isHash((string) data_get($evidence, 'cost_event_hash', '')),
            'dispatch_without_signed_policy' => (bool) data_get($evidence, 'dispatch_allowed', false) && ! $this->isHash((string) data_get($evidence, 'signed_dispatch_policy_hash', '')),
            'process_started_by_atlas_when_forbidden' => (bool) data_get($evidence, 'process_started_by_atlas', false),
            'mutated_hashes' => (bool) data_get($evidence, 'hash_mutation_detected', false),
            'missing_before_snapshot' => trim((string) data_get($evidence, 'before_snapshot_id', '')) === '',
            'missing_operator_reason' => trim((string) data_get($evidence, 'operator_reason', data_get($evidence, 'approval_reason', ''))) === '',
            'replay_mismatch' => (bool) data_get($evidence, 'replay_mismatch', false),
        ];
        $failures = [];
        foreach ($checks as $id => $failed) {
            if ($failed) {
                $failures[] = ['code' => $id];
            }
        }

        $status = $failures === [] ? 'passed' : 'blocked';
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'evaluated_at' => CarbonImmutable::now()->toIso8601String(),
            'checks' => $checks,
            'check_count' => count($checks),
            'failure_count' => count($failures),
            'failures' => $failures,
            'completion_claim_allowed' => $status === 'passed' && (bool) data_get($evidence, 'final_completion_allowed', false),
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
        ];
        $payload['anti_fraud_matrix_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @param array<string, mixed> $evidence */
    private function fakeHumanSignature(array $evidence): bool
    {
        $signedBy = strtolower(trim((string) data_get($evidence, 'signed_by', '')));

        return in_array($signedBy, ['', '<operator>', 'codex', 'assistant', 'system', 'codex-autosigned'], true);
    }

    /** @param array<string, mixed> $evidence */
    private function fakeProviderSmoke(array $evidence): bool
    {
        if ((string) data_get($evidence, 'kind', '') !== 'real_provider_packet_claim_to_completion') {
            return true;
        }

        return ! (bool) data_get($evidence, 'provider_call_observed', false)
            || ! (bool) data_get($evidence, 'real_provider_run_observed_by_operator', false)
            || ! $this->isHash((string) data_get($evidence, 'provider_response_hash', ''));
    }

    private function isHash(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['evaluated_at'], $payload['anti_fraud_matrix_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
}
