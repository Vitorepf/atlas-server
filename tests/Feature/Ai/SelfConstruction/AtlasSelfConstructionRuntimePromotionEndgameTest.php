<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimePromotionEndgameService;
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
}
