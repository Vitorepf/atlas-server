<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

final class AtlasSelfConstructionHumanCompletionReceiptMutationGuardService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.human_completion_receipt_mutation_guard.v1';

    public const MODE = 'read_only_human_completion_receipt_mutation_guard';

    /** @return array<string, mixed> */
    public function compare(array $before, array $after): array
    {
        $protectedFields = [
            'receipt_id',
            'signed_by',
            'reason',
            'completion_audit_hash',
            'release_dossier_hash',
            'replay_diff_hash',
            'runtime_gap_matrix_hash',
            'certification_status_batch_hash',
            'receipt_hash',
            'os_complete_approved',
            'operator_reviewed_completion_audit',
            'no_autopromotion_acknowledged',
        ];
        $mutations = [];
        foreach ($protectedFields as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $mutations[] = ['field' => $field, 'code' => 'protected_human_receipt_field_mutated'];
            }
        }

        $status = $mutations === [] ? 'passed' : 'blocked';
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'checked_at' => CarbonImmutable::now()->toIso8601String(),
            'mutation_count' => count($mutations),
            'mutations' => $mutations,
            'guard_passed' => $status === 'passed',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
        ];
        $payload['mutation_guard_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['checked_at'], $payload['mutation_guard_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
    private function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->ksortRecursive($value[$key]);
            }
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }
}
