<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRealProviderSmokeDraftService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionRealProviderSmokeDraftTest extends TestCase
{
    public function test_draft_blocks_without_operator_smoke_evidence(): void
    {
        $draft = (new AtlasSelfConstructionRealProviderSmokeDraftService)->build();

        $this->assertSame('atlas.self_construction.real_provider_smoke_draft.v1', $draft['schema_version']);
        $this->assertSame('blocked_operator_or_evidence_input_required', $draft['status']);
        $this->assertContains('provider_run_id', $draft['missing_operator_inputs']);
        $this->assertContains('operator_approval_receipt_hash', $draft['missing_evidence_hashes']);
        $this->assertFalse((bool) $draft['provider_call_allowed']);
        $this->assertContains('real_provider_smoke_draft_does_not_call_provider', $draft['non_execution_guarantees']);
    }

    public function test_draft_builds_verifier_ready_payload_from_operator_evidence(): void
    {
        $draft = (new AtlasSelfConstructionRealProviderSmokeDraftService)->build($this->validSmokePreimage());

        $this->assertSame('ready_for_operator_persistence', $draft['status']);
        $this->assertSame('verified_operator_supplied_real_provider_smoke_evidence', data_get($draft, 'verification.status'));
        $this->assertSame([], $draft['missing_operator_inputs']);
        $this->assertSame([], $draft['missing_evidence_hashes']);
        $this->assertSame([], $draft['missing_observation_flags']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $draft['smoke_hash']);
    }

    public function test_draft_blocks_portuguese_operator_placeholders(): void
    {
        $draft = (new AtlasSelfConstructionRealProviderSmokeDraftService)->build(array_merge($this->validSmokePreimage(), [
            'provider_run_id' => 'substitua pelo provider run real',
            'task_packet_id' => 'substitua pelo task packet real',
            'observed_by' => 'SEU_NOME',
            'approval_reason' => 'MOTIVO REAL COM PELO MENOS 32 CARACTERES',
        ]), [
            'persist_completion_evidence' => true,
        ]);

        $this->assertSame('blocked_operator_or_evidence_input_required', $draft['status']);
        $this->assertSame('real_provider_smoke_draft_not_ready_for_persistence', $draft['persistence_blocker']);
        $this->assertContains('provider_run_id', $draft['missing_operator_inputs']);
        $this->assertContains('task_packet_id', $draft['missing_operator_inputs']);
        $this->assertContains('observed_by', $draft['missing_operator_inputs']);
        $this->assertContains('approval_reason', $draft['missing_operator_inputs']);
        $this->assertFalse((bool) $draft['persisted']);
        $this->assertFalse((bool) $draft['completion_claim_allowed']);
        $this->assertFalse((bool) $draft['provider_call_allowed']);
        $this->assertFalse((bool) $draft['token_spend_allowed']);
    }

    public function test_draft_persists_only_when_ready_and_requested(): void
    {
        Storage::fake('local');

        $blocked = (new AtlasSelfConstructionRealProviderSmokeDraftService)->build([], [
            'persist_completion_evidence' => true,
        ]);
        $this->assertFalse((bool) $blocked['persisted']);
        $this->assertSame('real_provider_smoke_draft_not_ready_for_persistence', $blocked['persistence_blocker']);
        Storage::disk('local')->assertMissing('atlas/self-construction/os-completion/real-provider-smokes/registry.json');

        $persisted = (new AtlasSelfConstructionRealProviderSmokeDraftService)->build($this->validSmokePreimage(), [
            'persist_completion_evidence' => true,
        ]);
        $this->assertSame('persisted', $persisted['status']);
        $this->assertTrue((bool) $persisted['persisted']);
        Storage::disk('local')->assertExists((string) $persisted['smoke_path']);
    }

    public function test_draft_command_exposes_status_and_quartet(): void
    {
        $this->artisan('atlas:ai:self-construction', [
            '--atlas-self-construction-real-provider-smoke-draft-status' => true,
            '--real-provider-smoke-json' => json_encode($this->validSmokePreimage(), JSON_THROW_ON_ERROR),
            '--json' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('atlas.self_construction_agent_control_plane_atlas_self_construction_real_provider_smoke_draft_status.v1');

        foreach ([
            '--atlas-self-construction-real-provider-smoke-draft-contract' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_real_provider_smoke_draft_contract.v1',
            '--atlas-self-construction-real-provider-smoke-draft-preflight' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_real_provider_smoke_draft_preflight.v1',
            '--atlas-self-construction-real-provider-smoke-draft-implementation-packet' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_real_provider_smoke_draft_implementation_packet.v1',
        ] as $option => $schema) {
            $this->artisan('atlas:ai:self-construction', [$option => true, '--json' => true])
                ->assertExitCode(0)
                ->expectsOutputToContain($schema);
        }

        Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-real-provider-smoke-draft-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $summary = (array) data_get($payload, 'agent_control_plane_atlas_self_construction_real_provider_smoke_draft_status', []);

        $this->assertContains((string) $summary['current_required_operator_artifact'], [
            'runtime_promotion_receipt',
            'real_provider_smoke',
            'human_completion_receipt',
            'none',
        ]);
        $this->assertIsString($summary['next_required_command']);
        $this->assertArrayHasKey('next_required_persist_command', $summary);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $summary['runtime_gap_matrix_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $summary['expected_runtime_gap_matrix_hash_for_promotion_receipt']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $summary['runtime_promotion_basis_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $summary['runtime_promotion_closure_basis_hash']);
        $this->assertFalse((bool) $summary['execution_allowed']);
        $this->assertFalse((bool) $summary['dispatch_allowed']);
        $this->assertFalse((bool) $summary['provider_call_allowed']);
        $this->assertFalse((bool) $summary['token_spend_allowed']);
        $this->assertFalse((bool) $summary['adapter_execution_allowed']);
        $this->assertFalse((bool) $summary['completion_claim_allowed']);
        $this->assertFalse((bool) $summary['self_programming_allowed']);
    }

    /** @return array<string, mixed> */
    private function validSmokePreimage(): array
    {
        $hash = str_repeat('d', 64);

        return [
            'provider_run_id' => 'provider-run-1',
            'task_packet_id' => 'task-packet-1',
            'observed_by' => 'Vitore Operator',
            'approval_reason' => 'Operator approved and observed one bounded real provider claim-to-completion smoke.',
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
        ];
    }
}
