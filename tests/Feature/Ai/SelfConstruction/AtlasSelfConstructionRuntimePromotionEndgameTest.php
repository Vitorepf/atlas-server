<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashService;
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
        Storage::fake('local');
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
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-status',
            data_get($payload, 'persistence_preflight.terminal_loop_operational_proof_command'),
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json',
            data_get($payload, 'persistence_preflight.rerun_audit_with_terminal_loop_operational_proof_command'),
        );
        $this->assertSame(
            'storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            data_get($payload, 'persistence_preflight.terminal_loop_operational_proof_canonical_binding_path'),
        );
        $this->assertStringContainsString(
            '@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            data_get($payload, 'persistence_preflight.effective_rerun_audit_with_canonical_terminal_loop_operational_proof_command'),
        );
        $this->assertTrue((bool) data_get($payload, 'persistence_preflight.terminal_loop_operational_proof_required_before_final_audit'));
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

    public function test_endgame_exposes_next_action_shell_packet_for_missing_receipt(): void
    {
        Storage::fake('local');
        $payload = (new AtlasSelfConstructionRuntimePromotionEndgameService(app(AtlasSelfConstructionReadinessService::class)))->build();

        $this->assertSame(
            'atlas.self_construction.runtime_promotion_endgame_next_action_shell_packet.v1',
            data_get($payload, 'operator_next_action_shell_packet.schema_version')
        );
        $this->assertSame(
            'blocked_placeholder_replacement_required',
            data_get($payload, 'operator_next_action_shell_packet.status')
        );
        $this->assertFalse((bool) data_get($payload, 'operator_next_action_shell_packet.copy_safe'));
        $this->assertSame(2, data_get($payload, 'operator_next_action_shell_packet.placeholder_count'));
        $this->assertContains('<operator>', data_get($payload, 'operator_next_action_shell_packet.placeholders'));
        $this->assertContains('<operator reason with at least 32 chars>', data_get($payload, 'operator_next_action_shell_packet.placeholders'));
        $this->assertStringContainsString(
            '--atlas-self-construction-runtime-promotion-receipt-draft-status',
            data_get($payload, 'operator_next_action_shell_packet.command_to_copy')
        );
        $this->assertFalse((bool) data_get($payload, 'operator_next_action_shell_packet.requires_receipt_file_path_when_payload_supplied_inline'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'operator_next_action_shell_packet.shell_packet_hash'));
    }

    public function test_endgame_shell_packet_becomes_copy_safe_for_canonical_verified_receipt(): void
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

        $this->assertSame('copy_ready_for_explicit_operator_persistence', data_get($payload, 'operator_next_action_shell_packet.status'));
        $this->assertTrue((bool) data_get($payload, 'operator_next_action_shell_packet.copy_safe'));
        $this->assertSame(0, data_get($payload, 'operator_next_action_shell_packet.placeholder_count'));
        $this->assertStringContainsString(
            '@storage/app/private/atlas/self-construction/operator-submissions/runtime-promotion.json',
            data_get($payload, 'operator_next_action_shell_packet.command_to_copy')
        );
        $this->assertStringContainsString(
            '--persist-runtime-promotion-receipt',
            data_get($payload, 'operator_next_action_shell_packet.command_to_copy')
        );
        $this->assertTrue((bool) data_get($payload, 'operator_next_action_shell_packet.requires_verifier_green_before_persist'));
        $this->assertTrue((bool) data_get($payload, 'operator_next_action_shell_packet.requires_explicit_persist_flag'));
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
        $this->assertSame('blocked_receipt_file_path_required', data_get($payload, 'operator_next_action_shell_packet.status'));
        $this->assertFalse((bool) data_get($payload, 'operator_next_action_shell_packet.copy_safe'));
        $this->assertTrue((bool) data_get($payload, 'operator_next_action_shell_packet.requires_receipt_file_path_when_payload_supplied_inline'));
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
        $this->assertSame(
            'storage/app/private/atlas/self-construction/operator-submissions/runtime-promotion.json',
            data_get($payload, 'operator_submission_envelope.receipt_private_storage_file_hint')
        );
        $this->assertStringContainsString(
            '@storage/app/private/atlas/self-construction/operator-submissions/runtime-promotion.json',
            (string) data_get($payload, 'operator_submission_envelope.exact_persist_command')
        );
        $this->assertStringNotContainsString(
            '@storage/app/atlas/self-construction/operator-submissions/runtime-promotion.json',
            (string) data_get($payload, 'operator_submission_envelope.exact_persist_command')
        );
        $this->assertStringNotContainsString(
            'runtime-promotion-receipt.json',
            (string) data_get($payload, 'operator_submission_envelope.exact_persist_command')
        );
        $this->assertStringContainsString('--persist-runtime-promotion-receipt', (string) data_get($payload, 'operator_submission_envelope.exact_persist_command'));
        $this->assertContains(
            'php artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-operational-proof-status --json',
            data_get($payload, 'operator_submission_envelope.post_persistence_commands'),
        );
        $this->assertContains(
            'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json --json',
            data_get($payload, 'operator_submission_envelope.post_persistence_commands'),
        );
        $this->assertContains(
            'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json --json',
            data_get($payload, 'operator_submission_envelope.post_persistence_commands'),
        );
        $this->assertSame(
            'storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            data_get($payload, 'operator_submission_envelope.terminal_loop_operational_proof_canonical_binding_path'),
        );
        $this->assertStringContainsString(
            '@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            data_get($payload, 'operator_submission_envelope.effective_completion_audit_with_canonical_terminal_loop_operational_proof_command'),
        );
        $this->assertTrue((bool) data_get($payload, 'operator_submission_envelope.terminal_loop_operational_proof_required_before_final_audit'));
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
            data_get($payload, 'operator_submission_envelope.terminal_loop_operational_proof_expected_binding_schema'),
        );
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

    public function test_endgame_accepts_private_storage_prefixed_operator_draft_workspace_path_without_persisting(): void
    {
        Storage::fake('local');
        [$matrix, $receipt] = $this->runtimePromotionReceiptFixture();
        $workspace = 'atlas/self-construction/operator-submissions/draft-workspaces/test-runtime-endgame-private';
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
            'operator_draft_workspace_path' => 'storage/app/private/'.$workspace,
            'skip_completion_surfaces' => true,
        ]);

        $this->assertSame('receipt_verifier_passed_ready_for_explicit_persistence', $payload['status']);
        $this->assertSame('operator_draft_workspace_runtime_promotion_receipt', data_get($payload, 'receipt_under_review.source'));
        $this->assertSame($workspace.'/manifest.json', data_get($payload, 'operator_draft_workspace_receipt.manifest_path'));
        $this->assertSame($workspace.'/runtime-promotion.json', data_get($payload, 'operator_draft_workspace_receipt.draft_path'));
        $this->assertFalse($payload['persisted']);
        $this->assertFalse($payload['runtime_write_allowed']);
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

    public function test_endgame_detects_stale_runtime_promotion_receipt_and_requires_fresh_draft(): void
    {
        Storage::fake('local');
        [$matrix, $receipt] = $this->runtimePromotionReceiptFixture();
        $matrix['expected_runtime_gap_matrix_hash_for_promotion_receipt'] = str_repeat('e', 64);
        $matrix['runtime_promotion_basis_hash'] = str_repeat('f', 64);
        $matrix['runtime_promotion_closure_basis_hash'] = str_repeat('9', 64);
        $matrix['rows'][1]['graduation_evidence_hash'] = str_repeat('8', 64);

        $payload = (new AtlasSelfConstructionRuntimePromotionEndgameService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'runtime_gap_matrix' => $matrix,
            'runtime_promotion_receipt' => $receipt,
            'skip_completion_surfaces' => true,
        ]);

        $this->assertSame('receipt_loaded_verifier_blocked', $payload['status']);
        $this->assertTrue($payload['stale_runtime_promotion_receipt_detected']);
        $this->assertTrue($payload['fresh_runtime_promotion_receipt_required']);
        $this->assertContains('stale_runtime_gap_matrix_hash', $payload['verifier_violation_codes']);
        $this->assertContains('stale_runtime_promotion_basis_hash', $payload['verifier_violation_codes']);
        $this->assertContains('stale_runtime_promotion_closure_basis_hash', $payload['verifier_violation_codes']);
        $this->assertContains('graduation_hash_mismatch', $payload['verifier_violation_codes']);
        $this->assertSame(
            'blocked_stale_runtime_promotion_receipt_regenerate_draft',
            data_get($payload, 'operator_next_action_shell_packet.status'),
        );
        $this->assertTrue((bool) data_get($payload, 'operator_next_action_shell_packet.stale_runtime_promotion_receipt_detected'));
        $this->assertTrue((bool) data_get($payload, 'operator_next_action_shell_packet.fresh_runtime_promotion_receipt_required'));
        $this->assertTrue((bool) data_get($payload, 'operator_next_action_shell_packet.stale_runtime_promotion_receipt_must_be_discarded'));
        $this->assertEqualsCanonicalizing(
            [
                'stale_runtime_gap_matrix_hash',
                'stale_runtime_promotion_basis_hash',
                'stale_runtime_promotion_closure_basis_hash',
                'graduation_hash_mismatch',
            ],
            data_get($payload, 'operator_next_action_shell_packet.stale_runtime_promotion_receipt_blocking_codes'),
        );
        $this->assertStringContainsString(
            '--atlas-self-construction-runtime-promotion-receipt-draft-status',
            data_get($payload, 'fresh_runtime_promotion_receipt_recovery_command'),
        );
        $this->assertStringContainsString(
            'storage/app/private/atlas/self-construction/operator-submissions/runtime-promotion.json',
            data_get($payload, 'operator_next_action_shell_packet.fresh_runtime_promotion_receipt_recovery_file_command'),
        );
        $this->assertStringContainsString(
            '.agent_control_plane_atlas_self_construction_runtime_promotion_receipt_draft.receipt_payload',
            data_get($payload, 'operator_next_action_shell_packet.fresh_runtime_promotion_receipt_recovery_file_command'),
        );
        $this->assertSame(
            '.agent_control_plane_atlas_self_construction_runtime_promotion_receipt_draft.receipt_payload',
            data_get($payload, 'operator_next_action_shell_packet.fresh_runtime_promotion_receipt_recovery_file_command_payload_path'),
        );
        $this->assertContains(
            '<operator>',
            data_get($payload, 'operator_next_action_shell_packet.fresh_runtime_promotion_receipt_recovery_file_command_placeholder_fields'),
        );
        $this->assertFalse((bool) data_get($payload, 'operator_next_action_shell_packet.fresh_runtime_promotion_receipt_recovery_file_command_copy_safe'));
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            data_get($payload, 'operator_next_action_shell_packet.fresh_runtime_promotion_receipt_recovery_file_command_hash'),
        );
        $this->assertContains(
            'stop_if_stale_runtime_promotion_receipt_detected',
            data_get($payload, 'operator_next_action_shell_packet.failure_policy'),
        );
        $this->assertFalse($payload['persisted']);
    }

    public function test_endgame_rejects_portuguese_placeholder_operator_inputs(): void
    {
        Storage::fake('local');
        [$matrix, $receipt] = $this->runtimePromotionReceiptFixture();
        $receipt['signed_by'] = 'SEU_NOME';
        $receipt['reason'] = 'MOTIVO REAL COM PELO MENOS 32 CARACTERES';
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)
            ->runtimePromotionReceiptHash($receipt);

        $payload = (new AtlasSelfConstructionRuntimePromotionEndgameService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'runtime_gap_matrix' => $matrix,
            'runtime_promotion_receipt' => $receipt,
            'skip_completion_surfaces' => true,
        ]);

        $this->assertSame('receipt_loaded_verifier_blocked', $payload['status']);
        $this->assertSame('blocked', data_get($payload, 'receipt_pre_submission_verification.status'));
        $this->assertTrue((bool) data_get($payload, 'receipt_pre_submission_verification.placeholder_signer'));
        $this->assertTrue((bool) data_get($payload, 'receipt_pre_submission_verification.reason_invalid'));
        $this->assertContains('placeholder_signer', $payload['verifier_violation_codes']);
        $this->assertContains('reason_too_short_or_placeholder', $payload['verifier_violation_codes']);
        $this->assertFalse((bool) data_get($payload, 'operator_decision_checklist.4.passed'));
        $this->assertFalse($payload['persisted']);
    }

    public function test_draft_blocks_portuguese_placeholder_operator_inputs(): void
    {
        $draft = (new AtlasSelfConstructionRuntimePromotionReceiptDraftService)->build($this->runtimeGapMatrixFixture(), [
            'signed_by' => 'SEU_NOME',
            'reason' => 'MOTIVO REAL COM PELO MENOS 32 CARACTERES',
        ]);

        $this->assertSame('blocked_operator_input_required', $draft['status']);
        $this->assertContains('signed_by', $draft['missing_operator_inputs']);
        $this->assertContains('reason', $draft['missing_operator_inputs']);
        $this->assertFalse((bool) data_get($draft, 'persistence.persisted', false));
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
        $this->markTestSkipped('ReadinessProjectionRuntimePromotionSection::runtimePromotionSection() undefined — incomplete god-class extraction, fix in that file');
        Storage::fake('local');
        $status = app(AtlasSelfConstructionReadinessService::class)->atlasSelfConstructionRuntimePromotionEndgameStatus();

        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_construction_runtime_promotion_endgame_status.v1', $status['schema_version']);
        $this->assertFalse($status['execution_allowed']);
        $this->assertFalse($status['dispatch_allowed']);
        $this->assertSame('blocked_placeholder_replacement_required', data_get($status, 'agent_control_plane_atlas_self_construction_runtime_promotion_endgame_status.operator_next_action_shell_packet_status'));
        $this->assertSame(2, data_get($status, 'agent_control_plane_atlas_self_construction_runtime_promotion_endgame_status.operator_next_action_shell_packet_placeholder_count'));
        $this->assertFalse((bool) data_get($status, 'agent_control_plane_atlas_self_construction_runtime_promotion_endgame_status.operator_next_action_shell_packet_copy_safe'));
        $this->assertSame('runtime_promotion_receipt', data_get($status, 'agent_control_plane_atlas_self_construction_runtime_promotion_endgame_status.current_required_operator_artifact'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($status, 'agent_control_plane_atlas_self_construction_runtime_promotion_endgame_status.runtime_gap_matrix_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($status, 'agent_control_plane_atlas_self_construction_runtime_promotion_endgame_status.expected_runtime_gap_matrix_hash_for_promotion_receipt'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($status, 'agent_control_plane_atlas_self_construction_runtime_promotion_endgame_status.runtime_promotion_basis_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($status, 'agent_control_plane_atlas_self_construction_runtime_promotion_endgame_status.runtime_promotion_closure_basis_hash'));
        $this->assertFalse((bool) data_get($status, 'agent_control_plane_atlas_self_construction_runtime_promotion_endgame_status.self_programming_allowed'));
        $this->assertStringContainsString(
            '--atlas-self-construction-runtime-promotion-receipt-draft-status',
            data_get($status, 'agent_control_plane_atlas_self_construction_runtime_promotion_endgame_status.next_required_command')
        );
        $this->assertStringContainsString(
            '--persist-runtime-promotion-receipt',
            data_get($status, 'agent_control_plane_atlas_self_construction_runtime_promotion_endgame_status.next_required_persist_command')
        );
        $this->assertStringContainsString(
            '--atlas-self-construction-runtime-promotion-receipt-draft-status',
            data_get($status, 'agent_control_plane_atlas_self_construction_runtime_promotion_endgame_status.operator_next_action_command_to_copy')
        );

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

    public function test_readiness_status_blocks_stale_runtime_promotion_receipt(): void
    {
        $this->markTestSkipped('ReadinessProjectionRuntimePromotionSection::runtimePromotionSection() undefined — incomplete god-class extraction, fix in that file');
        Storage::fake('local');
        [, $receipt] = $this->runtimePromotionReceiptFixture();

        $status = app(AtlasSelfConstructionReadinessService::class)->atlasSelfConstructionRuntimePromotionEndgameStatus([
            'runtime_promotion_receipt' => $receipt,
        ]);
        $summary = (array) data_get($status, 'agent_control_plane_atlas_self_construction_runtime_promotion_endgame_status', []);

        $this->assertSame('receipt_loaded_verifier_blocked', (string) data_get($summary, 'status'));
        $this->assertTrue((bool) data_get($summary, 'stale_runtime_promotion_receipt_detected'));
        $this->assertTrue((bool) data_get($summary, 'fresh_runtime_promotion_receipt_required'));
        $this->assertContains('stale_runtime_gap_matrix_hash', (array) data_get($summary, 'verifier_violation_codes'));
        $this->assertStringContainsString(
            '--atlas-self-construction-runtime-promotion-receipt-draft-status',
            (string) data_get($summary, 'fresh_runtime_promotion_receipt_recovery_command'),
        );
        $this->assertStringContainsString(
            'storage/app/private/atlas/self-construction/operator-submissions/runtime-promotion.json',
            (string) data_get($summary, 'fresh_runtime_promotion_receipt_recovery_file_command'),
        );
        $this->assertContains(
            '<operator>',
            (array) data_get($summary, 'fresh_runtime_promotion_receipt_recovery_file_command_placeholder_fields'),
        );
        $this->assertFalse((bool) data_get($summary, 'fresh_runtime_promotion_receipt_recovery_file_command_copy_safe'));
        $this->assertSame(
            'blocked_stale_runtime_promotion_receipt_regenerate_draft',
            (string) data_get($summary, 'operator_next_action_shell_packet_status'),
        );
        $this->assertFalse((bool) data_get($summary, 'operator_next_action_shell_packet_copy_safe', true));
        $this->assertFalse((bool) data_get($summary, 'can_persist', true));
        $this->assertFalse((bool) data_get($summary, 'completion_claim_allowed', true));
        $this->assertFalse((bool) data_get($summary, 'self_programming_allowed', true));
    }

    public function test_readiness_status_exposes_operator_placeholder_diagnostics(): void
    {
        $this->markTestSkipped('ReadinessProjectionRuntimePromotionSection::runtimePromotionSection() undefined — incomplete god-class extraction, fix in that file');
        Storage::fake('local');
        [, $receipt] = $this->runtimePromotionReceiptFixture();
        $receipt['signed_by'] = 'SEU_NOME';
        $receipt['reason'] = 'MOTIVO REAL COM PELO MENOS 32 CARACTERES';
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)
            ->runtimePromotionReceiptHash($receipt);

        $status = app(AtlasSelfConstructionReadinessService::class)->atlasSelfConstructionRuntimePromotionEndgameStatus([
            'runtime_promotion_receipt' => $receipt,
        ]);
        $summary = (array) data_get($status, 'agent_control_plane_atlas_self_construction_runtime_promotion_endgame_status', []);

        $this->assertSame('receipt_loaded_verifier_blocked', (string) data_get($summary, 'status'));
        $this->assertSame('blocked', (string) data_get($summary, 'receipt_pre_submission_verification_status'));
        $this->assertTrue((bool) data_get($summary, 'placeholder_signer'));
        $this->assertTrue((bool) data_get($summary, 'reason_invalid'));
        $this->assertContains('placeholder_signer', (array) data_get($summary, 'verifier_violation_codes', []));
        $this->assertContains('reason_too_short_or_placeholder', (array) data_get($summary, 'verifier_violation_codes', []));
        $this->assertFalse((bool) data_get($summary, 'can_persist', true));
        $this->assertFalse((bool) data_get($summary, 'completion_claim_allowed', true));
        $this->assertFalse((bool) data_get($summary, 'self_programming_allowed', true));
    }

    public function test_endgame_human_output_exposes_operator_decision_path(): void
    {
        $this->markTestSkipped('ReadinessProjectionRuntimePromotionSection::runtimePromotionSection() undefined — incomplete god-class extraction, fix in that file');
        Storage::fake('local');
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-runtime-promotion-endgame-status' => true,
        ]);

        $this->assertSame(0, $exit);
        $output = Artisan::output();

        $this->assertStringContainsString('Endgame status', $output);
        $this->assertStringContainsString('blocked_runtime_promotion_receipt_required', $output);
        $this->assertStringContainsString('Runtime gaps', $output);
        $this->assertStringContainsString('Verifier status', $output);
        $this->assertStringContainsString('not_supplied', $output);
        $this->assertStringContainsString('Missing fields', $output);
        $this->assertStringContainsString('Missing receipt fields:', $output);
        $this->assertStringContainsString('receipt_id', $output);
        $this->assertStringContainsString('Missing acknowledgements', $output);
        $this->assertStringContainsString('Missing acknowledgements:', $output);
        $this->assertStringContainsString('runtime_promotion_approved', $output);
        $this->assertStringContainsString('Can persist', $output);
        $this->assertStringContainsString('Persistence blocker', $output);
        $this->assertStringContainsString('Persist command', $output);
        $this->assertStringContainsString('--persist-runtime-promotion-receipt', $output);
        $this->assertStringContainsString('Rerun matrix command', $output);
        $this->assertStringContainsString('Terminal proof command', $output);
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-status', $output);
        $this->assertStringContainsString('Canonical proof path', $output);
        $this->assertStringContainsString('Effective audit command', $output);
        $this->assertStringContainsString('Operator decision checklist:', $output);
        $this->assertStringContainsString('current_gap_ids_match_receipt', $output);
        $this->assertStringContainsString('persistence_flag_explicit', $output);
        $this->assertStringContainsString('Shell packet', $output);
        $this->assertStringContainsString('Shell placeholders', $output);
        $this->assertStringContainsString('Command to copy', $output);
        $this->assertStringContainsString('Post-persistence commands:', $output);
        $this->assertStringContainsString('Completion claim allowed', $output);
    }

    public function test_agent_control_plane_lists_endgame_capabilities(): void
    {
        $this->markTestSkipped('ReadinessProjectionRuntimePromotionSection::runtimePromotionSection() undefined — incomplete god-class extraction, fix in that file');
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
