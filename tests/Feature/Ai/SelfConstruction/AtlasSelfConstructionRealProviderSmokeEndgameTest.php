<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRealProviderSmokeEndgameService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionRealProviderSmokeEndgameTest extends TestCase
{
    public function test_endgame_blocks_when_no_smoke_supplied(): void
    {
        $result = $this->endgame()->build([]);

        $this->assertSame(AtlasSelfConstructionRealProviderSmokeEndgameService::SCHEMA_VERSION, $result['schema_version']);
        $this->assertSame(AtlasSelfConstructionRealProviderSmokeEndgameService::MODE, $result['mode']);
        $this->assertSame('blocked_operator_real_provider_smoke_required', $result['status']);
        $this->assertSame('end_to_end_real_provider_smoke_green', $result['blocker_id']);
        $this->assertFalse((bool) $result['persistence_allowed_here']);
    }

    public function test_endgame_composes_offline_harness_runbook_and_dossier(): void
    {
        $result = $this->endgame()->build([]);

        $this->assertSame('atlas.self_construction.real_provider_smoke_offline_harness.v1', (string) $result['offline_harness']['schema_version']);
        $this->assertSame('atlas.self_construction.real_provider_smoke_runbook.v1', (string) $result['runbook']['schema_version']);
        $this->assertSame('atlas.self_construction.real_provider_smoke_evidence_dossier.v1', (string) $result['evidence_dossier']['schema_version']);
    }

    public function test_ordered_operator_steps_have_exactly_16_steps(): void
    {
        $result = $this->endgame()->build([]);

        $steps = (array) $result['ordered_operator_steps'];
        $this->assertCount(16, $steps);
        $this->assertSame('review_offline_harness', $steps[0]);
        $this->assertSame('rerun_completion_audit', $steps[15]);
        $this->assertSame('compute_smoke_hash', $steps[11]);
        $this->assertSame('persist_with_explicit_flag_only', $steps[13]);
    }

    public function test_endgame_verifier_runs_and_is_blocked_without_input(): void
    {
        $result = $this->endgame()->build([]);

        $this->assertSame('atlas.self_construction.real_provider_smoke_endgame_verifier.v1', (string) $result['endgame_verifier_result']['schema_version']);
        $this->assertSame('blocked', (string) $result['endgame_verifier_result']['status']);
    }

    public function test_endgame_progresses_to_verifier_passed_with_canonical_test_payload(): void
    {
        $result = $this->endgame()->build(['real_provider_smoke' => $this->validTestPayload()]);

        $this->assertSame('passed', (string) $result['endgame_verifier_result']['status']);
        $this->assertContains((string) $result['status'], [
            'verifier_passed_ready_for_explicit_persistence',
            'smoke_persisted_completion_evidence_should_be_rerun',
        ]);
    }

    public function test_persistence_blocked_when_flag_not_supplied(): void
    {
        $result = $this->endgame()->build(['real_provider_smoke' => $this->validTestPayload()]);
        $attempt = (array) $result['persistence_attempt'];

        $this->assertFalse((bool) $attempt['requested']);
        $this->assertFalse((bool) $attempt['attempted']);
        $this->assertFalse((bool) $attempt['persisted']);
        $this->assertSame('persist_completion_evidence_flag_not_supplied', (string) $attempt['blocker']);
    }

    public function test_persistence_blocked_when_verifier_fails_even_with_flag(): void
    {
        Storage::fake('local');
        $payload = $this->validTestPayload(['provider_run_id' => '']);
        $result = $this->endgame()->build([
            'real_provider_smoke' => $payload,
            'persist_completion_evidence' => true,
        ]);
        $attempt = (array) $result['persistence_attempt'];

        $this->assertTrue((bool) $attempt['requested']);
        $this->assertFalse((bool) $attempt['attempted']);
        $this->assertFalse((bool) $attempt['persisted']);
        $this->assertSame('endgame_verifier_blocked', (string) $attempt['blocker']);
        $smokeFiles = array_filter(
            Storage::disk('local')->allFiles(),
            static fn (string $p): bool => str_contains($p, 'os-completion/real-provider-smokes'),
        );
        $this->assertSame([], array_values($smokeFiles));
    }

    public function test_endgame_does_not_persist_smoke_without_explicit_flag(): void
    {
        Storage::fake('local');
        $this->endgame()->build(['real_provider_smoke' => $this->validTestPayload()]);

        $smokeFiles = array_filter(
            Storage::disk('local')->allFiles(),
            static fn (string $p): bool => str_contains($p, 'os-completion/real-provider-smokes'),
        );
        $this->assertSame([], array_values($smokeFiles), 'Endgame must not persist smoke evidence without explicit flag.');
    }

    public function test_endgame_exposes_all_contracts(): void
    {
        $result = $this->endgame()->build([]);

        foreach ([
            'required_evidence_contract',
            'operator_approval_contract',
            'single_packet_scope_contract',
            'provider_observation_contract',
            'token_cost_contract',
            'work_product_contract',
            'continuation_summary_contract',
            'evidence_ledger_contract',
            'provider_response_contract',
        ] as $contractKey) {
            $this->assertArrayHasKey($contractKey, $result);
        }
    }

    public function test_endgame_exposes_anti_cheat_and_non_execution_guarantees(): void
    {
        $result = $this->endgame()->build([]);
        $policy = (array) $result['anti_cheat_policy'];
        $guarantees = (array) $result['non_execution_guarantees'];

        foreach ([
            'no_synthetic_smoke_accepted',
            'no_fixture_substitution_for_real_smoke',
            'atlas_must_not_call_provider',
            'atlas_must_not_spend_tokens',
            'operator_must_observe_real_provider_run',
            'persistence_requires_explicit_flag',
            'no_os_complete_claim_from_endgame',
        ] as $key) {
            $this->assertTrue((bool) $policy[$key], "policy {$key} must be true");
        }
        foreach ([
            'does_not_call_provider',
            'does_not_spend_tokens',
            'does_not_dispatch',
            'does_not_start_process',
            'does_not_persist_smoke',
            'does_not_promote_completion',
            'does_not_enable_runtime',
            'does_not_sign_for_operator',
        ] as $key) {
            $this->assertTrue((bool) $guarantees[$key], "guarantee {$key} must be true");
        }
    }

    public function test_endgame_hash_is_64_hex(): void
    {
        $result = $this->endgame()->build([]);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $result['real_provider_smoke_endgame_hash']);
    }

    public function test_endgame_includes_persistence_preflight_and_exporter(): void
    {
        $result = $this->endgame()->build([]);

        $this->assertSame(
            'atlas.self_construction.real_provider_smoke_evidence_ledger_preflight.v1',
            (string) $result['persistence_preflight']['schema_version'],
        );
        $this->assertSame(
            'atlas.self_construction.real_provider_smoke_operator_runbook_exporter.v1',
            (string) $result['operator_runbook_export']['schema_version'],
        );
    }

    private function endgame(): AtlasSelfConstructionRealProviderSmokeEndgameService
    {
        return new AtlasSelfConstructionRealProviderSmokeEndgameService;
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
