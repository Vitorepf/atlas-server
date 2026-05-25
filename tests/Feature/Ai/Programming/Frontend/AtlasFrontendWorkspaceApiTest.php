<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignDossierService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignReviewService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendEvidencePackVerifierService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendOutcomeMemoryService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendProductProofRuntimeService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendProviderInstructionPacketService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendQualityBudgetGateService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendRivalReplayHarnessService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendRunCertificationService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendVisualQualityGateService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendWorkspaceApiTest extends TestCase
{
    public function test_portfolio_api_scans_local_repo_folder_without_authorizing_execution(): void
    {
        $root = sys_get_temp_dir().'/atlas-frontend-workspace-api-portfolio-'.bin2hex(random_bytes(4));
        $repo = $this->frontendRepo($root, 'atlas-shop');

        $response = $this->withHeaders($this->headers())->getJson('/atlas-code/frontend/portfolio?'.http_build_query([
            'root' => $root,
            'task' => 'Melhorar checkout web',
            'max_depth' => 2,
        ]));

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.frontend.workspace_api.portfolio.v1')
            ->assertJsonPath('surface', 'atlas_code_frontend_workspace_selection')
            ->assertJsonPath('portfolio.schema_version', 'atlas.frontend.company_portfolio.v1')
            ->assertJsonPath('portfolio.primary_runtime_entrypoint', 'operator_selected_repository_workspace')
            ->assertJsonPath('portfolio.scan_policy.discovery_model', 'local_folder_with_multiple_repositories')
            ->assertJsonPath('portfolio.summary.candidate_repo_count', 1)
            ->assertJsonPath('portfolio.summary.portfolio_dispatch_allowed', false)
            ->assertJsonPath('meta.execution_allowed', false)
            ->assertJsonPath('meta.provider_dispatch_allowed', false)
            ->assertJsonPath('meta.next_required_contract', 'atlas.frontend.selected_workspace.v1')
            ->assertJsonPath('meta.portfolio_scan_is_inventory_only', true);

        $payload = $response->json();
        $this->assertSame('atlas-shop', data_get($payload, 'portfolio.repositories.0.repo_ref.relative_name'));
        $this->assertStringNotContainsString($root, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($repo, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_selected_workspace_api_binds_repo_as_primary_workspace_and_frontend_app_as_subscope(): void
    {
        $root = sys_get_temp_dir().'/atlas-frontend-workspace-api-selected-'.bin2hex(random_bytes(4));
        $repo = $this->frontendRepo($root, 'atlas-commerce');
        File::ensureDirectoryExists($repo.'/apps/web/src');
        File::put($repo.'/apps/web/package.json', json_encode([
            'scripts' => ['dev' => 'vite', 'test' => 'vitest run', 'build' => 'vite build'],
            'dependencies' => ['react' => '^latest', 'vite' => '^latest'],
        ], JSON_THROW_ON_ERROR));

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/frontend/selected-workspace', [
            'workspace' => $repo,
            'frontend_app' => 'apps/web',
            'task' => 'Construir dashboard SaaS premium',
            'selection_source' => 'atlas_code',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.frontend.workspace_api.selected_workspace.v1')
            ->assertJsonPath('surface', 'atlas_code_frontend_workspace_selection')
            ->assertJsonPath('selected_workspace.schema_version', 'atlas.frontend.selected_workspace.v1')
            ->assertJsonPath('selected_workspace.status', 'selected')
            ->assertJsonPath('selected_workspace.selection_type', 'operator_selected_repository_workspace')
            ->assertJsonPath('selected_workspace.confirmed_frontend_app_scope.status', 'subscope_selected')
            ->assertJsonPath('selected_workspace.confirmed_frontend_app_scope.relative_name', 'apps/web')
            ->assertJsonPath('selected_workspace.confirmed_frontend_app_scope.claim_policy.selected_repository_remains_primary_workspace', true)
            ->assertJsonPath('selected_workspace.confirmed_frontend_app_scope.claim_policy.relative_subscope_is_not_workspace', true)
            ->assertJsonPath('meta.selected_repository_is_primary_workspace', true)
            ->assertJsonPath('meta.frontend_app_is_subscope_only', true)
            ->assertJsonPath('meta.space_runtime_required', false)
            ->assertJsonPath('meta.provider_dispatch_allowed', false);

        $payload = $response->json();
        $this->assertStringNotContainsString($repo, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($root, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_selected_workspace_api_rejects_invalid_frontend_app_subscope(): void
    {
        $root = sys_get_temp_dir().'/atlas-frontend-workspace-api-invalid-'.bin2hex(random_bytes(4));
        $repo = $this->frontendRepo($root, 'atlas-admin');

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/frontend/selected-workspace', [
            'workspace' => $repo,
            'frontend_app' => 'apps/missing',
            'task' => 'Melhorar painel admin',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('selected_workspace.status', 'selected')
            ->assertJsonPath('selected_workspace.frontend_app_candidates.status', 'requested_frontend_app_subscope_invalid')
            ->assertJsonPath('meta.selected_repository_is_primary_workspace', true)
            ->assertJsonPath('meta.frontend_app_is_subscope_only', true)
            ->assertJsonPath('meta.space_runtime_required', false);
    }

    public function test_runtime_projection_api_returns_operator_cockpit_after_repo_selection(): void
    {
        $root = sys_get_temp_dir().'/atlas-frontend-workspace-api-runtime-'.bin2hex(random_bytes(4));
        $repo = $this->frontendRepo($root, 'atlas-product');
        $this->fillDesignDocs($repo);

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/frontend/runtime-projection', [
            'workspace' => $repo,
            'task' => 'Ajustar componente Button no frontend da empresa',
            'provider' => 'codex',
            'acceptance_criteria' => true,
            'test_plan' => true,
            'visual_quality_plan' => true,
            'evidence_plan' => true,
            'senior_design_review' => true,
            'asset_context' => true,
            'company_profile_ready' => true,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.frontend.workspace_api.runtime_projection.v1')
            ->assertJsonPath('surface', 'atlas_code_frontend_runtime_projection')
            ->assertJsonPath('runtime_projection.schema_version', 'atlas.frontend.workspace_runtime_projection.v1')
            ->assertJsonPath('runtime_projection.status', 'ready_for_provider_dispatch')
            ->assertJsonPath('runtime_projection.projection_type', 'read_only_selected_repo_frontend_operator_cockpit')
            ->assertJsonPath('runtime_projection.operator_cockpit.provider_dispatch_ready', true)
            ->assertJsonPath('runtime_projection.operator_cockpit.provider_dispatch_performed', false)
            ->assertJsonPath('runtime_projection.operator_cockpit.primary_action', 'dispatch_provider_with_provider_instruction_packet')
            ->assertJsonPath('runtime_projection.claim_policy.read_only_projection_does_not_write_workspace', true)
            ->assertJsonPath('runtime_projection.claim_policy.selected_repository_remains_primary_workspace', true)
            ->assertJsonPath('runtime_projection.claim_policy.frontend_app_is_subscope_only', true)
            ->assertJsonPath('runtime_projection.claim_policy.space_runtime_required', false)
            ->assertJsonPath('meta.execution_allowed', true)
            ->assertJsonPath('meta.provider_dispatch_performed', false);

        $payload = $response->json();
        $this->assertContains('provider_instruction_packet', collect(data_get($payload, 'runtime_projection.runtime_lanes'))->pluck('id')->all());
        $this->assertSame('ready', collect(data_get($payload, 'runtime_projection.runtime_lanes'))->firstWhere('id', 'provider_instruction_packet')['status']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'runtime_projection.workspace_runtime_projection_hash'));
        $this->assertStringNotContainsString($repo, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($root, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_runtime_projection_api_blocks_without_selected_repo_context(): void
    {
        $missing = sys_get_temp_dir().'/atlas-frontend-workspace-api-runtime-missing-'.bin2hex(random_bytes(4));

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/frontend/runtime-projection', [
            'workspace' => $missing,
            'task' => 'Criar dashboard',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('runtime_projection.status', 'blocked')
            ->assertJsonPath('runtime_projection.operator_cockpit.provider_dispatch_ready', false)
            ->assertJsonPath('runtime_projection.claim_policy.provider_dispatch_performed_by_this_endpoint', false)
            ->assertJsonPath('meta.selected_repository_is_primary_workspace', true)
            ->assertJsonPath('meta.frontend_app_is_subscope_only', true)
            ->assertJsonPath('meta.space_runtime_required', false);
    }

    public function test_prepare_evidence_api_writes_templates_and_provider_packet_without_returning_paths(): void
    {
        $root = sys_get_temp_dir().'/atlas-frontend-workspace-api-prepare-'.bin2hex(random_bytes(4));
        $repo = $this->frontendRepo($root, 'atlas-store');
        File::ensureDirectoryExists($repo.'/apps/web/src');
        File::put($repo.'/apps/web/package.json', json_encode([
            'scripts' => ['dev' => 'vite', 'test' => 'vitest run', 'build' => 'vite build'],
            'dependencies' => ['react' => '^latest', 'vite' => '^latest'],
        ], JSON_THROW_ON_ERROR));
        $this->fillDesignDocs($repo);
        $output = $repo.'/.atlas/frontend-evidence/apps-web';

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/frontend/prepare-evidence', [
            'workspace' => $repo,
            'frontend_app' => 'apps/web',
            'task' => 'Melhorar checkout SaaS com evidência visual e orçamento de qualidade',
            'provider' => 'codex',
            'output' => $output,
            'acceptance_criteria' => true,
            'test_plan' => true,
            'visual_quality_plan' => true,
            'evidence_plan' => true,
            'senior_design_review' => true,
            'asset_context' => true,
            'company_profile_ready' => true,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.frontend.workspace_api.prepare_evidence.v1')
            ->assertJsonPath('surface', 'atlas_code_frontend_evidence_preparation')
            ->assertJsonPath('evidence_preparation.schema_version', 'atlas.frontend.workspace_evidence_preparation.v1')
            ->assertJsonPath('evidence_preparation.status', 'ready_for_provider_dispatch')
            ->assertJsonPath('evidence_preparation.evidence_kit_status', 'ready')
            ->assertJsonPath('evidence_preparation.provider_packet_status', 'ready')
            ->assertJsonPath('evidence_preparation.claim_policy.preparation_is_not_execution', true)
            ->assertJsonPath('evidence_preparation.claim_policy.provider_dispatch_performed_by_this_endpoint', false)
            ->assertJsonPath('evidence_preparation.claim_policy.raw_absolute_path_returned', false)
            ->assertJsonPath('evidence_preparation.claim_policy.frontend_app_is_subscope_only', true)
            ->assertJsonPath('evidence_preparation.claim_policy.space_runtime_required', false)
            ->assertJsonPath('meta.provider_dispatch_performed', false)
            ->assertJsonPath('meta.frontend_completion_claim_allowed', false)
            ->assertJsonPath('meta.selected_repository_is_primary_workspace', true)
            ->assertJsonPath('meta.frontend_app_is_subscope_only', true)
            ->assertJsonPath('meta.space_runtime_required', false)
            ->assertJsonPath('meta.world_best_claim_allowed', false);

        $this->assertFileExists($output.'/provider-instruction-packet.json');
        $this->assertFileExists($output.'/scenario-matrix.json');
        $this->assertFileExists($output.'/visual-quality-report.json');
        $this->assertFileExists($output.'/quality-budget-report.json');
        $this->assertFileExists($output.'/design-review-report.json');
        $this->assertFileExists($output.'/evidence/evidence-pack.json');
        $this->assertFileExists($output.'/outcome-record-template.json');
        $this->assertFileExists($output.'/evidence-kit-manifest.json');

        $payload = $response->json();
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'evidence_preparation.evidence_preparation_hash'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'evidence_preparation.provider_packet_file_hash'));
        $this->assertStringNotContainsString($repo, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($root, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($output, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_prepare_evidence_api_blocks_without_acceptance_context_but_returns_safe_payload(): void
    {
        $root = sys_get_temp_dir().'/atlas-frontend-workspace-api-prepare-blocked-'.bin2hex(random_bytes(4));
        $repo = $this->frontendRepo($root, 'atlas-admin');
        $output = $repo.'/.atlas/frontend-evidence/root';

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/frontend/prepare-evidence', [
            'workspace' => $repo,
            'task' => 'Ajustar tela admin',
            'output' => $output,
            'acceptance_criteria' => false,
            'test_plan' => true,
            'visual_quality_plan' => true,
            'evidence_plan' => true,
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('schema_version', 'atlas.frontend.workspace_api.prepare_evidence.v1')
            ->assertJsonPath('evidence_preparation.status', 'blocked')
            ->assertJsonPath('evidence_preparation.evidence_kit_status', 'blocked')
            ->assertJsonPath('evidence_preparation.claim_policy.prepared_templates_are_not_completion_evidence', true)
            ->assertJsonPath('evidence_preparation.claim_policy.raw_absolute_path_returned', false)
            ->assertJsonPath('meta.provider_dispatch_performed', false)
            ->assertJsonPath('meta.frontend_completion_claim_allowed', false)
            ->assertJsonPath('meta.world_best_claim_allowed', false);

        $this->assertContains('scenario_matrix_not_ready', $response->json('evidence_preparation.blockers'));
        $payload = $response->json();
        $this->assertStringNotContainsString($repo, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($root, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($output, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_run_certification_api_certifies_provider_evidence_without_returning_paths(): void
    {
        $dir = $this->runCertificationFixtureDir();
        $bundle = $dir.'/bundle';
        app(AtlasFrontendProductProofRuntimeService::class)->buildStaticBundle($bundle);
        $outcomeStore = $dir.'/outcomes.jsonl';
        app(AtlasFrontendOutcomeMemoryService::class)->record([
            'status' => 'passed',
            'gates' => ['visual_quality_gate', 'design_5d_review', 'evidence_pack_verifier'],
            'evidence_refs' => ['receipt://visual-quality'],
        ], $outcomeStore);

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/frontend/run-certification', [
            'provider_packet' => $dir.'/provider-instruction-packet.json',
            'visual_report' => $dir.'/visual-quality-report.json',
            'design_review_report' => $dir.'/design-review-report.json',
            'quality_budget_report' => $dir.'/quality-budget-report.json',
            'evidence_manifest' => $dir.'/evidence/evidence-pack.json',
            'evidence_root' => $dir.'/evidence',
            'bundle' => $bundle,
            'outcome_store' => $outcomeStore,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.frontend.workspace_api.run_certification.v1')
            ->assertJsonPath('surface', 'atlas_code_frontend_delivery_certification')
            ->assertJsonPath('run_certification.schema_version', AtlasFrontendRunCertificationService::SCHEMA_VERSION)
            ->assertJsonPath('run_certification.status', 'certified')
            ->assertJsonPath('run_certification.claim_policy.frontend_completion_claim_allowed', true)
            ->assertJsonPath('run_certification.claim_policy.world_best_claim_allowed', false)
            ->assertJsonPath('meta.execution_certified', true)
            ->assertJsonPath('meta.frontend_completion_claim_allowed', true)
            ->assertJsonPath('meta.provider_dispatch_performed', false)
            ->assertJsonPath('meta.selected_repository_is_primary_workspace', true)
            ->assertJsonPath('meta.frontend_app_is_subscope_only', true)
            ->assertJsonPath('meta.space_runtime_required', false)
            ->assertJsonPath('meta.world_best_claim_allowed', false);

        $payload = $response->json();
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'run_certification.run_certification_hash'));
        $this->assertStringNotContainsString($dir, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_run_certification_api_blocks_missing_provider_evidence(): void
    {
        $missing = sys_get_temp_dir().'/atlas-frontend-workspace-api-cert-missing-'.bin2hex(random_bytes(4));

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/frontend/run-certification', [
            'provider_packet' => $missing.'/provider-instruction-packet.json',
            'visual_report' => $missing.'/visual-quality-report.json',
            'design_review_report' => $missing.'/design-review-report.json',
            'quality_budget_report' => $missing.'/quality-budget-report.json',
            'evidence_manifest' => $missing.'/evidence/evidence-pack.json',
            'evidence_root' => $missing.'/evidence',
            'outcome_store' => $missing.'/outcomes.jsonl',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('schema_version', 'atlas.frontend.workspace_api.run_certification.v1')
            ->assertJsonPath('run_certification.status', 'blocked')
            ->assertJsonPath('run_certification.claim_policy.frontend_completion_claim_allowed', false)
            ->assertJsonPath('meta.execution_certified', false)
            ->assertJsonPath('meta.frontend_completion_claim_allowed', false)
            ->assertJsonPath('meta.world_best_claim_allowed', false);

        $this->assertContains('provider_instruction_packet_ready', $response->json('run_certification.blockers'));
        $this->assertContains('evidence_pack_passed', $response->json('run_certification.blockers'));
    }

    public function test_delivery_handoff_api_compiles_ready_customer_handoff_from_certified_report(): void
    {
        $dir = $this->runCertificationFixtureDir();
        $outcomeStore = $dir.'/outcomes.jsonl';
        app(AtlasFrontendOutcomeMemoryService::class)->record([
            'status' => 'passed',
            'gates' => ['visual_quality_gate', 'design_5d_review', 'evidence_pack_verifier'],
            'evidence_refs' => ['receipt://visual-quality'],
        ], $outcomeStore);

        $certification = app(AtlasFrontendRunCertificationService::class)->certify([
            'provider_packet' => $dir.'/provider-instruction-packet.json',
            'visual_report' => $dir.'/visual-quality-report.json',
            'design_review_report' => $dir.'/design-review-report.json',
            'quality_budget_report' => $dir.'/quality-budget-report.json',
            'evidence_manifest' => $dir.'/evidence/evidence-pack.json',
            'evidence_root' => $dir.'/evidence',
            'outcome_store' => $outcomeStore,
        ]);
        $certificationPath = $dir.'/run-certification.json';
        File::put($certificationPath, json_encode($certification, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/frontend/handoff', [
            'run_certification_report' => $certificationPath,
            'evidence_manifest' => $dir.'/evidence/evidence-pack.json',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.frontend.workspace_api.delivery_handoff.v1')
            ->assertJsonPath('surface', 'atlas_code_frontend_delivery_handoff')
            ->assertJsonPath('delivery_handoff.schema_version', 'atlas.frontend.delivery_handoff.v1')
            ->assertJsonPath('delivery_handoff.status', 'ready')
            ->assertJsonPath('delivery_handoff.claim_policy.customer_handoff_allowed', true)
            ->assertJsonPath('delivery_handoff.claim_policy.world_best_claim_allowed', false)
            ->assertJsonPath('meta.customer_handoff_allowed', true)
            ->assertJsonPath('meta.provider_dispatch_performed', false)
            ->assertJsonPath('meta.selected_repository_is_primary_workspace', true)
            ->assertJsonPath('meta.frontend_app_is_subscope_only', true)
            ->assertJsonPath('meta.space_runtime_required', false)
            ->assertJsonPath('meta.world_best_claim_allowed', false);

        $payload = $response->json();
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'delivery_handoff.handoff_hash'));
        $this->assertStringNotContainsString($dir, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function frontendRepo(string $root, string $name): string
    {
        $workspace = $root.'/'.$name;
        File::ensureDirectoryExists($workspace.'/src/pages');
        File::put($workspace.'/pnpm-lock.yaml', 'lockfileVersion: 9.0');
        File::put($workspace.'/index.html', '<div id="root"></div>');
        File::put($workspace.'/src/main.tsx', 'import React from "react";');
        File::put($workspace.'/src/pages/Dashboard.tsx', 'export function Dashboard() { return <main />; }');
        File::put($workspace.'/package.json', json_encode([
            'scripts' => [
                'dev' => 'vite --host 127.0.0.1',
                'test' => 'vitest run',
                'build' => 'vite build',
                'typecheck' => 'tsc --noEmit',
            ],
            'dependencies' => [
                'react' => '^latest',
                'vite' => '^latest',
            ],
        ], JSON_THROW_ON_ERROR));

        return $workspace;
    }

    private function fillDesignDocs(string $workspace): void
    {
        $dossier = app(AtlasFrontendDesignDossierService::class);
        foreach ($dossier->requiredDocuments() as $definition) {
            File::ensureDirectoryExists(dirname($workspace.'/'.$definition['path']));
            File::put($workspace.'/'.$definition['path'], $this->filledDocument((string) $definition['title'], (array) $definition['sections']));
        }
    }

    private function runCertificationFixtureDir(): string
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-workspace-api-cert-'.bin2hex(random_bytes(4));
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
            'pack_id' => 'workspace-api-cert-1',
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

    /**
     * @param  array<int,string>  $sections
     */
    private function filledDocument(string $title, array $sections): string
    {
        $body = ['# '.$title, '', 'Status: filled'];
        foreach ($sections as $section) {
            $body[] = '## '.$section;
            $body[] = 'Concrete company-specific context for '.$section.'.';
        }

        return implode("\n\n", $body)."\n";
    }

    /**
     * @return array<string,string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'application/json',
            'X-Atlas-Token' => 'testing-atlas-token-with-enough-length',
        ];
    }
}
