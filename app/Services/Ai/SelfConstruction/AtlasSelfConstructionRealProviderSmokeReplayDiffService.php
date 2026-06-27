<?php

namespace App\Services\Ai\SelfConstruction;


use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use Carbon\CarbonImmutable;

final class AtlasSelfConstructionRealProviderSmokeReplayDiffService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    public const SCHEMA_VERSION = 'atlas.self_construction.real_provider_smoke_replay_diff.v1';

    public const MODE = 'read_only_real_provider_smoke_replay_diff';

    private const PROTECTED_FIELDS = [
        'kind',
        'status',
        'provider_run_id',
        'task_packet_id',
        'smoke_hash',
        'operator_approval_receipt_hash',
        'evidence_ledger_hash',
        'work_product_manifest_hash',
        'cost_event_hash',
        'continuation_summary_hash',
        'provider_response_hash',
        'provider_call_observed',
        'token_spend_observed',
        'claim_to_completion_observed',
        'work_product_collected',
    ];

    /** @return array<string, mixed> */
    public function compare(array $before, array $after): array
    {
        $mutations = [];
        foreach (self::PROTECTED_FIELDS as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $mutations[] = ['code' => 'protected_real_provider_smoke_field_mutated', 'field' => $field];
            }
        }
        foreach (['provider_called_by_atlas', 'token_spent_by_atlas', 'dispatch_allowed', 'adapter_execution_allowed', 'self_programming_allowed'] as $flag) {
            if ((bool) ($after[$flag] ?? false) === true) {
                $mutations[] = ['code' => 'forbidden_runtime_flag_true_after_replay', 'field' => $flag];
            }
        }

        $status = $mutations === [] ? 'passed' : 'blocked';
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'compared_at' => CarbonImmutable::now()->toIso8601String(),
            'mutation_count' => count($mutations),
            'mutations' => $mutations,
            'replay_diff_green' => $status === 'passed',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
        ];
        $payload['replay_diff_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['compared_at'], $payload['replay_diff_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
}
