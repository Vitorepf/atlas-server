<?php

namespace App\Services\Ai\SelfConstruction\NativeImplementation;

use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
use Carbon\CarbonImmutable;
use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;

final class AtlasSelfConstructionHumanCompletionReceiptPreflightService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    use KsortsArraysByReference;


    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
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

        $release = $this->criterion($audit, 'release_dossier_green');
        $replay = $this->criterion($audit, 'replay_diff_against_completion_snapshot_green');
        $runtime = $this->criterion($audit, 'runtime_gap_matrix_all_runtime_y');
        $smoke = $this->criterion($audit, 'end_to_end_real_provider_smoke_green');
        $batch = $this->criterion($audit, 'certification_status_batch_green');
        $humanReceipt = $this->criterion($audit, 'human_signed_os_complete_receipt_present');

        $evidenceReadinessMatrix = [
            $this->readinessRow('release_dossier', $release, 'hash'),
            $this->readinessRow('replay_diff', $replay, 'diff_hash'),
            $this->readinessRow('runtime_gap_matrix', $runtime, 'runtime_gap_matrix_hash'),
            $this->readinessRow('runtime_promotion_receipt', $runtime, 'runtime_promotion_receipt_hash'),
            $this->readinessRow('real_provider_smoke', $smoke, 'smoke_hash'),
            $this->readinessRow('certification_batch', $batch, 'hash'),
            $this->readinessRow('human_receipt', $humanReceipt, 'receipt_hash'),
        ];
        $readyUpstreamEvidence = array_values(array_filter(
            $evidenceReadinessMatrix,
            static fn (array $row): bool => $row['name'] !== 'human_receipt',
        ));
        $allUpstreamGreen = $readyUpstreamEvidence !== []
            && array_reduce(
                $readyUpstreamEvidence,
                static fn (bool $carry, array $row): bool => $carry && $row['readiness_status'] === 'green',
                true,
            );
        $ready = $blockingFailures === []
            && in_array('human_signed_os_complete_receipt_present', $failed, true)
            && (string) data_get($audit, 'status') === 'incomplete'
            && $allUpstreamGreen;

        $receiptPreimage = [
            'receipt_id' => '<operator_os_completion_receipt_id>',
            'signed_by' => '<operator>',
            'reason' => 'Operator reviewed the current completion audit, runtime matrix, release dossier, replay diff, and real provider smoke evidence.',
            'completion_audit_hash' => (string) data_get($audit, 'completion_audit_hash', ''),
            'release_dossier_hash' => (string) data_get($release, 'evidence.hash', ''),
            'replay_diff_hash' => (string) data_get($replay, 'evidence.diff_hash', ''),
            'runtime_gap_matrix_hash' => (string) data_get($runtime, 'evidence.runtime_gap_matrix_hash', ''),
            'runtime_promotion_receipt_hash' => (string) data_get($runtime, 'evidence.runtime_promotion_receipt_hash', data_get($audit, 'operator_action_packet.human_completion_receipt_template.runtime_promotion_receipt_hash', '')),
            'real_provider_smoke_hash' => (string) data_get($smoke, 'evidence.smoke_hash', data_get($audit, 'operator_action_packet.human_completion_receipt_template.real_provider_smoke_hash', '')),
            'certification_status_batch_hash' => (string) data_get($batch, 'evidence.hash', data_get($audit, 'operator_action_packet.human_completion_receipt_template.certification_status_batch_hash', '')),
            'receipt_hash' => '<operator_generated_64_hex_receipt_hash>',
            'os_complete_approved' => true,
            'operator_reviewed_completion_audit' => true,
            'no_autopromotion_acknowledged' => true,
        ];

        $hasPlaceholderPreimageFields = array_reduce(
            $receiptPreimage,
            static fn (bool $carry, mixed $value): bool => $carry || (is_string($value) && str_starts_with($value, '<')),
            false,
        );
        $nextOperatorActions = array_values(array_filter([
            $hasPlaceholderPreimageFields ? [
                'action' => 'replace_placeholders',
                'reason' => 'receipt_preimage_still_contains_placeholder_fields',
            ] : null,
            [
                'action' => 'compute_canonical_receipt_hash',
                'reason' => 'receipt_hash_must_be_recomputed_after_placeholders_are_replaced',
            ],
            $blockingFailures !== [] ? [
                'action' => 'rerun_blocked_upstream_evidence',
                'reason' => 'blocking_criteria_failed: '.implode(', ', $blockingFailures),
            ] : null,
            $ready ? [
                'action' => 'persist_through_verifier',
                'reason' => 'all_upstream_evidence_is_green_and_only_the_human_receipt_is_outstanding',
            ] : null,
        ]));

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $ready ? 'ready_for_human_signature' : 'blocked',
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'completion_audit_hash' => (string) data_get($audit, 'completion_audit_hash', ''),
            'failed_criteria' => $failed,
            'blocking_failures_before_human_signature' => $blockingFailures,
            'evidence_readiness_matrix' => $evidenceReadinessMatrix,
            'next_operator_actions' => $nextOperatorActions,
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

    /**
     * Builds one evidence_readiness_matrix row: green only when the
     * criterion passed AND its evidence hash is present, so a passing
     * status with a missing hash is still surfaced as not-ready.
     *
     * @param  array<string, mixed>  $criterion
     */
    private function readinessRow(string $name, array $criterion, string $hashField, ?bool $passedOverride = null): array
    {
        $passed = $passedOverride ?? (bool) ($criterion['passed'] ?? false);
        $hash = (string) data_get($criterion, 'evidence.'.$hashField, '');
        $readinessStatus = match (true) {
            $passed && $hash !== '' => 'green',
            $passed && $hash === '' => 'missing_evidence_hash',
            default => 'blocked',
        };

        return [
            'name' => $name,
            'criterion_id' => (string) ($criterion['id'] ?? ''),
            'passed' => $passed,
            'hash' => $hash,
            'readiness_status' => $readinessStatus,
        ];
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
}
