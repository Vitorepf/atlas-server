<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionCompletionEvidenceHashService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherService;
use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_publisher_blocks_without_workspace_path(): void
    {
        $payload = (new AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherService)->publish();

        $this->assertSame('atlas.self_construction.operator_evidence_draft_workspace_publisher.v1', $payload['schema_version']);
        $this->assertSame('blocked_operator_draft_workspace_required', $payload['status']);
        $this->assertFalse($payload['workspace_loaded']);
        $this->assertFalse($payload['execution_allowed']);
        $this->assertFalse($payload['dispatch_allowed']);
        $this->assertFalse($payload['can_persist_from_publisher']);
        $this->assertTrue($payload['requires_explicit_operator_persistence_commands']);
        $this->assertSame(6, $payload['post_publish_persistence_step_count']);
    }

    public function test_publisher_refuses_unfinalized_hashes_without_writing(): void
    {
        [$workspace] = $this->writeWorkspace($this->readyDrafts());

        $payload = (new AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherService)->publish([
            'operator_draft_workspace_path' => $workspace,
        ]);

        $this->assertSame('blocked_operator_draft_workspace_not_publishable', $payload['status']);
        $this->assertSame(3, $payload['artifact_count']);
        $this->assertSame(0, $payload['publishable_artifact_count']);
        $this->assertSame('draft_hash_not_finalized', data_get($payload, 'artifacts.runtime_promotion_receipt.publish_blocker'));
        Storage::disk('local')->assertMissing('atlas/self-construction/operator-submissions/runtime-promotion.json');
        Storage::disk('local')->assertMissing('atlas/self-construction/os-completion/completion-evidence/registry.json');
    }

    public function test_publisher_copies_only_finalized_drafts_with_explicit_flag(): void
    {
        [$workspace] = $this->writeWorkspace($this->readyDrafts());
        (new AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService)->finalize([
            'operator_draft_workspace_path' => $workspace,
            'write_computed_operator_draft_hashes' => true,
        ]);

        $readOnly = (new AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherService)->publish([
            'operator_draft_workspace_path' => $workspace,
        ]);
        $this->assertSame('ready_to_publish_operator_draft_workspace', $readOnly['status']);
        $this->assertSame(3, $readOnly['publishable_artifact_count']);
        $this->assertTrue((bool) $readOnly['atomic_bundle_publish_required']);
        $this->assertTrue((bool) $readOnly['atomic_bundle_ready']);
        $this->assertSame(0, $readOnly['published_artifact_count']);

        $payload = (new AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherService)->publish([
            'operator_draft_workspace_path' => $workspace,
            'publish_operator_draft_workspace' => true,
        ]);

        $this->assertSame('operator_draft_workspace_published', $payload['status']);
        $this->assertSame(3, $payload['published_artifact_count']);
        $this->assertEqualsCanonicalizing([
            'runtime_promotion_receipt',
            'real_provider_smoke',
            'human_completion_receipt',
        ], $payload['published_artifacts']);

        $this->assertSame(
            Storage::disk('local')->get($workspace.'/runtime-promotion.json'),
            Storage::disk('local')->get('atlas/self-construction/operator-submissions/runtime-promotion.json'),
        );
        $this->assertSame(
            Storage::disk('local')->get($workspace.'/real-provider-smoke.json'),
            Storage::disk('local')->get('atlas/self-construction/operator-submissions/real-provider-smoke.json'),
        );
        $this->assertSame(
            Storage::disk('local')->get($workspace.'/completion-receipt.json'),
            Storage::disk('local')->get('atlas/self-construction/operator-submissions/completion-receipt.json'),
        );
        $this->assertFalse($payload['ledger_write_allowed']);
        $this->assertFalse($payload['runtime_write_allowed']);
        $this->assertFalse($payload['can_persist_from_publisher']);
        Storage::disk('local')->assertMissing('atlas/self-construction/os-completion/completion-evidence/registry.json');
    }

    public function test_publisher_accepts_private_storage_prefixed_workspace_path(): void
    {
        [$workspace] = $this->writeWorkspace($this->readyDrafts());
        (new AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService)->finalize([
            'operator_draft_workspace_path' => 'storage/app/private/'.$workspace,
            'write_computed_operator_draft_hashes' => true,
        ]);

        $payload = (new AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherService)->publish([
            'operator_draft_workspace_path' => 'storage/app/private/'.$workspace,
        ]);

        $this->assertSame('ready_to_publish_operator_draft_workspace', $payload['status']);
        $this->assertSame($workspace.'/manifest.json', $payload['manifest_path']);
        $this->assertSame(3, $payload['publishable_artifact_count']);
        $this->assertSame(0, $payload['published_artifact_count']);
        $this->assertFalse($payload['runtime_write_allowed']);
        $this->assertFalse($payload['can_persist_from_publisher']);
    }

    public function test_publisher_exposes_ordered_post_publish_persistence_sequence_without_executing_it(): void
    {
        [$workspace] = $this->writeWorkspace($this->readyDrafts());
        (new AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService)->finalize([
            'operator_draft_workspace_path' => $workspace,
            'write_computed_operator_draft_hashes' => true,
        ]);

        $payload = (new AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherService)->publish([
            'operator_draft_workspace_path' => $workspace,
            'publish_operator_draft_workspace' => true,
        ]);

        $this->assertSame('operator_draft_workspace_published', $payload['status']);
        $this->assertTrue($payload['post_publish_persistence_sequence_ordered']);
        $this->assertTrue($payload['requires_explicit_operator_persistence_commands']);
        $this->assertTrue($payload['human_receipt_persistence_requires_runtime_and_smoke_green']);
        $this->assertFalse($payload['can_persist_from_publisher']);
        $this->assertSame(6, $payload['post_publish_persistence_step_count']);

        $sequence = $payload['post_publish_persistence_sequence'];
        $this->assertSame([
            'persist_runtime_promotion_receipt',
            'persist_real_provider_smoke',
            'persist_human_completion_receipt',
            'refresh_terminal_loop_operational_proof',
            'rerun_completion_audit_with_terminal_loop_operational_proof',
            'rerun_completion_audit',
        ], array_column($sequence, 'id'));
        $this->assertSame([1, 2, 3, 4, 5, 6], array_column($sequence, 'order'));

        $this->assertStringContainsString(
            '--runtime-promotion-receipt-json=@storage/app/private/atlas/self-construction/operator-submissions/runtime-promotion.json',
            $sequence[0]['command'],
        );
        $this->assertSame('storage/app/private/atlas/self-construction/operator-submissions/runtime-promotion.json', $sequence[0]['canonical_submission_private_storage_path']);
        $this->assertStringContainsString('--persist-runtime-promotion-receipt', $sequence[0]['command']);
        $this->assertStringContainsString(
            '--real-provider-smoke-json=@storage/app/private/atlas/self-construction/operator-submissions/real-provider-smoke.json',
            $sequence[1]['command'],
        );
        $this->assertSame('storage/app/private/atlas/self-construction/operator-submissions/real-provider-smoke.json', $sequence[1]['canonical_submission_private_storage_path']);
        $this->assertStringContainsString('--persist-completion-evidence', $sequence[1]['command']);
        $this->assertStringContainsString(
            '--completion-receipt-json=@storage/app/private/atlas/self-construction/operator-submissions/completion-receipt.json',
            $sequence[2]['command'],
        );
        $this->assertSame('storage/app/private/atlas/self-construction/operator-submissions/completion-receipt.json', $sequence[2]['canonical_submission_private_storage_path']);
        $this->assertStringContainsString('terminal-loop-operational-proof-status', $sequence[3]['command']);
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-json=', $sequence[4]['command']);
        $this->assertStringContainsString('--atlas-self-construction-os-completion-audit-status', $sequence[5]['command']);
        $this->assertSame(['persist_runtime_promotion_receipt', 'persist_real_provider_smoke'], $sequence[2]['required_previous_steps']);
        $this->assertContains('refresh_terminal_loop_operational_proof', $sequence[4]['required_previous_steps']);

        foreach ($sequence as $step) {
            $this->assertFalse($step['can_run_from_publisher']);
        }

        Storage::disk('local')->assertMissing('atlas/self-construction/os-completion/completion-evidence/registry.json');
    }

    public function test_publisher_uses_all_or_nothing_bundle_publish(): void
    {
        $drafts = $this->readyDrafts();
        $drafts['human_completion_receipt']['completion_audit_hash'] = 'not-a-hash';
        [$workspace] = $this->writeWorkspace($drafts);
        (new AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService)->finalize([
            'operator_draft_workspace_path' => $workspace,
            'write_computed_operator_draft_hashes' => true,
        ]);

        $payload = (new AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherService)->publish([
            'operator_draft_workspace_path' => $workspace,
            'publish_operator_draft_workspace' => true,
        ]);

        $this->assertSame('blocked_operator_draft_workspace_not_publishable', $payload['status']);
        $this->assertSame(2, $payload['publishable_artifact_count']);
        $this->assertSame(0, $payload['published_artifact_count']);
        $this->assertTrue((bool) $payload['atomic_bundle_publish_required']);
        $this->assertFalse((bool) $payload['atomic_bundle_ready']);
        $this->assertSame(
            'required_evidence_hashes_invalid',
            data_get($payload, 'artifacts.human_completion_receipt.publish_blocker'),
        );
        Storage::disk('local')->assertMissing('atlas/self-construction/operator-submissions/runtime-promotion.json');
        Storage::disk('local')->assertMissing('atlas/self-construction/operator-submissions/real-provider-smoke.json');
        Storage::disk('local')->assertMissing('atlas/self-construction/operator-submissions/completion-receipt.json');
    }

    public function test_readiness_status_hides_a_durable_draft_workspace_publish(): void
    {
        [$workspace] = $this->writeWorkspace($this->readyDrafts());
        (new AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService)->finalize([
            'operator_draft_workspace_path' => $workspace,
            'write_computed_operator_draft_hashes' => true,
        ]);

        $status = app(AtlasSelfConstructionReadinessService::class)->atlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherStatus([
            'operator_draft_workspace_path' => $workspace,
            'publish_operator_draft_workspace' => true,
        ]);

        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_construction_operator_evidence_draft_workspace_publisher_status.v1', $status['schema_version']);
        $this->assertSame('read_only_agent_control_plane_atlas_self_construction_operator_evidence_draft_workspace_publisher_status', $status['mode']);
        $this->assertFalse((bool) $status['runtime_write_allowed']);
        $this->assertFalse((bool) $status['execution_allowed']);
        $this->assertFalse((bool) $status['dispatch_allowed']);
        $this->assertFalse((bool) $status['ledger_write_allowed']);
        $this->assertSame(
            'operator_draft_workspace_published',
            data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_draft_workspace_publisher_status.status'),
        );
        $this->assertSame(3, data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_draft_workspace_publisher_status.published_artifact_count'));
        $this->assertSame(
            Storage::disk('local')->get($workspace.'/runtime-promotion.json'),
            Storage::disk('local')->get('atlas/self-construction/operator-submissions/runtime-promotion.json'),
        );
        $this->assertSame(
            Storage::disk('local')->get($workspace.'/real-provider-smoke.json'),
            Storage::disk('local')->get('atlas/self-construction/operator-submissions/real-provider-smoke.json'),
        );
        $this->assertSame(
            Storage::disk('local')->get($workspace.'/completion-receipt.json'),
            Storage::disk('local')->get('atlas/self-construction/operator-submissions/completion-receipt.json'),
        );
    }

    public function test_readiness_status_cli_quartet_and_capabilities_are_exposed(): void
    {
        [$workspace] = $this->writeWorkspace($this->readyDrafts());
        (new AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService)->finalize([
            'operator_draft_workspace_path' => $workspace,
            'write_computed_operator_draft_hashes' => true,
        ]);

        $status = app(AtlasSelfConstructionReadinessService::class)->atlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherStatus([
            'operator_draft_workspace_path' => $workspace,
        ]);
        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_construction_operator_evidence_draft_workspace_publisher_status.v1', $status['schema_version']);
        $this->assertSame(3, data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_draft_workspace_publisher_status.publishable_artifact_count'));
        $this->assertFalse($status['execution_allowed']);
        $this->assertFalse($status['dispatch_allowed']);

        foreach ([
            '--atlas-self-construction-operator-evidence-draft-workspace-publisher-contract' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_operator_evidence_draft_workspace_publisher_contract.v1',
            '--atlas-self-construction-operator-evidence-draft-workspace-publisher-preflight' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_operator_evidence_draft_workspace_publisher_preflight.v1',
            '--atlas-self-construction-operator-evidence-draft-workspace-publisher-implementation-packet' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_operator_evidence_draft_workspace_publisher_implementation_packet.v1',
        ] as $flag => $schema) {
            $exit = Artisan::call('atlas:ai:self-construction', [$flag => true, '--json' => true]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit);
            $this->assertSame($schema, $payload['schema_version']);
            $this->assertFalse($payload['execution_allowed']);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-operator-evidence-draft-workspace-publisher-status' => true,
            '--operator-draft-workspace-path' => $workspace,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $exit);
        $this->assertSame('ready_to_publish_operator_draft_workspace', data_get($payload, 'agent_control_plane_atlas_self_construction_operator_evidence_draft_workspace_publisher_status.status'));
        $this->assertFalse($payload['runtime_write_allowed']);

        $capabilities = (array) data_get(app(AtlasSelfConstructionReadinessService::class)->agentControlPlane(), 'control_plane.current_capability', []);
        foreach ([
            'atlas_self_construction_operator_evidence_draft_workspace_publisher_contract',
            'atlas_self_construction_operator_evidence_draft_workspace_publisher_preflight',
            'atlas_self_construction_operator_evidence_draft_workspace_publisher_implementation_packet',
            'atlas_self_construction_operator_evidence_draft_workspace_publisher_service',
            'atlas_self_construction_operator_evidence_draft_workspace_publisher_status_projection',
        ] as $capability) {
            $this->assertContains($capability, $capabilities);
        }
    }

    /** @return array{0: string, 1: array<string, array<string, mixed>>} */
    private function writeWorkspace(array $drafts): array
    {
        $workspace = 'atlas/self-construction/operator-submissions/draft-workspaces/test-operator-publisher';
        $paths = [
            'runtime_promotion_receipt' => [$workspace.'/runtime-promotion.json', 'storage/app/atlas/self-construction/operator-submissions/runtime-promotion.json'],
            'real_provider_smoke' => [$workspace.'/real-provider-smoke.json', 'storage/app/atlas/self-construction/operator-submissions/real-provider-smoke.json'],
            'human_completion_receipt' => [$workspace.'/completion-receipt.json', 'storage/app/atlas/self-construction/operator-submissions/completion-receipt.json'],
        ];
        foreach ($paths as $artifact => [$path]) {
            Storage::disk('local')->put($path, json_encode($drafts[$artifact], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        Storage::disk('local')->put($workspace.'/manifest.json', json_encode([
            'schema_version' => 'atlas.self_construction.operator_evidence_draft_workspace.v1',
            'workspace_directory' => $workspace,
            'files' => array_map(
                static fn (string $artifact, array $paths): array => [
                    'artifact' => $artifact,
                    'draft_path' => $paths[0],
                    'recommended_file_path' => $paths[1],
                    'draft_is_evidence' => false,
                    'can_persist_draft_directly' => false,
                ],
                array_keys($paths),
                array_values($paths),
            ),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return [$workspace, $drafts];
    }

    /** @return array<string, array<string, mixed>> */
    private function readyDrafts(): array
    {
        $hash = new AtlasSelfConstructionCompletionEvidenceHashService;
        $runtime = [
            'receipt_id' => 'operator-runtime-promotion-test',
            'signed_by' => 'vitorepf',
            'reason' => 'Operator reviewed runtime candidates and approves promotion without enabling execution.',
            'runtime_gap_matrix_hash' => str_repeat('1', 64),
            'runtime_promotion_basis_hash' => str_repeat('2', 64),
            'runtime_promotion_closure_basis_hash' => str_repeat('3', 64),
            'promoted_gap_ids' => ['adapter_execution_runtime'],
            'graduation_evidence_hashes' => ['adapter_execution_runtime' => str_repeat('4', 64)],
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
        ];
        $smoke = [
            'kind' => 'real_provider_packet_claim_to_completion',
            'status' => 'passed',
            'provider_run_id' => 'provider-run-test',
            'task_packet_id' => 'task-packet-test',
            'observed_by' => 'vitorepf',
            'approval_reason' => 'Operator approved and observed one real provider claim-to-completion smoke.',
            'smoke_hash' => '<64_hex_smoke_hash_from_operator_approved_real_provider_smoke>',
            'operator_approval_receipt_hash' => str_repeat('5', 64),
            'evidence_ledger_hash' => str_repeat('6', 64),
            'work_product_manifest_hash' => str_repeat('7', 64),
            'cost_event_hash' => str_repeat('8', 64),
            'continuation_summary_hash' => str_repeat('9', 64),
            'provider_response_hash' => str_repeat('a', 64),
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
        ];
        $human = [
            'receipt_id' => 'operator-os-complete-test',
            'signed_by' => 'vitorepf',
            'reason' => 'Operator reviewed the final completion evidence and approves OS completion.',
            'completion_audit_hash' => str_repeat('b', 64),
            'release_dossier_hash' => str_repeat('c', 64),
            'replay_diff_hash' => str_repeat('d', 64),
            'runtime_gap_matrix_hash' => str_repeat('e', 64),
            'runtime_promotion_receipt_hash' => $hash->runtimePromotionReceiptHash($runtime),
            'real_provider_smoke_hash' => $hash->realProviderSmokeHash($smoke),
            'certification_status_batch_hash' => str_repeat('f', 64),
            'receipt_hash' => '<operator_generated_64_hex_receipt_hash>',
            'os_complete_approved' => true,
            'operator_reviewed_completion_audit' => true,
            'no_autopromotion_acknowledged' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'completion_autopromoted' => false,
        ];

        return [
            'runtime_promotion_receipt' => $runtime,
            'real_provider_smoke' => $smoke,
            'human_completion_receipt' => $human,
        ];
    }
}
