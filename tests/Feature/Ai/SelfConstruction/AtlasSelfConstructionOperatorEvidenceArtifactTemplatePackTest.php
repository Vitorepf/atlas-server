<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionOperatorEvidenceArtifactTemplatePackService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasSelfConstructionOperatorEvidenceArtifactTemplatePackTest extends TestCase
{
    public function test_template_pack_generates_three_templates_without_persistence(): void
    {
        $payload = (new AtlasSelfConstructionOperatorEvidenceArtifactTemplatePackService(app(AtlasSelfConstructionReadinessService::class)))->build();

        $this->assertSame('atlas.self_construction.operator_evidence_artifact_template_pack.v1', $payload['schema_version']);
        $this->assertSame('read_only_operator_evidence_artifact_template_pack', $payload['mode']);
        $this->assertSame('available', $payload['status']);
        $this->assertSame(3, $payload['template_count']);
        $this->assertArrayHasKey('runtime_promotion_receipt_template', $payload['templates']);
        $this->assertArrayHasKey('real_provider_smoke_preimage_template', $payload['templates']);
        $this->assertArrayHasKey('human_completion_receipt_template', $payload['templates']);
        $this->assertNotEmpty($payload['template_pack_hash']);
        $this->assertFalse($payload['completion_allowed']);
        $this->assertFalse($payload['completion_claim_allowed']);
        $this->assertFalse($payload['execution_allowed']);
        $this->assertFalse($payload['dispatch_allowed']);
        $this->assertFalse($payload['provider_call_allowed']);
        $this->assertFalse($payload['token_spend_allowed']);
        $this->assertFalse($payload['adapter_execution_allowed']);
        $this->assertFalse($payload['self_programming_allowed']);
    }

    public function test_runtime_promotion_template_carries_current_context_hashes_and_required_fields(): void
    {
        $payload = (new AtlasSelfConstructionOperatorEvidenceArtifactTemplatePackService(app(AtlasSelfConstructionReadinessService::class)))->build();
        $runtime = $payload['templates']['runtime_promotion_receipt_template'];

        $this->assertContains('runtime_gap_matrix_hash', $runtime['fields_that_must_be_64_hex']);
        $this->assertContains('runtime_promotion_basis_hash', $runtime['fields_that_must_be_64_hex']);
        $this->assertContains('runtime_promotion_closure_basis_hash', $runtime['fields_that_must_be_64_hex']);
        $this->assertContains('receipt_hash', $runtime['fields_that_must_be_64_hex']);

        $this->assertArrayHasKey('runtime_gap_matrix_hash', $runtime['current_context_hashes']);
        $this->assertArrayHasKey('runtime_promotion_basis_hash', $runtime['current_context_hashes']);
        $this->assertArrayHasKey('runtime_promotion_closure_basis_hash', $runtime['current_context_hashes']);
        $this->assertArrayHasKey('promoted_gap_ids', $runtime['current_context_hashes']);
        $this->assertArrayHasKey('graduation_evidence_hashes', $runtime['current_context_hashes']);
        $this->assertStringContainsString('--atlas-self-construction-completion-evidence-hash-composer-status', $runtime['command_to_compute_hash']);
        $this->assertStringContainsString('--persist-runtime-promotion-receipt', $runtime['command_to_persist']);
    }

    public function test_real_provider_smoke_template_lists_required_hashes_and_observation_flags(): void
    {
        $payload = (new AtlasSelfConstructionOperatorEvidenceArtifactTemplatePackService(app(AtlasSelfConstructionReadinessService::class)))->build();
        $smoke = $payload['templates']['real_provider_smoke_preimage_template'];

        foreach ([
            'smoke_hash',
            'operator_approval_receipt_hash',
            'evidence_ledger_hash',
            'work_product_manifest_hash',
            'cost_event_hash',
            'continuation_summary_hash',
            'provider_response_hash',
        ] as $field) {
            $this->assertContains($field, $smoke['fields_that_must_be_64_hex']);
        }

        foreach ([
            'provider_call_observed',
            'token_spend_observed',
            'claim_to_completion_observed',
            'work_product_collected',
            'operator_supplied_evidence',
            'real_provider_run_observed_by_operator',
        ] as $flag) {
            $this->assertContains($flag, $smoke['boolean_acknowledgements']);
        }

        foreach ([
            'provider_called_by_atlas',
            'token_spent_by_atlas',
            'dispatch_allowed',
            'adapter_execution_allowed',
            'self_programming_allowed',
            'completion_claim_promoted_without_receipt',
        ] as $flag) {
            $this->assertContains($flag, $smoke['forbidden_flags']);
        }
    }

    public function test_human_receipt_template_references_runtime_and_smoke_hashes_as_placeholders(): void
    {
        $payload = (new AtlasSelfConstructionOperatorEvidenceArtifactTemplatePackService(app(AtlasSelfConstructionReadinessService::class)))->build();
        $human = $payload['templates']['human_completion_receipt_template'];

        $this->assertContains('runtime_promotion_receipt_hash', $human['fields_that_must_be_64_hex']);
        $this->assertContains('real_provider_smoke_hash', $human['fields_that_must_be_64_hex']);
        $this->assertContains('completion_audit_hash', $human['fields_that_must_be_64_hex']);
        $this->assertContains('release_dossier_hash', $human['fields_that_must_be_64_hex']);
        $this->assertContains('replay_diff_hash', $human['fields_that_must_be_64_hex']);
        $this->assertContains('runtime_gap_matrix_hash', $human['fields_that_must_be_64_hex']);
        $this->assertContains('certification_status_batch_hash', $human['fields_that_must_be_64_hex']);

        // Without a real provider smoke yet, smoke hash is unset/empty in payload_template,
        // but the placeholder vocabulary must still be consistent with the operator action packet template.
        $this->assertArrayHasKey('runtime_promotion_receipt_hash', $human['current_context_hashes']);
        $this->assertArrayHasKey('real_provider_smoke_hash', $human['current_context_hashes']);
    }

    public function test_readiness_status_and_cli_quartet_exist(): void
    {
        $status = app(AtlasSelfConstructionReadinessService::class)->atlasSelfConstructionOperatorEvidenceArtifactTemplatePackStatus();

        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_construction_operator_evidence_artifact_template_pack_status.v1', $status['schema_version']);
        $this->assertSame('available', $status['status']);
        $this->assertFalse($status['execution_allowed']);

        foreach ([
            'atlas-self-construction-operator-evidence-artifact-template-pack-contract',
            'atlas-self-construction-operator-evidence-artifact-template-pack-preflight',
            'atlas-self-construction-operator-evidence-artifact-template-pack-implementation-packet',
            'atlas-self-construction-operator-evidence-artifact-template-pack-status',
        ] as $option) {
            $exit = Artisan::call('atlas:ai:self-construction', [
                '--'.$option => true,
                '--json' => true,
            ]);
            $this->assertSame(0, $exit);
            $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($decoded);
            $this->assertFalse($decoded['dispatch_allowed']);
        }
    }

    public function test_agent_control_plane_lists_template_pack_capabilities(): void
    {
        $payload = app(AtlasSelfConstructionReadinessService::class)->agentControlPlane();
        $capabilities = (array) data_get($payload, 'control_plane.current_capability', []);

        foreach ([
            'atlas_self_construction_operator_evidence_artifact_template_pack_contract',
            'atlas_self_construction_operator_evidence_artifact_template_pack_preflight',
            'atlas_self_construction_operator_evidence_artifact_template_pack_implementation_packet',
            'atlas_self_construction_operator_evidence_artifact_template_pack_service',
            'atlas_self_construction_operator_evidence_artifact_template_pack_status_projection',
        ] as $capability) {
            $this->assertContains($capability, $capabilities);
        }
    }
}
