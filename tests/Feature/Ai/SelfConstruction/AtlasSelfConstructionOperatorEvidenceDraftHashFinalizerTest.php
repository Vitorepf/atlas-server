<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_workspace_finalizer_blocks_without_workspace_path(): void
    {
        $payload = (new AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService)->finalize();

        $this->assertSame('atlas.self_construction.operator_evidence_draft_hash_finalizer.v1', $payload['schema_version']);
        $this->assertSame('blocked_operator_draft_workspace_required', $payload['status']);
        $this->assertFalse($payload['workspace_loaded']);
        $this->assertFalse($payload['execution_allowed']);
        $this->assertFalse($payload['dispatch_allowed']);
        $this->assertContains('operator_draft_workspace_path_required', $payload['load_violations']);
    }

    public function test_workspace_finalizer_computes_all_hashes_without_writing_by_default(): void
    {
        [$workspace, $drafts] = $this->writeWorkspace($this->readyDrafts());
        $before = Storage::disk('local')->get($workspace.'/completion-receipt.json');

        $payload = (new AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService)->finalize([
            'operator_draft_workspace_path' => $workspace,
        ]);

        $this->assertSame('ready_to_write_computed_hashes', $payload['status']);
        $this->assertSame(3, $payload['artifact_count']);
        $this->assertSame(3, $payload['ready_artifact_count']);
        $this->assertSame(0, $payload['written_artifact_count']);
        foreach ([
            'runtime_promotion_receipt' => 'receipt_hash',
            'real_provider_smoke' => 'smoke_hash',
            'human_completion_receipt' => 'receipt_hash',
        ] as $artifact => $hashField) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, "artifacts.$artifact.computed_hash"));
            $this->assertSame(data_get($payload, "artifacts.$artifact.computed_hash"), data_get($payload, "artifacts.$artifact.payload_with_computed_hash.$hashField"));
            $this->assertFalse((bool) data_get($payload, "artifacts.$artifact.written"));
        }
        $this->assertSame($drafts['human_completion_receipt']['receipt_hash'], data_get($payload, 'artifacts.human_completion_receipt.original_hash'));
        $this->assertSame($before, Storage::disk('local')->get($workspace.'/completion-receipt.json'));
        Storage::disk('local')->assertMissing('atlas/self-construction/os-completion/completion-evidence/registry.json');
    }

    public function test_workspace_finalizer_writes_only_hash_fields_with_explicit_flag(): void
    {
        [$workspace, $drafts] = $this->writeWorkspace($this->readyDrafts());

        $payload = (new AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService)->finalize([
            'operator_draft_workspace_path' => $workspace.'/manifest.json',
            'write_computed_operator_draft_hashes' => true,
        ]);

        $this->assertSame('operator_draft_hashes_written', $payload['status']);
        $this->assertSame(3, $payload['written_artifact_count']);
        $this->assertEqualsCanonicalizing([
            'runtime_promotion_receipt',
            'real_provider_smoke',
            'human_completion_receipt',
        ], $payload['written_artifacts']);

        $runtime = $this->readJson($workspace.'/runtime-promotion.json');
        $smoke = $this->readJson($workspace.'/real-provider-smoke.json');
        $human = $this->readJson($workspace.'/completion-receipt.json');

        $this->assertSame(data_get($payload, 'artifacts.runtime_promotion_receipt.computed_hash'), $runtime['receipt_hash']);
        $this->assertSame(data_get($payload, 'artifacts.real_provider_smoke.computed_hash'), $smoke['smoke_hash']);
        $this->assertSame(data_get($payload, 'artifacts.human_completion_receipt.computed_hash'), $human['receipt_hash']);
        $this->assertSame($drafts['runtime_promotion_receipt']['signed_by'], $runtime['signed_by']);
        $this->assertSame($drafts['real_provider_smoke']['provider_run_id'], $smoke['provider_run_id']);
        $this->assertSame($drafts['human_completion_receipt']['reason'], $human['reason']);
        $this->assertFalse($runtime['dispatch_allowed']);
        $this->assertFalse($smoke['adapter_execution_allowed']);
        $this->assertFalse($human['self_programming_allowed']);
        Storage::disk('local')->assertMissing('atlas/self-construction/os-completion/completion-evidence/registry.json');
    }

    public function test_workspace_finalizer_accepts_private_storage_prefixed_workspace_path(): void
    {
        [$workspace] = $this->writeWorkspace($this->readyDrafts());

        $payload = (new AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService)->finalize([
            'operator_draft_workspace_path' => 'storage/app/private/'.$workspace,
        ]);

        $this->assertSame('ready_to_write_computed_hashes', $payload['status']);
        $this->assertSame($workspace.'/manifest.json', $payload['manifest_path']);
        $this->assertSame(3, $payload['artifact_count']);
        $this->assertSame(3, $payload['ready_artifact_count']);
        $this->assertSame(0, $payload['written_artifact_count']);
        $this->assertFalse($payload['execution_allowed']);
        $this->assertFalse($payload['dispatch_allowed']);
    }

    public function test_workspace_finalizer_blocks_only_unready_artifacts_and_allows_partial_ready_status(): void
    {
        $drafts = $this->readyDrafts();
        $drafts['real_provider_smoke']['provider_run_id'] = '<operator_observed_provider_run_id>';
        $drafts['human_completion_receipt']['dispatch_allowed'] = true;
        [$workspace] = $this->writeWorkspace($drafts);

        $payload = (new AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService)->finalize([
            'operator_draft_workspace_path' => $workspace,
        ]);

        $this->assertSame('partially_ready_to_write_computed_hashes', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'artifacts.runtime_promotion_receipt.can_write_hash_to_draft'));
        $this->assertFalse((bool) data_get($payload, 'artifacts.real_provider_smoke.can_write_hash_to_draft'));
        $this->assertFalse((bool) data_get($payload, 'artifacts.human_completion_receipt.can_write_hash_to_draft'));
        $this->assertContains('provider_run_id', data_get($payload, 'artifacts.real_provider_smoke.placeholder_fields'));
        $this->assertContains('dispatch_allowed', data_get($payload, 'artifacts.human_completion_receipt.forbidden_flags_true'));
    }

    public function test_readiness_status_cli_quartet_and_capabilities_are_exposed(): void
    {
        [$workspace] = $this->writeWorkspace($this->readyDrafts());

        $status = app(AtlasSelfConstructionReadinessService::class)->atlasSelfConstructionOperatorEvidenceDraftHashFinalizerStatus([
            'operator_draft_workspace_path' => $workspace,
        ]);
        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_construction_operator_evidence_draft_hash_finalizer_status.v1', $status['schema_version']);
        $this->assertSame(3, data_get($status, 'agent_control_plane_atlas_self_construction_operator_evidence_draft_hash_finalizer_status.ready_artifact_count'));
        $this->assertFalse($status['execution_allowed']);
        $this->assertFalse($status['dispatch_allowed']);

        foreach ([
            '--atlas-self-construction-operator-evidence-draft-hash-finalizer-contract' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_operator_evidence_draft_hash_finalizer_contract.v1',
            '--atlas-self-construction-operator-evidence-draft-hash-finalizer-preflight' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_operator_evidence_draft_hash_finalizer_preflight.v1',
            '--atlas-self-construction-operator-evidence-draft-hash-finalizer-implementation-packet' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_operator_evidence_draft_hash_finalizer_implementation_packet.v1',
        ] as $flag => $schema) {
            $exit = Artisan::call('atlas:ai:self-construction', [$flag => true, '--json' => true]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit);
            $this->assertSame($schema, $payload['schema_version']);
            $this->assertFalse($payload['execution_allowed']);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-operator-evidence-draft-hash-finalizer-status' => true,
            '--operator-draft-workspace-path' => $workspace,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $exit);
        $this->assertSame('ready_to_write_computed_hashes', data_get($payload, 'agent_control_plane_atlas_self_construction_operator_evidence_draft_hash_finalizer_status.status'));
        $this->assertFalse($payload['runtime_write_allowed']);

        $capabilities = (array) data_get(app(AtlasSelfConstructionReadinessService::class)->agentControlPlane(), 'control_plane.current_capability', []);
        foreach ([
            'atlas_self_construction_operator_evidence_draft_hash_finalizer_contract',
            'atlas_self_construction_operator_evidence_draft_hash_finalizer_preflight',
            'atlas_self_construction_operator_evidence_draft_hash_finalizer_implementation_packet',
            'atlas_self_construction_operator_evidence_draft_hash_finalizer_service',
            'atlas_self_construction_operator_evidence_draft_hash_finalizer_status_projection',
        ] as $capability) {
            $this->assertContains($capability, $capabilities);
        }
    }

    /** @return array{0: string, 1: array<string, array<string, mixed>>} */
    private function writeWorkspace(array $drafts): array
    {
        $workspace = 'atlas/self-construction/operator-submissions/draft-workspaces/test-operator-finalizer';
        $paths = [
            'runtime_promotion_receipt' => $workspace.'/runtime-promotion.json',
            'real_provider_smoke' => $workspace.'/real-provider-smoke.json',
            'human_completion_receipt' => $workspace.'/completion-receipt.json',
        ];
        foreach ($paths as $artifact => $path) {
            Storage::disk('local')->put($path, json_encode($drafts[$artifact], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        Storage::disk('local')->put($workspace.'/manifest.json', json_encode([
            'schema_version' => 'atlas.self_construction.operator_evidence_draft_workspace.v1',
            'workspace_directory' => $workspace,
            'files' => array_map(
                static fn (string $artifact, string $path): array => [
                    'artifact' => $artifact,
                    'draft_path' => $path,
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

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        return json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
