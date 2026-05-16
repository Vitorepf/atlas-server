<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionOperatorEvidenceArtifactTemplatePackService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionOperatorEvidenceArtifactTemplatePackTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private static ?array $cachedPayload = null;

    public function test_template_pack_generates_three_templates_without_persistence(): void
    {
        $payload = $this->payload();

        $this->assertSame('atlas.self_construction.operator_evidence_artifact_template_pack.v1', $payload['schema_version']);
        $this->assertSame('read_only_operator_evidence_artifact_template_pack', $payload['mode']);
        $this->assertSame('available', $payload['status']);
        $this->assertSame(3, $payload['template_count']);
        $this->assertArrayHasKey('runtime_promotion_receipt_template', $payload['templates']);
        $this->assertArrayHasKey('real_provider_smoke_preimage_template', $payload['templates']);
        $this->assertArrayHasKey('human_completion_receipt_template', $payload['templates']);
        $this->assertSame('atlas.self_construction.operator_evidence_submission_bundle.v1', data_get($payload, 'operator_submission_bundle.schema_version'));
        $this->assertSame(3, data_get($payload, 'operator_submission_bundle.artifact_count'));
        $this->assertSame('runtime_promotion_receipt', data_get($payload, 'operator_execution_plan.current_step'));
        $this->assertFalse((bool) data_get($payload, 'operator_execution_plan.parallel_submission_allowed'));
        $this->assertSame(
            'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            data_get($payload, 'operator_execution_plan.final_success_predicate'),
        );
        $this->assertSame('blocked', data_get($payload, 'completion_evidence_submission_preflight.status'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'completion_evidence_submission_preflight.submission_preflight_hash'));
        $this->assertSame('atlas.self_construction.operator_final_evidence_handoff_packet.v1', data_get($payload, 'operator_handoff_packet.schema_version'));
        $this->assertSame('runtime_promotion_receipt', data_get($payload, 'operator_handoff_packet.current_step'));
        $this->assertContains('operator_signed_runtime_promotion_receipt_json', data_get($payload, 'operator_handoff_packet.required_operator_inputs'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'operator_handoff_packet.handoff_packet_hash'));
        $this->assertFalse(data_get($payload, 'operator_submission_bundle.can_write_files_from_template_pack'));
        $this->assertFalse(data_get($payload, 'operator_submission_bundle.can_persist_from_template_pack'));
        $this->assertFalse($payload['persist_export_requested']);
        $this->assertFalse($payload['persist_operator_draft_workspace_requested']);
        $this->assertFalse($payload['persist']);
        $this->assertSame('', $payload['export_path']);
        $this->assertSame('not_requested', data_get($payload, 'operator_draft_workspace.status'));
        $this->assertFalse(data_get($payload, 'operator_draft_workspace.persisted'));
        $this->assertFalse(data_get($payload, 'operator_draft_workspace.can_persist_completion_evidence_from_draft_workspace'));
        $this->assertStringContainsString('Atlas Self-Construction Operator Evidence Submission Bundle v1', $payload['operator_submission_bundle_markdown']);
        $this->assertStringContainsString('runtime_promotion_receipt', $payload['operator_submission_bundle_markdown']);
        $this->assertStringContainsString('real_provider_smoke', $payload['operator_submission_bundle_markdown']);
        $this->assertStringContainsString('human_completion_receipt', $payload['operator_submission_bundle_markdown']);
        $this->assertStringContainsString('payload_template_json_sha256', $payload['operator_submission_bundle_markdown']);
        $this->assertStringContainsString('## Operator Execution Plan', $payload['operator_submission_bundle_markdown']);
        $this->assertStringContainsString('## Operator Current-Step Handoff', $payload['operator_submission_bundle_markdown']);
        $this->assertStringContainsString('operator_signed_runtime_promotion_receipt_json', $payload['operator_submission_bundle_markdown']);
        $this->assertStringContainsString('**Current blockers**', $payload['operator_submission_bundle_markdown']);
        $this->assertStringContainsString('adapter_execution_runtime', $payload['operator_submission_bundle_markdown']);
        $this->assertStringContainsString('**Handoff stop conditions**', $payload['operator_submission_bundle_markdown']);
        $this->assertStringContainsString('stop_if_any_required_input_is_placeholder', $payload['operator_submission_bundle_markdown']);
        $this->assertStringContainsString('parallel_submission_allowed**: `false`', $payload['operator_submission_bundle_markdown']);
        $this->assertStringContainsString('--atlas-self-construction-completion-evidence-submission-preflight-status', $payload['operator_submission_bundle_markdown']);
        $this->assertStringContainsString('--atlas-self-construction-completion-evidence-hash-composer-status', $payload['operator_submission_bundle_markdown']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['operator_submission_bundle_markdown_hash']);
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
        $payload = $this->payload();
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

    public function test_operator_submission_bundle_maps_artifacts_to_saved_file_commands_and_completion_blockers(): void
    {
        $payload = $this->payload();
        $bundle = (array) $payload['operator_submission_bundle'];
        $artifacts = (array) $bundle['artifacts'];

        $this->assertSame([
            'runtime_promotion_receipt',
            'real_provider_smoke',
            'human_completion_receipt',
        ], $bundle['artifact_sequence']);
        $this->assertSame('blocked', $bundle['submission_preflight_status']);
        $this->assertSame('runtime_promotion_receipt', $bundle['next_required_submission']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $bundle['submission_preflight_hash']);
        $this->assertSame('runtime_promotion_receipt', data_get($bundle, 'operator_handoff_packet.current_step'));
        $this->assertSame('blocked_missing_runtime_promotion_receipt', data_get($bundle, 'operator_handoff_packet.current_status'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($bundle, 'operator_handoff_packet.handoff_packet_hash'));
        $this->assertFalse($bundle['parallel_submission_allowed']);
        $this->assertTrue($bundle['operator_must_follow_order']);
        $this->assertSame(
            'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            $bundle['final_success_predicate'],
        );
        $this->assertSame([
            'runtime_promotion_receipt',
            'real_provider_smoke',
            'completion_evidence_hash_composition',
            'human_completion_receipt',
            'final_completion_audit',
        ], array_column(data_get($bundle, 'operator_execution_plan.ordered_command_queue'), 'id'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $bundle['operator_submission_bundle_hash']);

        $runtime = $artifacts[0];
        $this->assertSame('runtime_promotion_receipt', $runtime['artifact']);
        $this->assertSame('storage/app/atlas/self-construction/operator-submissions/runtime-promotion.json', $runtime['recommended_file_path']);
        $this->assertContains('runtime_gap_matrix_all_runtime_y', $runtime['blocks_completion_criteria']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $runtime['payload_template_json_sha256']);
        $this->assertStringContainsString('@storage/app/atlas/self-construction/operator-submissions/runtime-promotion.json', $runtime['command_to_compute_hash_saved_file']);
        $this->assertStringContainsString('@storage/app/atlas/self-construction/operator-submissions/runtime-promotion.json', $runtime['command_to_verify_saved_file']);
        $this->assertStringContainsString('--persist-runtime-promotion-receipt', $runtime['command_to_persist_saved_file']);
        $this->assertContains('compare_initial_file_sha256_to_payload_template_json_sha256_before_editing', $runtime['operator_checks_before_saving']);
        $this->assertContains('run_command_to_compute_hash_saved_file_after_editing', $runtime['operator_checks_before_saving']);
        $this->assertFalse($runtime['can_write_file_from_template_pack']);
        $this->assertFalse($runtime['can_persist_from_template_pack']);

        $smoke = $artifacts[1];
        $this->assertSame('real_provider_smoke', $smoke['artifact']);
        $this->assertContains('end_to_end_real_provider_smoke_green', $smoke['blocks_completion_criteria']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $smoke['payload_template_json_sha256']);
        $this->assertStringContainsString('@storage/app/atlas/self-construction/operator-submissions/real-provider-smoke.json', $smoke['command_to_compute_hash_saved_file']);
        $this->assertStringContainsString('@storage/app/atlas/self-construction/operator-submissions/real-provider-smoke.json', $smoke['command_to_persist_saved_file']);
        $this->assertContains('real_provider_run_observed_by_operator', $smoke['boolean_acknowledgements']);

        $human = $artifacts[2];
        $this->assertSame('human_completion_receipt', $human['artifact']);
        $this->assertContains('human_signed_os_complete_receipt_present', $human['blocks_completion_criteria']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $human['payload_template_json_sha256']);
        $this->assertStringContainsString('@storage/app/atlas/self-construction/operator-submissions/completion-receipt.json', $human['command_to_compute_hash_saved_file']);
        $this->assertStringContainsString('@storage/app/atlas/self-construction/operator-submissions/completion-receipt.json', $human['command_to_persist_saved_file']);
        $this->assertContains('os_complete_approved', $human['boolean_acknowledgements']);
    }

    public function test_operator_submission_bundle_markdown_export_persists_only_with_explicit_flag(): void
    {
        Storage::fake('local');
        self::$cachedPayload = null;

        $payload = (new AtlasSelfConstructionOperatorEvidenceArtifactTemplatePackService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'persist_export' => true,
        ]);

        $this->assertSame('exported', $payload['status']);
        $this->assertTrue($payload['persist_export_requested']);
        $this->assertTrue($payload['persist']);
        $this->assertStringStartsWith('atlas/self-construction/operator-evidence/template-pack-exports/', $payload['export_path']);
        Storage::disk('local')->assertExists($payload['export_path']);
        $this->assertSame($payload['operator_submission_bundle_markdown'], Storage::disk('local')->get($payload['export_path']));
        $this->assertSame('not_requested', data_get($payload, 'operator_draft_workspace.status'));

        Storage::disk('local')->assertMissing('atlas/self-construction/os-completion/runtime-promotion-receipts/registry.json');
        Storage::disk('local')->assertMissing('atlas/self-construction/os-completion/completion-evidence/registry.json');

        self::$cachedPayload = null;
    }

    public function test_operator_draft_workspace_export_writes_only_placeholder_drafts_with_explicit_flag(): void
    {
        Storage::fake('local');
        self::$cachedPayload = null;

        $payload = (new AtlasSelfConstructionOperatorEvidenceArtifactTemplatePackService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'persist_operator_draft_workspace' => true,
        ]);

        $workspace = (array) $payload['operator_draft_workspace'];
        $this->assertSame('persisted_placeholder_drafts', $workspace['status']);
        $this->assertTrue($payload['persist_operator_draft_workspace_requested']);
        $this->assertTrue($workspace['persisted']);
        $this->assertFalse($workspace['can_persist_completion_evidence_from_draft_workspace']);
        $this->assertFalse($workspace['can_promote_completion_from_draft_workspace']);
        $this->assertStringStartsWith('atlas/self-construction/operator-submissions/draft-workspaces/', $workspace['workspace_directory']);
        Storage::disk('local')->assertExists($workspace['manifest_path']);

        $files = (array) data_get($workspace, 'manifest.files', []);
        $this->assertCount(3, $files);

        foreach ($files as $file) {
            Storage::disk('local')->assertExists($file['draft_path']);
            $this->assertFalse($file['draft_is_evidence']);
            $this->assertFalse($file['can_persist_draft_directly']);
            $this->assertTrue($file['sha256_matches_payload_template']);
            $this->assertStringContainsString('--atlas-self-construction-operator-evidence-draft-hash-finalizer-status', $file['command_to_finalize_workspace_hashes']);
            $this->assertStringContainsString('--write-computed-operator-draft-hashes', $file['command_to_finalize_workspace_hashes']);
            $this->assertStringContainsString('@storage/app/atlas/self-construction/operator-submissions/draft-workspaces/', $file['command_to_compute_hash_draft_file']);
            $this->assertStringContainsString('@storage/app/atlas/self-construction/operator-submissions/draft-workspaces/', $file['command_to_verify_draft_file']);
            $this->assertStringNotContainsString('--persist-runtime-promotion-receipt', $file['command_to_verify_draft_file']);
            $this->assertStringNotContainsString('--persist-completion-evidence', $file['command_to_verify_draft_file']);
        }

        $runtimeFile = collect($files)->firstWhere('artifact', 'runtime_promotion_receipt');
        $this->assertFalse($runtimeFile['copy_to_recommended_file_path_before_persisting']);
        $this->assertStringContainsString('--atlas-self-construction-runtime-promotion-draft-hash-finalizer-status', $runtimeFile['command_to_finalize_draft_receipt_hash']);
        $this->assertStringContainsString('--write-computed-runtime-promotion-receipt-hash', $runtimeFile['command_to_finalize_draft_receipt_hash']);
        $this->assertStringContainsString('--atlas-self-construction-runtime-promotion-endgame-status', $runtimeFile['command_to_review_draft_file_with_endgame']);
        $this->assertStringContainsString('--operator-draft-workspace-path=storage/app/atlas/self-construction/operator-submissions/draft-workspaces/', $runtimeFile['command_to_review_draft_file_with_endgame']);
        $this->assertStringContainsString('--persist-runtime-promotion-receipt', $runtimeFile['command_to_persist_draft_file_with_endgame']);
        $this->assertStringContainsString('runtime_promotion_endgame_can_read_operator_draft_workspace_path_directly', $runtimeFile['copy_not_required_reason']);

        $smokeFile = collect($files)->firstWhere('artifact', 'real_provider_smoke');
        $humanFile = collect($files)->firstWhere('artifact', 'human_completion_receipt');
        $this->assertTrue($smokeFile['copy_to_recommended_file_path_before_persisting']);
        $this->assertTrue($humanFile['copy_to_recommended_file_path_before_persisting']);
        $this->assertContains('run_operator_evidence_draft_hash_finalizer_against_the_draft_workspace_path', data_get($workspace, 'manifest.operator_required_next_steps'));
        $this->assertContains('for_runtime_promotion_receipt_run_hash_finalizer_against_the_draft_workspace_path', data_get($workspace, 'manifest.operator_required_next_steps'));
        $this->assertContains('for_runtime_promotion_receipt_run_endgame_against_the_draft_workspace_path', data_get($workspace, 'manifest.operator_required_next_steps'));
        $this->assertContains('follow_operator_execution_plan_order_without_parallel_submission', data_get($workspace, 'manifest.operator_required_next_steps'));
        $this->assertSame('runtime_promotion_receipt', data_get($workspace, 'manifest.operator_execution_plan.current_step'));
        $this->assertSame('runtime_promotion_receipt', data_get($workspace, 'manifest.operator_handoff_packet.current_step'));
        $this->assertContains('operator_signed_runtime_promotion_receipt_json', data_get($workspace, 'manifest.operator_handoff_packet.required_operator_inputs'));
        $this->assertSame('runtime_promotion_receipt', data_get($workspace, 'manifest.next_required_submission'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($workspace, 'manifest.submission_preflight_hash'));

        Storage::disk('local')->assertMissing('atlas/self-construction/os-completion/runtime-promotion-receipts/registry.json');
        Storage::disk('local')->assertMissing('atlas/self-construction/os-completion/completion-evidence/registry.json');

        self::$cachedPayload = null;
    }

    public function test_real_provider_smoke_template_lists_required_hashes_and_observation_flags(): void
    {
        $payload = $this->payload();
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
        $payload = $this->payload();
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

    /** @return array<string, mixed> */
    private function payload(): array
    {
        if (self::$cachedPayload === null) {
            self::$cachedPayload = (new AtlasSelfConstructionOperatorEvidenceArtifactTemplatePackService(app(AtlasSelfConstructionReadinessService::class)))->build();
        }

        return self::$cachedPayload;
    }
}
