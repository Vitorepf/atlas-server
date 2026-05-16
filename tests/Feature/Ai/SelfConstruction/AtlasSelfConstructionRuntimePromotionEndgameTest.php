<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimePromotionEndgameService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimePromotionReceiptDraftService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionRuntimePromotionEndgameTest extends TestCase
{
    public function test_endgame_blocks_without_receipt(): void
    {
        $payload = (new AtlasSelfConstructionRuntimePromotionEndgameService(app(AtlasSelfConstructionReadinessService::class)))->build();

        $this->assertSame('atlas.self_construction.runtime_promotion_endgame.v1', $payload['schema_version']);
        $this->assertSame('read_only_runtime_promotion_endgame', $payload['mode']);
        $this->assertSame('blocked_runtime_promotion_receipt_required', $payload['status']);
        $this->assertFalse($payload['completion_allowed']);
        $this->assertFalse($payload['completion_claim_allowed']);
        $this->assertFalse($payload['execution_allowed']);
        $this->assertFalse($payload['dispatch_allowed']);
        $this->assertFalse($payload['provider_call_allowed']);
        $this->assertFalse($payload['token_spend_allowed']);
        $this->assertFalse($payload['adapter_execution_allowed']);
        $this->assertFalse($payload['self_programming_allowed']);
        $this->assertNotEmpty($payload['endgame_hash']);
    }

    public function test_endgame_generates_draft_when_signed_by_and_reason_supplied(): void
    {
        $payload = (new AtlasSelfConstructionRuntimePromotionEndgameService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'signed_by' => 'operator-endgame-real',
            'reason' => 'Operator generated endgame draft for runtime promotion review test scenario.',
        ]);

        $this->assertTrue(data_get($payload, 'receipt_draft.requested'));
        $this->assertNotEmpty(data_get($payload, 'receipt_draft.draft_hash'));
        $this->assertContains(data_get($payload, 'receipt_draft.status'), [
            'ready_for_operator_persistence',
            'blocked_operator_input_required',
        ]);
    }

    public function test_endgame_lists_blocked_gaps_consistently_with_promoted_gap_ids(): void
    {
        $payload = (new AtlasSelfConstructionRuntimePromotionEndgameService(app(AtlasSelfConstructionReadinessService::class)))->build();

        $this->assertSameSize($payload['blocked_gap_ids'], $payload['promoted_gap_ids']);
        $this->assertSame($payload['blocked_gap_ids'], $payload['promoted_gap_ids']);
        $this->assertSame(count($payload['blocked_gap_ids']), $payload['runtime_gap_count']);
        foreach ($payload['blocked_gap_ids'] as $gapId) {
            $this->assertIsString($gapId);
            $this->assertNotSame('', $gapId);
            $this->assertArrayHasKey($gapId, $payload['graduation_evidence_hashes']);
        }
    }

    public function test_endgame_exposes_expected_runtime_gap_matrix_hash_for_promotion_receipt(): void
    {
        $payload = (new AtlasSelfConstructionRuntimePromotionEndgameService(app(AtlasSelfConstructionReadinessService::class)))->build();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $payload['expected_runtime_gap_matrix_hash_for_promotion_receipt']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $payload['current_runtime_gap_matrix_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $payload['runtime_promotion_basis_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $payload['runtime_promotion_closure_basis_hash']);
    }

    public function test_endgame_does_not_persist_without_explicit_flag(): void
    {
        Storage::fake('local');
        $payload = (new AtlasSelfConstructionRuntimePromotionEndgameService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'signed_by' => 'operator-endgame-real',
            'reason' => 'Operator drafted endgame but did not request persistence in this test scenario.',
        ]);

        $this->assertFalse($payload['persisted']);
        $this->assertSame([], (array) data_get($payload, 'persistence_result', []));
        $this->assertSame('persistence_flag_not_supplied', (string) data_get($payload, 'persistence_preflight.persistence_blocked_reason'));
        $this->assertSame([], Storage::disk('local')->allFiles('atlas/self-construction/os-completion/runtime-promotion-receipts'));
    }

    public function test_endgame_accepts_operator_supplied_receipt_without_regenerating_draft(): void
    {
        Storage::fake('local');
        [$matrix, $receipt] = $this->runtimePromotionReceiptFixture();

        $payload = (new AtlasSelfConstructionRuntimePromotionEndgameService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'runtime_gap_matrix' => $matrix,
            'runtime_promotion_receipt' => $receipt,
            'skip_completion_surfaces' => true,
        ]);

        $this->assertSame('receipt_verifier_passed_ready_for_explicit_persistence', $payload['status']);
        $this->assertSame('operator_supplied_runtime_promotion_receipt', data_get($payload, 'receipt_under_review.source'));
        $this->assertTrue(data_get($payload, 'receipt_under_review.provided'));
        $this->assertSame('not_drafted', data_get($payload, 'receipt_draft.status'));
        $this->assertSame('passed', data_get($payload, 'receipt_pre_submission_verification.status'));
        $this->assertTrue(data_get($payload, 'receipt_pre_submission_verification.can_persist'));
        $this->assertSame('ready_for_explicit_operator_persistence', data_get($payload, 'operator_submission_envelope.status'));
        $this->assertSame($receipt['receipt_hash'], data_get($payload, 'operator_submission_envelope.receipt_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'operator_submission_envelope.receipt_json_sha256'));
        $this->assertSame(
            'storage/app/atlas/self-construction/operator-submissions/runtime-promotion.json',
            data_get($payload, 'operator_submission_envelope.receipt_file_hint')
        );
        $this->assertStringContainsString(
            '@storage/app/atlas/self-construction/operator-submissions/runtime-promotion.json',
            (string) data_get($payload, 'operator_submission_envelope.exact_persist_command')
        );
        $this->assertStringNotContainsString(
            'runtime-promotion-receipt.json',
            (string) data_get($payload, 'operator_submission_envelope.exact_persist_command')
        );
        $this->assertStringContainsString('--persist-runtime-promotion-receipt', (string) data_get($payload, 'operator_submission_envelope.exact_persist_command'));
        $this->assertFalse($payload['persisted']);
        $this->assertSame('persistence_flag_not_supplied', data_get($payload, 'persistence_preflight.persistence_blocked_reason'));
    }

    public function test_endgame_loads_runtime_promotion_receipt_from_operator_draft_workspace_without_persisting(): void
    {
        Storage::fake('local');
        [$matrix, $receipt] = $this->runtimePromotionReceiptFixture();
        $workspace = 'atlas/self-construction/operator-submissions/draft-workspaces/test-runtime-endgame';
        Storage::disk('local')->put($workspace.'/runtime-promotion.json', json_encode($receipt, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
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
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        $payload = (new AtlasSelfConstructionRuntimePromotionEndgameService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'runtime_gap_matrix' => $matrix,
            'operator_draft_workspace_path' => $workspace,
            'skip_completion_surfaces' => true,
        ]);

        $this->assertSame('receipt_verifier_passed_ready_for_explicit_persistence', $payload['status'], json_encode($payload['operator_draft_workspace_receipt'], JSON_THROW_ON_ERROR));
        $this->assertSame('operator_draft_workspace_runtime_promotion_receipt', data_get($payload, 'receipt_under_review.source'));
        $this->assertTrue(data_get($payload, 'receipt_under_review.provided'));
        $this->assertFalse(data_get($payload, 'receipt_under_review.provided_by_cli_payload'));
        $this->assertTrue(data_get($payload, 'receipt_under_review.provided_by_operator_draft_workspace'));
        $this->assertSame('loaded_for_endgame_review', data_get($payload, 'operator_draft_workspace_receipt.status'));
        $this->assertSame($workspace.'/runtime-promotion.json', data_get($payload, 'operator_draft_workspace_receipt.draft_path'));
        $this->assertSame($receipt['receipt_hash'], data_get($payload, 'operator_submission_envelope.receipt_hash'));
        $this->assertSame('passed', data_get($payload, 'receipt_pre_submission_verification.status'));
        $this->assertSame('ready_for_explicit_operator_persistence', data_get($payload, 'operator_submission_envelope.status'));
        $this->assertFalse($payload['persisted']);
        $this->assertSame([], Storage::disk('local')->allFiles('atlas/self-construction/os-completion/runtime-promotion-receipts'));
    }

    public function test_endgame_can_persist_verified_receipt_loaded_from_operator_draft_workspace_only_with_explicit_flag(): void
    {
        Storage::fake('local');
        [$matrix, $receipt] = $this->runtimePromotionReceiptFixture();
        $draftPath = 'atlas/self-construction/operator-submissions/draft-workspaces/test-runtime-endgame/runtime-promotion.json';
        Storage::disk('local')->put($draftPath, json_encode($receipt, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        $payload = (new AtlasSelfConstructionRuntimePromotionEndgameService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'runtime_gap_matrix' => $matrix,
            'operator_draft_workspace_path' => $draftPath,
            'persist_runtime_promotion_receipt' => true,
            'skip_completion_surfaces' => true,
        ]);

        $this->assertSame('receipt_persisted_runtime_gap_matrix_should_be_rerun', $payload['status']);
        $this->assertSame('operator_draft_workspace_runtime_promotion_receipt', data_get($payload, 'receipt_under_review.source'));
        $this->assertTrue($payload['persisted']);
        $this->assertTrue(data_get($payload, 'persistence_result.persisted'));
        $this->assertSame('persisted_runtime_promotion_receipt', data_get($payload, 'operator_submission_envelope.status'));
        $this->assertNotEmpty(Storage::disk('local')->allFiles('atlas/self-construction/os-completion/runtime-promotion-receipts'));
    }

    public function test_endgame_loads_canonical_published_runtime_promotion_submission_without_persisting(): void
    {
        Storage::fake('local');
        [$matrix, $receipt] = $this->runtimePromotionReceiptFixture();
        Storage::disk('local')->put(
            'atlas/self-construction/operator-submissions/runtime-promotion.json',
            json_encode($receipt, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
        );

        $payload = (new AtlasSelfConstructionRuntimePromotionEndgameService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'runtime_gap_matrix' => $matrix,
            'skip_completion_surfaces' => true,
        ]);

        $this->assertSame('receipt_verifier_passed_ready_for_explicit_persistence', $payload['status']);
        $this->assertSame('canonical_published_runtime_promotion_receipt', data_get($payload, 'receipt_under_review.source'));
        $this->assertTrue(data_get($payload, 'receipt_under_review.provided'));
        $this->assertFalse(data_get($payload, 'receipt_under_review.provided_by_cli_payload'));
        $this->assertFalse(data_get($payload, 'receipt_under_review.provided_by_operator_draft_workspace'));
        $this->assertTrue(data_get($payload, 'receipt_under_review.provided_by_canonical_submission'));
        $this->assertSame('loaded_for_endgame_review', data_get($payload, 'canonical_submission_receipt.status'));
        $this->assertSame('atlas/self-construction/operator-submissions/runtime-promotion.json', data_get($payload, 'canonical_submission_receipt.submission_path'));
        $this->assertSame($receipt['receipt_hash'], data_get($payload, 'operator_submission_envelope.receipt_hash'));
        $this->assertSame('passed', data_get($payload, 'receipt_pre_submission_verification.status'));
        $this->assertSame('ready_for_explicit_operator_persistence', data_get($payload, 'operator_submission_envelope.status'));
        $this->assertFalse($payload['persisted']);
        $this->assertSame([], Storage::disk('local')->allFiles('atlas/self-construction/os-completion/runtime-promotion-receipts'));
    }

    public function test_endgame_reports_loaded_workspace_receipt_as_verifier_blocked_when_operator_fields_are_placeholders(): void
    {
        Storage::fake('local');
        [$matrix, $receipt] = $this->runtimePromotionReceiptFixture();
        $receipt['signed_by'] = '<operator>';
        $receipt['receipt_hash'] = '<operator_generated_64_hex_receipt_hash>';
        $draftPath = 'atlas/self-construction/operator-submissions/draft-workspaces/test-runtime-endgame/runtime-promotion.json';
        Storage::disk('local')->put($draftPath, json_encode($receipt, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        $payload = (new AtlasSelfConstructionRuntimePromotionEndgameService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'runtime_gap_matrix' => $matrix,
            'operator_draft_workspace_path' => $draftPath,
            'skip_completion_surfaces' => true,
        ]);

        $this->assertSame('receipt_loaded_verifier_blocked', $payload['status']);
        $this->assertSame('operator_draft_workspace_runtime_promotion_receipt', data_get($payload, 'receipt_under_review.source'));
        $this->assertSame('blocked', data_get($payload, 'receipt_pre_submission_verification.status'));
        $this->assertContains('signed_by', data_get($payload, 'receipt_pre_submission_verification.placeholder_fields'));
        $this->assertContains('receipt_hash', data_get($payload, 'receipt_pre_submission_verification.placeholder_fields'));
        $this->assertFalse($payload['persisted']);
    }

    public function test_endgame_persists_operator_supplied_receipt_only_with_explicit_flag_and_green_verifier(): void
    {
        Storage::fake('local');
        [$matrix, $receipt] = $this->runtimePromotionReceiptFixture();

        $payload = (new AtlasSelfConstructionRuntimePromotionEndgameService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'runtime_gap_matrix' => $matrix,
            'runtime_promotion_receipt' => $receipt,
            'persist_runtime_promotion_receipt' => true,
            'skip_completion_surfaces' => true,
        ]);

        $this->assertSame('receipt_persisted_runtime_gap_matrix_should_be_rerun', $payload['status']);
        $this->assertTrue($payload['persisted']);
        $this->assertTrue(data_get($payload, 'persistence_result.persisted'));
        $this->assertSame('passed', data_get($payload, 'persistence_result.status'));
        $this->assertSame('operator_supplied_runtime_promotion_receipt', data_get($payload, 'receipt_under_review.source'));
        $this->assertSame('persisted_runtime_promotion_receipt', data_get($payload, 'operator_submission_envelope.status'));
        $this->assertTrue(data_get($payload, 'operator_submission_envelope.persisted'));
        $this->assertNotEmpty(Storage::disk('local')->allFiles('atlas/self-construction/os-completion/runtime-promotion-receipts'));
    }

    public function test_anti_cheat_and_non_execution_guarantees_complete(): void
    {
        $payload = (new AtlasSelfConstructionRuntimePromotionEndgameService(app(AtlasSelfConstructionReadinessService::class)))->build();

        foreach ([
            'reject_runtime_autopromotion',
            'reject_stale_gap_matrix_hash',
            'reject_stale_closure_basis_hash',
            'reject_gap_id_drift',
            'reject_graduation_hash_mismatch',
            'reject_placeholder_operator',
            'reject_hash_mismatch',
            'reject_runtime_enabled_flags_true',
            'reject_completion_claim_from_runtime_receipt_alone',
            'reject_persistence_without_operator_submission_envelope',
        ] as $rule) {
            $this->assertContains($rule, $payload['anti_cheat_policy']);
        }

        foreach ([
            'endgame_does_not_autopromote_runtime',
            'endgame_does_not_persist_without_flag_and_verifier_green',
            'endgame_does_not_sign_for_operator',
            'endgame_does_not_call_codex_cli_or_app',
            'endgame_does_not_call_provider',
            'endgame_does_not_spend_tokens',
            'endgame_does_not_dispatch',
            'endgame_does_not_start_process',
            'endgame_does_not_enable_self_programming',
            'endgame_does_not_promote_completion',
            'endgame_does_not_declare_os_complete',
            'operator_submission_envelope_does_not_write_files_or_receipts',
        ] as $guarantee) {
            $this->assertContains($guarantee, $payload['non_execution_guarantees']);
        }
    }

    public function test_operator_decision_checklist_canonical_ids(): void
    {
        $payload = (new AtlasSelfConstructionRuntimePromotionEndgameService(app(AtlasSelfConstructionReadinessService::class)))->build();
        $ids = array_column($payload['operator_decision_checklist'], 'id');

        foreach ([
            'current_gap_ids_match_receipt',
            'graduation_hashes_match_receipt',
            'closure_basis_hash_current',
            'no_runtime_autopromotion_acknowledged',
            'operator_signature_present',
            'receipt_hash_canonical',
            'persistence_flag_explicit',
            'completion_audit_rerun_required',
        ] as $required) {
            $this->assertContains($required, $ids);
        }
    }

    public function test_readiness_status_json_and_cli_quartet(): void
    {
        $status = app(AtlasSelfConstructionReadinessService::class)->atlasSelfConstructionRuntimePromotionEndgameStatus();

        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_construction_runtime_promotion_endgame_status.v1', $status['schema_version']);
        $this->assertFalse($status['execution_allowed']);
        $this->assertFalse($status['dispatch_allowed']);

        foreach ([
            'atlas-self-construction-runtime-promotion-endgame-contract',
            'atlas-self-construction-runtime-promotion-endgame-preflight',
            'atlas-self-construction-runtime-promotion-endgame-implementation-packet',
            'atlas-self-construction-runtime-promotion-endgame-status',
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

    public function test_agent_control_plane_lists_endgame_capabilities(): void
    {
        $payload = app(AtlasSelfConstructionReadinessService::class)->agentControlPlane();
        $capabilities = (array) data_get($payload, 'control_plane.current_capability', []);

        foreach ([
            'atlas_self_construction_runtime_promotion_endgame_contract',
            'atlas_self_construction_runtime_promotion_endgame_preflight',
            'atlas_self_construction_runtime_promotion_endgame_implementation_packet',
            'atlas_self_construction_runtime_promotion_endgame_service',
            'atlas_self_construction_runtime_promotion_endgame_status_projection',
        ] as $capability) {
            $this->assertContains($capability, $capabilities);
        }
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function runtimePromotionReceiptFixture(): array
    {
        $matrix = $this->runtimeGapMatrixFixture();
        $draft = (new AtlasSelfConstructionRuntimePromotionReceiptDraftService)->build($matrix, [
            'signed_by' => 'operator-endgame-real',
            'reason' => 'Operator supplied runtime promotion receipt for endgame persistence coverage.',
        ]);

        $this->assertSame('ready_for_operator_persistence', $draft['status'], json_encode([
            'matrix_status' => $matrix['status'] ?? null,
            'runtime_gap_count' => $matrix['runtime_gap_count'] ?? null,
            'runtime_y_candidate_count' => $matrix['runtime_y_candidate_count'] ?? null,
            'blocked_gap_ids' => $matrix['blocked_gap_ids'] ?? [],
            'candidate_gap_ids' => $matrix['graduation_candidate_gap_ids'] ?? [],
            'missing_operator_inputs' => $draft['missing_operator_inputs'] ?? [],
            'verification_status' => data_get($draft, 'verification.status'),
            'violations' => data_get($draft, 'verification.violations', []),
        ], JSON_PRETTY_PRINT));

        return [$matrix, (array) $draft['receipt_payload']];
    }

    /** @return array<string, mixed> */
    private function runtimeGapMatrixFixture(): array
    {
        $rows = [
            $this->runtimeGapRow('adapter_execution_runtime', str_repeat('1', 64)),
            $this->runtimeGapRow('automatic_cost_import_runtime', str_repeat('2', 64)),
        ];

        return [
            'schema_version' => 'atlas.self_construction.runtime_gap_matrix.v1',
            'mode' => 'read_only_runtime_gap_matrix',
            'status' => 'blocked',
            'all_runtime_y' => false,
            'runtime_gap_count' => 2,
            'runtime_y_candidate_count' => 2,
            'runtime_enabled_count' => 0,
            'runtime_gap_matrix_hash' => str_repeat('a', 64),
            'expected_runtime_gap_matrix_hash_for_promotion_receipt' => str_repeat('b', 64),
            'runtime_promotion_basis_hash' => str_repeat('c', 64),
            'runtime_promotion_closure_basis_hash' => str_repeat('d', 64),
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
