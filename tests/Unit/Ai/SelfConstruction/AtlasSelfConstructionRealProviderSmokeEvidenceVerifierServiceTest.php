<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRealProviderSmokeEvidenceVerifierService;
use Tests\TestCase;

final class AtlasSelfConstructionRealProviderSmokeEvidenceVerifierServiceTest extends TestCase
{
    private function verifier(): AtlasSelfConstructionRealProviderSmokeEvidenceVerifierService
    {
        return new AtlasSelfConstructionRealProviderSmokeEvidenceVerifierService;
    }

    /** @param array<string, mixed> $overrides */
    private function validSmoke(array $overrides = []): array
    {
        $payload = array_merge([
            'kind' => 'real_provider_packet_claim_to_completion',
            'status' => 'passed',
            'provider_run_id' => 'provider-run-001',
            'task_packet_id' => 'task-packet-001',
            'observed_by' => 'Vitore Operator',
            'approval_reason' => 'Operator observed the bounded real provider smoke.',
            'smoke_hash' => '',
            'operator_approval_receipt_hash' => str_repeat('1', 64),
            'evidence_ledger_hash' => str_repeat('2', 64),
            'work_product_manifest_hash' => str_repeat('3', 64),
            'cost_event_hash' => str_repeat('4', 64),
            'continuation_summary_hash' => str_repeat('5', 64),
            'provider_response_hash' => str_repeat('6', 64),
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
        if (! array_key_exists('smoke_hash', $overrides)) {
            $payload['smoke_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->realProviderSmokeHash($payload);
        }

        return $payload;
    }

    public function test_clean_payload_has_empty_forgery_classifier(): void
    {
        $result = $this->verifier()->verify($this->validSmoke());

        $this->assertSame(0, array_sum($result['forgery_classifier']['category_counts']));
        $this->assertNull($result['forgery_classifier']['highest_risk_category']);
    }

    public function test_forbidden_atlas_runtime_flag_is_highest_risk_even_with_other_violations(): void
    {
        $payload = $this->validSmoke([
            'provider_called_by_atlas' => true,
            'operator_supplied_evidence' => false,
        ]);
        $payload['smoke_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->realProviderSmokeHash($payload);

        $result = $this->verifier()->verify($payload);
        $classifier = $result['forgery_classifier'];

        $this->assertSame(
            AtlasSelfConstructionRealProviderSmokeEvidenceVerifierService::FORGERY_CATEGORY_FORBIDDEN_ATLAS_RUNTIME_FLAG,
            $classifier['highest_risk_category'],
        );
        $this->assertGreaterThan(0, $classifier['category_counts'][AtlasSelfConstructionRealProviderSmokeEvidenceVerifierService::FORGERY_CATEGORY_FORBIDDEN_ATLAS_RUNTIME_FLAG]);
        $this->assertGreaterThan(0, $classifier['category_counts'][AtlasSelfConstructionRealProviderSmokeEvidenceVerifierService::FORGERY_CATEGORY_MISSING_OPERATOR_ACK]);
    }

    public function test_hash_mismatch_classifies_into_hash_mismatch_category(): void
    {
        $payload = $this->validSmoke(['smoke_hash' => str_repeat('0', 64)]);

        $result = $this->verifier()->verify($payload);
        $classifier = $result['forgery_classifier'];

        $this->assertGreaterThan(0, $classifier['category_counts'][AtlasSelfConstructionRealProviderSmokeEvidenceVerifierService::FORGERY_CATEGORY_HASH_MISMATCH]);
    }

    public function test_missing_operator_ack_classifies_into_missing_operator_ack_category(): void
    {
        $payload = $this->validSmoke(['operator_supplied_evidence' => false]);
        $payload['smoke_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->realProviderSmokeHash($payload);

        $result = $this->verifier()->verify($payload);
        $classifier = $result['forgery_classifier'];

        $this->assertSame(
            AtlasSelfConstructionRealProviderSmokeEvidenceVerifierService::FORGERY_CATEGORY_MISSING_OPERATOR_ACK,
            $classifier['highest_risk_category'],
        );
        $this->assertGreaterThan(0, $classifier['category_counts'][AtlasSelfConstructionRealProviderSmokeEvidenceVerifierService::FORGERY_CATEGORY_MISSING_OPERATOR_ACK]);
    }

    public function test_missing_identity_fields_classify_into_placeholder_category(): void
    {
        $result = $this->verifier()->verify([
            'kind' => 'real_provider_packet_claim_to_completion',
            'status' => 'passed',
        ]);
        $classifier = $result['forgery_classifier'];

        $this->assertGreaterThan(0, $classifier['category_counts'][AtlasSelfConstructionRealProviderSmokeEvidenceVerifierService::FORGERY_CATEGORY_PLACEHOLDER]);
    }

    public function test_category_counts_cover_every_declared_category(): void
    {
        $result = $this->verifier()->verify($this->validSmoke());

        $this->assertArrayHasKey(AtlasSelfConstructionRealProviderSmokeEvidenceVerifierService::FORGERY_CATEGORY_PLACEHOLDER, $result['forgery_classifier']['category_counts']);
        $this->assertArrayHasKey(AtlasSelfConstructionRealProviderSmokeEvidenceVerifierService::FORGERY_CATEGORY_MISSING_OPERATOR_ACK, $result['forgery_classifier']['category_counts']);
        $this->assertArrayHasKey(AtlasSelfConstructionRealProviderSmokeEvidenceVerifierService::FORGERY_CATEGORY_FORBIDDEN_ATLAS_RUNTIME_FLAG, $result['forgery_classifier']['category_counts']);
        $this->assertArrayHasKey(AtlasSelfConstructionRealProviderSmokeEvidenceVerifierService::FORGERY_CATEGORY_HASH_MISMATCH, $result['forgery_classifier']['category_counts']);
        $this->assertArrayHasKey(AtlasSelfConstructionRealProviderSmokeEvidenceVerifierService::FORGERY_CATEGORY_CERTIFICATION_FAILURE, $result['forgery_classifier']['category_counts']);
    }
}
