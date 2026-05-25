<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
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

class AtlasFrontendRunCertifyCommandTest extends TestCase
{
    public function test_run_certify_command_blocks_without_evidence(): void
    {
        $exitCode = Artisan::call('atlas:frontend:run-certify', [
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(AtlasFrontendRunCertificationService::SCHEMA_VERSION, $output);
        $this->assertStringContainsString('run_certification_hash', $output);
        $this->assertStringContainsString('evidence_pack_passed', $output);
    }

    public function test_run_certify_command_certifies_real_evidence_bundle(): void
    {
        $dir = $this->fixtureDir();
        $bundle = $dir.'/bundle';
        app(AtlasFrontendProductProofRuntimeService::class)->buildStaticBundle($bundle);
        $outcomeStore = $dir.'/outcomes.jsonl';
        app(AtlasFrontendOutcomeMemoryService::class)->record([
            'status' => 'passed',
            'gates' => ['visual_quality_gate', 'design_5d_review', 'evidence_pack_verifier'],
            'evidence_refs' => ['receipt://visual-quality'],
        ], $outcomeStore);

        $exitCode = Artisan::call('atlas:frontend:run-certify', [
            '--provider-packet' => $dir.'/provider-instruction-packet.json',
            '--visual-report' => $dir.'/visual-quality-report.json',
            '--design-review-report' => $dir.'/design-review-report.json',
            '--quality-budget-report' => $dir.'/quality-budget-report.json',
            '--evidence-manifest' => $dir.'/evidence/evidence-pack.json',
            '--evidence-root' => $dir.'/evidence',
            '--bundle' => $bundle,
            '--outcome-store' => $outcomeStore,
            '--json' => true,
            '--strict' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(AtlasFrontendRunCertificationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('certified', $payload['status']);
        $this->assertSame(str_repeat('a', 64), $payload['task_spec_hash']);
        $this->assertSame('pass', collect($payload['checks'])->firstWhere('id', 'provider_execution_guardrails_present')['status']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.frontend_completion_claim_allowed'));
    }

    private function fixtureDir(): string
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-run-cert-command-'.bin2hex(random_bytes(4));
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
            'pack_id' => 'run-cert-command-1',
            'case_id' => 'saas_dashboard_repair',
            'system' => 'atlas_frontend',
            'task_spec_hash' => $taskSpecHash,
            'artifacts' => $artifacts,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

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
