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
        $this->assertSame('atlas.self_construction.completion_audit_blocker_summary.v1', data_get($payload, 'submission_preflight.completion_audit_blocker_summary.schema_version'));
        $this->assertSame('human', data_get($payload, 'submission_preflight.completion_audit_blocker_summary.blockers_by_id.human_signed_os_complete_receipt_present.blocker_type'));
        $this->assertSame('atlas.self_construction.human_signed_completion_receipt.v1', data_get($payload, 'submission_preflight.completion_audit_blocker_summary.blockers_by_id.human_signed_os_complete_receipt_present.expected_receipt_schema'));
        $this->assertContains('human_signed_os_complete_receipt_present', data_get($payload, 'current_completion_audit.blocker_classification.human_blockers'));
        $this->assertNotEmpty(data_get($payload, 'current_completion_audit.failed_criteria_detailed'));
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
        $this->assertSame(
            'blocked_until_runtime_smoke_prior_persistence_and_evidence_context_are_green',
            (string) data_get($payload, 'operator_submission_envelopes.human_completion_receipt.status'),
        );
        $this->assertFalse((bool) data_get($payload, 'current_completion_evidence_status.real_provider_smoke_persisted_before_human_receipt_command'));
        $this->assertFalse((bool) data_get($payload, 'operator_submission_envelopes.human_completion_receipt.current_evidence_context.real_provider_smoke_persisted_before_human_receipt_command'));
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

    public function test_operator_next_action_surfaces_one_exact_safe_handoff_step(): void
    {
        $payload = (new AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService(app(AtlasSelfConstructionReadinessService::class)))->build();
        $nextAction = $payload['operator_next_action'];

        $this->assertSame('atlas.self_construction.final_operator_next_action.v1', $nextAction['schema_version']);
        $this->assertSame('blocked_operator_action_required', $nextAction['status']);
        $this->assertSame('runtime_promotion_receipt', $nextAction['next_required_submission']);
        $this->assertSame('draft_runtime_promotion_receipt', $nextAction['next_step_id']);
        $this->assertSame('runtime_promotion', $nextAction['next_step_phase']);
        $this->assertStringContainsString('--atlas-self-construction-runtime-promotion-receipt-draft-status', $nextAction['exact_command']);
        $this->assertSame('', $nextAction['exact_persist_command']);
        $this->assertTrue($nextAction['command_contains_placeholders']);
        $this->assertContains('<operator>', $nextAction['placeholder_fields_to_replace']);
        $this->assertContains('<operator reason with at least 32 chars>', $nextAction['placeholder_fields_to_replace']);
        $this->assertFalse($nextAction['can_run_automatically']);
        $this->assertSame('requires_operator_signature_and_runtime_promotion_judgment', $nextAction['why_not_automatic']);
        $this->assertContains('signed_by', $nextAction['required_inputs']);
        $this->assertContains('operator_signed_runtime_promotion_receipt', $nextAction['missing_inputs']);
        $this->assertContains('placeholder_signed_by', $nextAction['forbidden_shortcuts']);
        $this->assertContains(
            'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
            $nextAction['proof_commands_after_action'],
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $nextAction['operator_next_action_hash']);
        $this->assertContains('next_action_projection_does_not_call_provider', $nextAction['non_execution_guarantees']);
    }

    public function test_operator_closure_handoff_is_scriptable_without_executing_anything(): void
    {
        $payload = (new AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService(app(AtlasSelfConstructionReadinessService::class)))->build();
        $handoff = $payload['operator_closure_handoff'];

        $this->assertSame('atlas.self_construction.final_operator_closure_handoff.v1', $handoff['schema_version']);
        $this->assertSame('read_only_operator_closure_handoff', $handoff['mode']);
        $this->assertSame('blocked_operator_action_required', $handoff['status']);
        $this->assertSame('runtime_promotion_receipt', $handoff['next_required_submission']);
        $this->assertSame('draft_runtime_promotion_receipt', $handoff['next_step_id']);
        $this->assertStringContainsString('--atlas-self-construction-runtime-promotion-receipt-draft-status', $handoff['immediate_command']);
        $this->assertSame('', $handoff['immediate_persist_command']);
        $this->assertTrue($handoff['command_contains_placeholders']);
        $this->assertContains('<operator>', $handoff['placeholder_fields_to_replace']);
        $this->assertFalse($handoff['can_run_automatically']);
        $this->assertTrue($handoff['requires_human_operator']);
        $this->assertTrue($handoff['requires_real_provider_smoke']);
        $this->assertSame(3, $handoff['blocking_artifact_count']);
        $this->assertSame('atlas.self_construction.completion_audit_blocker_summary.v1', data_get($handoff, 'completion_audit_blocker_summary.schema_version'));
        $this->assertSame('real_provider', data_get($handoff, 'completion_audit_blocker_summary.blockers_by_id.end_to_end_real_provider_smoke_green.blocker_type'));
        $this->assertStringContainsString('--atlas-self-construction-real-provider-smoke-draft-status', data_get($handoff, 'completion_audit_blocker_summary.blockers_by_id.end_to_end_real_provider_smoke_green.remediation_command'));
        $this->assertCount(16, $handoff['full_ordered_command_sequence']);
        $this->assertSame(
            'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            $handoff['success_predicate_after_all_actions'],
        );
        $this->assertSame(
            data_get($payload, 'submission_preflight.resumption_checkpoint_hash'),
            data_get($handoff, 'resumption_checkpoint_hash'),
        );
        $this->assertSame('atlas.self_construction.operator_closure_command_replay.v1', data_get($payload, 'operator_closure_command_replay.schema_version'));
        $this->assertSame('runtime_promotion_receipt', data_get($payload, 'operator_closure_command_replay.current_step'));
        $this->assertSame(5, data_get($payload, 'operator_closure_command_replay.replay_step_count'));
        $this->assertSame('runtime_promotion_receipt', data_get($handoff, 'resumption_checkpoint_current_step'));
        $this->assertSame('runtime_promotion_receipt', data_get($handoff, 'operator_closure_command_replay_current_step'));
        $this->assertSame(5, data_get($handoff, 'operator_closure_command_replay_step_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($handoff, 'operator_closure_command_replay_hash'));
        $this->assertSame(
            data_get($payload, 'operator_closure_command_replay.command_replay_hash'),
            data_get($handoff, 'operator_closure_command_replay_hash'),
        );
        $this->assertStringContainsString(
            '--atlas-self-construction-runtime-promotion-receipt-draft-status',
            data_get($handoff, 'resumption_checkpoint_exact_next_command'),
        );
        $this->assertTrue((bool) data_get($handoff, 'can_resume_without_chat_history'));
        $this->assertTrue((bool) data_get($handoff, 'requires_fresh_preflight_before_persist'));
        $this->assertStringContainsString(
            '--atlas-self-construction-operator-evidence-artifact-template-pack-status',
            data_get($handoff, 'workspace_flow.template_pack_command'),
        );
        $this->assertTrue((bool) data_get($handoff, 'workspace_flow.requires_explicit_operator_publish'));
        $this->assertTrue((bool) data_get($handoff, 'workspace_flow.requires_explicit_operator_persistence'));
        $this->assertContains('operator_closure_handoff_does_not_call_provider', $handoff['non_execution_guarantees']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $handoff['operator_closure_handoff_hash']);
    }

    public function test_operator_execution_runbook_is_resumable_and_non_executing(): void
    {
        $payload = (new AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService(app(AtlasSelfConstructionReadinessService::class)))->build();
        $runbook = $payload['operator_execution_runbook'];

        $this->assertSame('atlas.self_construction.final_operator_execution_runbook.v1', $runbook['schema_version']);
        $this->assertSame('read_only_operator_execution_runbook', $runbook['mode']);
        $this->assertSame('blocked_operator_driven_steps_remaining', $runbook['status']);
        $this->assertSame('draft_runtime_promotion_receipt', $runbook['current_step_id']);
        $this->assertSame('runtime_promotion', $runbook['current_step_phase']);
        $this->assertSame(16, $runbook['step_count']);
        $this->assertSame(4, $runbook['blocked_artifact_count']);
        $this->assertContains('real_provider_smoke', $runbook['blocked_artifact_ids']);
        $this->assertContains('final_completion_audit', $runbook['blocked_artifact_ids']);
        $this->assertStringContainsString('--atlas-self-construction-runtime-promotion-receipt-draft-status', $runbook['current_step_command']);
        $this->assertSame(
            data_get($payload, 'operator_closure_handoff.operator_closure_handoff_hash'),
            $runbook['operator_closure_handoff_hash'],
        );
        $this->assertSame(
            data_get($payload, 'operator_closure_command_replay.command_replay_hash'),
            $runbook['operator_closure_command_replay_hash'],
        );
        $this->assertSame('runtime_promotion_receipt', $runbook['operator_closure_command_replay_current_step']);
        $this->assertSame(5, $runbook['operator_closure_command_replay_step_count']);
        $this->assertStringContainsString(
            '--atlas-self-construction-final-operator-evidence-closure-corridor-status',
            data_get($runbook, 'proof_commands_after_each_step.closure_corridor'),
        );
        $this->assertTrue((bool) data_get($runbook, 'resume_without_chat_history.can_resume_without_chat_history'));
        $this->assertFalse((bool) $runbook['can_run_automatically']);
        $this->assertFalse((bool) $runbook['can_persist_from_runbook']);
        $this->assertFalse((bool) $runbook['can_call_provider_from_runbook']);
        $this->assertFalse((bool) $runbook['can_sign_for_operator_from_runbook']);
        $this->assertContains('operator_execution_runbook_does_not_call_provider', $runbook['non_execution_guarantees']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $runbook['operator_execution_runbook_hash']);
    }

    public function test_closure_readiness_summary_separates_technical_green_from_operator_evidence_blockers(): void
    {
        $payload = (new AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService(app(AtlasSelfConstructionReadinessService::class)))->build();
        $summary = $payload['closure_readiness_summary'];

        $this->assertSame('atlas.self_construction.final_operator_closure_readiness_summary.v1', $summary['schema_version']);
        $this->assertContains($summary['status'], [
            'technical_closure_green_operator_evidence_remaining',
            'technical_closure_blocked',
        ]);
        $this->assertIsBool($summary['technical_closure_green']);
        $this->assertSame($summary['technical_closure_green'] ? 0 : count($summary['technical_blocker_ids']), $summary['technical_blocker_count']);
        $this->assertGreaterThanOrEqual(1, $summary['human_blocker_count']);
        $this->assertContains('human_signed_os_complete_receipt_present', $summary['human_blocker_ids']);
        $this->assertSame(1, $summary['real_provider_blocker_count']);
        $this->assertContains('end_to_end_real_provider_smoke_green', $summary['real_provider_blocker_ids']);
        $this->assertSame(3, $summary['operator_evidence_blocking_artifact_count']);
        $this->assertEqualsCanonicalizing(
            ['runtime_promotion_receipt', 'real_provider_smoke', 'human_completion_receipt'],
            $summary['operator_evidence_blocking_artifacts'],
        );
        foreach ([
            'release_dossier_green',
            'replay_diff_green',
            'promotion_gate_green',
            'mutation_guard_green',
            'certification_status_batch_green',
            'terminal_loop_certification_green',
        ] as $gate) {
            $this->assertIsBool($summary[$gate]);
        }
        if ($summary['technical_closure_green']) {
            $this->assertTrue($summary['release_dossier_green']);
            $this->assertTrue($summary['replay_diff_green']);
            $this->assertTrue($summary['promotion_gate_green']);
            $this->assertTrue($summary['mutation_guard_green']);
            $this->assertTrue($summary['certification_status_batch_green']);
            $this->assertTrue($summary['terminal_loop_certification_green']);
        }
        $this->assertFalse($summary['can_finish_without_operator']);
        $this->assertFalse($summary['can_finish_without_real_provider_smoke']);
        $this->assertFalse($summary['can_self_promote_completion']);
        $this->assertSame('runtime_promotion_receipt', $summary['next_required_submission']);
        $this->assertSame('draft_runtime_promotion_receipt', $summary['next_step_id']);
        $this->assertStringContainsString('--atlas-self-construction-runtime-promotion-receipt-draft-status', $summary['exact_next_command']);
        $this->assertContains('closure_readiness_summary_does_not_promote_completion', $summary['non_execution_guarantees']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $summary['closure_readiness_summary_hash']);
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
        $this->assertSame('no_canonical_submission_files_loaded', data_get($payload, 'operator_workspace_diagnostics.canonical_submission_persistence_plan_status'));
        $this->assertSame('persist_runtime_promotion_receipt', data_get($payload, 'operator_workspace_diagnostics.canonical_submission_persistence_plan_next_step_id'));
        $this->assertFalse((bool) data_get($payload, 'operator_workspace_diagnostics.canonical_submission_persisted_evidence_state.runtime_promotion_receipt.persisted_green'));
        $this->assertFalse((bool) data_get($payload, 'operator_workspace_diagnostics.canonical_submission_persisted_evidence_state.real_provider_smoke.persisted_green'));
        $this->assertFalse((bool) data_get($payload, 'operator_workspace_diagnostics.canonical_submission_persisted_evidence_state.human_completion_receipt.persisted_green'));
        $this->assertTrue((bool) data_get($payload, 'operator_workspace_diagnostics.human_receipt_persistence_requires_prior_persisted_smoke_command'));
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

    public function test_operator_command_surface_integrity_audits_all_final_closure_commands(): void
    {
        $payload = (new AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService(app(AtlasSelfConstructionReadinessService::class)))->build();
        $integrity = $payload['operator_command_surface_integrity'];

        $this->assertSame('atlas.self_construction.final_operator_closure_command_surface_integrity.v1', $integrity['schema_version']);
        $this->assertSame('read_only_final_operator_closure_command_surface_integrity', $integrity['mode']);
        $this->assertSame('command_surface_aligned', $integrity['status']);
        $this->assertSame('atlas:ai:self-construction', $integrity['command_name']);
        $this->assertGreaterThanOrEqual(20, $integrity['command_count']);
        $this->assertGreaterThanOrEqual(20, $integrity['checked_option_count']);
        $this->assertSame(0, $integrity['missing_option_count']);
        $this->assertSame([], $integrity['missing_options']);
        $this->assertFalse($integrity['can_execute_commands_from_integrity_check']);
        $this->assertFalse($integrity['can_persist_from_integrity_check']);
        $this->assertFalse($integrity['can_call_provider_from_integrity_check']);
        $this->assertFalse($integrity['can_sign_for_operator_from_integrity_check']);
        $this->assertContains('final_operator_closure_command_surface_integrity_does_not_run_operator_commands', $integrity['non_execution_guarantees']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $integrity['command_surface_integrity_hash']);

        $commands = collect($integrity['commands'])->keyBy('payload_path');
        $this->assertTrue($commands->contains(
            fn (array $row): bool => str_contains($row['command'], '--atlas-self-construction-runtime-promotion-receipt-draft-status')
                && $row['surface_ok'] === true
        ));
        $this->assertTrue($commands->contains(
            fn (array $row): bool => str_contains($row['command'], '--atlas-self-construction-real-provider-smoke-offline-harness-status')
                && $row['surface_ok'] === true
        ));
        $this->assertTrue($commands->contains(
            fn (array $row): bool => str_contains($row['command'], '--atlas-self-construction-human-completion-receipt-draft-status')
                && $row['surface_ok'] === true
        ));
        $this->assertTrue($commands->contains(
            fn (array $row): bool => str_contains($row['command'], '--atlas-self-construction-os-completion-audit-status')
                && $row['surface_ok'] === true
        ));
    }

    public function test_operator_completion_progress_meter_summarizes_final_artifact_state(): void
    {
        $payload = (new AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService(app(AtlasSelfConstructionReadinessService::class)))->build();
        $meter = $payload['operator_completion_progress_meter'];

        $this->assertSame('atlas.self_construction.final_operator_completion_progress_meter.v1', $meter['schema_version']);
        $this->assertSame('read_only_final_operator_completion_progress_meter', $meter['mode']);
        $this->assertSame('operator_evidence_remaining', $meter['status']);
        $this->assertSame(4, $meter['artifact_count']);
        $this->assertGreaterThanOrEqual(1, $meter['blocked_artifact_count']);
        $this->assertLessThanOrEqual(100, $meter['progress_percent']);
        $this->assertSame('runtime_promotion_receipt', $meter['current_required_artifact']);
        $this->assertSame('draft_runtime_promotion_receipt', $meter['current_step_id']);
        $this->assertStringContainsString('--atlas-self-construction-runtime-promotion-receipt-draft-status', $meter['exact_next_command']);
        $this->assertSame('', $meter['exact_next_persist_command']);
        $this->assertIsBool($meter['technical_blockers_clear']);
        $this->assertGreaterThanOrEqual(1, $meter['human_blocker_count']);
        $this->assertSame(1, $meter['real_provider_blocker_count']);
        $this->assertFalse($meter['completion_allowed']);
        $this->assertFalse($meter['can_finish_without_operator']);
        $this->assertFalse($meter['can_finish_without_real_provider_smoke']);
        $this->assertFalse($meter['can_self_promote_completion']);
        $this->assertSame(
            ['runtime_promotion_receipt', 'real_provider_smoke', 'human_completion_receipt', 'final_completion_audit'],
            array_column($meter['artifact_rows'], 'artifact'),
        );
        $this->assertContains('completion_progress_meter_does_not_call_provider', $meter['non_execution_guarantees']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $meter['progress_meter_hash']);
    }

    public function test_operator_failure_recovery_matrix_centralizes_safe_recovery_paths(): void
    {
        $payload = (new AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService(app(AtlasSelfConstructionReadinessService::class)))->build();
        $matrix = $payload['operator_failure_recovery_matrix'];

        $this->assertSame('atlas.self_construction.final_operator_failure_recovery_matrix.v1', $matrix['schema_version']);
        $this->assertSame('read_only_operator_failure_recovery_matrix', $matrix['mode']);
        $this->assertSame('operator_recovery_guidance_available', $matrix['status']);
        $this->assertSame(5, $matrix['row_count']);
        $this->assertSame(
            [
                'placeholder_command_detected',
                'post_action_verifier_not_green',
                'hash_mismatch_detected',
                'stale_replay_snapshot_or_runtime_basis',
                'real_provider_smoke_aborted_or_incomplete',
            ],
            array_column($matrix['rows'], 'failure_id'),
        );
        $this->assertTrue($matrix['recovery_requires_fresh_corridor_status']);
        $this->assertFalse($matrix['can_recover_from_matrix']);
        $this->assertFalse($matrix['can_execute_from_matrix']);
        $this->assertFalse($matrix['can_persist_from_matrix']);
        $this->assertFalse($matrix['can_call_provider_from_matrix']);
        $this->assertFalse($matrix['can_sign_for_operator_from_matrix']);
        $this->assertStringContainsString('--atlas-self-construction-final-operator-evidence-closure-corridor-status', data_get($matrix, 'rows.0.safe_recovery_command'));
        $this->assertContains('do_not_execute_placeholder_command', data_get($matrix, 'rows.0.do_not_do'));
        $this->assertContains('do_not_advance_to_next_artifact', data_get($matrix, 'rows.1.do_not_do'));
        $this->assertStringContainsString('--atlas-self-construction-completion-evidence-hash-composer-status', data_get($matrix, 'rows.2.safe_recovery_command'));
        $this->assertStringContainsString('--agent-control-plane-replay-snapshot-store-capture', data_get($matrix, 'rows.3.safe_recovery_command'));
        $this->assertStringContainsString('--atlas-self-construction-real-provider-smoke-offline-harness-status', data_get($matrix, 'rows.4.safe_recovery_command'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($matrix, 'rows.0.recovery_row_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $matrix['failure_recovery_matrix_hash']);
    }

    public function test_operator_next_action_shell_packet_is_copy_ready_only_after_placeholders_are_replaced(): void
    {
        $payload = (new AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService(app(AtlasSelfConstructionReadinessService::class)))->build();
        $packet = $payload['operator_next_action_shell_packet'];

        $this->assertSame('atlas.self_construction.final_operator_next_action_shell_packet.v1', $packet['schema_version']);
        $this->assertSame('read_only_final_operator_next_action_shell_packet', $packet['mode']);
        $this->assertSame('blocked_replace_placeholders_before_copy', $packet['status']);
        $this->assertSame('runtime_promotion_receipt', $packet['current_required_artifact']);
        $this->assertSame('draft_runtime_promotion_receipt', $packet['current_step_id']);
        $this->assertStringContainsString('--atlas-self-construction-runtime-promotion-receipt-draft-status', $packet['exact_command']);
        $this->assertTrue($packet['command_contains_placeholders']);
        $this->assertFalse($packet['safe_to_copy_after_operator_review']);
        $this->assertTrue($packet['operator_must_replace_placeholders']);
        $this->assertContains('<operator>', $packet['placeholder_fields_to_replace']);
        $this->assertSame(2, $packet['placeholder_replacement_contract_count']);
        $this->assertSame('<operator>', data_get($packet, 'placeholder_replacement_contract.0.placeholder'));
        $this->assertSame('operator_identity', data_get($packet, 'placeholder_replacement_contract.0.replacement_kind'));
        $this->assertContains('codex', data_get($packet, 'placeholder_replacement_contract.0.must_not_equal'));
        $this->assertSame('operator_reason', data_get($packet, 'placeholder_replacement_contract.1.replacement_kind'));
        $this->assertSame(32, data_get($packet, 'placeholder_replacement_contract.1.minimum_length'));
        $this->assertStringContainsString('--atlas-self-construction-final-operator-evidence-closure-corridor-status', $packet['preflight_command']);
        $this->assertSame('runtime_promotion_receipt_draft', data_get($packet, 'post_action_success_checks.0.surface'));
        $this->assertStringContainsString('ready_for_operator_persistence', data_get($packet, 'post_action_success_checks.0.expected'));
        $this->assertSame('atlas.self_construction.final_operator_next_action_post_action_verification_bundle.v1', data_get($packet, 'post_action_verification_bundle.schema_version'));
        $this->assertSame('verify_before_next_artifact_or_persist', data_get($packet, 'post_action_verification_bundle.status'));
        $this->assertSame('runtime_promotion_receipt', data_get($packet, 'post_action_verification_bundle.next_required_submission'));
        $this->assertGreaterThanOrEqual(2, data_get($packet, 'post_action_verification_bundle.verification_command_count'));
        $this->assertSame(3, data_get($packet, 'post_action_verification_bundle.success_check_count'));
        $this->assertStringContainsString(
            '--atlas-self-construction-os-completion-audit-status',
            json_encode(data_get($packet, 'post_action_verification_bundle.verification_commands'), JSON_THROW_ON_ERROR),
        );
        $this->assertTrue((bool) data_get($packet, 'post_action_verification_bundle.failure_policy.do_not_advance_to_next_artifact'));
        $this->assertTrue((bool) data_get($packet, 'post_action_verification_bundle.failure_policy.do_not_persist_receipt_until_verifier_green'));
        $this->assertFalse((bool) data_get($packet, 'post_action_verification_bundle.can_execute_from_bundle'));
        $this->assertFalse((bool) data_get($packet, 'post_action_verification_bundle.can_persist_from_bundle'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($packet, 'post_action_verification_bundle.verification_bundle_hash'));
        $this->assertSame('atlas.self_construction.final_operator_next_action_shell_packet_resume.v1', data_get($packet, 'resume_after_interruption.schema_version'));
        $this->assertSame('resume_by_rerunning_closure_corridor_status', data_get($packet, 'resume_after_interruption.status'));
        $this->assertSame('runtime_promotion_receipt', data_get($packet, 'resume_after_interruption.current_required_artifact'));
        $this->assertSame('draft_runtime_promotion_receipt', data_get($packet, 'resume_after_interruption.current_step_id'));
        $this->assertStringContainsString('--atlas-self-construction-final-operator-evidence-closure-corridor-status', data_get($packet, 'resume_after_interruption.resume_command'));
        $this->assertTrue((bool) data_get($packet, 'resume_after_interruption.requires_fresh_preflight_before_persist'));
        $this->assertTrue((bool) data_get($packet, 'resume_after_interruption.do_not_run_persist_command_until_verifier_green'));
        $this->assertTrue((bool) data_get($packet, 'resume_after_interruption.can_resume_without_chat_history'));
        $this->assertFalse((bool) data_get($packet, 'resume_after_interruption.can_execute_from_resume_contract'));
        $this->assertFalse((bool) data_get($packet, 'resume_after_interruption.can_persist_from_resume_contract'));
        $this->assertContains('do_not_persist_from_stale_chat_memory', data_get($packet, 'resume_after_interruption.forbidden_resume_shortcuts'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($packet, 'resume_after_interruption.resume_contract_hash'));
        $this->assertGreaterThanOrEqual(4, $packet['ordered_shell_command_count']);
        $this->assertSame(1, data_get($packet, 'ordered_shell_commands.0.order'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($packet, 'ordered_shell_commands.0.command_hash'));
        $this->assertContains('placeholder_signed_by', $packet['forbidden_shortcuts']);
        $this->assertFalse($packet['can_execute_from_packet']);
        $this->assertFalse($packet['can_persist_from_packet']);
        $this->assertFalse($packet['can_call_provider_from_packet']);
        $this->assertFalse($packet['can_sign_for_operator_from_packet']);
        $this->assertContains('next_action_shell_packet_does_not_execute_commands', $packet['non_execution_guarantees']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $packet['shell_packet_hash']);
    }

    public function test_readiness_status_json_and_cli_quartet_exist(): void
    {
        $readiness = app(AtlasSelfConstructionReadinessService::class);
        $status = $readiness->atlasSelfConstructionFinalOperatorEvidenceClosureCorridorStatus();

        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.v1', $status['schema_version']);
        $this->assertSame('blocked_operator_evidence_required', $status['status']);
        $this->assertSame(
            'blocked_operator_action_required',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_next_action_status'),
        );
        $this->assertSame(
            'draft_runtime_promotion_receipt',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_next_action_step_id'),
        );
        $this->assertSame(
            'runtime_promotion',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_next_action_phase'),
        );
        $this->assertStringContainsString(
            '--atlas-self-construction-runtime-promotion-receipt-draft-status',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_next_action_exact_command'),
        );
        $this->assertSame(
            '',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_next_action_exact_persist_command'),
        );
        $this->assertFalse((bool) data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_next_action_can_run_automatically'));
        $this->assertContains(
            '<operator>',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_next_action_placeholder_fields_to_replace'),
        );
        $this->assertSame(
            'requires_operator_signature_and_runtime_promotion_judgment',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_next_action_why_not_automatic'),
        );
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_next_action_hash'),
        );
        $this->assertSame(
            'blocked_operator_action_required',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_closure_handoff_status'),
        );
        $this->assertStringContainsString(
            '--atlas-self-construction-runtime-promotion-receipt-draft-status',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_closure_handoff_immediate_command'),
        );
        $this->assertSame(
            'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_closure_handoff_success_predicate'),
        );
        $this->assertSame(
            'runtime_promotion_receipt',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_closure_handoff_resumption_checkpoint_current_step'),
        );
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_closure_handoff_command_replay_hash'),
        );
        $this->assertSame(
            'runtime_promotion_receipt',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_closure_handoff_command_replay_current_step'),
        );
        $this->assertTrue((bool) data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_closure_handoff_can_resume_without_chat_history'));
        $this->assertTrue((bool) data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_closure_handoff_requires_fresh_preflight_before_persist'));
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_closure_handoff_resumption_checkpoint_hash'),
        );
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_closure_handoff_hash'),
        );
        $this->assertSame(
            'blocked_operator_driven_steps_remaining',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_execution_runbook_status'),
        );
        $this->assertSame(
            'draft_runtime_promotion_receipt',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_execution_runbook_current_step_id'),
        );
        $this->assertSame(
            16,
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_execution_runbook_step_count'),
        );
        $this->assertSame(
            4,
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_execution_runbook_blocked_artifact_count'),
        );
        $this->assertTrue((bool) data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_execution_runbook_can_resume_without_chat_history'));
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_execution_runbook_command_replay_hash'),
        );
        $this->assertFalse((bool) data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_execution_runbook_can_persist_from_runbook'));
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_execution_runbook_hash'),
        );
        $this->assertSame(
            'blocked_replace_placeholders_before_copy',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_next_action_shell_packet_status'),
        );
        $this->assertFalse((bool) data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_next_action_shell_packet_safe_to_copy_after_operator_review'));
        $this->assertGreaterThanOrEqual(
            4,
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_next_action_shell_packet_ordered_command_count'),
        );
        $this->assertSame(
            2,
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_next_action_shell_packet_placeholder_count'),
        );
        $this->assertSame(
            2,
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_next_action_shell_packet_placeholder_replacement_contract_count'),
        );
        $this->assertSame(
            3,
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_next_action_shell_packet_post_action_success_check_count'),
        );
        $this->assertSame(
            'verify_before_next_artifact_or_persist',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_next_action_shell_packet_post_action_verification_status'),
        );
        $this->assertGreaterThanOrEqual(
            2,
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_next_action_shell_packet_post_action_verification_command_count'),
        );
        $this->assertSame(
            3,
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_next_action_shell_packet_post_action_verification_success_check_count'),
        );
        $this->assertTrue((bool) data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_next_action_shell_packet_post_action_verification_failure_stops_advance'));
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_next_action_shell_packet_post_action_verification_hash'),
        );
        $this->assertSame(
            'resume_by_rerunning_closure_corridor_status',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_next_action_shell_packet_resume_status'),
        );
        $this->assertStringContainsString(
            '--atlas-self-construction-final-operator-evidence-closure-corridor-status',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_next_action_shell_packet_resume_command'),
        );
        $this->assertSame(
            'draft_runtime_promotion_receipt',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_next_action_shell_packet_resume_current_step_id'),
        );
        $this->assertTrue((bool) data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_next_action_shell_packet_requires_fresh_preflight_before_persist'));
        $this->assertTrue((bool) data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_next_action_shell_packet_do_not_persist_until_verifier_green'));
        $this->assertTrue((bool) data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_next_action_shell_packet_can_resume_without_chat_history'));
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_next_action_shell_packet_resume_contract_hash'),
        );
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_next_action_shell_packet_hash'),
        );
        $this->assertSame(
            'operator_recovery_guidance_available',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_failure_recovery_matrix_status'),
        );
        $this->assertSame(
            5,
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_failure_recovery_matrix_row_count'),
        );
        $this->assertTrue((bool) data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_failure_recovery_matrix_requires_fresh_corridor_status'));
        $this->assertFalse((bool) data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_failure_recovery_matrix_can_recover_from_matrix'));
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_failure_recovery_matrix_hash'),
        );
        $this->assertSame(
            'command_surface_aligned',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_command_surface_integrity_status'),
        );
        $this->assertGreaterThanOrEqual(
            20,
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_command_surface_integrity_command_count'),
        );
        $this->assertSame(
            0,
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_command_surface_integrity_missing_option_count'),
        );
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_command_surface_integrity_hash'),
        );
        $this->assertSame(
            'operator_evidence_remaining',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_completion_progress_meter_status'),
        );
        $this->assertGreaterThanOrEqual(
            1,
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_completion_progress_meter_blocked_artifact_count'),
        );
        $this->assertSame(
            'runtime_promotion_receipt',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_completion_progress_meter_current_required_artifact'),
        );
        $this->assertIsBool(data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_completion_progress_meter_technical_blockers_clear'));
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.operator_completion_progress_meter_hash'),
        );
        $this->assertContains(
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.closure_readiness_summary_status'),
            [
                'technical_closure_green_operator_evidence_remaining',
                'technical_closure_blocked',
            ],
        );
        $this->assertIsBool(data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.closure_readiness_summary_technical_closure_green'));
        $this->assertIsInt(data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.closure_readiness_summary_technical_blocker_count'));
        $this->assertGreaterThanOrEqual(
            1,
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.closure_readiness_summary_human_blocker_count'),
        );
        $this->assertSame(
            1,
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.closure_readiness_summary_real_provider_blocker_count'),
        );
        $this->assertSame(
            3,
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.closure_readiness_summary_operator_evidence_blocking_artifact_count'),
        );
        $this->assertSame(
            'draft_runtime_promotion_receipt',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.closure_readiness_summary_next_step_id'),
        );
        $this->assertFalse((bool) data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.closure_readiness_summary_can_self_promote_completion'));
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            data_get($status, 'agent_control_plane_atlas_self_construction_final_operator_evidence_closure_corridor_status.closure_readiness_summary_hash'),
        );
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
