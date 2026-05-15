<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

final class AtlasSelfConstructionHumanCompletionReceiptPreflightService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.human_completion_receipt_preflight.v1';

    public const MODE = 'read_only_human_completion_receipt_preflight';

    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $readiness,
    ) {}

    /** @return array<string, mixed> */
    public function build(array $options = []): array
    {
        $audit = (array) ($options['completion_audit'] ?? (new AtlasSelfConstructionOsCompletionAuditService($this->readiness))->audit());
        $failed = (array) data_get($audit, 'failed_criteria', []);
        $blockingFailures = array_values(array_diff($failed, ['human_signed_os_complete_receipt_present']));
        $ready = $blockingFailures === []
            && in_array('human_signed_os_complete_receipt_present', $failed, true)
            && (string) data_get($audit, 'status') === 'incomplete';

        $release = $this->criterion($audit, 'release_dossier_green');
        $replay = $this->criterion($audit, 'replay_diff_against_completion_snapshot_green');
        $runtime = $this->criterion($audit, 'runtime_gap_matrix_all_runtime_y');
        $batch = $this->criterion($audit, 'certification_status_batch_green');

        $receiptPreimage = [
            'receipt_id' => '<operator_os_completion_receipt_id>',
            'signed_by' => '<operator>',
            'reason' => 'Operator reviewed the current completion audit, runtime matrix, release dossier, replay diff, and real provider smoke evidence.',
            'completion_audit_hash' => (string) data_get($audit, 'completion_audit_hash', ''),
            'release_dossier_hash' => (string) data_get($release, 'evidence.hash', ''),
            'replay_diff_hash' => (string) data_get($replay, 'evidence.diff_hash', ''),
            'runtime_gap_matrix_hash' => (string) data_get($runtime, 'evidence.runtime_gap_matrix_hash', ''),
            'certification_status_batch_hash' => (string) data_get($batch, 'evidence.hash', data_get($audit, 'operator_action_packet.human_completion_receipt_template.certification_status_batch_hash', '')),
            'receipt_hash' => '<operator_generated_64_hex_receipt_hash>',
            'os_complete_approved' => true,
            'operator_reviewed_completion_audit' => true,
            'no_autopromotion_acknowledged' => true,
        ];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $ready ? 'ready_for_human_signature' : 'blocked',
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'completion_audit_hash' => (string) data_get($audit, 'completion_audit_hash', ''),
            'failed_criteria' => $failed,
            'blocking_failures_before_human_signature' => $blockingFailures,
            'human_signature_required' => true,
            'receipt_preimage' => $receiptPreimage,
            'receipt_template_hash' => (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receiptPreimage),
            'operator_checklist' => [
                'verify_runtime_gap_matrix_all_runtime_y',
                'verify_release_dossier_green',
                'verify_replay_diff_green',
                'verify_real_provider_smoke_green',
                'replace_placeholders',
                'compute_canonical_receipt_hash',
                'sign_receipt',
                'persist_receipt_through_verifier',
            ],
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'completion_claim_allowed' => false,
            'non_execution_guarantees' => [
                'human_completion_receipt_preflight_does_not_sign_for_operator',
                'human_completion_receipt_preflight_does_not_persist_receipts',
                'human_completion_receipt_preflight_does_not_promote_completion',
            ],
        ];
        $payload['preflight_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @param array<string, mixed> $audit */
    private function criterion(array $audit, string $id): array
    {
        foreach ((array) data_get($audit, 'criteria', []) as $criterion) {
            if ((string) ($criterion['id'] ?? '') === $id) {
                return (array) $criterion;
            }
        }

        return [];
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['preflight_hash']);

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
