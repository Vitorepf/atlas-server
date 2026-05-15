<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimePromotionClosureExecutionPackService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionRuntimePromotionClosureExecutionPackTest extends TestCase
{
    public function test_pack_blocks_when_no_receipt_supplied(): void
    {
        $payload = (new AtlasSelfConstructionRuntimePromotionClosureExecutionPackService(app(AtlasSelfConstructionReadinessService::class)))->build();

        $this->assertSame('atlas.self_construction.runtime_promotion_closure_execution_pack.v1', $payload['schema_version']);
        $this->assertSame('read_only_runtime_promotion_closure_execution_pack', $payload['mode']);
        $this->assertSame('blocked_operator_runtime_promotion_receipt_required', $payload['status']);
        $this->assertFalse($payload['completion_allowed']);
        $this->assertFalse($payload['completion_claim_allowed']);
        $this->assertNotEmpty($payload['closure_pack_hash']);
        $this->assertFalse($payload['execution_allowed']);
        $this->assertFalse($payload['dispatch_allowed']);
        $this->assertFalse($payload['provider_call_allowed']);
        $this->assertFalse($payload['token_spend_allowed']);
        $this->assertFalse($payload['adapter_execution_allowed']);
        $this->assertFalse($payload['self_programming_allowed']);
    }

    public function test_pack_lists_blocked_gaps_consistently(): void
    {
        $payload = (new AtlasSelfConstructionRuntimePromotionClosureExecutionPackService(app(AtlasSelfConstructionReadinessService::class)))->build();

        // The matrix-derived gap rows depend on the agentControlPlane state. The pack
        // always exposes both lists; they must agree on count and content. Each id, when
        // present, must be a non-empty string. Graduation hashes must cover every id.
        $this->assertSameSize($payload['blocked_gap_ids'], $payload['promoted_gap_ids']);
        $this->assertSame($payload['blocked_gap_ids'], $payload['promoted_gap_ids']);
        $this->assertSame(count($payload['blocked_gap_ids']), $payload['runtime_gap_count']);
        foreach ($payload['blocked_gap_ids'] as $gapId) {
            $this->assertIsString($gapId);
            $this->assertNotSame('', $gapId);
            $this->assertArrayHasKey($gapId, $payload['graduation_evidence_hashes']);
        }
    }

    public function test_pack_exposes_canonical_hashes_for_receipt_signing(): void
    {
        $payload = (new AtlasSelfConstructionRuntimePromotionClosureExecutionPackService(app(AtlasSelfConstructionReadinessService::class)))->build();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $payload['runtime_gap_matrix_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $payload['expected_runtime_gap_matrix_hash_for_promotion_receipt']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $payload['runtime_promotion_basis_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $payload['runtime_promotion_closure_basis_hash']);
        foreach ($payload['graduation_evidence_hashes'] as $hash) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $hash);
        }
    }

    public function test_pack_ordered_operator_steps_have_canonical_ids_in_order(): void
    {
        $payload = (new AtlasSelfConstructionRuntimePromotionClosureExecutionPackService(app(AtlasSelfConstructionReadinessService::class)))->build();
        $stepIds = array_column($payload['ordered_operator_steps'], 'id');

        $this->assertSame([
            'review_current_runtime_gap_matrix',
            'review_graduation_evidence_hashes',
            'generate_runtime_promotion_receipt_draft',
            'compute_canonical_receipt_hash',
            'verify_receipt_hash_matches_current_matrix',
            'persist_with_explicit_flag_only',
            'rerun_runtime_gap_matrix',
            'rerun_completion_audit',
        ], $stepIds);
    }

    public function test_draft_only_ready_with_real_signer_and_reason(): void
    {
        $payloadEmpty = (new AtlasSelfConstructionRuntimePromotionClosureExecutionPackService(app(AtlasSelfConstructionReadinessService::class)))->build();
        $this->assertSame('not_drafted', data_get($payloadEmpty, 'receipt_draft.status'));
        $this->assertFalse(data_get($payloadEmpty, 'receipt_draft.requested'));

        $payloadPlaceholder = (new AtlasSelfConstructionRuntimePromotionClosureExecutionPackService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'signed_by' => '<operator>',
            'reason' => 'short',
        ]);
        $this->assertSame('blocked_operator_input_required', data_get($payloadPlaceholder, 'receipt_draft.status'));
        $this->assertTrue(data_get($payloadPlaceholder, 'receipt_draft.requested'));
        $this->assertContains('signed_by', (array) data_get($payloadPlaceholder, 'receipt_draft.missing_operator_inputs', []));
        $this->assertContains('reason', (array) data_get($payloadPlaceholder, 'receipt_draft.missing_operator_inputs', []));
    }

    public function test_anti_cheat_policy_and_non_execution_guarantees_complete(): void
    {
        $payload = (new AtlasSelfConstructionRuntimePromotionClosureExecutionPackService(app(AtlasSelfConstructionReadinessService::class)))->build();

        foreach ([
            'reject_runtime_autopromotion',
            'reject_stale_runtime_gap_matrix_hash',
            'reject_missing_closure_basis_hash',
            'reject_promoted_gap_id_drift',
            'reject_graduation_hash_mismatch',
            'reject_placeholder_operator',
            'reject_receipt_hash_mismatch',
            'reject_runtime_enabled_flags_true',
        ] as $rule) {
            $this->assertContains($rule, $payload['anti_cheat_policy']);
        }

        foreach ([
            'does_not_enable_runtime',
            'does_not_persist_without_flag',
            'does_not_start_process',
            'does_not_call_provider',
            'does_not_spend_tokens',
            'does_not_dispatch',
            'does_not_promote_completion',
            'does_not_sign_for_operator',
        ] as $guarantee) {
            $this->assertContains($guarantee, $payload['non_execution_guarantees']);
        }
    }

    public function test_readiness_status_and_cli_quartet(): void
    {
        $status = app(AtlasSelfConstructionReadinessService::class)->atlasSelfConstructionRuntimePromotionClosureExecutionPackStatus();

        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_construction_runtime_promotion_closure_execution_pack_status.v1', $status['schema_version']);
        $this->assertFalse($status['execution_allowed']);

        foreach ([
            'atlas-self-construction-runtime-promotion-closure-execution-pack-contract',
            'atlas-self-construction-runtime-promotion-closure-execution-pack-preflight',
            'atlas-self-construction-runtime-promotion-closure-execution-pack-implementation-packet',
            'atlas-self-construction-runtime-promotion-closure-execution-pack-status',
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

    public function test_agent_control_plane_lists_closure_execution_pack_capabilities(): void
    {
        $payload = app(AtlasSelfConstructionReadinessService::class)->agentControlPlane();
        $capabilities = (array) data_get($payload, 'control_plane.current_capability', []);

        foreach ([
            'atlas_self_construction_runtime_promotion_closure_execution_pack_contract',
            'atlas_self_construction_runtime_promotion_closure_execution_pack_preflight',
            'atlas_self_construction_runtime_promotion_closure_execution_pack_implementation_packet',
            'atlas_self_construction_runtime_promotion_closure_execution_pack_service',
            'atlas_self_construction_runtime_promotion_closure_execution_pack_status_projection',
        ] as $capability) {
            $this->assertContains($capability, $capabilities);
        }
    }

    public function test_nothing_persists_without_explicit_flag(): void
    {
        Storage::fake('local');
        $payload = (new AtlasSelfConstructionRuntimePromotionClosureExecutionPackService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'signed_by' => 'operator-real',
            'reason' => 'Operator drafted runtime promotion receipt without persistence intent.',
        ]);

        // The pack always requires the explicit persistence flag, and the persistence-preflight
        // payload reports that it is blocked because the operator did not invoke the flag here.
        $this->assertSame('--persist-runtime-promotion-receipt', data_get($payload, 'receipt_persistence_preflight.requires_explicit_flag'));
        $this->assertSame(\App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimePromotionReceiptService::class, data_get($payload, 'receipt_persistence_preflight.gated_by_service'));
        $this->assertNotEmpty(data_get($payload, 'receipt_draft.draft_hash'));
        $this->assertSame([], Storage::disk('local')->allFiles('atlas/self-construction/os-completion/runtime-promotion-receipts'));
    }
}
