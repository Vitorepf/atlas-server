<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRealProviderSmokeClosureExecutionPackService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionRealProviderSmokeClosureExecutionPackTest extends TestCase
{
    public function test_pack_blocks_when_no_smoke_is_supplied(): void
    {
        $result = $this->pack()->build([]);

        $this->assertSame(AtlasSelfConstructionRealProviderSmokeClosureExecutionPackService::SCHEMA_VERSION, $result['schema_version']);
        $this->assertSame(AtlasSelfConstructionRealProviderSmokeClosureExecutionPackService::MODE, $result['mode']);
        $this->assertSame('blocked_operator_real_provider_smoke_required', $result['status']);
        $this->assertSame('end_to_end_real_provider_smoke_green', $result['blocker_id']);
        $this->assertFalse((bool) $result['persistence_allowed_here']);
    }

    public function test_pack_exposes_offline_harness_runbook_and_dossier(): void
    {
        $result = $this->pack()->build([]);

        $this->assertSame('atlas.self_construction.real_provider_smoke_offline_harness.v1', $result['offline_harness']['schema_version']);
        $this->assertSame('atlas.self_construction.real_provider_smoke_runbook.v1', $result['runbook']['schema_version']);
        $this->assertSame('atlas.self_construction.real_provider_smoke_evidence_dossier.v1', $result['evidence_dossier']['schema_version']);
        $this->assertNotEmpty((array) $result['offline_harness']['required_evidence_fields']);
    }

    public function test_pack_status_progresses_with_pre_submission_passed(): void
    {
        $result = $this->pack()->build(['real_provider_smoke' => $this->validTestPayload()]);

        $this->assertSame('passed', (string) $result['pre_submission_verifier_result']['status']);
        $this->assertContains(
            (string) $result['status'],
            ['ready_to_persist_real_provider_smoke', 'real_provider_smoke_verified'],
        );
    }

    public function test_pack_exposes_full_ordered_operator_steps(): void
    {
        $result = $this->pack()->build([]);

        $this->assertSame([
            'review_offline_harness',
            'approve_single_packet_scope',
            'run_real_provider_smoke_outside_read_only_surface',
            'collect_provider_response_hash',
            'collect_cost_event_hash',
            'collect_work_product_manifest_hash',
            'collect_continuation_summary_hash',
            'collect_evidence_ledger_hash',
            'build_smoke_preimage',
            'compute_smoke_hash',
            'verify_smoke_payload',
            'persist_with_explicit_flag_only',
            'rerun_completion_evidence_status',
            'rerun_completion_audit',
        ], $result['ordered_operator_steps']);
    }

    public function test_pack_exposes_anti_cheat_and_non_execution_guarantees(): void
    {
        $result = $this->pack()->build([]);
        $policy = (array) $result['anti_cheat_policy'];
        $guarantees = (array) $result['non_execution_guarantees'];

        $this->assertTrue((bool) $policy['no_synthetic_smoke_accepted']);
        $this->assertTrue((bool) $policy['atlas_must_not_call_provider']);
        $this->assertTrue((bool) $policy['atlas_must_not_spend_tokens']);
        $this->assertTrue((bool) $policy['operator_must_observe_real_provider_run']);
        $this->assertTrue((bool) $policy['persistence_requires_explicit_flag']);
        $this->assertTrue((bool) $policy['no_os_complete_claim_from_pack']);

        $this->assertTrue((bool) $guarantees['does_not_call_provider']);
        $this->assertTrue((bool) $guarantees['does_not_spend_tokens']);
        $this->assertTrue((bool) $guarantees['does_not_dispatch']);
        $this->assertTrue((bool) $guarantees['does_not_start_process']);
        $this->assertTrue((bool) $guarantees['does_not_persist_smoke']);
        $this->assertTrue((bool) $guarantees['does_not_promote_completion']);
        $this->assertTrue((bool) $guarantees['does_not_enable_runtime']);
        $this->assertTrue((bool) $guarantees['does_not_sign_for_operator']);
    }

    public function test_pack_exposes_all_contracts(): void
    {
        $result = $this->pack()->build([]);

        foreach ([
            'operator_approval_contract',
            'single_packet_scope_contract',
            'work_product_collection_contract',
            'cost_event_contract',
            'continuation_summary_contract',
            'evidence_ledger_contract',
            'provider_response_contract',
        ] as $contractKey) {
            $this->assertArrayHasKey($contractKey, $result);
            $this->assertArrayHasKey('rule', (array) $result[$contractKey]);
        }
    }

    public function test_pack_does_not_persist_real_provider_smoke_evidence(): void
    {
        Storage::fake('local');

        $this->pack()->build([
            'real_provider_smoke' => $this->validTestPayload(),
        ]);

        $files = Storage::disk('local')->allFiles();
        $smokeArtifacts = array_filter(
            $files,
            static fn (string $path): bool => str_contains($path, 'os-completion/real-provider-smokes')
                || str_contains($path, 'os-completion/runtime-promotion-receipts')
                || str_contains($path, 'os-completion/human-signed-receipts'),
        );
        $this->assertSame([], array_values($smokeArtifacts), 'Closure pack must not persist operator evidence.');
    }

    public function test_pack_hash_is_64_hex(): void
    {
        $result = $this->pack()->build([]);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $result['closure_pack_hash']);
    }

    public function test_pack_exposes_pre_submission_and_checklist(): void
    {
        $result = $this->pack()->build([]);

        $this->assertSame(
            'atlas.self_construction.real_provider_smoke_pre_submission_verifier.v1',
            (string) $result['pre_submission_verifier_result']['schema_version'],
        );
        $this->assertSame(
            'atlas.self_construction.real_provider_smoke_operator_checklist.v1',
            (string) $result['operator_checklist']['schema_version'],
        );
    }

    private function pack(): AtlasSelfConstructionRealProviderSmokeClosureExecutionPackService
    {
        return new AtlasSelfConstructionRealProviderSmokeClosureExecutionPackService;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validTestPayload(array $overrides = []): array
    {
        $hash = str_repeat('a', 64);
        $payload = array_merge([
            'kind' => 'real_provider_packet_claim_to_completion',
            'status' => 'passed',
            'provider_run_id' => 'run-2026-05-15-001',
            'task_packet_id' => 'packet-2026-05-15-001',
            'observed_by' => 'Real Operator',
            'approval_reason' => 'Operator approved and observed one real provider claim-to-completion smoke.',
            'operator_approval_receipt_hash' => $hash,
            'evidence_ledger_hash' => $hash,
            'work_product_manifest_hash' => $hash,
            'cost_event_hash' => $hash,
            'continuation_summary_hash' => $hash,
            'provider_response_hash' => $hash,
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
        ], $overrides);
        $payload['smoke_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->realProviderSmokeHash($payload);

        return $payload;
    }
}
