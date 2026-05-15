<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

final class AtlasSelfConstructionCompletionEvidenceSubmissionPreflightService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.completion_evidence_submission_preflight.v1';

    public const MODE = 'read_only_completion_evidence_submission_preflight';

    /** @return array<string, mixed> */
    public function build(array $completionAudit, array $completionEvidence, array $blockerExplainer): array
    {
        $runtimeReceiptReady = (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_promotion_receipt.status') === 'passed'
            && (bool) data_get($completionEvidence, 'runtime_gap_matrix.all_runtime_y', false);
        $realProviderSmokeReady = (string) data_get($completionEvidence, 'real_provider_smoke.status') === 'passed';
        $humanReceiptReady = (string) data_get($completionEvidence, 'human_signed_completion_receipt.status') === 'passed';
        $completionAuditReady = (string) data_get($completionAudit, 'status') === 'complete'
            && (bool) data_get($completionAudit, 'completion_allowed', false);

        $orderedSteps = [
            $this->step(
                id: 'runtime_promotion_receipt',
                status: (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_promotion_receipt.status', 'blocked'),
                ready: $runtimeReceiptReady,
                command: (string) data_get($blockerExplainer, 'command_plan.draft_runtime_promotion_receipt', ''),
                persistCommand: (string) data_get($blockerExplainer, 'command_plan.persist_runtime_promotion_receipt', ''),
                requiredBefore: [],
                evidenceHash: (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_promotion_receipt.receipt_hash', ''),
                blockers: (array) data_get($completionEvidence, 'runtime_gap_matrix.blocked_gap_ids', []),
            ),
            $this->step(
                id: 'real_provider_smoke',
                status: (string) data_get($completionEvidence, 'real_provider_smoke.status', 'blocked'),
                ready: $realProviderSmokeReady,
                command: (string) data_get($blockerExplainer, 'command_plan.draft_real_provider_smoke', ''),
                persistCommand: (string) data_get($blockerExplainer, 'command_plan.persist_real_provider_smoke', ''),
                requiredBefore: ['runtime_promotion_receipt'],
                evidenceHash: (string) data_get($completionEvidence, 'real_provider_smoke.smoke_hash', ''),
                blockers: (array) data_get($completionEvidence, 'real_provider_smoke.violations', []),
            ),
            $this->step(
                id: 'completion_evidence_hash_composition',
                status: $runtimeReceiptReady && $realProviderSmokeReady ? 'ready_for_operator_hash_composition' : 'waiting_for_runtime_receipt_and_real_provider_smoke',
                ready: $runtimeReceiptReady && $realProviderSmokeReady,
                command: (string) data_get($blockerExplainer, 'command_plan.compose_completion_evidence_hashes', ''),
                persistCommand: '',
                requiredBefore: ['runtime_promotion_receipt', 'real_provider_smoke'],
                evidenceHash: '',
                blockers: [],
            ),
            $this->step(
                id: 'human_completion_receipt',
                status: (string) data_get($completionEvidence, 'human_signed_completion_receipt.status', 'blocked'),
                ready: $humanReceiptReady,
                command: (string) data_get($blockerExplainer, 'command_plan.draft_human_completion_receipt', ''),
                persistCommand: (string) data_get($blockerExplainer, 'command_plan.persist_human_completion_receipt', ''),
                requiredBefore: ['runtime_promotion_receipt', 'real_provider_smoke', 'completion_evidence_hash_composition'],
                evidenceHash: (string) data_get($completionEvidence, 'human_signed_completion_receipt.receipt_hash', ''),
                blockers: (array) data_get($completionEvidence, 'human_signed_completion_receipt.violations', []),
            ),
            $this->step(
                id: 'final_completion_audit',
                status: (string) data_get($completionAudit, 'status', 'incomplete'),
                ready: $completionAuditReady,
                command: (string) data_get($blockerExplainer, 'command_plan.completion_audit', ''),
                persistCommand: '',
                requiredBefore: ['runtime_promotion_receipt', 'real_provider_smoke', 'human_completion_receipt'],
                evidenceHash: (string) data_get($completionAudit, 'completion_audit_hash', ''),
                blockers: (array) data_get($completionAudit, 'failed_criteria', []),
            ),
        ];

        $firstBlocked = collect($orderedSteps)->first(
            static fn (array $step): bool => $step['ready'] !== true && $step['id'] !== 'completion_evidence_hash_composition',
        );
        $readySteps = count(array_filter($orderedSteps, static fn (array $step): bool => (bool) $step['ready']));
        $status = $firstBlocked === null ? 'ready_for_final_completion_audit' : 'blocked';

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'completion_allowed' => false,
            'completion_claim_allowed' => false,
            'ordered_steps' => $orderedSteps,
            'step_count' => count($orderedSteps),
            'ready_step_count' => $readySteps,
            'blocked_step_count' => count($orderedSteps) - $readySteps,
            'next_required_submission' => $firstBlocked['id'] ?? 'rerun_completion_audit',
            'next_required_command' => (string) ($firstBlocked['command'] ?? data_get($blockerExplainer, 'command_plan.completion_audit', '')),
            'next_required_persist_command' => (string) ($firstBlocked['persist_command'] ?? ''),
            'completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
            'completion_evidence_status_hash' => (string) data_get($completionEvidence, 'completion_evidence_status_hash', ''),
            'blocker_explainer_hash' => (string) data_get($blockerExplainer, 'explainer_hash', ''),
            'operator_required' => true,
            'real_provider_required' => in_array('end_to_end_real_provider_smoke_green', (array) data_get($completionAudit, 'failed_criteria', []), true),
            'non_execution_guarantees' => [
                'completion_evidence_submission_preflight_does_not_persist_receipts',
                'completion_evidence_submission_preflight_does_not_call_provider',
                'completion_evidence_submission_preflight_does_not_spend_tokens',
                'completion_evidence_submission_preflight_does_not_dispatch_work',
                'completion_evidence_submission_preflight_does_not_enable_runtime',
                'completion_evidence_submission_preflight_does_not_promote_completion',
            ],
        ];
        $payload['submission_preflight_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /**
     * @param  list<string>  $requiredBefore
     * @param  array<int|string, mixed>  $blockers
     * @return array<string, mixed>
     */
    private function step(string $id, string $status, bool $ready, string $command, string $persistCommand, array $requiredBefore, string $evidenceHash, array $blockers): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'ready' => $ready,
            'command' => $command,
            'persist_command' => $persistCommand,
            'required_before' => $requiredBefore,
            'evidence_hash' => $evidenceHash,
            'blocker_count' => count($blockers),
            'blockers' => $blockers,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['submission_preflight_hash']);

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
