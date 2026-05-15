<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRealProviderSmokePreSubmissionVerifierService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionRealProviderSmokePreSubmissionVerifierTest extends TestCase
{
    public function test_empty_input_returns_blocked_status_with_no_persistence_allowed(): void
    {
        $result = $this->verifier()->verify([]);

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse((bool) $result['can_persist']);
        $this->assertFalse((bool) $result['input_present']);
        $this->assertTrue((bool) $result['not_operator_real_until_observed']);
    }

    public function test_detects_each_family_of_missing_evidence(): void
    {
        $result = $this->verifier()->verify([
            'kind' => 'real_provider_packet_claim_to_completion',
            'status' => 'passed',
            'provider_run_id' => '',
            'task_packet_id' => '',
            'observed_by' => '<operator>',
            'approval_reason' => '<missing>',
            'smoke_hash' => 'short',
            'operator_approval_receipt_hash' => '',
            'evidence_ledger_hash' => '',
            'work_product_manifest_hash' => '',
            'cost_event_hash' => '',
            'continuation_summary_hash' => '',
            'provider_response_hash' => '',
            'provider_call_observed' => false,
            'token_spend_observed' => false,
            'claim_to_completion_observed' => false,
            'work_product_collected' => false,
            'operator_supplied_evidence' => false,
            'real_provider_run_observed_by_operator' => false,
        ]);

        $codes = array_column((array) $result['diagnostics'], 'code');
        foreach ([
            'missing_provider_run_id',
            'missing_task_packet_id',
            'missing_observed_by',
            'missing_approval_reason',
            'invalid_smoke_hash',
            'invalid_operator_approval_receipt_hash',
            'invalid_evidence_ledger_hash',
            'invalid_work_product_manifest_hash',
            'invalid_cost_event_hash',
            'invalid_continuation_summary_hash',
            'invalid_provider_response_hash',
            'provider_call_observed_false',
            'token_spend_observed_false',
            'claim_to_completion_observed_false',
            'work_product_collected_false',
            'operator_supplied_evidence_false',
            'real_provider_run_observed_by_operator_false',
        ] as $expectedCode) {
            $this->assertContains($expectedCode, $codes, "missing diagnostic: {$expectedCode}");
        }
        $this->assertSame('blocked', $result['status']);
        $this->assertFalse((bool) $result['can_persist']);
    }

    public function test_forbidden_atlas_runtime_flags_block_submission(): void
    {
        $result = $this->verifier()->verify($this->validTestPayload([
            'provider_called_by_atlas' => true,
            'token_spent_by_atlas' => true,
            'self_programming_allowed' => true,
        ]));

        $codes = array_column((array) $result['diagnostics'], 'code');
        $this->assertSame('blocked', $result['status']);
        $this->assertContains('forbidden_flag_true', $codes);
        $flags = array_column((array) $result['diagnostics'], 'flag');
        $this->assertContains('provider_called_by_atlas', $flags);
        $this->assertContains('token_spent_by_atlas', $flags);
        $this->assertContains('self_programming_allowed', $flags);
    }

    public function test_synthetic_marker_in_provider_run_id_is_blocked(): void
    {
        $result = $this->verifier()->verify($this->validTestPayload([
            'provider_run_id' => 'synthetic-fake-run-001',
        ]));

        $codes = array_column((array) $result['diagnostics'], 'code');
        $this->assertContains('synthetic_marker_present', $codes);
        $this->assertSame('blocked', $result['status']);
    }

    public function test_smoke_hash_mismatch_is_blocked(): void
    {
        $payload = $this->validTestPayload();
        $payload['smoke_hash'] = str_repeat('0', 64);
        $result = $this->verifier()->verify($payload);

        $codes = array_column((array) $result['diagnostics'], 'code');
        $this->assertContains('smoke_hash_mismatch', $codes);
        $this->assertSame('blocked', $result['status']);
    }

    public function test_valid_test_payload_passes_but_is_marked_not_operator_real_until_observed(): void
    {
        $result = $this->verifier()->verify($this->validTestPayload());

        $this->assertSame('passed', $result['status']);
        $this->assertTrue((bool) $result['can_persist']);
        $this->assertTrue((bool) $result['not_operator_real_until_observed']);
        $this->assertFalse((bool) $result['persistence_allowed_here']);
    }

    public function test_verifier_does_not_persist_does_not_call_provider_does_not_spend_token(): void
    {
        Storage::fake('local');

        $result = $this->verifier()->verify($this->validTestPayload());

        $this->assertSame([], Storage::disk('local')->allFiles());
        $guarantees = (array) $result['non_execution_guarantees'];
        $this->assertTrue((bool) $guarantees['does_not_call_provider']);
        $this->assertTrue((bool) $guarantees['does_not_spend_tokens']);
        $this->assertTrue((bool) $guarantees['does_not_dispatch']);
        $this->assertTrue((bool) $guarantees['does_not_persist_smoke']);
        $this->assertTrue((bool) $guarantees['does_not_promote_completion']);
    }

    private function verifier(): AtlasSelfConstructionRealProviderSmokePreSubmissionVerifierService
    {
        return new AtlasSelfConstructionRealProviderSmokePreSubmissionVerifierService;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validTestPayload(array $overrides = []): array
    {
        $hash = str_repeat('a', 64);
        $payload = array_merge([
            'kind' => 'real_provider_packet_claim_to_completion',
            'status' => 'passed',
            'provider_run_id' => 'run-2026-05-15-001',
            'task_packet_id' => 'packet-2026-05-15-001',
            'observed_by' => 'Real Operator',
            'approval_reason' => 'Operator approved and observed one real provider claim-to-completion smoke.',
            'operator_approval_receipt_hash' => $hash,
            'evidence_ledger_hash' => $hash,
            'work_product_manifest_hash' => $hash,
            'cost_event_hash' => $hash,
            'continuation_summary_hash' => $hash,
            'provider_response_hash' => $hash,
            'provider_call_observed' => true,
            'token_spend_observed' => true,
            'claim_to_completion_observed' => true,
            'work_product_collected' => true,
            'operator_supplied_evidence' => true,
            'real_provider_run_observed_by_operator' => true,
            'provider_called_by_atlas' => false,
            'token_spent_by_atlas' => false,
            'dispatch_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'completion_claim_promoted_without_receipt' => false,
        ], $overrides);
        $payload['smoke_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->realProviderSmokeHash($payload);

        return $payload;
    }
}
