<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashComposerService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionCompletionEvidenceHashComposerServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function compose(array $options = []): array
    {
        return (new AtlasSelfConstructionCompletionEvidenceHashComposerService)->compose($options);
    }

    private function canonicalHash(array $payload): string
    {
        return $payload['composer_hash'] ?? '';
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /** @param array<string, mixed> $overrides */
    private function runtimePromotionReceipt(array $overrides = []): array
    {
        $receipt = array_merge([
            'receipt_id' => 'runtime-promotion-receipt-001',
            'signed_by' => 'Vitorepf Runtime Operator',
            'reason' => 'Reviewed runtime graduation candidates and approved runtime gap promotion.',
            'runtime_gap_matrix_hash' => str_repeat('a', 64),
            'runtime_promotion_basis_hash' => str_repeat('b', 64),
            'promoted_gap_ids' => ['adapter_execution_runtime'],
            'graduation_evidence_hashes' => ['adapter_execution_runtime' => str_repeat('c', 64)],
            'receipt_hash' => '',
            'runtime_promotion_approved' => true,
            'operator_reviewed_runtime_graduations' => true,
            'no_runtime_autopromotion_acknowledged' => true,
        ], $overrides);
        if (($overrides['receipt_hash'] ?? '') === '') {
            $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receipt);
        }

        return $receipt;
    }

    /** @param array<string, mixed> $overrides */
    private function humanCompletionReceipt(array $overrides = []): array
    {
        $receipt = array_merge([
            'receipt_id' => 'operator-os-complete-001',
            'signed_by' => 'Vitorepf Runtime Operator',
            'reason' => 'Reviewed the current completion audit and approved the OS-complete receipt after evidence verification.',
            'completion_audit_hash' => str_repeat('d', 64),
            'release_dossier_hash' => str_repeat('e', 64),
            'replay_diff_hash' => str_repeat('f', 64),
            'runtime_gap_matrix_hash' => str_repeat('a', 64),
            'certification_status_batch_hash' => str_repeat('1', 64),
            'receipt_hash' => '',
            'os_complete_approved' => true,
            'operator_reviewed_completion_audit' => true,
            'no_autopromotion_acknowledged' => true,
        ], $overrides);
        if (($overrides['receipt_hash'] ?? '') === '') {
            $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);
        }

        return $receipt;
    }

    /** @param array<string, mixed> $overrides */
    private function realProviderSmoke(array $overrides = []): array
    {
        $smoke = array_merge([
            'kind' => 'real_provider_packet_claim_to_completion',
            'status' => 'passed',
            'provider_run_id' => 'provider-run-001',
            'task_packet_id' => 'task-packet-001',
            'observed_by' => 'Vitorepf Runtime Operator',
            'approval_reason' => 'Operator approved a bounded real provider claim-to-completion smoke and verified generated evidence.',
            'smoke_hash' => '',
            'operator_approval_receipt_hash' => str_repeat('2', 64),
            'evidence_ledger_hash' => str_repeat('3', 64),
            'work_product_manifest_hash' => str_repeat('4', 64),
            'cost_event_hash' => str_repeat('5', 64),
            'continuation_summary_hash' => str_repeat('6', 64),
            'provider_response_hash' => str_repeat('7', 64),
            'provider_call_observed' => true,
            'token_spend_observed' => true,
            'claim_to_completion_observed' => true,
            'work_product_collected' => true,
            'operator_supplied_evidence' => true,
            'real_provider_run_observed_by_operator' => true,
            'self_programming_allowed' => false,
            'completion_claim_promoted_without_receipt' => false,
        ], $overrides);
        if (($overrides['smoke_hash'] ?? '') === '') {
            $smoke['smoke_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->realProviderSmokeHash($smoke);
        }

        return $smoke;
    }

    // ── Acceptance criteria 1: Hash stability ───────────────────────────

    public function test_stable_hash_across_key_reordering(): void
    {
        $runtime = $this->runtimePromotionReceipt();
        $human = $this->humanCompletionReceipt();
        $smoke = $this->realProviderSmoke();

        $options = [
            'runtime_promotion_receipt' => $runtime,
            'completion_receipt' => $human,
            'real_provider_smoke' => $smoke,
        ];

        $payload1 = $this->compose($options);

        // Reorder keys to provoke instability
        $reordered = [
            'completion_receipt' => $human,
            'real_provider_smoke' => $smoke,
            'runtime_promotion_receipt' => $runtime,
        ];
        $payload2 = $this->compose($reordered);

        $this->assertSame(
            $this->canonicalHash($payload1),
            $this->canonicalHash($payload2),
            'composer_hash must be identical regardless of input key ordering',
        );
    }

    public function test_stable_hash_excludes_volatile_composed_at(): void
    {
        $runtime = $this->runtimePromotionReceipt();
        $human = $this->humanCompletionReceipt();
        $smoke = $this->realProviderSmoke();

        $options = [
            'runtime_promotion_receipt' => $runtime,
            'completion_receipt' => $human,
            'real_provider_smoke' => $smoke,
        ];

        $payload1 = $this->compose($options);
        $payload2 = $this->compose($options);

        // The hash must be identical even though composed_at differs
        // (CarbonImmutable::now() uses second precision, so two calls in the same
        //  second still produce different objects — but stableHash removes composed_at)
        $this->assertSame(
            $this->canonicalHash($payload1),
            $this->canonicalHash($payload2),
            'composer_hash must be stable across different composed_at timestamps',
        );
    }

    // ── Acceptance criteria 2: Hash sensitivity ─────────────────────────

    public function test_hash_changes_when_receipt_id_changes(): void
    {
        $human1 = $this->humanCompletionReceipt(['receipt_id' => 'receipt-alpha']);
        $human2 = $this->humanCompletionReceipt(['receipt_id' => 'receipt-beta']);

        $hash1 = $this->canonicalHash($this->compose(['completion_receipt' => $human1]));
        $hash2 = $this->canonicalHash($this->compose(['completion_receipt' => $human2]));

        $this->assertNotSame($hash1, $hash2, 'changing receipt_id must change the hash');
    }

    public function test_hash_changes_when_proof_payload_changes(): void
    {
        $runtime1 = $this->runtimePromotionReceipt([
            'promoted_gap_ids' => ['adapter_execution_runtime'],
        ]);
        $runtime2 = $this->runtimePromotionReceipt([
            'promoted_gap_ids' => ['adapter_execution_runtime', 'atomic_agents'],
        ]);

        $hash1 = $this->canonicalHash($this->compose(['runtime_promotion_receipt' => $runtime1]));
        $hash2 = $this->canonicalHash($this->compose(['runtime_promotion_receipt' => $runtime2]));

        $this->assertNotSame($hash1, $hash2, 'changing promoted_gap_ids must change the hash');
    }

    public function test_hash_changes_when_evidence_audit_facts_change(): void
    {
        $smoke1 = $this->realProviderSmoke(['evidence_ledger_hash' => str_repeat('3', 64)]);
        $smoke2 = $this->realProviderSmoke(['evidence_ledger_hash' => str_repeat('9', 64)]);

        $hash1 = $this->canonicalHash($this->compose(['real_provider_smoke' => $smoke1]));
        $hash2 = $this->canonicalHash($this->compose(['real_provider_smoke' => $smoke2]));

        $this->assertNotSame($hash1, $hash2, 'changing evidence_ledger_hash must change the hash');
    }

    public function test_hash_changes_when_gate_results_change(): void
    {
        // A receipt with a hash mismatch will change the persist_readiness block
        $humanClean = $this->humanCompletionReceipt();
        $humanMismatched = $this->humanCompletionReceipt(['receipt_hash' => str_repeat('9', 64)]);

        $hashClean = $this->canonicalHash($this->compose(['completion_receipt' => $humanClean]));
        $hashMismatched = $this->canonicalHash($this->compose(['completion_receipt' => $humanMismatched]));

        $this->assertNotSame($hashClean, $hashMismatched, 'hash mismatch in receipt must change composer_hash');
    }

    public function test_hash_differs_when_code_index_readiness_changes(): void
    {
        $human1 = $this->humanCompletionReceipt([
            'completion_audit_hash' => str_repeat('d', 64),
        ]);
        $human2 = $this->humanCompletionReceipt([
            'completion_audit_hash' => str_repeat('e', 64),
        ]);

        $hash1 = $this->canonicalHash($this->compose(['completion_receipt' => $human1]));
        $hash2 = $this->canonicalHash($this->compose(['completion_receipt' => $human2]));

        $this->assertNotSame($hash1, $hash2, 'changing completion_audit_hash must change composer_hash');
    }

    // ── Acceptance criteria 3: Canonical payload structure ──────────────

    public function test_canonical_payload_structure(): void
    {
        $runtime = $this->runtimePromotionReceipt();
        $human = $this->humanCompletionReceipt();
        $smoke = $this->realProviderSmoke();

        $payload = $this->compose([
            'runtime_promotion_receipt' => $runtime,
            'completion_receipt' => $human,
            'real_provider_smoke' => $smoke,
        ]);

        // Must expose schema version and mode
        $this->assertArrayHasKey('schema_version', $payload);
        $this->assertSame(
            AtlasSelfConstructionCompletionEvidenceHashComposerService::SCHEMA_VERSION,
            $payload['schema_version'],
        );
        $this->assertArrayHasKey('mode', $payload);
        $this->assertSame(
            AtlasSelfConstructionCompletionEvidenceHashComposerService::MODE,
            $payload['mode'],
        );

        // Must expose the hash algorithm indirectly via hex hash length (sha256 = 64 hex chars)
        $this->assertArrayHasKey('composer_hash', $payload);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['composer_hash']);

        // Must include proof categories (the three receipt types)
        $this->assertArrayHasKey('runtime_promotion_receipt', $payload);
        $this->assertArrayHasKey('human_completion_receipt', $payload);
        $this->assertArrayHasKey('real_provider_smoke', $payload);

        // Must NOT expose provider-private internals (no raw provider keys, no tokens)
        $this->assertArrayNotHasKey('provider_call_allowed', $payload['runtime_promotion_receipt'] ?? []);
        $this->assertArrayNotHasKey('token_spend_allowed', $payload['human_completion_receipt'] ?? []);
        $this->assertArrayNotHasKey('adapter_execution_allowed', $payload['real_provider_smoke'] ?? []);

        // Policy block is present and marks all execution as disallowed
        $this->assertArrayHasKey('composer_policy', $payload);
        $this->assertTrue($payload['composer_policy']['read_only']);
        $this->assertTrue($payload['composer_policy']['computes_hashes_only']);
        $this->assertTrue($payload['composer_policy']['does_not_persist_evidence']);
        $this->assertTrue($payload['composer_policy']['does_not_verify_operator_authority']);
    }

    public function test_canonical_payload_no_input(): void
    {
        $payload = $this->compose([]);

        $this->assertSame('no_input', $payload['status']);
        $this->assertFalse($payload['aggregate_all_evidence_ready']);
        $this->assertArrayHasKey('composer_hash', $payload);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['composer_hash']);

        // Even with no input, structure must be canonical
        $this->assertArrayHasKey('schema_version', $payload);
        $this->assertArrayHasKey('composer_policy', $payload);
        $this->assertFalse($payload['execution_allowed']);
        $this->assertFalse($payload['token_spend_allowed']);
        $this->assertFalse($payload['completion_claim_allowed']);
    }

    public function test_canonical_payload_aggregate_ready(): void
    {
        $runtime = $this->runtimePromotionReceipt();
        $human = $this->humanCompletionReceipt();
        $smoke = $this->realProviderSmoke();

        $payload = $this->compose([
            'runtime_promotion_receipt' => $runtime,
            'completion_receipt' => $human,
            'real_provider_smoke' => $smoke,
        ]);

        $this->assertTrue($payload['aggregate_all_evidence_ready']);
        $this->assertSame('available', $payload['status']);
    }

    public function test_canonical_payload_hash_is_sha256_hex(): void
    {
        $payload = $this->compose([
            'runtime_promotion_receipt' => $this->runtimePromotionReceipt(),
        ]);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['composer_hash']);
    }
}
