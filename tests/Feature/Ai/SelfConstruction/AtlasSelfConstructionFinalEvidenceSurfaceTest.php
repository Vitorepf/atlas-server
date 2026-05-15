<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasSelfConstructionFinalEvidenceSurfaceTest extends TestCase
{
    public function test_final_evidence_surfaces_expose_contracts_through_cli(): void
    {
        foreach ([
            '--atlas-self-construction-final-evidence-bundle-contract' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_final_evidence_bundle_contract.v1',
            '--atlas-self-construction-completion-audit-blocker-explainer-contract' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_completion_audit_blocker_explainer_contract.v1',
            '--atlas-self-construction-runtime-promotion-evidence-dossier-contract' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_runtime_promotion_evidence_dossier_contract.v1',
            '--atlas-self-construction-human-completion-receipt-dossier-contract' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_human_completion_receipt_dossier_contract.v1',
            '--atlas-self-construction-real-provider-smoke-evidence-dossier-contract' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_real_provider_smoke_evidence_dossier_contract.v1',
        ] as $flag => $schema) {
            $exit = Artisan::call('atlas:ai:self-construction', [$flag => true, '--json' => true]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit);
            $this->assertSame($schema, $payload['schema_version']);
            $this->assertFalse($payload['execution_allowed']);
            $this->assertFalse($payload['dispatch_allowed']);
            $this->assertFalse($payload['ledger_write_allowed']);
            $this->assertFalse($payload['runtime_write_allowed']);
        }
    }

    public function test_final_evidence_surfaces_expose_preflights_through_cli(): void
    {
        foreach ([
            '--atlas-self-construction-final-evidence-bundle-preflight',
            '--atlas-self-construction-completion-audit-blocker-explainer-preflight',
            '--atlas-self-construction-runtime-promotion-evidence-dossier-preflight',
            '--atlas-self-construction-human-completion-receipt-dossier-preflight',
            '--atlas-self-construction-real-provider-smoke-evidence-dossier-preflight',
        ] as $flag) {
            $exit = Artisan::call('atlas:ai:self-construction', [$flag => true, '--json' => true]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit);
            $this->assertStringEndsWith('_preflight.v1', $payload['schema_version']);
            $this->assertSame(0, data_get($payload, array_key_first(array_filter($payload, static fn ($value, $key): bool => str_ends_with((string) $key, '_preflight'), ARRAY_FILTER_USE_BOTH)).'.blocking_count', 0));
            $this->assertFalse($payload['execution_allowed']);
            $this->assertFalse($payload['dispatch_allowed']);
        }
    }

    public function test_final_evidence_bundle_status_accepts_injected_snapshots_directly(): void
    {
        $payload = app(AtlasSelfConstructionReadinessService::class)->atlasSelfConstructionFinalEvidenceBundleStatus($this->snapshotOptions());

        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_construction_final_evidence_bundle_status.v1', $payload['schema_version']);
        $this->assertSame('available', $payload['status']);
        $this->assertSame(0, data_get($payload, 'agent_control_plane_atlas_self_construction_final_evidence_bundle_status.missing_component_count'));
        $this->assertSame(3, data_get($payload, 'agent_control_plane_atlas_self_construction_final_evidence_bundle_status.blocker_count'));
        $this->assertFalse(data_get($payload, 'agent_control_plane_atlas_self_construction_final_evidence_bundle_status.completion_claim_allowed'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'agent_control_plane_atlas_self_construction_final_evidence_bundle_status.bundle_hash'));
    }

    public function test_runtime_promotion_and_real_provider_dossier_statuses_are_read_only(): void
    {
        $readiness = app(AtlasSelfConstructionReadinessService::class);
        $runtime = $readiness->atlasSelfConstructionRuntimePromotionEvidenceDossierStatus($this->snapshotOptions());
        $provider = $readiness->atlasSelfConstructionRealProviderSmokeEvidenceDossierStatus();

        $this->assertSame('available', $runtime['status']);
        $this->assertTrue(data_get($runtime, 'agent_control_plane_atlas_self_construction_runtime_promotion_evidence_dossier_status.promotion_receipt_required'));
        $this->assertFalse($runtime['execution_allowed']);
        $this->assertSame('available', $provider['status']);
        $this->assertFalse(data_get($provider, 'agent_control_plane_atlas_self_construction_real_provider_smoke_evidence_dossier_status.ready_for_persistence'));
        $this->assertFalse($provider['dispatch_allowed']);
    }

    public function test_agent_control_plane_lists_final_evidence_capabilities(): void
    {
        $payload = app(AtlasSelfConstructionReadinessService::class)->agentControlPlane();
        $capabilities = data_get($payload, 'control_plane.current_capability', []);

        $this->assertContains('atlas_self_construction_final_evidence_bundle_status_projection', $capabilities);
        $this->assertContains('atlas_self_construction_completion_audit_blocker_explainer_status_projection', $capabilities);
        $this->assertContains('atlas_self_construction_runtime_promotion_evidence_dossier_status_projection', $capabilities);
        $this->assertContains('atlas_self_construction_human_completion_receipt_draft_status_projection', $capabilities);
        $this->assertContains('atlas_self_construction_human_completion_receipt_dossier_status_projection', $capabilities);
        $this->assertContains('atlas_self_construction_real_provider_smoke_evidence_dossier_status_projection', $capabilities);
        $this->assertContains('atlas_self_construction_real_provider_smoke_offline_harness_status_projection', $capabilities);
        $this->assertContains('atlas_self_construction_real_provider_smoke_draft_status_projection', $capabilities);
    }

    /** @return array<string, mixed> */
    private function snapshotOptions(): array
    {
        $hash = str_repeat('a', 64);

        return [
            'runtime_gap_matrix' => [
                'schema_version' => 'atlas.self_construction.runtime_gap_matrix.v1',
                'status' => 'blocked',
                'all_runtime_y' => false,
                'runtime_gap_matrix_hash' => $hash,
                'runtime_promotion_basis_hash' => str_repeat('b', 64),
                'runtime_y_candidate_count' => 1,
                'rows' => [
                    [
                        'gap_id' => 'adapter_execution_runtime',
                        'runtime_y' => false,
                        'runtime_y_candidate' => true,
                        'runtime_enabled' => false,
                        'graduation_status' => 'passed',
                        'graduation_schema' => 'atlas.test.graduation.v1',
                        'graduation_evidence_hash' => str_repeat('c', 64),
                        'blockers' => ['runtime_not_promoted_even_though_graduation_candidate_may_exist'],
                    ],
                ],
            ],
            'human_receipt' => [
                'schema_version' => 'atlas.self_construction.human_signed_completion_receipt.v1',
                'status' => 'blocked_missing_operator_receipt',
                'completion_claim_allowed' => false,
            ],
            'real_provider_smoke_result' => [
                'schema_version' => 'atlas.self_construction.real_provider_smoke_certification.v1',
                'status' => 'blocked_missing_real_provider_smoke',
                'completion_criterion_green' => false,
            ],
            'operator_action_packet' => [
                'schema_version' => 'atlas.self_construction.completion_operator_action_packet.v1',
                'status' => 'operator_action_required',
                'missing_operator_artifacts' => ['runtime_promotion_receipt', 'human_signed_os_complete_receipt', 'real_provider_claim_to_completion_smoke'],
            ],
            'completion_audit' => [
                'schema_version' => 'atlas.self_construction.os_completion_audit.v1',
                'status' => 'incomplete',
                'completion_allowed' => false,
                'completion_audit_hash' => $hash,
                'failed_criteria' => [
                    'runtime_gap_matrix_all_runtime_y',
                    'human_signed_os_complete_receipt_present',
                    'end_to_end_real_provider_smoke_green',
                ],
                'criteria' => [
                    ['id' => 'release_dossier_green', 'passed' => true, 'evidence' => ['hash' => $hash, 'baseline_snapshot_capture_required' => false]],
                    ['id' => 'replay_diff_against_completion_snapshot_green', 'passed' => true, 'evidence' => ['diff_hash' => $hash]],
                    ['id' => 'runtime_gap_matrix_all_runtime_y', 'passed' => false, 'evidence' => ['runtime_gap_matrix_hash' => $hash]],
                    ['id' => 'certification_status_batch_green', 'passed' => true, 'evidence' => ['checked_count' => 49, 'failed_count' => 0, 'hash' => $hash]],
                ],
            ],
        ];
    }
}
