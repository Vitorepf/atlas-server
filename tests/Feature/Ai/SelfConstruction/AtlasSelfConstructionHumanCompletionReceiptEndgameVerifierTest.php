<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionHumanCompletionReceiptEndgameVerifierService;
use Tests\TestCase;

final class AtlasSelfConstructionHumanCompletionReceiptEndgameVerifierTest extends TestCase
{
    public function test_verifier_detects_missing_fields(): void
    {
        $result = (new AtlasSelfConstructionHumanCompletionReceiptEndgameVerifierService)
            ->verify([], $this->context());

        $codes = (array) data_get($result, 'diagnostic_codes', []);
        $this->assertSame('atlas.self_construction.human_completion_receipt_endgame_verifier.v1', $result['schema_version']);
        $this->assertSame('blocked', $result['status']);
        $this->assertContains('missing_receipt_id', $codes);
        $this->assertContains('missing_signed_by', $codes);
        $this->assertContains('missing_reason', $codes);
        $this->assertContains('missing_completion_audit_hash', $codes);
        $this->assertContains('missing_runtime_promotion_receipt_hash', $codes);
        $this->assertFalse((bool) $result['can_persist']);
    }

    public function test_verifier_detects_placeholder_signer(): void
    {
        $receipt = $this->canonicalReceipt();
        $receipt['signed_by'] = 'codex-autosigned';
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);

        $result = (new AtlasSelfConstructionHumanCompletionReceiptEndgameVerifierService)
            ->verify($receipt, $this->context());

        $this->assertContains('placeholder_or_fake_signer', (array) data_get($result, 'diagnostic_codes', []));
    }

    public function test_verifier_detects_stale_completion_audit_hash(): void
    {
        $receipt = $this->canonicalReceipt();
        $receipt['completion_audit_hash'] = str_repeat('b', 64);
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);

        $result = (new AtlasSelfConstructionHumanCompletionReceiptEndgameVerifierService)
            ->verify($receipt, $this->context());

        $this->assertContains('stale_completion_audit_hash', (array) data_get($result, 'diagnostic_codes', []));
    }

    public function test_verifier_detects_stale_runtime_and_smoke_hashes(): void
    {
        $receipt = $this->canonicalReceipt();
        $receipt['runtime_gap_matrix_hash'] = str_repeat('c', 64);
        $receipt['runtime_promotion_receipt_hash'] = str_repeat('c', 64);
        $receipt['real_provider_smoke_hash'] = str_repeat('c', 64);
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);

        $result = (new AtlasSelfConstructionHumanCompletionReceiptEndgameVerifierService)
            ->verify($receipt, $this->context());

        $codes = (array) data_get($result, 'diagnostic_codes', []);
        $this->assertContains('stale_runtime_gap_matrix_hash', $codes);
        $this->assertContains('stale_runtime_promotion_receipt_hash', $codes);
        $this->assertContains('stale_real_provider_smoke_hash', $codes);
    }

    public function test_verifier_detects_forbidden_flags(): void
    {
        $receipt = $this->canonicalReceipt();
        $receipt['execution_allowed'] = true;
        $receipt['adapter_execution_allowed'] = true;
        $receipt['completion_autopromoted'] = true;
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);

        $result = (new AtlasSelfConstructionHumanCompletionReceiptEndgameVerifierService)
            ->verify($receipt, $this->context());

        $this->assertContains('forbidden_flag_true', (array) data_get($result, 'diagnostic_codes', []));
    }

    public function test_verifier_detects_short_reason(): void
    {
        $receipt = $this->canonicalReceipt();
        $receipt['reason'] = 'too short';
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);

        $result = (new AtlasSelfConstructionHumanCompletionReceiptEndgameVerifierService)
            ->verify($receipt, $this->context());

        $this->assertContains('reason_too_short', (array) data_get($result, 'diagnostic_codes', []));
    }

    public function test_verifier_detects_acknowledgement_flags_off(): void
    {
        $receipt = $this->canonicalReceipt();
        $receipt['os_complete_approved'] = false;
        $receipt['operator_reviewed_completion_audit'] = false;
        $receipt['no_autopromotion_acknowledged'] = false;
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);

        $result = (new AtlasSelfConstructionHumanCompletionReceiptEndgameVerifierService)
            ->verify($receipt, $this->context());

        $codes = (array) data_get($result, 'diagnostic_codes', []);
        $this->assertContains('os_complete_approved_false', $codes);
        $this->assertContains('operator_reviewed_completion_audit_false', $codes);
        $this->assertContains('no_autopromotion_acknowledged_false', $codes);
    }

    public function test_verifier_blocks_with_failed_prerequisites(): void
    {
        $receipt = $this->canonicalReceipt();
        $context = $this->context() + [
            'prerequisites' => [
                'runtime_gap_matrix_all_runtime_y' => ['green' => false],
                'real_provider_smoke_green' => ['green' => false],
            ],
        ];

        $result = (new AtlasSelfConstructionHumanCompletionReceiptEndgameVerifierService)
            ->verify($receipt, $context);

        $this->assertContains('prerequisites_not_green', (array) data_get($result, 'diagnostic_codes', []));
        $this->assertSame(
            ['runtime_gap_matrix_all_runtime_y', 'real_provider_smoke_green'],
            (array) data_get($result, 'failed_prerequisites'),
        );
        $this->assertFalse((bool) $result['can_persist']);
        $this->assertSame('human_completion_receipt_prerequisites_not_green', $result['persistence_blocker']);
    }

    public function test_verifier_detects_receipt_hash_invalid_and_mismatch(): void
    {
        $receipt = $this->canonicalReceipt();
        $receipt['receipt_hash'] = 'NOT-HEX';
        $invalid = (new AtlasSelfConstructionHumanCompletionReceiptEndgameVerifierService)
            ->verify($receipt, $this->context());
        $this->assertContains('receipt_hash_invalid', (array) data_get($invalid, 'diagnostic_codes', []));

        $receipt['receipt_hash'] = str_repeat('f', 64);
        $mismatch = (new AtlasSelfConstructionHumanCompletionReceiptEndgameVerifierService)
            ->verify($receipt, $this->context());
        $this->assertContains('receipt_hash_mismatch', (array) data_get($mismatch, 'diagnostic_codes', []));
    }

    public function test_verifier_passes_with_canonical_test_payload_and_green_prereqs(): void
    {
        $receipt = $this->canonicalReceipt();
        $context = $this->context() + [
            'prerequisites' => [
                'runtime_gap_matrix_all_runtime_y' => ['green' => true],
                'runtime_promotion_receipt_present' => ['green' => true],
                'real_provider_smoke_green' => ['green' => true],
                'release_dossier_green' => ['green' => true],
                'replay_diff_green' => ['green' => true],
                'certification_status_batch_green' => ['green' => true],
            ],
        ];

        $result = (new AtlasSelfConstructionHumanCompletionReceiptEndgameVerifierService)
            ->verify($receipt, $context);

        $this->assertSame('passed', $result['status']);
        $this->assertSame([], (array) data_get($result, 'diagnostics'));
        $this->assertTrue((bool) $result['can_persist']);
        $this->assertFalse((bool) $result['completion_claim_allowed']);
        $this->assertFalse((bool) $result['execution_allowed']);
    }

    public function test_verifier_never_persists_or_promotes_completion(): void
    {
        $result = (new AtlasSelfConstructionHumanCompletionReceiptEndgameVerifierService)
            ->verify($this->canonicalReceipt(), $this->context());

        $this->assertFalse((bool) $result['completion_claim_allowed']);
        $this->assertFalse((bool) $result['execution_allowed']);
        $this->assertFalse((bool) $result['provider_call_allowed']);
        $this->assertFalse((bool) $result['token_spend_allowed']);
        $this->assertContains('human_completion_receipt_endgame_verifier_does_not_persist_receipts', (array) $result['non_execution_guarantees']);
    }

    /** @return array<string, mixed> */
    private function canonicalReceipt(): array
    {
        $hash = str_repeat('a', 64);
        $receipt = [
            'receipt_id' => 'endgame-test-receipt-1',
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

    /** @return array<string, string> */
    private function context(): array
    {
        $hash = str_repeat('a', 64);

        return [
            'completion_audit_hash' => $hash,
            'release_dossier_hash' => $hash,
            'replay_diff_hash' => $hash,
            'runtime_gap_matrix_hash' => $hash,
            'runtime_promotion_receipt_hash' => $hash,
            'real_provider_smoke_hash' => $hash,
            'certification_status_batch_hash' => $hash,
        ];
    }
}
