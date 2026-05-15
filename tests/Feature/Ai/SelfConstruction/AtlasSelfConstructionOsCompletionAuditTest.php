<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionOsCompletionAuditService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionOsCompletionAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_completion_audit_blocks_false_completion_claims(): void
    {
        $audit = (new AtlasSelfConstructionOsCompletionAuditService(app(AtlasSelfConstructionReadinessService::class)))->audit();

        $this->assertSame('atlas.self_construction.os_completion_audit.v1', $audit['schema_version']);
        $this->assertSame('incomplete', $audit['status']);
        $this->assertFalse($audit['completion_allowed']);
        $this->assertFalse($audit['completion_claim_allowed']);
        $this->assertGreaterThan(0, $audit['failed_count']);
        $this->assertNotNull(collect($audit['criteria'])->firstWhere('id', 'runtime_gap_matrix_all_runtime_y'));
        $this->assertContains('human_signed_os_complete_receipt_present', $audit['failed_criteria']);
        $this->assertContains('end_to_end_real_provider_smoke_green', $audit['failed_criteria']);
        $this->assertNotContains('forge_self_improvement_integration_smoke_green', $audit['failed_criteria']);
        $this->assertSame('passed', data_get(collect($audit['criteria'])->firstWhere('id', 'forge_self_improvement_integration_smoke_green'), 'evidence.status'));
        $this->assertSame('continue_implementation_until_failed_completion_criteria_have_real_evidence', $audit['next_action']);
        $this->assertSame('atlas.self_construction.completion_operator_action_packet.v1', data_get($audit, 'operator_action_packet.schema_version'));
        $this->assertSame('operator_action_required', data_get($audit, 'operator_action_packet.status'));
        $this->assertContains('human_signed_os_complete_receipt', data_get($audit, 'operator_action_packet.missing_operator_artifacts'));
        $this->assertContains('real_provider_claim_to_completion_smoke', data_get($audit, 'operator_action_packet.missing_operator_artifacts'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($audit, 'operator_action_packet.operator_action_packet_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $audit['completion_audit_hash']);

        $runtimeCriterion = collect($audit['criteria'])->firstWhere('id', 'runtime_gap_matrix_all_runtime_y');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($runtimeCriterion, 'evidence.expected_runtime_gap_matrix_hash_for_promotion_receipt'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($runtimeCriterion, 'evidence.runtime_promotion_basis_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($runtimeCriterion, 'evidence.runtime_promotion_closure_basis_hash'));

        $batchCriterion = collect($audit['criteria'])->firstWhere('id', 'certification_status_batch_green');
        $this->assertTrue((bool) data_get($batchCriterion, 'evidence.full_batch_required'));
        $this->assertGreaterThanOrEqual(49, (int) data_get($batchCriterion, 'evidence.checked_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($batchCriterion, 'evidence.hash'));

        $releaseCriterion = collect($audit['criteria'])->firstWhere('id', 'release_dossier_green');
        $replayCriterion = collect($audit['criteria'])->firstWhere('id', 'replay_diff_against_completion_snapshot_green');
        $humanTemplate = (array) data_get($audit, 'operator_action_packet.human_completion_receipt_template', []);
        $this->assertSame((string) data_get($releaseCriterion, 'evidence.hash'), (string) ($humanTemplate['release_dossier_hash'] ?? ''));
        $this->assertSame((string) data_get($replayCriterion, 'evidence.diff_hash'), (string) ($humanTemplate['replay_diff_hash'] ?? ''));
        $this->assertSame((string) data_get($batchCriterion, 'evidence.hash'), (string) ($humanTemplate['certification_status_batch_hash'] ?? ''));

        $runtimeRows = (array) data_get($audit, 'operator_action_packet.runtime_promotion_receipt_template.promoted_gap_ids', []);
        $this->assertSame((array) data_get($runtimeCriterion, 'evidence.blocked_gap_ids'), $runtimeRows);
    }

    public function test_completion_audit_exposes_prompt_to_artifact_checklist(): void
    {
        $audit = (new AtlasSelfConstructionOsCompletionAuditService(app(AtlasSelfConstructionReadinessService::class)))->audit();
        $requirements = array_column($audit['prompt_to_artifact_checklist'], 'requirement');

        $this->assertContains('Atlas Self-Construction OS complete', $requirements);
        $this->assertContains('all runtime gaps closed', $requirements);
        $this->assertContains('release dossier green', $requirements);
        $this->assertContains('human signed completion receipt', $requirements);
        $this->assertContains('real provider end-to-end smoke', $requirements);
        $this->assertContains('Forge/Self-Improvement integration smoke', $requirements);
        $this->assertSame($audit['checklist_count'], count($audit['prompt_to_artifact_checklist']));
    }

    public function test_command_exposes_completion_audit_quartet(): void
    {
        foreach ([
            '--atlas-self-construction-os-completion-audit-contract' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_os_completion_audit_contract.v1',
            '--atlas-self-construction-os-completion-audit-preflight' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_os_completion_audit_preflight.v1',
            '--atlas-self-construction-os-completion-audit-implementation-packet' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_os_completion_audit_implementation_packet.v1',
            '--atlas-self-construction-os-completion-audit-status' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_os_completion_audit_status.v1',
        ] as $flag => $schema) {
            $exit = Artisan::call('atlas:ai:self-construction', [$flag => true, '--json' => true]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit);
            $this->assertSame($schema, $payload['schema_version']);
            $this->assertFalse($payload['execution_allowed']);
            $this->assertFalse($payload['dispatch_allowed']);
            $this->assertFalse($payload['ledger_write_allowed']);
            $this->assertFalse($payload['runtime_write_allowed']);
        }
    }

    public function test_agent_control_plane_lists_completion_audit_capabilities(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $capabilities = (array) data_get($payload, 'control_plane.current_capability', []);

        foreach ([
            'atlas_self_construction_os_completion_audit_contract',
            'atlas_self_construction_os_completion_audit_preflight',
            'atlas_self_construction_os_completion_audit_implementation_packet',
            'atlas_self_construction_os_completion_audit_service',
            'atlas_self_construction_os_completion_audit_status_projection',
        ] as $capability) {
            $this->assertContains($capability, $capabilities);
        }
    }
}
