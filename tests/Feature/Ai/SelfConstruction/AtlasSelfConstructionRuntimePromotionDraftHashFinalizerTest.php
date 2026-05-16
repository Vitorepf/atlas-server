<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimePromotionDraftHashFinalizerService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimePromotionReceiptDraftService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionRuntimePromotionDraftHashFinalizerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_finalizer_blocks_without_operator_draft_workspace(): void
    {
        $payload = (new AtlasSelfConstructionRuntimePromotionDraftHashFinalizerService)->finalize();

        $this->assertSame('atlas.self_construction.runtime_promotion_draft_hash_finalizer.v1', $payload['schema_version']);
        $this->assertSame('blocked_operator_draft_workspace_required', $payload['status']);
        $this->assertFalse($payload['draft_loaded']);
        $this->assertFalse($payload['written']);
        $this->assertFalse($payload['execution_allowed']);
        $this->assertFalse($payload['dispatch_allowed']);
        $this->assertFalse($payload['runtime_write_allowed']);
        $this->assertContains('operator_draft_workspace_path_required', $payload['load_violations']);
    }

    public function test_finalizer_computes_hash_without_writing_by_default(): void
    {
        [$workspace, $receipt] = $this->writeDraftWorkspace($this->receiptWithPlaceholderHash());
        $before = Storage::disk('local')->get($workspace.'/runtime-promotion.json');

        $payload = (new AtlasSelfConstructionRuntimePromotionDraftHashFinalizerService)->finalize([
            'operator_draft_workspace_path' => $workspace,
        ]);

        $this->assertSame('ready_to_write_computed_hash', $payload['status']);
        $this->assertTrue($payload['draft_loaded']);
        $this->assertTrue($payload['can_write_hash_to_draft']);
        $this->assertFalse($payload['write_requested']);
        $this->assertFalse($payload['written']);
        $this->assertSame($receipt['receipt_hash'], $payload['original_receipt_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['computed_receipt_hash']);
        $this->assertSame($payload['computed_receipt_hash'], data_get($payload, 'payload_with_computed_hash.receipt_hash'));
        $this->assertSame($before, Storage::disk('local')->get($workspace.'/runtime-promotion.json'));
        $this->assertFalse($payload['execution_allowed']);
        $this->assertFalse($payload['runtime_write_allowed']);
        Storage::disk('local')->assertMissing('atlas/self-construction/os-completion/runtime-promotion-receipts/registry.json');
    }

    public function test_finalizer_writes_only_receipt_hash_with_explicit_flag(): void
    {
        [$workspace, $receipt] = $this->writeDraftWorkspace($this->receiptWithPlaceholderHash());

        $payload = (new AtlasSelfConstructionRuntimePromotionDraftHashFinalizerService)->finalize([
            'operator_draft_workspace_path' => $workspace.'/manifest.json',
            'write_computed_runtime_promotion_receipt_hash' => true,
        ]);
        $written = json_decode(Storage::disk('local')->get($workspace.'/runtime-promotion.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('draft_hash_written', $payload['status']);
        $this->assertTrue($payload['write_requested']);
        $this->assertTrue($payload['written']);
        $this->assertSame('', $payload['write_blocker']);
        $this->assertSame($payload['computed_receipt_hash'], $written['receipt_hash']);
        $this->assertNotSame($receipt['receipt_hash'], $written['receipt_hash']);
        $this->assertSame($receipt['signed_by'], $written['signed_by']);
        $this->assertSame($receipt['reason'], $written['reason']);
        $this->assertSame($receipt['promoted_gap_ids'], $written['promoted_gap_ids']);
        $this->assertFalse($written['execution_allowed']);
        $this->assertFalse($written['dispatch_allowed']);
        $this->assertNotSame($payload['draft_file_sha256_before'], $payload['draft_file_sha256_after']);
        Storage::disk('local')->assertMissing('atlas/self-construction/os-completion/runtime-promotion-receipts/registry.json');
    }

    public function test_finalizer_refuses_placeholder_operator_even_with_write_flag(): void
    {
        [$workspace, $receipt] = $this->writeDraftWorkspace(array_replace($this->receiptWithPlaceholderHash(), [
            'signed_by' => '<operator>',
        ]));

        $payload = (new AtlasSelfConstructionRuntimePromotionDraftHashFinalizerService)->finalize([
            'operator_draft_workspace_path' => $workspace.'/runtime-promotion.json',
            'write_computed_runtime_promotion_receipt_hash' => true,
        ]);
        $written = json_decode(Storage::disk('local')->get($workspace.'/runtime-promotion.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('blocked_operator_draft_not_ready_for_hash_write', $payload['status']);
        $this->assertSame('operator_identity_or_reason_not_ready', $payload['write_blocker']);
        $this->assertContains('signed_by', $payload['placeholder_fields']);
        $this->assertFalse($payload['written']);
        $this->assertSame($receipt['receipt_hash'], $written['receipt_hash']);
    }

    public function test_finalizer_refuses_runtime_enabling_flags_even_with_write_flag(): void
    {
        [$workspace] = $this->writeDraftWorkspace(array_replace($this->receiptWithPlaceholderHash(), [
            'dispatch_allowed' => true,
        ]));

        $payload = (new AtlasSelfConstructionRuntimePromotionDraftHashFinalizerService)->finalize([
            'operator_draft_workspace_path' => $workspace,
            'write_computed_runtime_promotion_receipt_hash' => true,
        ]);

        $this->assertSame('blocked_operator_draft_not_ready_for_hash_write', $payload['status']);
        $this->assertSame('runtime_enabling_flags_forbidden', $payload['write_blocker']);
        $this->assertContains('dispatch_allowed', $payload['forbidden_runtime_flags_true']);
        $this->assertFalse($payload['written']);
    }

    public function test_readiness_status_cli_quartet_and_agent_control_plane_capabilities(): void
    {
        [$workspace] = $this->writeDraftWorkspace($this->receiptWithPlaceholderHash());

        $status = app(AtlasSelfConstructionReadinessService::class)->atlasSelfConstructionRuntimePromotionDraftHashFinalizerStatus([
            'operator_draft_workspace_path' => $workspace,
        ]);
        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_construction_runtime_promotion_draft_hash_finalizer_status.v1', $status['schema_version']);
        $this->assertFalse($status['execution_allowed']);
        $this->assertFalse($status['dispatch_allowed']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($status, 'agent_control_plane_atlas_self_construction_runtime_promotion_draft_hash_finalizer_status.computed_receipt_hash'));

        foreach ([
            '--atlas-self-construction-runtime-promotion-draft-hash-finalizer-contract' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_runtime_promotion_draft_hash_finalizer_contract.v1',
            '--atlas-self-construction-runtime-promotion-draft-hash-finalizer-preflight' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_runtime_promotion_draft_hash_finalizer_preflight.v1',
            '--atlas-self-construction-runtime-promotion-draft-hash-finalizer-implementation-packet' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_runtime_promotion_draft_hash_finalizer_implementation_packet.v1',
        ] as $flag => $schema) {
            $exit = Artisan::call('atlas:ai:self-construction', [$flag => true, '--json' => true]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit);
            $this->assertSame($schema, $payload['schema_version']);
            $this->assertFalse($payload['execution_allowed']);
            $this->assertFalse($payload['dispatch_allowed']);
        }

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-runtime-promotion-draft-hash-finalizer-status' => true,
            '--operator-draft-workspace-path' => $workspace,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $exit);
        $this->assertSame('ready_to_write_computed_hash', data_get($payload, 'agent_control_plane_atlas_self_construction_runtime_promotion_draft_hash_finalizer_status.status'));
        $this->assertFalse($payload['runtime_write_allowed']);

        $capabilities = (array) data_get(app(AtlasSelfConstructionReadinessService::class)->agentControlPlane(), 'control_plane.current_capability', []);
        foreach ([
            'atlas_self_construction_runtime_promotion_draft_hash_finalizer_contract',
            'atlas_self_construction_runtime_promotion_draft_hash_finalizer_preflight',
            'atlas_self_construction_runtime_promotion_draft_hash_finalizer_implementation_packet',
            'atlas_self_construction_runtime_promotion_draft_hash_finalizer_service',
            'atlas_self_construction_runtime_promotion_draft_hash_finalizer_status_projection',
        ] as $capability) {
            $this->assertContains($capability, $capabilities);
        }
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function writeDraftWorkspace(array $receipt): array
    {
        $workspace = 'atlas/self-construction/operator-submissions/draft-workspaces/test-runtime-promotion-finalizer';
        Storage::disk('local')->put($workspace.'/runtime-promotion.json', json_encode($receipt, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        Storage::disk('local')->put($workspace.'/manifest.json', json_encode([
            'schema_version' => 'atlas.self_construction.operator_evidence_draft_workspace.v1',
            'workspace_directory' => $workspace,
            'files' => [
                [
                    'artifact' => 'runtime_promotion_receipt',
                    'draft_path' => $workspace.'/runtime-promotion.json',
                    'draft_is_evidence' => false,
                    'can_persist_draft_directly' => false,
                ],
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return [$workspace, $receipt];
    }

    /** @return array<string, mixed> */
    private function receiptWithPlaceholderHash(): array
    {
        $draft = (new AtlasSelfConstructionRuntimePromotionReceiptDraftService)->build($this->candidateMatrix(), [
            'signed_by' => 'vitorepf',
            'reason' => 'Operator reviewed the current runtime promotion candidates and approves this receipt without enabling execution directly.',
        ]);
        $receipt = (array) $draft['receipt_payload'];
        $receipt['receipt_hash'] = '<operator_generated_64_hex_receipt_hash>';

        return $receipt;
    }

    /** @return array<string, mixed> */
    private function candidateMatrix(): array
    {
        $rows = [
            $this->runtimeGapRow('adapter_execution_runtime', str_repeat('a', 64)),
            $this->runtimeGapRow('automatic_cost_import_runtime', str_repeat('b', 64)),
        ];

        return [
            'schema_version' => 'atlas.self_construction.runtime_gap_matrix.v1',
            'mode' => 'read_only_runtime_gap_matrix',
            'status' => 'blocked',
            'all_runtime_y' => false,
            'runtime_gap_count' => 2,
            'runtime_y_candidate_count' => 2,
            'runtime_enabled_count' => 0,
            'runtime_gap_matrix_hash' => str_repeat('1', 64),
            'expected_runtime_gap_matrix_hash_for_promotion_receipt' => str_repeat('2', 64),
            'runtime_promotion_basis_hash' => str_repeat('3', 64),
            'runtime_promotion_closure_basis_hash' => str_repeat('4', 64),
            'blocked_gap_ids' => ['adapter_execution_runtime', 'automatic_cost_import_runtime'],
            'graduation_candidate_gap_ids' => ['adapter_execution_runtime', 'automatic_cost_import_runtime'],
            'rows' => $rows,
        ];
    }

    /** @return array<string, mixed> */
    private function runtimeGapRow(string $gapId, string $graduationHash): array
    {
        return [
            'gap_id' => $gapId,
            'listed_as_gap' => true,
            'runtime_y' => false,
            'runtime_y_candidate' => true,
            'runtime_enabled' => false,
            'certification_status' => 'available',
            'evidence_hash' => $graduationHash,
            'graduation_schema' => 'atlas.self_construction.test_runtime_graduation.v1',
            'graduation_status' => 'passed',
            'graduation_evidence_hash' => $graduationHash,
            'promoted_by_runtime_receipt' => false,
            'required_promotion' => 'operator_signed_runtime_promotion',
            'blockers' => ['runtime_not_promoted_even_though_graduation_candidate_may_exist'],
        ];
    }
}
