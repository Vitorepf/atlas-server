<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionHumanCompletionReceiptVerifierService;
use Tests\TestCase;

final class AtlasSelfConstructionHumanCompletionReceiptVerifierServiceTest extends TestCase
{
    public function test_valid_receipt_with_matching_context_hashes_passes_with_stable_hash(): void
    {
        $receipt = $this->canonicalReceipt();
        $context = $this->contextFor($receipt);

        $service = new AtlasSelfConstructionHumanCompletionReceiptVerifierService;
        $first = $service->verify($receipt, $context);
        $second = $service->verify($receipt, $context);

        $this->assertSame('passed', $first['status']);
        $this->assertTrue($first['completion_claim_allowed']);
        $this->assertSame(0, $first['violation_count']);
        $this->assertArrayHasKey('field_verification_matrix', $first);
        foreach ($first['field_verification_matrix'] as $row) {
            $this->assertTrue($row['ok'], 'field '.$row['field'].' must be ok on a valid receipt');
            $this->assertNull($row['blocking_reason']);
        }
        $this->assertSame($first['verification_hash'], $second['verification_hash'], 'verification_hash must exclude timestamps');
    }

    public function test_placeholder_signer_produces_blocking_reason_tied_to_signed_by_field(): void
    {
        $receipt = $this->canonicalReceipt();
        $receipt['signed_by'] = 'codex-autosigned';
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);

        $result = (new AtlasSelfConstructionHumanCompletionReceiptVerifierService)->verify($receipt, $this->contextFor($receipt));

        $this->assertSame('blocked', $result['status']);
        $row = collect($result['field_verification_matrix'])->firstWhere('field', 'signed_by');
        $this->assertNotNull($row);
        $this->assertFalse($row['ok']);
        $this->assertSame('human_completion_receipt_signer_invalid_or_placeholder', $row['blocking_reason']);
    }

    public function test_placeholder_reason_produces_blocking_reason_tied_to_reason_field(): void
    {
        $receipt = $this->canonicalReceipt();
        $receipt['reason'] = 'TODO';
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);

        $result = (new AtlasSelfConstructionHumanCompletionReceiptVerifierService)->verify($receipt, $this->contextFor($receipt));

        $row = collect($result['field_verification_matrix'])->firstWhere('field', 'reason');
        $this->assertFalse($row['ok']);
        $this->assertSame('human_completion_receipt_reason_placeholder', $row['blocking_reason']);
    }

    public function test_external_agent_completion_claim_reason_produces_blocking_reason(): void
    {
        $receipt = $this->canonicalReceipt();
        $receipt['reason'] = 'Codex disse que terminou e revisei a evidência antes de assinar este recibo de conclusão final.';
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);

        $result = (new AtlasSelfConstructionHumanCompletionReceiptVerifierService)->verify($receipt, $this->contextFor($receipt));

        $row = collect($result['field_verification_matrix'])->firstWhere('field', 'reason');
        $this->assertFalse($row['ok']);
        $this->assertSame('human_completion_receipt_reason_relies_on_external_agent_claim', $row['blocking_reason']);
    }

    public function test_context_hash_mismatch_produces_blocking_reason_tied_to_offending_field(): void
    {
        $receipt = $this->canonicalReceipt();
        $context = $this->contextFor($receipt);
        $context['runtime_gap_matrix_hash'] = str_repeat('b', 64);

        $result = (new AtlasSelfConstructionHumanCompletionReceiptVerifierService)->verify($receipt, $context);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('context_hash_mismatch', array_column($result['violations'], 'code'));
        $row = collect($result['field_verification_matrix'])->firstWhere('field', 'runtime_gap_matrix_hash');
        $this->assertNotNull($row);
        $this->assertFalse($row['ok']);
        $this->assertSame('context_hash_mismatch', $row['blocking_reason']);
    }

    public function test_forbidden_runtime_flag_true_produces_blocking_reason_tied_to_flag_field(): void
    {
        $receipt = $this->canonicalReceipt();
        $receipt['dispatch_allowed'] = true;
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);

        $result = (new AtlasSelfConstructionHumanCompletionReceiptVerifierService)->verify($receipt, $this->contextFor($receipt));

        $this->assertSame('blocked', $result['status']);
        $row = collect($result['field_verification_matrix'])->firstWhere('field', 'dispatch_allowed');
        $this->assertNotNull($row);
        $this->assertFalse($row['ok']);
        $this->assertSame('forbidden_flag_in_human_completion_receipt', $row['blocking_reason']);
    }

    /** @return array<string, mixed> */
    private function canonicalReceipt(): array
    {
        $hash = str_repeat('a', 64);
        $receipt = [
            'receipt_id' => 'verifier-matrix-test-receipt-1',
            'signed_by' => 'Vitore Test Operator',
            'reason' => 'Operator reviewed final audit, runtime promotion receipt, real-provider smoke and release dossier evidence in this test context.',
            'completion_audit_hash' => $hash,
            'release_dossier_hash' => $hash,
            'replay_diff_hash' => $hash,
            'runtime_gap_matrix_hash' => $hash,
            'runtime_promotion_receipt_hash' => $hash,
            'real_provider_smoke_hash' => $hash,
            'certification_status_batch_hash' => $hash,
            'receipt_hash' => '',
            'os_complete_approved' => true,
            'operator_reviewed_completion_audit' => true,
            'no_autopromotion_acknowledged' => true,
        ];
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);

        return $receipt;
    }

    /** @param array<string, mixed> $receipt
     * @return array<string, string> */
    private function contextFor(array $receipt): array
    {
        return [
            'completion_audit_hash' => (string) $receipt['completion_audit_hash'],
            'release_dossier_hash' => (string) $receipt['release_dossier_hash'],
            'replay_diff_hash' => (string) $receipt['replay_diff_hash'],
            'runtime_gap_matrix_hash' => (string) $receipt['runtime_gap_matrix_hash'],
            'runtime_promotion_receipt_hash' => (string) $receipt['runtime_promotion_receipt_hash'],
            'real_provider_smoke_hash' => (string) $receipt['real_provider_smoke_hash'],
            'certification_status_batch_hash' => (string) $receipt['certification_status_batch_hash'],
        ];
    }
}
