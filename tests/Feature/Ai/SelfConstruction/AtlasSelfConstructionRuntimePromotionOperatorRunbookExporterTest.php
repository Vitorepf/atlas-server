<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimePromotionOperatorRunbookExporterService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionRuntimePromotionOperatorRunbookExporterTest extends TestCase
{
    public function test_exporter_returns_markdown_and_machine_summary_without_persistence(): void
    {
        Storage::fake('local');
        $payload = (new AtlasSelfConstructionRuntimePromotionOperatorRunbookExporterService(app(AtlasSelfConstructionReadinessService::class)))->build();

        $this->assertSame('atlas.self_construction.runtime_promotion_operator_runbook_exporter.v1', $payload['schema_version']);
        $this->assertSame('available_in_memory_only', $payload['status']);
        $this->assertFalse($payload['persist']);
        $this->assertSame('', $payload['export_path']);
        $this->assertStringContainsString('Runtime Promotion Operator Runbook v1', $payload['markdown']);
        $this->assertStringContainsString('Current Blockers', $payload['markdown']);
        $this->assertStringContainsString('Current Hashes', $payload['markdown']);
        $this->assertStringContainsString('Receipt Template', $payload['markdown']);
        $this->assertStringContainsString('How To Compute Receipt Hash', $payload['markdown']);
        $this->assertStringContainsString('How To Verify', $payload['markdown']);
        $this->assertStringContainsString('How To Persist', $payload['markdown']);
        $this->assertStringContainsString('How To Rerun Audit', $payload['markdown']);
        $this->assertStringContainsString('Stop Conditions', $payload['markdown']);
        $this->assertStringContainsString('Non-Execution Guarantees', $payload['markdown']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['markdown_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['machine_summary_hash']);

        $this->assertSame([], Storage::disk('local')->allFiles('atlas/self-construction/runtime-promotion/operator-runbook-exports'));
    }

    public function test_exporter_persists_only_when_persist_export_true(): void
    {
        Storage::fake('local');
        $payload = (new AtlasSelfConstructionRuntimePromotionOperatorRunbookExporterService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'persist_export' => true,
        ]);

        $this->assertSame('exported', $payload['status']);
        $this->assertTrue($payload['persist']);
        $this->assertNotSame('', $payload['export_path']);
        $this->assertStringStartsWith('atlas/self-construction/runtime-promotion/operator-runbook-exports/', $payload['export_path']);
        $this->assertTrue(Storage::disk('local')->exists($payload['export_path']));
        $persistedContents = Storage::disk('local')->get($payload['export_path']);
        $this->assertSame($payload['markdown'], $persistedContents);
    }

    public function test_machine_summary_contains_canonical_keys(): void
    {
        $payload = (new AtlasSelfConstructionRuntimePromotionOperatorRunbookExporterService(app(AtlasSelfConstructionReadinessService::class)))->build();
        $summary = $payload['machine_summary'];

        $this->assertArrayHasKey('current_blockers', $summary);
        $this->assertArrayHasKey('runtime_gap_count', $summary);
        $this->assertArrayHasKey('current_hashes', $summary);
        $this->assertArrayHasKey('graduation_evidence_hashes', $summary);
        $this->assertArrayHasKey('receipt_template_preimage', $summary);
        $this->assertArrayHasKey('commands', $summary);
        $this->assertArrayHasKey('stop_conditions', $summary);
        $this->assertArrayHasKey('non_execution_guarantees', $summary);
        $this->assertArrayHasKey('completion_audit_status', $summary);
        $this->assertStringContainsString('--atlas-self-construction-runtime-promotion-endgame-verifier-status', $summary['commands']['pre_submission_verify']);
        $this->assertStringContainsString('--persist-runtime-promotion-receipt', $summary['commands']['persist_receipt']);
        $this->assertArrayHasKey('refresh_terminal_loop_operational_proof', $summary['commands']);
        $this->assertArrayHasKey('rerun_completion_audit_with_terminal_loop_operational_proof', $summary['commands']);
        $this->assertStringContainsString('terminal-loop-operational-proof-status', $summary['commands']['refresh_terminal_loop_operational_proof']);
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-json=', $summary['commands']['rerun_completion_audit_with_terminal_loop_operational_proof']);
    }

    public function test_completion_audit_status_surface_is_exposed_without_evidence_persistence(): void
    {
        // The exporter MUST surface completion_audit_status as a snapshot from the audit
        // service, but it must never persist a receipt or autopromote runtime.
        Storage::fake('local');
        $payload = (new AtlasSelfConstructionRuntimePromotionOperatorRunbookExporterService(app(AtlasSelfConstructionReadinessService::class)))->build();

        $this->assertArrayHasKey('completion_audit_status', $payload['machine_summary']);
        $this->assertArrayHasKey('status', $payload['machine_summary']['completion_audit_status']);
        $this->assertArrayHasKey('failed_criteria', $payload['machine_summary']['completion_audit_status']);
        $this->assertArrayHasKey('runtime_gap_matrix_all_runtime_y_passed', $payload['machine_summary']['completion_audit_status']);
        $this->assertIsBool(data_get($payload, 'machine_summary.completion_audit_status.runtime_gap_matrix_all_runtime_y_passed'));

        // No receipts persisted to runtime-promotion-receipts storage prefix.
        $this->assertSame([], Storage::disk('local')->allFiles('atlas/self-construction/os-completion/runtime-promotion-receipts'));
        // And no runbook export either (default persist_export=false).
        $this->assertSame([], Storage::disk('local')->allFiles('atlas/self-construction/runtime-promotion/operator-runbook-exports'));
    }

    public function test_readiness_status_and_cli_quartet(): void
    {
        $status = app(AtlasSelfConstructionReadinessService::class)->atlasSelfConstructionRuntimePromotionOperatorRunbookExporterStatus();
        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_construction_runtime_promotion_operator_runbook_exporter_status.v1', $status['schema_version']);

        foreach ([
            'atlas-self-construction-runtime-promotion-operator-runbook-exporter-contract',
            'atlas-self-construction-runtime-promotion-operator-runbook-exporter-preflight',
            'atlas-self-construction-runtime-promotion-operator-runbook-exporter-implementation-packet',
            'atlas-self-construction-runtime-promotion-operator-runbook-exporter-status',
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

    public function test_agent_control_plane_lists_runbook_exporter_capabilities(): void
    {
        $payload = app(AtlasSelfConstructionReadinessService::class)->agentControlPlane();
        $capabilities = (array) data_get($payload, 'control_plane.current_capability', []);

        foreach ([
            'atlas_self_construction_runtime_promotion_operator_runbook_exporter_contract',
            'atlas_self_construction_runtime_promotion_operator_runbook_exporter_preflight',
            'atlas_self_construction_runtime_promotion_operator_runbook_exporter_implementation_packet',
            'atlas_self_construction_runtime_promotion_operator_runbook_exporter_service',
            'atlas_self_construction_runtime_promotion_operator_runbook_exporter_status_projection',
        ] as $capability) {
            $this->assertContains($capability, $capabilities);
        }
    }
}
