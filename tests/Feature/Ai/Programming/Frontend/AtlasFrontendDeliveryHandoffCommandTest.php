<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\Frontend\AtlasFrontendDeliveryHandoffService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignReviewService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendEvidencePackVerifierService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendOutcomeMemoryService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendProductProofRuntimeService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendProviderInstructionPacketService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendQualityBudgetGateService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendRivalReplayHarnessService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendRunCertificationService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendVisualQualityGateService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendDeliveryHandoffCommandTest extends TestCase
{
    public function test_handoff_command_compiles_ready_package(): void
    {
        $dir = $this->fixtureDir();

        $exitCode = Artisan::call('atlas:frontend:handoff', [
            'action' => 'compile',
            '--run-certification' => $dir.'/run-certification.json',
            '--evidence-manifest' => $dir.'/evidence/evidence-pack.json',
            '--json' => true,
            '--strict' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(AtlasFrontendDeliveryHandoffService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.customer_handoff_allowed'));
        $this->assertSame('missing_report', data_get($payload, 'publication_attestation.status'));
        $this->assertTrue((bool) data_get($payload, 'publication_attestation.claim_policy.local_bundle_is_not_public_distribution'));
    }

    public function test_handoff_template_command_writes_inputs(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-handoff-command-template-'.bin2hex(random_bytes(4));

        $exitCode = Artisan::call('atlas:frontend:handoff', [
            'action' => 'template',
            '--output' => $dir,
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(AtlasFrontendDeliveryHandoffService::TEMPLATE_SCHEMA_VERSION, $output);
        $this->assertTrue(File::isFile($dir.'/frontend-delivery-handoff-inputs.json'));
    }

    private function fixtureDir(): string
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-handoff-command-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir.'/evidence/artifacts');
        $taskSpecHash = str_repeat('a', 64);
        $visualGate = app(AtlasFrontendVisualQualityGateService::class);
        $reviewGate = app(AtlasFrontendDesignReviewService::class);
        $qualityBudgetGate = app(AtlasFrontendQualityBudgetGateService::class);
        $evidenceGate = app(AtlasFrontendEvidencePackVerifierService::class);

        File::put($dir.'/provider-instruction-packet.json', json_encode($this->providerPacket(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        File::put($dir.'/visual-quality-report.json', json_encode([
            'schema_version' => AtlasFrontendVisualQualityGateService::REPORT_SCHEMA_VERSION,
            'status' => 'passed',
            'task_spec_hash' => $taskSpecHash,
            'routes' => ['/', '/settings'],
            'viewports' => $visualGate->requiredViewports(),
            'checks' => array_fill_keys($visualGate->requiredChecks(), 'passed'),
            'artifacts' => array_map(fn (string $kind): array => [
                'kind' => $kind,
                'path' => 'artifacts/'.$kind.'.json',
                'sha256' => str_repeat('b', 64),
            ], $visualGate->requiredArtifactKinds()),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        File::put($dir.'/design-review-report.json', json_encode([
            'schema_version' => AtlasFrontendDesignReviewService::REPORT_SCHEMA_VERSION,
            'status' => 'passed',
            'task_spec_hash' => $taskSpecHash,
            'dimensions' => collect($reviewGate->requiredDimensions())->mapWithKeys(fn (string $dimension): array => [
                $dimension => ['score' => 9, 'rationale' => 'Evidence-backed pass.', 'evidence_refs' => ['receipt://'.$dimension]],
            ])->all(),
            'evidence_refs' => ['receipt://visual-quality', 'receipt://anti-slop'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        File::put($dir.'/quality-budget-report.json', json_encode([
            'schema_version' => AtlasFrontendQualityBudgetGateService::REPORT_SCHEMA_VERSION,
            'status' => 'passed',
            'task_spec_hash' => $taskSpecHash,
            'viewports' => $qualityBudgetGate->requiredViewports(),
            'metrics' => collect($qualityBudgetGate->budgets())->mapWithKeys(fn (array $budget, string $id): array => [
                $id => $budget['warning'],
            ])->all(),
            'operator_approved_exception' => false,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $artifacts = [];
        foreach ($evidenceGate->requiredArtifactKinds() as $kind) {
            $path = 'artifacts/'.$kind.'.json';
            File::put($dir.'/evidence/'.$path, json_encode(['kind' => $kind, 'ok' => true], JSON_THROW_ON_ERROR));
            $artifacts[] = ['kind' => $kind, 'path' => $path, 'sha256' => hash_file('sha256', $dir.'/evidence/'.$path)];
        }
        File::put($dir.'/evidence/evidence-pack.json', json_encode([
            'schema_version' => AtlasFrontendEvidencePackVerifierService::PACK_SCHEMA_VERSION,
            'pack_id' => 'handoff-command-1',
            'case_id' => 'saas_dashboard_repair',
            'system' => 'atlas_frontend',
            'task_spec_hash' => $taskSpecHash,
            'artifacts' => $artifacts,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $bundle = $dir.'/bundle';
        app(AtlasFrontendProductProofRuntimeService::class)->buildStaticBundle($bundle);
        $outcomeStore = $dir.'/outcomes.jsonl';
        app(AtlasFrontendOutcomeMemoryService::class)->record([
            'status' => 'passed',
            'gates' => ['visual_quality_gate', 'design_5d_review', 'evidence_pack_verifier'],
            'evidence_refs' => ['receipt://visual-quality'],
        ], $outcomeStore);

        $run = app(AtlasFrontendRunCertificationService::class)->certify([
            'provider_packet' => $dir.'/provider-instruction-packet.json',
            'visual_report' => $dir.'/visual-quality-report.json',
            'design_review_report' => $dir.'/design-review-report.json',
            'quality_budget_report' => $dir.'/quality-budget-report.json',
            'evidence_manifest' => $dir.'/evidence/evidence-pack.json',
            'evidence_root' => $dir.'/evidence',
            'bundle' => $bundle,
            'outcome_store' => $outcomeStore,
        ]);
        File::put($dir.'/run-certification.json', json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $dir;
    }

    /**
     * @return array<string,mixed>
     */
    private function providerPacket(): array
    {
        $packet = [
            'schema_version' => AtlasFrontendProviderInstructionPacketService::SCHEMA_VERSION,
            'status' => 'ready',
            'packet_type' => 'provider_safe_frontend_execution_instruction_packet',
            'frontend_app_scope' => ['status' => 'repo_root', 'relative_name_hash' => null],
            'provider_execution_guardrails' => [
                'schema_version' => AtlasFrontendProviderInstructionPacketService::EXECUTION_GUARDRAILS_SCHEMA_VERSION,
                'selected_workspace_contract' => [
                    'selected_repository_remains_primary_workspace' => true,
                    'frontend_app_is_subscope_only' => true,
                    'frontend_app_scope_status' => 'repo_root',
                    'frontend_app_relative_name_hash' => null,
                    'space_runtime_required' => false,
                    'raw_absolute_path_returned' => false,
                ],
                'mandatory_runtime_receipts' => [
                    'pre_execution_gate_hash',
                    'work_order_hash',
                    'runbook_hash',
                    'visual_quality_report',
                    'quality_budget_report',
                    'design_review_report',
                    'evidence_pack_hash',
                    'run_certification_hash',
                    'outcome_memory_hash',
                    'handoff_hash',
                ],
                'mandatory_detector_receipts' => [
                    'atlas_frontend_static_anti_slop_detector',
                    'atlas_frontend_browser_detector_event',
                    'design_system_drift_gate',
                ],
                'world_best_claim_gate' => [
                    'requires_decisive_lead_each_complete_case' => true,
                    'minimum_decisive_lead_points' => AtlasFrontendRivalReplayHarnessService::DECISIVE_LEAD_MINIMUM_POINTS,
                ],
            ],
        ];
        $packet['provider_instruction_packet_hash'] = MissionCanonicalHash::sha256($packet);

        return $packet;
    }
}
