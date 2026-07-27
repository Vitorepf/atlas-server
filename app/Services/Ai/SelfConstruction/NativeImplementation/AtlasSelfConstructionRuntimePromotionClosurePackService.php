<?php

namespace App\Services\Ai\SelfConstruction\NativeImplementation;

use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
use Carbon\CarbonImmutable;
use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;

final class AtlasSelfConstructionRuntimePromotionClosurePackService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    use KsortsArraysByReference;


    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */


    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    public const SCHEMA_VERSION = 'atlas.self_construction.runtime_promotion_closure_pack.v1';

    public const MODE = 'read_only_runtime_promotion_closure_pack';

    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $readiness,
    ) {}

    /** @return array<string, mixed> */
    public function build(array $options = []): array
    {
        $matrix = (array) ($options['runtime_gap_matrix'] ?? (new AtlasSelfConstructionRuntimeGapMatrixService($this->readiness))->matrix([
            'runtime_promotion_receipt' => (array) ($options['runtime_promotion_receipt'] ?? []),
            'persist_runtime_promotion_receipt' => false,
        ]));
        $rows = array_values(array_filter((array) data_get($matrix, 'rows', []), 'is_array'));
        $gapRows = array_values(array_filter($rows, static fn (array $row): bool => ! (bool) ($row['runtime_y'] ?? false)));
        $graduationHashes = $this->graduationHashes($gapRows);
        $promotedGapIds = array_values(array_map(static fn (array $row): string => (string) ($row['gap_id'] ?? ''), $gapRows));
        $runtimeEnabledCount = count(array_filter($rows, static fn (array $row): bool => (bool) ($row['runtime_enabled'] ?? false)));
        $runtimeYCandidateCount = count(array_filter($gapRows, static fn (array $row): bool => (bool) ($row['runtime_y_candidate'] ?? false)));
        $allCandidatesReady = $gapRows !== []
            && $runtimeYCandidateCount === count($gapRows)
            && $runtimeEnabledCount === 0;
        $runtimePromotionMatrixHash = (string) data_get(
            $matrix,
            'expected_runtime_gap_matrix_hash_for_promotion_receipt',
            data_get($matrix, 'runtime_gap_matrix_hash', ''),
        );
        $runtimePromotionClosureBasisHash = (string) data_get(
            $matrix,
            'runtime_promotion_closure_basis_hash',
            $this->runtimePromotionClosureBasisHash($gapRows, $runtimePromotionMatrixHash, (string) data_get($matrix, 'runtime_promotion_basis_hash', ''), $runtimeEnabledCount),
        );

        $receiptPreimage = [
            'receipt_id' => '<operator_runtime_promotion_receipt_id>',
            'signed_by' => '<operator>',
            'reason' => 'Operator reviewed the current runtime graduation candidate hashes and approves runtime gap promotion without enabling execution directly.',
            'runtime_gap_matrix_hash' => $runtimePromotionMatrixHash,
            'runtime_promotion_basis_hash' => (string) data_get($matrix, 'runtime_promotion_basis_hash', ''),
            'runtime_promotion_closure_basis_hash' => $runtimePromotionClosureBasisHash,
            'promoted_gap_ids' => $promotedGapIds,
            'graduation_evidence_hashes' => $graduationHashes,
            'receipt_hash' => '<operator_generated_64_hex_receipt_hash>',
            'runtime_promotion_approved' => true,
            'operator_reviewed_runtime_graduations' => true,
            'no_runtime_autopromotion_acknowledged' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
        ];
        $receiptTemplateHash = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receiptPreimage);

        $blockers = [];
        if ($gapRows === []) {
            $blockers[] = 'runtime_gap_matrix_has_no_promotable_gap_rows';
        }
        if ($runtimeYCandidateCount !== count($gapRows)) {
            $blockers[] = 'not_all_runtime_gaps_have_graduation_candidate_evidence';
        }
        if ($runtimeEnabledCount > 0) {
            $blockers[] = 'runtime_enabled_before_operator_receipt';
        }
        foreach ($graduationHashes as $gapId => $hash) {
            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                $blockers[] = 'graduation_evidence_hash_missing_for_'.$gapId;
            }
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $allCandidatesReady && $blockers === [] ? 'ready_for_operator_signature' : 'blocked',
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'read_only' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'runtime_gap_matrix_hash' => (string) data_get($matrix, 'runtime_gap_matrix_hash', ''),
            'expected_runtime_gap_matrix_hash_for_promotion_receipt' => $runtimePromotionMatrixHash,
            'runtime_promotion_basis_hash' => (string) data_get($matrix, 'runtime_promotion_basis_hash', ''),
            'runtime_promotion_closure_basis_hash' => $runtimePromotionClosureBasisHash,
            'runtime_gap_count' => count($gapRows),
            'runtime_y_candidate_count' => $runtimeYCandidateCount,
            'runtime_enabled_count' => $runtimeEnabledCount,
            'promoted_gap_ids' => $promotedGapIds,
            'graduation_evidence_hashes' => $graduationHashes,
            'graduation_evidence' => array_map(static fn (array $row): array => [
                'gap_id' => (string) ($row['gap_id'] ?? ''),
                'runtime_y_candidate' => (bool) ($row['runtime_y_candidate'] ?? false),
                'runtime_y' => (bool) ($row['runtime_y'] ?? false),
                'runtime_enabled' => (bool) ($row['runtime_enabled'] ?? false),
                'graduation_schema' => (string) ($row['graduation_schema'] ?? ''),
                'graduation_status' => (string) ($row['graduation_status'] ?? ''),
                'graduation_evidence_hash' => (string) ($row['graduation_evidence_hash'] ?? ''),
                'required_promotion' => (string) ($row['required_promotion'] ?? ''),
                'blockers' => (array) ($row['blockers'] ?? []),
            ], $gapRows),
            'runtime_promotion_receipt_preimage' => $receiptPreimage,
            'runtime_promotion_receipt_template_hash' => $receiptTemplateHash,
            'receipt_hash_policy' => [
                'canonical_hash_service' => AtlasSelfConstructionCompletionEvidenceHashService::class,
                'canonical_hash_method' => 'runtimePromotionReceiptHash',
                'template_hash_is_not_operator_receipt_hash' => true,
                'operator_must_replace_placeholders' => true,
                'operator_must_recompute_receipt_hash_after_signing' => true,
            ],
            'operator_checklist' => [
                'review_runtime_gap_matrix_hash',
                'review_runtime_promotion_basis_hash',
                'review_every_graduation_evidence_hash',
                'replace_operator_placeholders',
                'compute_canonical_receipt_hash',
                'sign_runtime_promotion_receipt',
                'persist_receipt_through_runtime_promotion_verifier',
                'rerun_completion_audit',
                'refresh_terminal_loop_operational_proof',
                'persist_terminal_loop_operational_proof_binding',
                'rerun_completion_audit_with_terminal_loop_operational_proof',
            ],
            'terminal_loop_operational_proof_required_before_final_audit' => true,
            'terminal_loop_operational_proof_expected_binding_schema' => 'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
            'terminal_loop_operational_proof_command' => 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-operational-proof-status --json',
            'terminal_loop_operational_proof_binding_persist_command' => 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-operational-proof-status --persist-terminal-loop-operational-proof-binding --json',
            'terminal_loop_operational_proof_canonical_binding_path' => $this->terminalLoopOperationalProofCanonicalBindingPath(),
            'completion_audit_with_terminal_loop_operational_proof_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json --json',
            'effective_completion_audit_with_canonical_terminal_loop_operational_proof_command' => $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
            'blockers' => array_values(array_unique($blockers)),
            'blocker_count' => count(array_unique($blockers)),
            'non_execution_guarantees' => [
                'runtime_promotion_closure_pack_does_not_persist_receipts',
                'runtime_promotion_closure_pack_does_not_enable_runtime',
                'runtime_promotion_closure_pack_does_not_start_codex',
                'runtime_promotion_closure_pack_does_not_call_provider',
                'runtime_promotion_closure_pack_does_not_dispatch_work',
                'runtime_promotion_closure_pack_does_not_spend_tokens',
                'runtime_promotion_closure_pack_does_not_sign_for_operator',
            ],
        ];
        $payload['closure_pack_hash'] = $this->stableHash($payload);

        return $payload;
    }

    private function completionAuditWithCanonicalTerminalLoopOperationalProofCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@'.$this->terminalLoopOperationalProofCanonicalBindingPath().' --json';
    }

    private function terminalLoopOperationalProofCanonicalBindingPath(): string
    {
        return 'storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json';
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function graduationHashes(array $rows): array
    {
        $hashes = [];
        foreach ($rows as $row) {
            $gapId = (string) ($row['gap_id'] ?? '');
            if ($gapId !== '') {
                $hashes[$gapId] = (string) ($row['graduation_evidence_hash'] ?? '');
            }
        }

        return $hashes;
    }

    /** @param array<int, array<string, mixed>> $gapRows */
    private function runtimePromotionClosureBasisHash(array $gapRows, string $runtimePromotionMatrixHash, string $runtimePromotionBasisHash, int $runtimeEnabledCount): string
    {
        $graduationHashes = $this->graduationHashes($gapRows);

        return $this->stableHash([
            'runtime_promotion_closure_basis' => [
                'runtime_promotion_basis_hash' => $runtimePromotionBasisHash,
                'expected_runtime_gap_matrix_hash' => $runtimePromotionMatrixHash,
                'promoted_gap_ids' => array_values(array_keys($graduationHashes)),
                'graduation_evidence_hashes' => $graduationHashes,
                'runtime_gap_count' => count($gapRows),
                'runtime_y_candidate_count' => count(array_filter($gapRows, static fn (array $row): bool => (bool) ($row['runtime_y_candidate'] ?? false))),
                'runtime_enabled_count' => $runtimeEnabledCount,
            ],
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['closure_pack_hash'], $payload['runtime_promotion_receipt_preimage']['receipt_id']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
}
