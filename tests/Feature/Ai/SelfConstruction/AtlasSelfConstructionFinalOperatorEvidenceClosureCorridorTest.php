<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionOperatorEvidenceArtifactTemplatePackService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorTest extends TestCase
{
    public function test_closure_corridor_is_blocked_until_three_real_artifacts_present(): void
    {
        $payload = (new AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService(app(AtlasSelfConstructionReadinessService::class)))->build();

        $this->assertSame('atlas.self_construction.final_operator_evidence_closure_corridor.v1', $payload['schema_version']);
        $this->assertSame('read_only_final_operator_evidence_closure_corridor', $payload['mode']);
        $this->assertSame('blocked_operator_evidence_required', $payload['status']);
        $this->assertFalse($payload['completion_allowed']);
        $this->assertFalse($payload['completion_claim_allowed']);
        $this->assertEqualsCanonicalizing(
            ['runtime_promotion_receipt', 'real_provider_smoke', 'human_completion_receipt'],
            $payload['blocking_artifacts'],
        );
        $this->assertSame(3, $payload['blocking_artifact_count']);
        $this->assertNotEmpty($payload['closure_corridor_hash']);
        $this->assertSame(
            'blocked_until_all_required_operator_envelopes_are_ready',
            (string) data_get($payload, 'operator_submission_envelopes.status'),
        );
        $this->assertSame('runtime_promotion_receipt', (string) data_get($payload, 'operator_submission_envelopes.next_required_envelope'));
        $this->assertSame(3, (int) data_get($payload, 'operator_submission_envelopes.required_envelope_count'));
        $this->assertArrayHasKey('runtime_promotion_receipt', (array) data_get($payload, 'operator_submission_envelopes', []));
        $this->assertArrayHasKey('real_provider_smoke', (array) data_get($payload, 'operator_submission_envelopes', []));
        $this->assertArrayHasKey('human_completion_receipt', (array) data_get($payload, 'operator_submission_envelopes', []));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'operator_submission_envelopes.operator_submission_envelopes_hash'));
    }

    public function test_ordered_operator_path_contains_sixteen_canonical_steps_in_order(): void
    {
        $payload = (new AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService(app(AtlasSelfConstructionReadinessService::class)))->build();
        $path = $payload['ordered_operator_path'];

        $this->assertCount(16, $path);
        $this->assertSame(16, $payload['ordered_operator_path_step_count']);
        $this->assertSame([
            'refresh_replay_snapshot_if_stale',
            'draft_runtime_promotion_receipt',
            'compose_runtime_promotion_receipt_hash',
            'persist_runtime_promotion_receipt_after_verifier_passes',
            'prepare_real_provider_smoke_offline_harness',
            'run_operator_approved_real_provider_smoke_outside_this_read_only_surface',
            'draft_real_provider_smoke_payload',
            'compose_real_provider_smoke_hash',
            'persist_real_provider_smoke_after_verifier_passes',
            'draft_human_completion_receipt',
            'compose_human_completion_receipt_hash',
            'finalize_operator_draft_workspace_hashes',
            'publish_finalized_operator_draft_workspace',
            'persist_human_completion_receipt_after_prerequisites_green',
            'rerun_completion_audit',
            'promote_next_stage_only_after_completion_audit_complete',
        ], array_column($path, 'id'));

        foreach ($path as $step) {
            $this->assertArrayHasKey('phase', $step);
            $this->assertArrayHasKey('status', $step);
            $this->assertArrayHasKey('required_inputs', $step);
            $this->assertArrayHasKey('produced_artifacts', $step);
            $this->assertArrayHasKey('verifier_service', $step);
            $this->assertArrayHasKey('command', $step);
            $this->assertArrayHasKey('persist_command', $step);
            $this->assertArrayHasKey('stop_condition', $step);
            $this->assertArrayHasKey('forbidden_shortcuts', $step);
            $this->assertArrayHasKey('evidence_hashes_currently_available', $step);
            $this->assertArrayHasKey('missing_inputs', $step);
            $this->assertArrayHasKey('can_run_automatically', $step);
        }
    }

    public function test_operator_dependent_steps_are_marked_not_runnable_automatically(): void
    {
        $payload = (new AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService(app(AtlasSelfConstructionReadinessService::class)))->build();
        $steps = collect($payload['ordered_operator_path'])->keyBy('id');

        foreach ([
            'draft_runtime_promotion_receipt',
            'persist_runtime_promotion_receipt_after_verifier_passes',
            'run_operator_approved_real_provider_smoke_outside_this_read_only_surface',
            'draft_real_provider_smoke_payload',
            'persist_real_provider_smoke_after_verifier_passes',
            'draft_human_completion_receipt',
            'finalize_operator_draft_workspace_hashes',
            'publish_finalized_operator_draft_workspace',
            'persist_human_completion_receipt_after_prerequisites_green',
            'promote_next_stage_only_after_completion_audit_complete',
        ] as $stepId) {
            $this->assertFalse(
                $steps[$stepId]['can_run_automatically'],
                "Step {$stepId} must require operator/provider/human signature",
            );
        }
    }

    public function test_next_required_is_runtime_promotion_receipt_when_no_artifact_exists(): void
    {
        $payload = (new AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService(app(AtlasSelfConstructionReadinessService::class)))->build();

        $this->assertSame('runtime_promotion_receipt', data_get($payload, 'submission_preflight.next_required_submission'));
        $this->assertStringContainsString('--atlas-self-construction-runtime-promotion-receipt-draft-status', data_get($payload, 'submission_preflight.next_required_command'));
    }

    public function test_anti_cheat_policy_lists_canon_anti_cheat_rules(): void
    {
        $payload = (new AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService(app(AtlasSelfConstructionReadinessService::class)))->build();

        $this->assertSame(
            AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService::class,
            collect($payload['ordered_operator_path'])->firstWhere('id', 'finalize_operator_draft_workspace_hashes')['verifier_service'],
        );
        $this->assertSame(
            AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherService::class,
            collect($payload['ordered_operator_path'])->firstWhere('id', 'publish_finalized_operator_draft_workspace')['verifier_service'],
        );
        $this->assertStringContainsString(
            '--atlas-self-construction-operator-evidence-draft-hash-finalizer-status',
            data_get($payload, 'operator_command_plan.finalize_operator_draft_workspace_hashes'),
        );
        $this->assertStringContainsString(
            '--atlas-self-construction-operator-evidence-draft-workspace-publisher-status',
            data_get($payload, 'operator_command_plan.publish_finalized_operator_draft_workspace'),
        );

        foreach ([
            'reject_fake_real_provider_smoke',
            'reject_synthetic_provider_call',
            'reject_token_spend_without_cost_event',
            'reject_human_receipt_before_runtime_and_smoke_green',
            'reject_runtime_autopromotion',
            'reject_completion_claim_without_human_receipt',
            'reject_placeholder_operator',
            'reject_hash_mismatch',
            'reject_stale_replay_snapshot',
            'reject_direct_provider_call_from_read_only_surface',
            'reject_persistence_without_operator_submission_envelope',
        ] as $rule) {
            $this->assertContains($rule, $payload['anti_cheat_policy']);
        }
    }

    public function test_closure_corridor_surfaces_operator_draft_workspace_diagnostics(): void
    {
        $workspacePayload = (new AtlasSelfConstructionOperatorEvidenceArtifactTemplatePackService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'persist_operator_draft_workspace' => true,
        ]);
        $workspace = (string) data_get($workspacePayload, 'operator_draft_workspace.workspace_directory');

        $payload = (new AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'operator_draft_workspace_path' => 'storage/app/'.$workspace,
        ]);

        $this->assertSame('operator_draft_workspace_loaded', data_get($payload, 'operator_workspace_diagnostics.status'));
        $this->assertSame($workspace, data_get($payload, 'operator_workspace_diagnostics.workspace_directory'));
        $this->assertEqualsCanonicalizing(
            ['runtime_promotion_receipt', 'real_provider_smoke', 'human_completion_receipt'],
            data_get($payload, 'operator_workspace_diagnostics.loaded_artifacts'),
        );
        $this->assertSame('blocked_operator_drafts_not_ready_for_hash_write', data_get($payload, 'operator_workspace_diagnostics.draft_hash_finalization_status'));
        $this->assertSame('blocked_operator_draft_workspace_not_publishable', data_get($payload, 'operator_workspace_diagnostics.draft_workspace_publisher_status'));
        $this->assertSame(0, data_get($payload, 'operator_workspace_diagnostics.draft_workspace_publishable_artifact_count'));
        $this->assertTrue((bool) data_get($payload, 'operator_workspace_diagnostics.draft_workspace_atomic_bundle_publish_required'));
        $this->assertFalse((bool) data_get($payload, 'operator_workspace_diagnostics.draft_workspace_atomic_bundle_ready'));
        $this->assertStringContainsString('--atlas-self-construction-operator-evidence-draft-workspace-publisher-status', data_get($payload, 'operator_workspace_diagnostics.draft_workspace_publish_command'));
        $this->assertSame(4, data_get($payload, 'operator_workspace_diagnostics.draft_workspace_post_publish_persistence_step_count'));
        $this->assertTrue((bool) data_get($payload, 'operator_workspace_diagnostics.draft_workspace_post_publish_persistence_sequence_ordered'));
        $this->assertTrue((bool) data_get($payload, 'operator_workspace_diagnostics.draft_workspace_requires_explicit_operator_persistence_commands'));
        $this->assertFalse((bool) data_get($payload, 'operator_workspace_diagnostics.draft_workspace_can_persist_from_publisher'));
        $this->assertSame(
            [
                'persist_runtime_promotion_receipt',
                'persist_real_provider_smoke',
                'persist_human_completion_receipt',
                'rerun_completion_audit',
            ],
            array_column(data_get($payload, 'operator_workspace_diagnostics.draft_workspace_post_publish_persistence_sequence'), 'id'),
        );
        $this->assertFalse(data_get($payload, 'operator_workspace_diagnostics.can_write_from_corridor'));
        $this->assertFalse(data_get($payload, 'operator_workspace_diagnostics.can_persist_from_corridor'));

        $finalizeStep = collect($payload['ordered_operator_path'])->firstWhere('id', 'finalize_operator_draft_workspace_hashes');
        $this->assertSame('blocked_until_operator_drafts_are_hashable', $finalizeStep['status']);
        $this->assertSame(['operator_must_finish_draft_workspace_or_run_readiness'], $finalizeStep['missing_inputs']);

        $publishStep = collect($payload['ordered_operator_path'])->firstWhere('id', 'publish_finalized_operator_draft_workspace');
        $this->assertSame('blocked_until_operator_draft_hashes_are_finalized', $publishStep['status']);
        $this->assertSame(['operator_must_finalize_all_draft_hashes_before_publishing'], $publishStep['missing_inputs']);
        $this->assertFalse($publishStep['can_run_automatically']);
    }

    public function test_non_execution_guarantees_block_every_runtime_capability(): void
    {
        $payload = (new AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService(app(AtlasSelfConstructionReadinessService::class)))->build();

        foreach ([
            'does_not_start_codex',
            'does_not_call_codex_cli_or_app',
            'does_not_spawn_process',
            'does_not_call_provider',
            'does_not_spend_tokens',
            'does_not_dispatch_work',
            'does_not_execute_adapter',
            'does_not_enable_runtime',
            'does_not_write_ledger',
            'does_not_persist_receipts',
            'does_not_promote_completion',
            'does_not_sign_for_operator',
        ] as $guarantee) {
            $this->assertContains($guarantee, $payload['non_execution_guarantees']);
        }

        $this->assertFalse($payload['execution_allowed']);
        $this->assertFalse($payload['dispatch_allowed']);
        $this->assertFalse($payload['ledger_write_allowed']);
        $this->assertFalse($payload['provider_call_allowed']);
        $this->assertFalse($payload['token_spend_allowed']);
        $this->assertFalse($payload['adapter_execution_allowed']);
        $this->assertFalse($payload['self_programming_allowed']);
    }

    public function test_artifact_verification_matrix_maps_four_artifacts(): void
    {
        $payload = (new AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService(app(AtlasSelfConstructionReadinessService::class)))->build();
        $matrix = $payload['artifact_verification_matrix'];

        $this->assertArrayHasKey('runtime_promotion_receipt', $matrix);
        $this->assertArrayHasKey('real_provider_smoke', $matrix);
        $this->assertArrayHasKey('human_completion_receipt', $matrix);
        $this->assertArrayHasKey('final_completion_audit', $matrix);

        foreach ($matrix as $row) {
            $this->assertArrayHasKey('required_fields', $row);
            $this->assertArrayHasKey('required_hash_fields', $row);
            $this->assertArrayHasKey('required_boolean_acknowledgements', $row);
            $this->assertArrayHasKey('forbidden_flags', $row);
            $this->assertArrayHasKey('verifying_service', $row);
            $this->assertArrayHasKey('current_status', $row);
            $this->assertArrayHasKey('current_hash', $row);
            $this->assertArrayHasKey('missing_count', $row);
            $this->assertArrayHasKey('blocker_count', $row);
        }
    }

    public function test_readiness_status_json_and_cli_quartet_exist(): void
    {
        $readiness = app(AtlasSelfConstructionReadinessService::class);
        $status = $readiness->atlasSelfConstructionFinalOperatorEvidenceClosureCorridorStatus();

        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.v1', $status['schema_version']);
        $this->assertSame('blocked_operator_evidence_required', $status['status']);
        $this->assertFalse($status['execution_allowed']);

        foreach ([
            'atlas-self-construction-final-operator-evidence-closure-corridor-contract',
            'atlas-self-construction-final-operator-evidence-closure-corridor-preflight',
            'atlas-self-construction-final-operator-evidence-closure-corridor-implementation-packet',
            'atlas-self-construction-final-operator-evidence-closure-corridor-status',
        ] as $option) {
            $exit = Artisan::call('atlas:ai:self-construction', [
                '--'.$option => true,
                '--json' => true,
            ]);
            $this->assertSame(0, $exit, "CLI option {$option} should succeed");
            $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($decoded);
            $this->assertFalse($decoded['execution_allowed']);
            $this->assertFalse($decoded['dispatch_allowed']);
        }
    }

    public function test_agent_control_plane_lists_closure_corridor_capabilities(): void
    {
        $payload = app(AtlasSelfConstructionReadinessService::class)->agentControlPlane();
        $capabilities = (array) data_get($payload, 'control_plane.current_capability', []);

        foreach ([
            'atlas_self_construction_final_operator_evidence_closure_corridor_contract',
            'atlas_self_construction_final_operator_evidence_closure_corridor_preflight',
            'atlas_self_construction_final_operator_evidence_closure_corridor_implementation_packet',
            'atlas_self_construction_final_operator_evidence_closure_corridor_service',
            'atlas_self_construction_final_operator_evidence_closure_corridor_status_projection',
        ] as $capability) {
            $this->assertContains($capability, $capabilities);
        }
    }
}
