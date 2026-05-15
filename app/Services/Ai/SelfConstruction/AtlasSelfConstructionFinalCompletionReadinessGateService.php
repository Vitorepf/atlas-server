<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

/**
 * Read-only final readiness gate for Atlas Self-Construction OS.
 *
 * It is the single authority that may emit `status=complete` AND only when
 * the canonical completion audit is `complete` with zero failed criteria
 * and a human-signed receipt is present.
 *
 * It NEVER:
 *   - signs receipts;
 *   - persists state;
 *   - promotes completion (it only reports `completion_claim_allowed`);
 *   - declares the next stage (Atlas Self-Programming OS) ready unless
 *     the current OS is fully complete in real evidence.
 */
final class AtlasSelfConstructionFinalCompletionReadinessGateService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.final_completion_readiness_gate.v1';

    public const MODE = 'read_only_final_completion_readiness_gate';

    public const NEXT_STAGE_NAME = 'Atlas Self-Programming OS';

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

        $criteria = (array) data_get($completionAudit, 'criteria', []);
        $criteriaMatrix = [];
        $blockers = [];
        foreach ($criteria as $criterion) {
            $id = (string) ($criterion['id'] ?? '');
            $passed = (bool) ($criterion['passed'] ?? false);
            $criteriaMatrix[$id] = [
                'passed' => $passed,
                'status' => $passed ? 'green' : 'blocked',
                'requirement' => (string) ($criterion['requirement'] ?? ''),
                'evidence' => (array) ($criterion['evidence'] ?? []),
            ];
            if (! $passed) {
                $blockers[] = $id;
            }
        }

        $auditComplete = (string) data_get($completionAudit, 'status') === 'complete'
            && (int) data_get($completionAudit, 'failed_count', 0) === 0
            && (array) data_get($completionAudit, 'failed_criteria', []) === [];
        $humanReceiptGreen = (bool) data_get($criteriaMatrix, 'human_signed_os_complete_receipt_present.passed', false);
        $runtimeGreen = (bool) data_get($criteriaMatrix, 'runtime_gap_matrix_all_runtime_y.passed', false);
        $smokeGreen = (bool) data_get($criteriaMatrix, 'end_to_end_real_provider_smoke_green.passed', false);

        if ($auditComplete) {
            $status = 'complete';
        } elseif ($blockers === ['human_signed_os_complete_receipt_present']
            && $runtimeGreen
            && $smokeGreen
        ) {
            $status = 'complete_candidate';
        } else {
            $status = 'incomplete';
        }

        $completionAllowed = $status === 'complete';
        $completionClaimAllowed = $completionAllowed && $auditComplete && (bool) data_get($completionAudit, 'completion_allowed', false);
        $nextStageAllowed = $completionClaimAllowed && $humanReceiptGreen;

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'completion_allowed' => $completionAllowed,
            'completion_claim_allowed' => $completionClaimAllowed,
            'next_stage_allowed' => $nextStageAllowed,
            'next_stage_name' => $nextStageAllowed ? self::NEXT_STAGE_NAME : '',
            'next_stage_blocked_by' => $nextStageAllowed ? [] : array_values(array_unique(array_merge($blockers, $auditComplete ? [] : ['completion_audit_not_status_complete']))),
            'blockers' => $blockers,
            'blocker_count' => count($blockers),
            'audit_complete' => $auditComplete,
            'human_receipt_green' => $humanReceiptGreen,
            'runtime_green' => $runtimeGreen,
            'smoke_green' => $smokeGreen,
            'criteria_matrix' => $criteriaMatrix,
            'completion_audit_status' => (string) data_get($completionAudit, 'status'),
            'completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
            'completion_audit_failed_criteria' => (array) data_get($completionAudit, 'failed_criteria', []),
            'command_to_rerun_audit' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'non_execution_guarantees' => [
                'final_completion_readiness_gate_does_not_sign_receipts',
                'final_completion_readiness_gate_does_not_persist_receipts',
                'final_completion_readiness_gate_does_not_persist_evidence',
                'final_completion_readiness_gate_does_not_promote_completion',
                'final_completion_readiness_gate_does_not_call_provider',
                'final_completion_readiness_gate_does_not_spend_tokens',
                'final_completion_readiness_gate_does_not_dispatch_work',
                'final_completion_readiness_gate_does_not_enable_runtime',
                'final_completion_readiness_gate_does_not_promote_next_stage_without_audit_complete',
            ],
            'safety_invariants' => [
                'completion_claim_requires_audit_status_complete' => true,
                'completion_claim_requires_human_signed_receipt' => true,
                'completion_claim_requires_runtime_and_smoke_green' => true,
                'next_stage_requires_completion_claim_allowed' => true,
            ],
        ];
        $payload['gate_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['gate_hash']);

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
