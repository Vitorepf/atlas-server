<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRealProviderSmokeCertificationService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRealProviderSmokeEndgameVerifierService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRealProviderSmokeEvidenceVerifierService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionRealProviderSmokeEndgameVerifierTest extends TestCase
{
    public function test_empty_input_is_blocked(): void
    {
        $result = $this->verifier()->verify([]);

        $this->assertSame(AtlasSelfConstructionRealProviderSmokeEndgameVerifierService::SCHEMA_VERSION, $result['schema_version']);
        $this->assertSame('blocked', $result['status']);
        $this->assertFalse((bool) $result['input_present']);
        $this->assertFalse((bool) $result['can_persist']);
        $this->assertTrue((bool) $result['not_operator_real_until_observed']);
    }

    public function test_missing_identity_fields_produce_specific_diagnostics(): void
    {
        $result = $this->verifier()->verify([
            'kind' => 'real_provider_packet_claim_to_completion',
            'provider_run_id' => '',
            'task_packet_id' => '<placeholder>',
            'observed_by' => '<operator>',
            'approval_reason' => '',
        ]);

        $codes = array_column((array) $result['diagnostics'], 'code');
        $this->assertContains('missing_provider_run_id', $codes);
        $this->assertContains('missing_task_packet_id', $codes);
        $this->assertContains('missing_observed_by', $codes);
        $this->assertContains('missing_approval_reason', $codes);
        $this->assertSame('blocked', $result['status']);
    }

    public function test_invalid_hashes_each_produce_specific_diagnostic(): void
    {
        $result = $this->verifier()->verify([
            'smoke_hash' => 'short',
            'operator_approval_receipt_hash' => 'nope',
            'evidence_ledger_hash' => '',
            'work_product_manifest_hash' => 'zzzz',
            'cost_event_hash' => '',
            'continuation_summary_hash' => '',
            'provider_response_hash' => '',
        ]);

        $codes = array_column((array) $result['diagnostics'], 'code');
        foreach ([
            'invalid_smoke_hash',
            'invalid_operator_approval_receipt_hash',
            'invalid_evidence_ledger_hash',
            'invalid_work_product_manifest_hash',
            'invalid_cost_event_hash',
            'invalid_continuation_summary_hash',
            'invalid_provider_response_hash',
        ] as $expected) {
            $this->assertContains($expected, $codes, "missing diagnostic: {$expected}");
        }
    }

    public function test_smoke_hash_mismatch_is_blocked_with_expected_hash(): void
    {
        $payload = $this->validTestPayload();
        $payload['smoke_hash'] = str_repeat('0', 64);
        $result = $this->verifier()->verify($payload);

        $mismatch = $this->diagnosticByCode($result, 'smoke_hash_mismatch');
        $this->assertNotNull($mismatch);
        $this->assertSame(str_repeat('0', 64), (string) $mismatch['submitted_smoke_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $mismatch['expected_smoke_hash']);
    }

    public function test_provider_call_observed_false_is_blocked(): void
    {
        $result = $this->verifier()->verify($this->validTestPayload(['provider_call_observed' => false]));

        $codes = array_column((array) $result['diagnostics'], 'code');
        $this->assertContains('provider_call_observed_false', $codes);
        $this->assertSame('blocked', $result['status']);
    }

    public function test_token_spend_observed_false_is_blocked(): void
    {
        $result = $this->verifier()->verify($this->validTestPayload(['token_spend_observed' => false]));

        $codes = array_column((array) $result['diagnostics'], 'code');
        $this->assertContains('token_spend_observed_false', $codes);
        $this->assertSame('blocked', $result['status']);
    }

    public function test_synthetic_marker_in_provider_run_id_is_blocked(): void
    {
        $result = $this->verifier()->verify($this->validTestPayload(['provider_run_id' => 'synthetic-fake-run-001']));

        $codes = array_column((array) $result['diagnostics'], 'code');
        $this->assertContains('synthetic_marker_present', $codes);
        $this->assertSame('blocked', $result['status']);
    }

    public function test_portuguese_placeholder_operator_smoke_inputs_are_blocked(): void
    {
        $payload = $this->validTestPayload([
            'observed_by' => 'SEU_NOME',
            'approval_reason' => 'MOTIVO REAL COM PELO MENOS 32 CARACTERES',
        ]);

        $endgame = $this->verifier()->verify($payload);
        $evidence = (new AtlasSelfConstructionRealProviderSmokeEvidenceVerifierService)->verify($payload);
        $certification = (new AtlasSelfConstructionRealProviderSmokeCertificationService)->certify($payload);

        $this->assertSame('blocked', $endgame['status']);
        $this->assertContains('missing_observed_by', array_column((array) $endgame['diagnostics'], 'code'));
        $this->assertContains('missing_approval_reason', array_column((array) $endgame['diagnostics'], 'code'));
        $this->assertSame('blocked', $evidence['status']);
        $this->assertContains('operator_smoke_placeholder_or_missing', array_column((array) $evidence['violations'], 'code'));
        $this->assertSame('blocked_missing_real_provider_smoke', $certification['status']);
        $this->assertContains('required_real_smoke_field_placeholder', array_column((array) $certification['violations'], 'code'));
        $this->assertFalse((bool) $endgame['can_persist']);
        $this->assertFalse((bool) $evidence['completion_criterion_green']);
        $this->assertFalse((bool) $certification['completion_criterion_green']);
    }

    public function test_forbidden_flags_true_are_blocked(): void
    {
        $result = $this->verifier()->verify($this->validTestPayload([
            'provider_called_by_atlas' => true,
            'token_spent_by_atlas' => true,
            'self_programming_allowed' => true,
        ]));

        $flags = array_column((array) $result['diagnostics'], 'flag');
        $this->assertContains('provider_called_by_atlas', $flags);
        $this->assertContains('token_spent_by_atlas', $flags);
        $this->assertContains('self_programming_allowed', $flags);
        $this->assertSame('blocked', $result['status']);
    }

    public function test_canonical_test_only_payload_passes_but_is_marked_not_operator_real(): void
    {
        $result = $this->verifier()->verify($this->validTestPayload());

        $this->assertSame('passed', $result['status']);
        $this->assertTrue((bool) $result['can_persist']);
        $this->assertTrue((bool) $result['operator_supplied']);
        $this->assertTrue((bool) $result['not_operator_real_until_observed']);
        $this->assertTrue((bool) $result['smoke_hash_matches_payload']);
        $this->assertFalse((bool) $result['persistence_allowed_here']);
    }

    public function test_verifier_does_not_persist_anything(): void
    {
        Storage::fake('local');
        $this->verifier()->verify($this->validTestPayload());

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_verifier_hash_is_64_hex(): void
    {
        $result = $this->verifier()->verify($this->validTestPayload());

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $result['verifier_hash']);
    }

    private function verifier(): AtlasSelfConstructionRealProviderSmokeEndgameVerifierService
    {
        return new AtlasSelfConstructionRealProviderSmokeEndgameVerifierService;
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

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>|null
     */
    private function diagnosticByCode(array $result, string $code): ?array
    {
        foreach ((array) $result['diagnostics'] as $diagnostic) {
            if (($diagnostic['code'] ?? '') === $code) {
                return (array) $diagnostic;
            }
        }

        return null;
    }
}
