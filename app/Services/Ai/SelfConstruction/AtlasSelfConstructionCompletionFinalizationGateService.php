<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

/**
 * Read-only final gate that decides whether Atlas Self-Construction OS may
 * claim completion AND whether the next stage may be promoted.
 *
 * It NEVER mutates state, NEVER promotes completion, NEVER signs receipts.
 * In the current real state (audit incomplete) it MUST return
 * completion_claim_allowed=false.
 */
final class AtlasSelfConstructionCompletionFinalizationGateService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.completion_finalization_gate.v1';

    public const MODE = 'read_only_completion_finalization_gate';

    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $readiness,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function evaluate(array $options = []): array
    {
        $completionAudit = (array) ($options['completion_audit']
            ?? (new AtlasSelfConstructionOsCompletionAuditService($this->readiness))->audit([
                'completion_receipt' => (array) ($options['completion_receipt'] ?? []),
                'real_provider_smoke' => (array) ($options['real_provider_smoke'] ?? []),
                'forge_self_improvement_smoke' => (array) ($options['forge_self_improvement_smoke'] ?? []),
            ]));
        $completionEvidence = (array) ($options['completion_evidence'] ?? []);

        $checks = [
            'completion_audit_complete' => $this->check(
                $this->completionAuditComplete($completionAudit),
                'completion_audit.status_must_be_complete_and_failed_count_zero',
                [
                    'completion_audit_status' => (string) data_get($completionAudit, 'status'),
                    'failed_count' => (int) data_get($completionAudit, 'failed_count', 0),
                    'failed_criteria' => (array) data_get($completionAudit, 'failed_criteria', []),
                ],
            ),
            'runtime_all_y' => $this->check(
                $this->criterionGreen($completionAudit, 'runtime_gap_matrix_all_runtime_y'),
                'completion_audit.criteria.runtime_gap_matrix_all_runtime_y_must_be_passed',
                [
                    'runtime_gap_matrix_status' => (string) data_get($completionEvidence, 'runtime_gap_matrix.status', ''),
                    'runtime_gap_matrix_all_runtime_y' => (bool) data_get($completionEvidence, 'runtime_gap_matrix.all_runtime_y', false),
                ],
            ),
            'smoke_green' => $this->check(
                $this->criterionGreen($completionAudit, 'end_to_end_real_provider_smoke_green'),
                'completion_audit.criteria.end_to_end_real_provider_smoke_green_must_be_passed',
                [
                    'real_provider_smoke_status' => (string) data_get($completionEvidence, 'real_provider_smoke.status', ''),
                ],
            ),
            'human_receipt_green' => $this->check(
                $this->criterionGreen($completionAudit, 'human_signed_os_complete_receipt_present'),
                'completion_audit.criteria.human_signed_os_complete_receipt_present_must_be_passed',
                [
                    'human_signed_completion_receipt_status' => (string) data_get($completionEvidence, 'human_signed_completion_receipt.status', ''),
                ],
            ),
            'replay_green' => $this->check(
                $this->criterionGreen($completionAudit, 'replay_diff_against_completion_snapshot_green'),
                'completion_audit.criteria.replay_diff_against_completion_snapshot_green_must_be_passed',
                [],
            ),
            'dossier_green' => $this->check(
                $this->criterionGreen($completionAudit, 'release_dossier_green'),
                'completion_audit.criteria.release_dossier_green_must_be_passed',
                [],
            ),
            'batch_green' => $this->check(
                $this->criterionGreen($completionAudit, 'certification_status_batch_green'),
                'completion_audit.criteria.certification_status_batch_green_must_be_passed',
                [],
            ),
            'mutation_guard_green' => $this->check(
                $this->criterionGreen($completionAudit, 'mutation_guard_green'),
                'completion_audit.criteria.mutation_guard_green_must_be_passed',
                [],
            ),
        ];

        $failed = array_values(array_filter(
            array_keys($checks),
            static fn (string $key): bool => (bool) ($checks[$key]['passed'] ?? false) !== true,
        ));

        $completionClaimAllowed = $failed === [];
        $nextStageAllowed = $completionClaimAllowed;
        $nextStageBlockers = array_values(array_map(
            static fn (string $key): string => 'finalization_gate_blocked_by_'.$key,
            $failed,
        ));

        $status = $completionClaimAllowed ? 'passed' : 'blocked';

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'completion_audit_complete' => $checks['completion_audit_complete']['passed'],
            'runtime_all_y' => $checks['runtime_all_y']['passed'],
            'smoke_green' => $checks['smoke_green']['passed'],
            'human_receipt_green' => $checks['human_receipt_green']['passed'],
            'replay_green' => $checks['replay_green']['passed'],
            'dossier_green' => $checks['dossier_green']['passed'],
            'batch_green' => $checks['batch_green']['passed'],
            'mutation_guard_green' => $checks['mutation_guard_green']['passed'],
            'completion_claim_allowed' => $completionClaimAllowed,
            'next_stage_allowed' => $nextStageAllowed,
            'next_stage_blockers' => $nextStageBlockers,
            'next_stage_blocker_count' => count($nextStageBlockers),
            'checks' => $checks,
            'failed_check_ids' => $failed,
            'completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
            'completion_audit_status' => (string) data_get($completionAudit, 'status'),
            'completion_audit_failed_criteria' => (array) data_get($completionAudit, 'failed_criteria', []),
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'non_execution_guarantees' => [
                'completion_finalization_gate_does_not_promote_completion',
                'completion_finalization_gate_does_not_sign_receipts',
                'completion_finalization_gate_does_not_persist_receipts',
                'completion_finalization_gate_does_not_call_provider',
                'completion_finalization_gate_does_not_spend_tokens',
                'completion_finalization_gate_does_not_dispatch_work',
                'completion_finalization_gate_does_not_enable_runtime',
            ],
        ];
        $payload['completion_finalization_gate_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function check(bool $passed, string $evidenceSource, array $evidence): array
    {
        return [
            'passed' => $passed,
            'evidence_source' => $evidenceSource,
            'evidence' => $evidence,
        ];
    }

    /** @param array<string, mixed> $completionAudit */
    private function completionAuditComplete(array $completionAudit): bool
    {
        return (string) data_get($completionAudit, 'status') === 'complete'
            && (int) data_get($completionAudit, 'failed_count', 0) === 0
            && (array) data_get($completionAudit, 'failed_criteria', []) === [];
    }

    /** @param array<string, mixed> $completionAudit */
    private function criterionGreen(array $completionAudit, string $id): bool
    {
        foreach ((array) data_get($completionAudit, 'criteria', []) as $criterion) {
            if ((string) ($criterion['id'] ?? '') === $id) {
                return (bool) ($criterion['passed'] ?? false);
            }
        }

        return false;
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['completion_finalization_gate_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
    private function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->ksortRecursive($entry);
            }
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }
}
