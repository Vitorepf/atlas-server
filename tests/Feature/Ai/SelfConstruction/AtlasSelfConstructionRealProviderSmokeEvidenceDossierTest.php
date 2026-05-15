<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRealProviderSmokeEvidenceDossierService;
use Tests\TestCase;

final class AtlasSelfConstructionRealProviderSmokeEvidenceDossierTest extends TestCase
{
    public function test_real_provider_smoke_evidence_dossier_exposes_contract_and_template_without_execution(): void
    {
        $payload = $this->service()->build();

        $this->assertSame('atlas.self_construction.real_provider_smoke_evidence_dossier.v1', $payload['schema_version']);
        $this->assertSame('available', $payload['status']);
        $this->assertSame('real_provider_packet_claim_to_completion', $payload['smoke_contract']['kind']);
        $this->assertContains('provider_run_id', $payload['smoke_contract']['required_fields']);
        $this->assertSame('<provider_run_id_from_operator_approved_real_provider_smoke>', $payload['smoke_template']['provider_run_id']);
        $this->assertContains('operator_supplied_evidence', $payload['smoke_contract']['required_observation_flags']);
        $this->assertTrue($payload['smoke_template']['real_provider_run_observed_by_operator']);
        $this->assertFalse($payload['safety_profile']['provider_call_made']);
        $this->assertFalse($payload['safety_profile']['token_spend_made']);
        $this->assertFalse($payload['safety_profile']['smoke_persisted']);
    }

    public function test_real_provider_smoke_evidence_dossier_reports_missing_fields_without_payload(): void
    {
        $payload = $this->service()->build();

        $this->assertContains('provider_run_id', $payload['smoke_hash_preflight']['missing_fields']);
        $this->assertContains('cost_event_hash', $payload['smoke_hash_preflight']['missing_fields']);
        $this->assertContains('smoke_hash', $payload['smoke_hash_preflight']['invalid_hash_fields']);
        $this->assertContains('operator_supplied_evidence', $payload['smoke_hash_preflight']['missing_operator_acknowledgements']);
        $this->assertFalse($payload['machine_status']['ready_for_persistence']);
        $this->assertFalse($payload['machine_status']['certification_would_pass']);
    }

    public function test_real_provider_smoke_evidence_dossier_detects_forbidden_flags(): void
    {
        $payload = $this->service()->build(['real_provider_smoke' => [
            'self_programming_allowed' => true,
            'completion_claim_promoted_without_receipt' => true,
        ]]);

        $this->assertContains('self_programming_allowed', $payload['smoke_hash_preflight']['forbidden_flags']);
        $this->assertContains('completion_claim_promoted_without_receipt', $payload['smoke_hash_preflight']['forbidden_flags']);
    }

    public function test_real_provider_smoke_evidence_dossier_accepts_valid_payload_as_ready_for_persistence(): void
    {
        $smoke = $this->validSmoke();
        $payload = $this->service()->build(['real_provider_smoke' => $smoke]);

        $this->assertSame([], $payload['smoke_hash_preflight']['missing_fields']);
        $this->assertSame([], $payload['smoke_hash_preflight']['invalid_hash_fields']);
        $this->assertSame([], $payload['smoke_hash_preflight']['missing_operator_acknowledgements']);
        $this->assertTrue($payload['smoke_hash_preflight']['smoke_hash_matches_payload']);
        $this->assertTrue($payload['machine_status']['ready_for_persistence']);
        $this->assertTrue($payload['machine_status']['certification_would_pass']);
        $this->assertSame('passed', $payload['certification_preview']['status']);
    }

    public function test_real_provider_smoke_evidence_dossier_rejects_hash_mismatch(): void
    {
        $smoke = $this->validSmoke();
        $smoke['smoke_hash'] = str_repeat('f', 64);
        $payload = $this->service()->build(['real_provider_smoke' => $smoke]);

        $this->assertFalse($payload['smoke_hash_preflight']['smoke_hash_matches_payload']);
        $this->assertFalse($payload['machine_status']['ready_for_persistence']);
        $this->assertFalse($payload['machine_status']['certification_would_pass']);
    }

    public function test_real_provider_smoke_evidence_dossier_maps_claim_to_completion_evidence(): void
    {
        $payload = $this->service()->build();

        foreach (['task_packet', 'claim_lease', 'execution_workspace', 'provider_run_id', 'cost_event', 'work_product_manifest', 'evidence_ledger_receipt', 'continuation_summary'] as $evidence) {
            $this->assertContains($evidence, $payload['claim_to_completion_evidence_map']);
        }
        $this->assertTrue($payload['operator_runbook_alignment']['covers_required_fields']);
        $this->assertTrue($payload['operator_runbook_alignment']['covers_required_observation_flags']);
    }

    public function test_real_provider_smoke_evidence_dossier_hash_is_deterministic(): void
    {
        $first = $this->service()->build(['real_provider_smoke' => $this->validSmoke()]);
        $second = $this->service()->build(['real_provider_smoke' => $this->validSmoke()]);

        $this->assertSame($first['dossier_hash'], $second['dossier_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first['dossier_hash']);
    }

    public function test_real_provider_smoke_evidence_dossier_is_json_serializable(): void
    {
        $payload = $this->service()->build();

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertJson($encoded);
    }

    private function service(): AtlasSelfConstructionRealProviderSmokeEvidenceDossierService
    {
        return new AtlasSelfConstructionRealProviderSmokeEvidenceDossierService;
    }

    /** @return array<string, mixed> */
    private function validSmoke(): array
    {
        $hash = str_repeat('d', 64);
        $smoke = [
            'kind' => 'real_provider_packet_claim_to_completion',
            'status' => 'passed',
            'provider_run_id' => 'provider-run-1',
            'task_packet_id' => 'task-packet-1',
            'observed_by' => 'operator',
            'approval_reason' => 'Operator approved real provider smoke.',
            'smoke_hash' => $hash,
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
            'self_programming_allowed' => false,
            'completion_claim_promoted_without_receipt' => false,
        ];
        $smoke['smoke_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->realProviderSmokeHash($smoke);

        return $smoke;
    }
}
