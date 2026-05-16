<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimeGapMatrixService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionOperatorEvidenceSubmissionReadinessTest extends TestCase
{
    public function test_no_input_reports_runtime_promotion_receipt_as_next_required(): void
    {
        $payload = (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService(app(AtlasSelfConstructionReadinessService::class)))->build();

        $this->assertSame('atlas.self_construction.operator_evidence_submission_readiness.v1', $payload['schema_version']);
        $this->assertSame('read_only_operator_evidence_submission_readiness', $payload['mode']);
        $this->assertSame('no_input', $payload['status']);
        $this->assertSame('runtime_promotion_receipt', $payload['next_required']);
        $this->assertStringContainsString('--atlas-self-construction-runtime-promotion-receipt-draft-status', $payload['next_required_command']);
        $this->assertFalse($payload['completion_allowed']);
        $this->assertFalse($payload['completion_claim_allowed']);
        $this->assertFalse($payload['runtime_promotion_receipt_passed']);
        $this->assertFalse($payload['real_provider_smoke_passed']);
        $this->assertFalse($payload['human_completion_receipt_passed']);
        $this->assertFalse($payload['human_receipt_out_of_order']);
        $this->assertSame(
            'blocked_until_required_operator_submission_envelopes_are_ready',
            data_get($payload, 'operator_submission_envelopes.status'),
        );
        $this->assertSame('runtime_promotion_receipt', data_get($payload, 'operator_submission_envelopes.next_required_envelope'));
        $this->assertSame(3, data_get($payload, 'operator_submission_envelopes.required_envelope_count'));
        $this->assertSame(0, data_get($payload, 'operator_submission_envelopes.ready_envelope_count'));
        $this->assertSame(
            'blocked_until_operator_runtime_promotion_receipt_exists',
            data_get($payload, 'operator_submission_envelopes.runtime_promotion_receipt.status'),
        );
        $this->assertFalse(data_get($payload, 'operator_submission_envelopes.can_persist_from_readiness'));
        $this->assertSame('no_canonical_submission_files_loaded', data_get($payload, 'canonical_submission_persistence_plan.status'));
        $this->assertFalse(data_get($payload, 'canonical_submission_persistence_plan.can_persist_from_readiness'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'operator_submission_envelopes.operator_submission_envelopes_hash'));
        $this->assertNotEmpty($payload['submission_readiness_hash']);
    }

    public function test_invalid_runtime_receipt_keeps_next_required_at_runtime_promotion_with_violations(): void
    {
        Storage::fake('local');
        $payload = (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'runtime_promotion_receipt' => [
                'receipt_id' => 'rp-invalid',
                'signed_by' => '<operator>',
                'reason' => 'short',
                'runtime_gap_matrix_hash' => str_repeat('0', 64),
                'runtime_promotion_basis_hash' => str_repeat('0', 64),
                'runtime_promotion_closure_basis_hash' => str_repeat('0', 64),
                'promoted_gap_ids' => [],
                'graduation_evidence_hashes' => [],
                'receipt_hash' => str_repeat('0', 64),
                'runtime_promotion_approved' => true,
                'operator_reviewed_runtime_graduations' => true,
                'no_runtime_autopromotion_acknowledged' => true,
            ],
        ]);

        $this->assertSame('runtime_promotion_receipt', $payload['next_required']);
        $this->assertFalse($payload['runtime_promotion_receipt_passed']);
        $this->assertGreaterThan(0, (int) data_get($payload, 'diagnostics.runtime_promotion_receipt.violation_count', 0));
        $this->assertTrue(data_get($payload, 'diagnostics.runtime_promotion_receipt.supplied'));
        $this->assertFalse(data_get($payload, 'diagnostics.runtime_promotion_receipt.ready'));
    }

    public function test_invalid_real_provider_smoke_detects_missing_provider_cost_and_observation_flags(): void
    {
        $payload = (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'real_provider_smoke' => [
                'kind' => 'real_provider_packet_claim_to_completion',
                'status' => 'passed',
                'provider_run_id' => '<missing>',
                'task_packet_id' => '<missing>',
                'observed_by' => '<missing>',
                'approval_reason' => '<missing>',
                'smoke_hash' => str_repeat('0', 64),
                'operator_approval_receipt_hash' => '<missing>',
                'evidence_ledger_hash' => '<missing>',
                'work_product_manifest_hash' => '<missing>',
                'cost_event_hash' => '<missing>',
                'continuation_summary_hash' => '<missing>',
                'provider_response_hash' => '<missing>',
                'provider_call_observed' => false,
                'token_spend_observed' => false,
                'claim_to_completion_observed' => false,
                'work_product_collected' => false,
                'operator_supplied_evidence' => false,
                'real_provider_run_observed_by_operator' => false,
            ],
        ]);

        $errors = (array) data_get($payload, 'diagnostics.real_provider_smoke.errors', []);
        $this->assertContains('missing_or_placeholder_provider_run_id', $errors);
        $this->assertContains('missing_or_placeholder_cost_event_hash', $errors);
        $this->assertContains('missing_or_placeholder_work_product_manifest_hash', $errors);
        $this->assertContains('missing_or_placeholder_continuation_summary_hash', $errors);
        $this->assertContains('missing_observation_flag_provider_call_observed', $errors);
        $this->assertContains('missing_observation_flag_token_spend_observed', $errors);
        $this->assertContains('missing_observation_flag_work_product_collected', $errors);
        $this->assertFalse($payload['real_provider_smoke_passed']);
        $this->assertSame(
            'blocked_until_real_provider_smoke_verifier_passes',
            data_get($payload, 'operator_submission_envelopes.real_provider_smoke.status'),
        );
        $this->assertSame(
            'real_provider_smoke',
            data_get($payload, 'operator_submission_envelopes.real_provider_smoke.artifact'),
        );
        $this->assertStringContainsString(
            '--real-provider-smoke-json=@/path/to/real-provider-smoke.json',
            (string) data_get($payload, 'operator_submission_envelopes.real_provider_smoke.persist_command'),
        );
        $this->assertFalse(data_get($payload, 'operator_submission_envelopes.real_provider_smoke.ready_for_explicit_operator_persistence'));
        $this->assertFalse(data_get($payload, 'operator_submission_envelopes.real_provider_smoke.can_persist_from_readiness'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'operator_submission_envelopes.real_provider_smoke.operator_submission_envelope_hash'));
    }

    public function test_human_receipt_supplied_before_runtime_and_smoke_is_marked_out_of_order(): void
    {
        $payload = (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'completion_receipt' => [
                'receipt_id' => 'human-early',
                'signed_by' => 'operator-name-real',
                'reason' => 'Operator reviewed everything as if it was green.',
                'completion_audit_hash' => str_repeat('1', 64),
                'release_dossier_hash' => str_repeat('1', 64),
                'replay_diff_hash' => str_repeat('1', 64),
                'runtime_gap_matrix_hash' => str_repeat('1', 64),
                'runtime_promotion_receipt_hash' => str_repeat('1', 64),
                'real_provider_smoke_hash' => str_repeat('1', 64),
                'certification_status_batch_hash' => str_repeat('1', 64),
                'receipt_hash' => str_repeat('1', 64),
                'os_complete_approved' => true,
                'operator_reviewed_completion_audit' => true,
                'no_autopromotion_acknowledged' => true,
            ],
        ]);

        $this->assertTrue($payload['human_receipt_out_of_order']);
        $this->assertFalse(data_get($payload, 'diagnostics.human_completion_receipt.ready'));
        $this->assertContains(
            'human_completion_receipt_supplied_before_runtime_and_smoke_green',
            (array) data_get($payload, 'diagnostics.human_completion_receipt.errors', []),
        );
        $this->assertSame(
            'blocked_until_runtime_and_smoke_envelopes_are_green',
            data_get($payload, 'operator_submission_envelopes.human_completion_receipt.status'),
        );
        $this->assertSame(
            'completion_receipt',
            data_get($payload, 'operator_submission_envelopes.human_completion_receipt.source_option_key'),
        );
        $this->assertIsArray(data_get($payload, 'operator_submission_envelopes.human_completion_receipt.current_evidence_context'));
        $this->assertSame('runtime_promotion_receipt', $payload['next_required']);
    }

    public function test_forbidden_flags_in_real_provider_smoke_payload_are_flagged(): void
    {
        $payload = (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'real_provider_smoke' => [
                'kind' => 'real_provider_packet_claim_to_completion',
                'status' => 'passed',
                'provider_run_id' => 'pr-1',
                'task_packet_id' => 'tp-1',
                'observed_by' => 'operator-real',
                'approval_reason' => 'Operator approved this and observed it.',
                'smoke_hash' => str_repeat('0', 64),
                'operator_approval_receipt_hash' => str_repeat('0', 64),
                'evidence_ledger_hash' => str_repeat('0', 64),
                'work_product_manifest_hash' => str_repeat('0', 64),
                'cost_event_hash' => str_repeat('0', 64),
                'continuation_summary_hash' => str_repeat('0', 64),
                'provider_response_hash' => str_repeat('0', 64),
                'provider_call_observed' => true,
                'token_spend_observed' => true,
                'claim_to_completion_observed' => true,
                'work_product_collected' => true,
                'operator_supplied_evidence' => true,
                'real_provider_run_observed_by_operator' => true,
                'dispatch_allowed' => true,
                'self_programming_allowed' => true,
                'completion_claim_promoted_without_receipt' => true,
            ],
        ]);

        $flagsTrue = (array) data_get($payload, 'diagnostics.real_provider_smoke.forbidden_flags_true', []);
        $this->assertContains('dispatch_allowed', $flagsTrue);
        $this->assertContains('self_programming_allowed', $flagsTrue);
        $this->assertContains('completion_claim_promoted_without_receipt', $flagsTrue);
    }

    public function test_stale_runtime_context_hashes_are_detected_when_receipt_drifted(): void
    {
        $payload = (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'runtime_promotion_receipt' => [
                'receipt_id' => 'rp-stale',
                'signed_by' => 'operator-real',
                'reason' => 'Operator reviewed and signed under stale hashes.',
                'runtime_gap_matrix_hash' => str_repeat('f', 64),
                'runtime_promotion_basis_hash' => str_repeat('e', 64),
                'runtime_promotion_closure_basis_hash' => str_repeat('d', 64),
                'promoted_gap_ids' => [],
                'graduation_evidence_hashes' => [],
                'receipt_hash' => str_repeat('c', 64),
                'runtime_promotion_approved' => true,
                'operator_reviewed_runtime_graduations' => true,
                'no_runtime_autopromotion_acknowledged' => true,
            ],
        ]);

        $stale = (array) $payload['stale_context_hashes'];
        $this->assertContains('runtime_gap_matrix_hash', $stale);
        $this->assertContains('runtime_promotion_basis_hash', $stale);
        $this->assertContains('runtime_promotion_closure_basis_hash', $stale);
    }

    public function test_submission_readiness_can_load_operator_draft_workspace_without_persisting(): void
    {
        Storage::fake('local');
        $workspace = $this->writeDraftWorkspaceForSubmissionReadiness();

        $payload = (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'operator_draft_workspace_path' => $workspace,
        ]);

        $this->assertSame('loaded_for_read_only_submission_readiness', data_get($payload, 'draft_workspace_input.status'));
        $this->assertSame('workspace_safe_for_operator_editing', data_get($payload, 'draft_workspace_input.inspector_status'));
        $this->assertSame([
            'runtime_promotion_receipt',
            'real_provider_smoke',
            'human_completion_receipt',
        ], data_get($payload, 'draft_workspace_input.loaded_artifacts'));
        $this->assertTrue(data_get($payload, 'diagnostics.runtime_promotion_receipt.supplied'));
        $this->assertTrue(data_get($payload, 'diagnostics.real_provider_smoke.supplied'));
        $this->assertTrue(data_get($payload, 'diagnostics.human_completion_receipt.supplied'));
        $this->assertFalse(data_get($payload, 'operator_submission_envelopes.can_persist_from_readiness'));
        $this->assertFalse($payload['completion_allowed']);
        $this->assertFalse($payload['dispatch_allowed']);
        $this->assertFalse($payload['provider_call_allowed']);
        $this->assertFalse($payload['token_spend_allowed']);
        $this->assertFalse($payload['self_programming_allowed']);
        $this->assertSame('blocked_operator_drafts_not_ready_for_hash_write', data_get($payload, 'draft_hash_finalization.status'));
        $this->assertSame(3, data_get($payload, 'draft_hash_finalization.artifact_count'));
        $this->assertStringContainsString('--atlas-self-construction-operator-evidence-draft-hash-finalizer-status', data_get($payload, 'draft_hash_finalization.write_command'));
        $this->assertFalse($payload['draft_hash_finalization_required']);
    }

    public function test_submission_readiness_auto_loads_canonical_published_submission_files_without_persisting(): void
    {
        Storage::fake('local');
        $this->writeCanonicalSubmissionFilesForReadiness();

        $payload = (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService(app(AtlasSelfConstructionReadinessService::class)))->build();

        $this->assertSame('loaded_for_read_only_submission_readiness', data_get($payload, 'canonical_submission_input.status'));
        $this->assertEqualsCanonicalizing(
            ['runtime_promotion_receipt', 'real_provider_smoke', 'human_completion_receipt'],
            data_get($payload, 'canonical_submission_input.loaded_artifacts'),
        );
        $this->assertTrue(data_get($payload, 'diagnostics.runtime_promotion_receipt.supplied'));
        $this->assertTrue(data_get($payload, 'diagnostics.real_provider_smoke.supplied'));
        $this->assertTrue(data_get($payload, 'diagnostics.human_completion_receipt.supplied'));
        $this->assertSame('runtime_promotion_receipt', $payload['next_required']);
        $this->assertFalse(data_get($payload, 'canonical_submission_input.published_submission_json_is_evidence'));
        $this->assertFalse(data_get($payload, 'canonical_submission_input.can_persist_canonical_submission_files_directly'));
        $this->assertFalse(data_get($payload, 'operator_submission_envelopes.can_persist_from_readiness'));
        $this->assertSame(
            'blocked_until_canonical_submission_files_are_ready',
            data_get($payload, 'canonical_submission_persistence_plan.status'),
        );
        $this->assertTrue(data_get($payload, 'canonical_submission_persistence_plan.canonical_source_authoritative'));
        $this->assertTrue(data_get($payload, 'canonical_submission_persistence_plan.sequence_ordered'));
        $this->assertTrue(data_get($payload, 'canonical_submission_persistence_plan.requires_explicit_operator_persistence_commands'));
        $this->assertFalse(data_get($payload, 'canonical_submission_persistence_plan.can_persist_from_readiness'));
        $this->assertSame(
            [
                'persist_runtime_promotion_receipt',
                'persist_real_provider_smoke',
                'persist_human_completion_receipt',
                'rerun_completion_audit',
            ],
            array_column(data_get($payload, 'canonical_submission_persistence_plan.steps'), 'id'),
        );
        $this->assertStringContainsString(
            '--runtime-promotion-receipt-json=@storage/app/atlas/self-construction/operator-submissions/runtime-promotion.json',
            data_get($payload, 'canonical_submission_persistence_plan.steps.0.command'),
        );
        $this->assertStringContainsString(
            '--real-provider-smoke-json=@storage/app/atlas/self-construction/operator-submissions/real-provider-smoke.json',
            data_get($payload, 'canonical_submission_persistence_plan.steps.1.command'),
        );
        $this->assertStringContainsString(
            '--completion-receipt-json=@storage/app/atlas/self-construction/operator-submissions/completion-receipt.json',
            data_get($payload, 'canonical_submission_persistence_plan.steps.2.command'),
        );
        $this->assertSame(
            'canonical_submission_verifier_not_ready',
            data_get($payload, 'canonical_submission_persistence_plan.steps.0.blocker'),
        );
        $this->assertFalse($payload['completion_allowed']);
        $this->assertFalse($payload['dispatch_allowed']);
        $this->assertFalse($payload['provider_call_allowed']);
        $this->assertFalse($payload['token_spend_allowed']);
        $this->assertFalse($payload['self_programming_allowed']);

        $status = app(AtlasSelfConstructionReadinessService::class)->atlasSelfConstructionOperatorEvidenceSubmissionReadinessStatus();
        $this->assertSame(
            'loaded_for_read_only_submission_readiness',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.canonical_submission_input_status'),
        );
        $this->assertEqualsCanonicalizing(
            ['runtime_promotion_receipt', 'real_provider_smoke', 'human_completion_receipt'],
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.canonical_submission_loaded_artifacts'),
        );
        $this->assertSame(
            'blocked_until_canonical_submission_files_are_ready',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.canonical_submission_persistence_plan_status'),
        );
        $this->assertSame(
            'persist_runtime_promotion_receipt',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.canonical_submission_persistence_plan_next_step_id'),
        );
        $this->assertSame(
            4,
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.canonical_submission_persistence_plan_step_count'),
        );
        $this->assertTrue(data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.canonical_submission_persistence_plan_sequence_ordered'));
        $this->assertFalse(data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.canonical_submission_can_persist_from_readiness'));
    }

    public function test_submission_readiness_reports_when_workspace_hash_finalization_is_required(): void
    {
        Storage::fake('local');
        $runtimeMatrix = (new AtlasSelfConstructionRuntimeGapMatrixService(app(AtlasSelfConstructionReadinessService::class)))->matrix();
        $workspace = $this->writeDraftWorkspaceForSubmissionReadiness([
            'runtime_promotion_receipt' => [
                'receipt_id' => 'runtime-test',
                'signed_by' => 'operator-real',
                'reason' => 'Operator reviewed runtime promotion candidates and approves without enabling execution.',
                'runtime_gap_matrix_hash' => (string) data_get($runtimeMatrix, 'expected_runtime_gap_matrix_hash_for_promotion_receipt'),
                'runtime_promotion_basis_hash' => (string) data_get($runtimeMatrix, 'runtime_promotion_basis_hash'),
                'runtime_promotion_closure_basis_hash' => (string) data_get($runtimeMatrix, 'runtime_promotion_closure_basis_hash'),
                'promoted_gap_ids' => (array) data_get($runtimeMatrix, 'blocked_gap_ids', []),
                'graduation_evidence_hashes' => [],
                'receipt_hash' => '<operator_generated_64_hex_receipt_hash>',
                'runtime_promotion_approved' => true,
                'operator_reviewed_runtime_graduations' => true,
                'no_runtime_autopromotion_acknowledged' => true,
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'adapter_execution_allowed' => false,
                'self_programming_allowed' => false,
            ],
        ]);

        $payload = (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'operator_draft_workspace_path' => $workspace,
        ]);

        $this->assertTrue($payload['draft_hash_finalization_required']);
        $this->assertTrue(data_get($payload, 'draft_hash_finalization.artifacts.runtime_promotion_receipt.can_write_hash_to_draft'));
        $this->assertFalse(data_get($payload, 'draft_hash_finalization.artifacts.runtime_promotion_receipt.input_hash_matches_computed_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'draft_hash_finalization.artifacts.runtime_promotion_receipt.computed_hash'));
        $this->assertStringContainsString('--write-computed-operator-draft-hashes', data_get($payload, 'draft_hash_finalization.write_command'));
        $this->assertFalse(data_get($payload, 'draft_hash_finalization.can_write_from_submission_readiness'));
    }

    public function test_submission_readiness_recommends_refresh_for_stale_draft_workspace_hashes(): void
    {
        Storage::fake('local');
        $workspace = $this->writeDraftWorkspaceForSubmissionReadiness([
            'runtime_promotion_receipt' => [
                'receipt_id' => 'runtime-test',
                'signed_by' => 'operator-real',
                'reason' => 'Operator reviewed and signed under stale hashes.',
                'runtime_gap_matrix_hash' => str_repeat('1', 64),
                'runtime_promotion_basis_hash' => str_repeat('2', 64),
                'runtime_promotion_closure_basis_hash' => str_repeat('3', 64),
                'receipt_hash' => '<hash>',
            ],
        ]);

        $payload = (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'operator_draft_workspace_path' => $workspace,
        ]);

        $this->assertTrue($payload['draft_workspace_refresh_required']);
        $this->assertSame(
            'refresh_recommended_before_operator_signature',
            data_get($payload, 'draft_workspace_refresh.status'),
        );
        $this->assertContains(
            'runtime_promotion_receipt_context_hashes_are_stale',
            data_get($payload, 'draft_workspace_refresh.reasons'),
        );
        $this->assertContains('runtime_gap_matrix_hash', data_get($payload, 'draft_workspace_refresh.stale_context_hashes'));
        $this->assertStringContainsString(
            '--persist-operator-draft-workspace',
            (string) data_get($payload, 'draft_workspace_refresh.refresh_command'),
        );
        $this->assertFalse(data_get($payload, 'draft_workspace_refresh.can_refresh_from_readiness'));
        $this->assertFalse(data_get($payload, 'draft_workspace_refresh.can_persist_evidence_from_refresh'));
    }

    public function test_cli_submission_readiness_loads_operator_draft_workspace_path(): void
    {
        Storage::fake('local');
        $workspace = $this->writeDraftWorkspaceForSubmissionReadiness();

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-operator-evidence-submission-readiness-status' => true,
            '--operator-draft-workspace-path' => $workspace,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('loaded_for_read_only_submission_readiness', data_get($decoded, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness.draft_workspace_input.status'));
        $this->assertSame([
            'runtime_promotion_receipt',
            'real_provider_smoke',
            'human_completion_receipt',
        ], data_get($decoded, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.draft_workspace_loaded_artifacts'));
        $this->assertFalse(data_get($decoded, 'agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.draft_workspace_refresh_required'));
        $this->assertFalse($decoded['dispatch_allowed']);
    }

    public function test_readiness_status_and_cli_quartet_exist(): void
    {
        $status = app(AtlasSelfConstructionReadinessService::class)->atlasSelfConstructionOperatorEvidenceSubmissionReadinessStatus();

        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.v1', $status['schema_version']);
        $this->assertSame('no_input', $status['status']);
        $this->assertFalse($status['execution_allowed']);

        foreach ([
            'atlas-self-construction-operator-evidence-submission-readiness-contract',
            'atlas-self-construction-operator-evidence-submission-readiness-preflight',
            'atlas-self-construction-operator-evidence-submission-readiness-implementation-packet',
            'atlas-self-construction-operator-evidence-submission-readiness-status',
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

    public function test_agent_control_plane_lists_submission_readiness_capabilities(): void
    {
        $payload = app(AtlasSelfConstructionReadinessService::class)->agentControlPlane();
        $capabilities = (array) data_get($payload, 'control_plane.current_capability', []);

        foreach ([
            'atlas_self_construction_operator_evidence_submission_readiness_contract',
            'atlas_self_construction_operator_evidence_submission_readiness_preflight',
            'atlas_self_construction_operator_evidence_submission_readiness_implementation_packet',
            'atlas_self_construction_operator_evidence_submission_readiness_service',
            'atlas_self_construction_operator_evidence_submission_readiness_status_projection',
        ] as $capability) {
            $this->assertContains($capability, $capabilities);
        }
    }

    /** @param array<string, array<string, mixed>> $overrides */
    private function writeDraftWorkspaceForSubmissionReadiness(array $overrides = []): string
    {
        $workspace = 'atlas/self-construction/operator-submissions/draft-workspaces/20260515-010000-readiness';
        $artifacts = [
            'runtime_promotion_receipt' => ['receipt_id' => 'runtime-test', 'signed_by' => '<operator>', 'receipt_hash' => '<hash>'],
            'real_provider_smoke' => ['provider_run_id' => '<provider_run_id>', 'smoke_hash' => '<hash>'],
            'human_completion_receipt' => ['receipt_id' => 'completion-test', 'signed_by' => '<operator>', 'receipt_hash' => '<hash>'],
        ];
        foreach ($overrides as $artifact => $override) {
            $artifacts[$artifact] = array_merge($artifacts[$artifact] ?? [], $override);
        }
        $files = [];

        foreach ($artifacts as $artifact => $payload) {
            $filename = match ($artifact) {
                'runtime_promotion_receipt' => 'runtime-promotion.json',
                'real_provider_smoke' => 'real-provider-smoke.json',
                default => 'completion-receipt.json',
            };
            $path = $workspace.'/'.$filename;
            $json = $this->draftJson($payload);
            Storage::disk('local')->put($path, $json);
            $files[] = [
                'artifact' => $artifact,
                'draft_path' => $path,
                'payload_template_json_sha256' => hash('sha256', $json),
                'draft_file_sha256' => hash('sha256', $json),
                'command_to_verify_draft_file' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
                'draft_is_evidence' => false,
                'can_persist_draft_directly' => false,
            ];
        }

        Storage::disk('local')->put($workspace.'/manifest.json', $this->draftJson([
            'schema_version' => 'atlas.self_construction.operator_evidence_draft_workspace.v1',
            'workspace_directory' => $workspace,
            'files' => $files,
        ]));

        return $workspace;
    }

    private function writeCanonicalSubmissionFilesForReadiness(): void
    {
        Storage::disk('local')->put('atlas/self-construction/operator-submissions/runtime-promotion.json', $this->draftJson([
            'receipt_id' => 'runtime-canonical',
            'signed_by' => '<operator>',
            'receipt_hash' => '<hash>',
        ]));
        Storage::disk('local')->put('atlas/self-construction/operator-submissions/real-provider-smoke.json', $this->draftJson([
            'provider_run_id' => '<provider_run_id>',
            'smoke_hash' => '<hash>',
        ]));
        Storage::disk('local')->put('atlas/self-construction/operator-submissions/completion-receipt.json', $this->draftJson([
            'receipt_id' => 'completion-canonical',
            'signed_by' => '<operator>',
            'receipt_hash' => '<hash>',
        ]));
    }

    /** @param array<string, mixed> $payload */
    private function draftJson(array $payload): string
    {
        ksort($payload);

        return (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
