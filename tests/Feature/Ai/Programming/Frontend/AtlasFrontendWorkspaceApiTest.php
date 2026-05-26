<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Models\AtlasWorkspaceProfile;
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
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
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
            ->assertJsonPath('portfolio.operator_flow.schema_version', 'atlas.frontend.company_portfolio.operator_flow.v1')
            ->assertJsonPath('portfolio.operator_flow.status', 'ready_for_repository_choice')
            ->assertJsonPath('portfolio.operator_flow.invariants.selected_repository_is_primary_workspace', true)
            ->assertJsonPath('portfolio.operator_flow.invariants.frontend_app_is_relative_subscope_only', true)
            ->assertJsonPath('portfolio.operator_flow.invariants.space_runtime_required', false)
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

    public function test_selection_receipt_api_writes_multi_repo_selection_receipt_without_authorizing_execution(): void
    {
        $root = sys_get_temp_dir().'/atlas-frontend-workspace-api-selection-receipt-'.bin2hex(random_bytes(4));
        $repo = $this->frontendRepo($root, 'atlas-commerce');
        File::ensureDirectoryExists($repo.'/apps/web/src');
        File::put($repo.'/apps/web/package.json', json_encode([
            'scripts' => ['dev' => 'vite', 'test' => 'vitest run', 'build' => 'vite build'],
            'dependencies' => ['react' => '^latest', 'vite' => '^latest'],
        ], JSON_THROW_ON_ERROR));
        $output = $repo.'/.atlas/frontend-evidence/apps-web/selection-receipt.json';

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/frontend/selection-receipt', [
            'portfolio_root' => $root,
            'workspace' => $repo,
            'frontend_app' => 'apps/web',
            'task' => 'Selecionar repo para Atlas Frontend',
            'selection_source' => 'atlas_code',
            'output' => $output,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.frontend.workspace_api.selection_receipt.v1')
            ->assertJsonPath('surface', 'atlas_code_frontend_workspace_selection_receipt')
            ->assertJsonPath('selection_receipt.schema_version', 'atlas.frontend.selected_workspace.selection_receipt.v1')
            ->assertJsonPath('selection_receipt.status', 'ready')
            ->assertJsonPath('selection_receipt.receipt_type', 'multi_repo_selected_repository_frontend_workspace_receipt')
            ->assertJsonPath('selection_receipt.portfolio_binding.portfolio_root_is_not_selected_workspace', true)
            ->assertJsonPath('selection_receipt.portfolio_binding.selected_workspace_inside_portfolio_root', true)
            ->assertJsonPath('selection_receipt.runtime_policy.selected_repository_is_primary_workspace', true)
            ->assertJsonPath('selection_receipt.runtime_policy.frontend_app_is_subscope_only', true)
            ->assertJsonPath('selection_receipt.runtime_policy.space_runtime_required', false)
            ->assertJsonPath('selection_receipt.runtime_policy.provider_dispatch_performed', false)
            ->assertJsonPath('selection_receipt.evidence_policy.selection_receipt_is_not_delivery_evidence', true)
            ->assertJsonPath('selection_receipt.evidence_policy.portfolio_candidate_is_not_execution_evidence', true)
            ->assertJsonPath('selection_receipt.write_performed', true)
            ->assertJsonPath('meta.execution_allowed', false)
            ->assertJsonPath('meta.provider_dispatch_allowed', false)
            ->assertJsonPath('meta.selected_repository_is_primary_workspace', true)
            ->assertJsonPath('meta.frontend_app_is_subscope_only', true)
            ->assertJsonPath('meta.space_runtime_required', false)
            ->assertJsonPath('meta.world_best_claim_allowed', false);

        $this->assertFileExists($output);
        $payload = $response->json();
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'selection_receipt.selection_receipt_hash'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'selection_receipt.receipt_file_hash'));
        $this->assertStringNotContainsString($repo, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($root, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($output, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_project_activation_persists_selected_repo_as_atlas_code_project_without_authorizing_execution(): void
    {
        $this->createWorkspaceProfilesTable();
        $root = sys_get_temp_dir().'/atlas-frontend-workspace-api-project-activation-'.bin2hex(random_bytes(4));
        $repo = $this->frontendRepo($root, 'atlas-commerce');
        File::ensureDirectoryExists($repo.'/apps/web/src');
        File::put($repo.'/apps/web/package.json', json_encode([
            'scripts' => ['dev' => 'vite', 'test' => 'vitest run', 'build' => 'vite build'],
            'dependencies' => ['react' => '^latest', 'vite' => '^latest'],
        ], JSON_THROW_ON_ERROR));

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/frontend/project-activation', [
            'workspace' => $repo,
            'frontend_app' => 'apps/web',
            'task' => 'Ativar repo selecionado no Atlas Code',
            'project_slug' => 'atlas-commerce',
            'project_name' => 'Atlas Commerce',
            'selection_source' => 'atlas_code',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.frontend.workspace_api.project_activation.v1')
            ->assertJsonPath('surface', 'atlas_code_frontend_project_workspace_activation')
            ->assertJsonPath('project_activation.schema_version', 'atlas.frontend.selected_workspace.project_activation.v1')
            ->assertJsonPath('project_activation.status', 'ready')
            ->assertJsonPath('project_activation.activation_type', 'selected_repository_to_atlas_code_project_workspace')
            ->assertJsonPath('project_activation.project_workspace.slug', 'atlas-commerce')
            ->assertJsonPath('project_activation.project_workspace.name', 'Atlas Commerce')
            ->assertJsonPath('project_activation.project_workspace.kind', 'frontend_product_repo')
            ->assertJsonPath('project_activation.project_workspace.workspace_path_exists', true)
            ->assertJsonPath('project_activation.runtime_policy.atlas_code_project_workspace_persisted', true)
            ->assertJsonPath('project_activation.runtime_policy.selected_repository_is_primary_workspace', true)
            ->assertJsonPath('project_activation.runtime_policy.frontend_app_is_subscope_only', true)
            ->assertJsonPath('project_activation.runtime_policy.space_runtime_required', false)
            ->assertJsonPath('project_activation.runtime_policy.provider_dispatch_performed', false)
            ->assertJsonPath('meta.execution_allowed', false)
            ->assertJsonPath('meta.provider_dispatch_allowed', false)
            ->assertJsonPath('meta.selected_repository_is_primary_workspace', true)
            ->assertJsonPath('meta.frontend_app_is_subscope_only', true)
            ->assertJsonPath('meta.space_runtime_required', false);

        $this->assertDatabaseHas('atlas_workspace_profiles', [
            'slug' => 'atlas-commerce',
            'name' => 'Atlas Commerce',
            'kind' => 'frontend_product_repo',
            'workspace_path' => $repo,
            'source' => 'atlas_frontend_selected_workspace',
            'status' => 'active',
        ]);

        $payload = $response->json();
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'project_activation.project_activation_hash'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'project_activation.project_workspace.workspace_path_hash'));
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

    public function test_control_plane_api_summarizes_market_and_public_proof_without_returning_paths(): void
    {
        $root = sys_get_temp_dir().'/atlas-frontend-workspace-api-control-plane-'.bin2hex(random_bytes(4));
        $repo = $this->frontendRepo($root, 'atlas-shop');
        $bundle = $repo.'/.atlas/frontend-evidence/apps-web/product-proof';
        $rivalEvidence = $repo.'/.atlas/frontend-evidence/apps-web/rival-replay';

        File::ensureDirectoryExists($repo.'/apps/web/src/components/ui');
        File::ensureDirectoryExists($repo.'/apps/web/src/pages');
        File::put($repo.'/apps/web/package.json', json_encode([
            'scripts' => ['dev' => 'vite', 'test' => 'vitest run', 'build' => 'vite build'],
            'dependencies' => ['react' => '^latest', 'vite' => '^latest', 'tailwindcss' => '^latest'],
        ], JSON_THROW_ON_ERROR));
        File::put($repo.'/apps/web/src/pages/Dashboard.tsx', 'export function Dashboard() { return <main />; }');
        File::put($repo.'/apps/web/src/components/ui/Button.tsx', 'export function Button() { return <button className="bg-primary text-white" />; }');
        File::put($repo.'/apps/web/src/styles.css', ':root { --color-primary: #123456; --space-2: 8px; }');
        $this->fillDesignDocs($repo);
        app(AtlasFrontendProductProofRuntimeService::class)->buildStaticBundle($bundle, 'apps/web');
        app(AtlasFrontendRivalReplayHarnessService::class)->writeRunnerKit($rivalEvidence);

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/frontend/control-plane', [
            'workspace' => $repo,
            'frontend_app' => 'apps/web',
            'task' => 'Avaliar readiness Atlas Frontend',
            'acceptance_criteria' => true,
            'test_plan' => true,
            'visual_quality_plan' => true,
            'evidence_plan' => true,
            'senior_design_review' => true,
            'asset_context' => true,
            'company_profile_ready' => true,
            'rival_evidence' => $rivalEvidence,
            'bundle' => $bundle,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.frontend.workspace_api.control_plane.v1')
            ->assertJsonPath('surface', 'atlas_code_frontend_control_plane')
            ->assertJsonPath('control_plane.schema_version', 'atlas.frontend.control_plane.v1')
            ->assertJsonPath('control_plane.status', 'warning')
            ->assertJsonPath('control_plane.input_scope.frontend_app_hash', hash('sha256', 'apps/web'))
            ->assertJsonPath('control_plane.readiness_levels.runtime_contract_ready', true)
            ->assertJsonPath('control_plane.readiness_levels.external_replay_ready', false)
            ->assertJsonPath('control_plane.readiness_levels.public_distribution_ready', false)
            ->assertJsonPath('control_plane.claim_policy.may_claim_more_complete_than_impeccable', false)
            ->assertJsonPath('control_plane.claim_policy.private_benchmark_for_internal_improvement_only', true)
            ->assertJsonPath('control_plane.claim_policy.world_best_claim_allowed', false)
            ->assertJsonPath('control_plane.claim_policy.documentation_only_claim_forbidden', true)
            ->assertJsonPath('control_plane.signals.rival_replay.status', 'ready_for_replay')
            ->assertJsonPath('control_plane.signals.publication.status', 'local_ready')
            ->assertJsonPath('meta.provider_dispatch_performed', false)
            ->assertJsonPath('meta.selected_repository_is_primary_workspace', true)
            ->assertJsonPath('meta.frontend_app_is_subscope_only', true)
            ->assertJsonPath('meta.space_runtime_required', false)
            ->assertJsonPath('meta.world_best_claim_allowed', false);

        $payload = $response->json();
        $this->assertContains('external_rival_replay_not_completed', data_get($payload, 'control_plane.warnings'));
        $this->assertContains('public_distribution_receipt_not_verified', data_get($payload, 'control_plane.warnings'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'control_plane.control_plane_hash'));
        $this->assertStringNotContainsString($repo, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($root, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($bundle, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($rivalEvidence, json_encode($payload, JSON_THROW_ON_ERROR));
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

    public function test_prepare_rival_replay_api_writes_operator_packet_without_returning_paths(): void
    {
        $root = sys_get_temp_dir().'/atlas-frontend-workspace-api-rival-replay-'.bin2hex(random_bytes(4));
        $repo = $this->frontendRepo($root, 'atlas-shop');
        $output = $repo.'/.atlas/frontend-evidence/apps-web/rival-replay';

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/frontend/prepare-rival-replay', [
            'workspace' => $repo,
            'frontend_app' => 'apps/web',
            'task' => 'Provar superioridade contra Impeccable e Claude Design',
            'output' => $output,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.frontend.workspace_api.prepare_rival_replay.v1')
            ->assertJsonPath('surface', 'atlas_code_frontend_rival_replay_preparation')
            ->assertJsonPath('rival_replay_preparation.schema_version', 'atlas.frontend.workspace_rival_replay_preparation.v1')
            ->assertJsonPath('rival_replay_preparation.status', 'ready_for_external_rival_replay')
            ->assertJsonPath('rival_replay_preparation.runner_kit_schema_version', 'atlas.frontend.rival_replay_runner_kit.v1')
            ->assertJsonPath('rival_replay_preparation.worklist_schema_version', 'atlas.frontend.rival_replay_evidence_worklist.v1')
            ->assertJsonPath('rival_replay_preparation.proof_contract_schema_version', 'atlas.frontend.rival_replay_competitive_proof_contract_file.v1')
            ->assertJsonPath('rival_replay_preparation.operator_packet_schema_version', 'atlas.frontend.rival_replay_operator_packet.v1')
            ->assertJsonPath('rival_replay_preparation.operator_packet_status', 'ready_for_external_operator_replay')
            ->assertJsonPath('rival_replay_preparation.operator_packet_verification_schema_version', 'atlas.frontend.rival_replay_operator_packet_verification.v1')
            ->assertJsonPath('rival_replay_preparation.operator_packet_verification_status', 'passed')
            ->assertJsonPath('rival_replay_preparation.external_operator_run_count', 10)
            ->assertJsonPath('rival_replay_preparation.run_packet_count', 15)
            ->assertJsonPath('rival_replay_preparation.action_queue.schema_version', 'atlas.frontend.rival_replay_evidence_worklist.v1')
            ->assertJsonPath('rival_replay_preparation.action_queue.status', 'pending')
            ->assertJsonPath('rival_replay_preparation.action_queue.work_item_count', 15)
            ->assertJsonPath('rival_replay_preparation.action_queue.work_items.0.requires_evidence_pack', true)
            ->assertJsonPath('rival_replay_preparation.action_queue.raw_absolute_path_returned', false)
            ->assertJsonPath('rival_replay_preparation.claim_policy.runner_kit_is_not_replay_evidence', true)
            ->assertJsonPath('rival_replay_preparation.claim_policy.operator_packet_is_not_replay_evidence', true)
            ->assertJsonPath('rival_replay_preparation.claim_policy.operator_packet_verification_is_not_replay_evidence', true)
            ->assertJsonPath('rival_replay_preparation.claim_policy.external_rival_replay_receipts_required', true)
            ->assertJsonPath('rival_replay_preparation.claim_policy.raw_absolute_path_returned', false)
            ->assertJsonPath('rival_replay_preparation.claim_policy.world_best_claim_allowed', false)
            ->assertJsonPath('meta.provider_dispatch_performed', false)
            ->assertJsonPath('meta.frontend_completion_claim_allowed', false)
            ->assertJsonPath('meta.selected_repository_is_primary_workspace', true)
            ->assertJsonPath('meta.frontend_app_is_subscope_only', true)
            ->assertJsonPath('meta.space_runtime_required', false)
            ->assertJsonPath('meta.world_best_claim_allowed', false);

        $this->assertFileExists($output.'/replay-runner-kit.json');
        $this->assertFileExists($output.'/replay-evidence-worklist.json');
        $this->assertFileExists($output.'/replay-competitive-proof-contract.json');
        $this->assertFileExists($output.'/replay-operator-packet.json');
        $this->assertFileExists($output.'/saas_dashboard_repair/task-spec.json');
        $this->assertFileExists($output.'/saas_dashboard_repair/pbakaus_impeccable/manifest.json');
        $this->assertFileExists($output.'/saas_dashboard_repair/pbakaus_impeccable/evidence/evidence-pack.json');

        $payload = $response->json();
        $this->assertContains('pbakaus_impeccable', data_get($payload, 'rival_replay_preparation.system_ids'));
        $this->assertContains('claude_design_plugin', data_get($payload, 'rival_replay_preparation.system_ids'));
        $this->assertContains('open_replay_operator_packet', data_get($payload, 'rival_replay_preparation.required_next_actions'));
        $this->assertContains('fill_and_hash_missing_rival_replay_evidence_packs', data_get($payload, 'rival_replay_preparation.action_queue.next_actions'));
        $this->assertContains('external_rival_replay_receipts_missing', data_get($payload, 'rival_replay_preparation.blockers'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'rival_replay_preparation.proof_contract_file_hash'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'rival_replay_preparation.operator_packet_hash'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'rival_replay_preparation.operator_packet_verification_hash'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'rival_replay_preparation.rival_replay_preparation_hash'));
        $this->assertStringNotContainsString($repo, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($root, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($output, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_inspect_rival_replay_api_summarizes_external_replay_without_returning_paths(): void
    {
        $root = sys_get_temp_dir().'/atlas-frontend-workspace-api-rival-inspect-'.bin2hex(random_bytes(4));
        $repo = $this->frontendRepo($root, 'atlas-shop');
        $output = $repo.'/.atlas/frontend-evidence/apps-web/rival-replay';

        app(AtlasFrontendRivalReplayHarnessService::class)->writeRunnerKit($output);

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/frontend/inspect-rival-replay', [
            'workspace' => $repo,
            'frontend_app' => 'apps/web',
            'task' => 'Inspecionar replay competitivo',
            'evidence' => $output,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.frontend.workspace_api.inspect_rival_replay.v1')
            ->assertJsonPath('surface', 'atlas_code_frontend_rival_replay_inspection')
            ->assertJsonPath('rival_replay_inspection.schema_version', 'atlas.frontend.workspace_rival_replay_inspection.v1')
            ->assertJsonPath('rival_replay_inspection.status', 'ready_for_replay')
            ->assertJsonPath('rival_replay_inspection.summary.total_runs', 15)
            ->assertJsonPath('rival_replay_inspection.summary.external_replay_completed', false)
            ->assertJsonPath('rival_replay_inspection.action_queue.schema_version', 'atlas.frontend.rival_replay_evidence_worklist.v1')
            ->assertJsonPath('rival_replay_inspection.action_queue.status', 'pending')
            ->assertJsonPath('rival_replay_inspection.action_queue.work_item_count', 15)
            ->assertJsonPath('rival_replay_inspection.action_queue.work_items.0.requires_evidence_pack', true)
            ->assertJsonPath('rival_replay_inspection.action_queue.raw_absolute_path_returned', false)
            ->assertJsonPath('rival_replay_inspection.competitive_proof_contract.schema_version', 'atlas.frontend.rival_replay_competitive_proof_contract.v1')
            ->assertJsonPath('rival_replay_inspection.competitive_proof_contract.status', 'evidence_packs_required')
            ->assertJsonPath('rival_replay_inspection.competitive_proof_contract.claim_policy.may_claim_world_best_frontend_system', false)
            ->assertJsonPath('rival_replay_inspection.claim_policy.may_claim_external_replay_completed', false)
            ->assertJsonPath('rival_replay_inspection.claim_policy.may_claim_world_best_frontend_system', false)
            ->assertJsonPath('rival_replay_inspection.claim_policy.raw_absolute_path_returned', false)
            ->assertJsonPath('rival_replay_inspection.claim_policy.space_runtime_required', false)
            ->assertJsonPath('meta.provider_dispatch_performed', false)
            ->assertJsonPath('meta.frontend_completion_claim_allowed', false)
            ->assertJsonPath('meta.selected_repository_is_primary_workspace', true)
            ->assertJsonPath('meta.frontend_app_is_subscope_only', true)
            ->assertJsonPath('meta.space_runtime_required', false)
            ->assertJsonPath('meta.world_best_claim_allowed', false);

        $payload = $response->json();
        $this->assertCount(15, data_get($payload, 'rival_replay_inspection.runs'));
        $this->assertCount(15, data_get($payload, 'rival_replay_inspection.action_queue.work_items'));
        $this->assertContains('fill_and_hash_missing_rival_replay_evidence_packs', data_get($payload, 'rival_replay_inspection.action_queue.next_actions'));
        $this->assertContains('fill_and_hash_missing_rival_replay_evidence_packs', data_get($payload, 'rival_replay_inspection.competitive_proof_contract.next_minimum_actions'));
        $this->assertContains('external_rival_replay_artifacts_required_for_world_best_claim', data_get($payload, 'rival_replay_inspection.remaining_gaps'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'rival_replay_inspection.rival_replay_inspection_hash'));
        $this->assertStringNotContainsString($repo, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($root, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($output, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_replay_apply_patch_api_applies_provider_safe_patch_without_returning_paths(): void
    {
        $root = sys_get_temp_dir().'/atlas-frontend-workspace-api-replay-apply-patch-'.bin2hex(random_bytes(4));
        $repo = $this->frontendRepo($root, 'atlas-shop');
        $output = $repo.'/.atlas/frontend-evidence/apps-web/rival-replay';
        $service = app(AtlasFrontendRivalReplayHarnessService::class);
        $service->writeTemplate($output);
        $taskSpec = json_decode(File::get($output.'/saas_dashboard_repair/task-spec.json'), true);
        $taskSpecHash = (string) ($taskSpec['task_spec_hash'] ?? hash_file('sha256', $output.'/saas_dashboard_repair/task-spec.json'));
        $hashes = $this->writeReplayEvidencePack($output, 'saas_dashboard_repair', 'pbakaus_impeccable', $taskSpecHash);

        File::put($output.'/saas_dashboard_repair/pbakaus_impeccable/manifest.json', json_encode([
            'case_id' => 'saas_dashboard_repair',
            'system' => 'pbakaus_impeccable',
            'status' => 'complete',
            'run_id' => 'workspace-api-replay-apply-patch',
            'task_spec_hash' => $taskSpecHash,
            'task_spec_ref' => '../task-spec.json',
            'evidence_pack_ref' => 'evidence/evidence-pack.json',
            'output_artifact_ref' => 'artifact://workspace-api-replay-apply-patch',
            'output_artifact_hash' => $hashes['output_artifact'],
            'screenshot_hashes' => [$hashes['screenshot_set']],
            'anti_slop_report_hash' => $hashes['anti_slop_report'],
            'verification_hashes' => [$hashes['verification_report']],
            'score_breakdown' => [
                'product_intent_fit' => 2,
                'visual_craft' => 2,
                'interaction_completeness' => 2,
                'engineering_integration' => 1,
                'evidence_quality' => 2,
            ],
            'score_total' => 9,
            'score_max' => 100,
            'completed_at' => '2026-05-25T00:00:00Z',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $patch = $service->writeExternalExecutionReceiptTemplate($output, 'saas_dashboard_repair', 'pbakaus_impeccable');
        $patch['manifest_patch']['external_execution_receipt']['status'] = 'verified';
        $patch['manifest_patch']['external_execution_receipt']['captured_at'] = '2026-05-25T00:00:00Z';
        $patch['manifest_patch']['external_execution_receipt']['operator_approved'] = true;
        File::put($output.'/external-patch.json', json_encode($patch, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/frontend/replay-apply-patch', [
            'workspace' => $repo,
            'frontend_app' => 'apps/web',
            'task' => 'Aplicar receipt externo verificado ao replay competitivo',
            'evidence' => $output,
            'patch' => $output.'/external-patch.json',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.frontend.workspace_api.replay_apply_patch.v1')
            ->assertJsonPath('surface', 'atlas_code_frontend_rival_replay_manifest_patch_application')
            ->assertJsonPath('rival_replay_manifest_patch_application.schema_version', 'atlas.frontend.workspace_rival_replay_manifest_patch_application.v1')
            ->assertJsonPath('rival_replay_manifest_patch_application.status', 'applied')
            ->assertJsonPath('rival_replay_manifest_patch_application.application_schema_version', 'atlas.frontend.rival_replay.manifest_patch_application.v1')
            ->assertJsonPath('rival_replay_manifest_patch_application.applied_keys.0', 'external_execution_receipt')
            ->assertJsonPath('rival_replay_manifest_patch_application.write_performed', true)
            ->assertJsonPath('rival_replay_manifest_patch_application.claim_policy.manifest_patch_application_is_not_world_best_evidence', true)
            ->assertJsonPath('rival_replay_manifest_patch_application.claim_policy.raw_absolute_path_returned', false)
            ->assertJsonPath('rival_replay_manifest_patch_application.claim_policy.space_runtime_required', false)
            ->assertJsonPath('meta.provider_dispatch_performed', false)
            ->assertJsonPath('meta.frontend_completion_claim_allowed', false)
            ->assertJsonPath('meta.selected_repository_is_primary_workspace', true)
            ->assertJsonPath('meta.frontend_app_is_subscope_only', true)
            ->assertJsonPath('meta.space_runtime_required', false)
            ->assertJsonPath('meta.world_best_claim_allowed', false);

        $manifest = json_decode(File::get($output.'/saas_dashboard_repair/pbakaus_impeccable/manifest.json'), true);
        $payload = $response->json();

        $this->assertSame('verified', data_get($manifest, 'external_execution_receipt.status'));
        $this->assertContains('rerun_atlas_frontend_rival_replay_inspection', data_get($payload, 'rival_replay_manifest_patch_application.required_next_actions'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'rival_replay_manifest_patch_application.application_hash'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'rival_replay_manifest_patch_application.workspace_manifest_patch_application_hash'));
        $this->assertStringNotContainsString($repo, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($root, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($output, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_replay_template_apis_write_provider_safe_patches_without_returning_paths(): void
    {
        $root = sys_get_temp_dir().'/atlas-frontend-workspace-api-replay-templates-'.bin2hex(random_bytes(4));
        $repo = $this->frontendRepo($root, 'atlas-shop');
        $output = $repo.'/.atlas/frontend-evidence/apps-web/rival-replay';
        $service = app(AtlasFrontendRivalReplayHarnessService::class);
        $service->writeTemplate($output);
        $taskSpec = json_decode(File::get($output.'/saas_dashboard_repair/task-spec.json'), true);
        $taskSpecHash = (string) ($taskSpec['task_spec_hash'] ?? hash_file('sha256', $output.'/saas_dashboard_repair/task-spec.json'));
        $hashes = $this->writeReplayEvidencePack($output, 'saas_dashboard_repair', 'pbakaus_impeccable', $taskSpecHash);

        File::put($output.'/saas_dashboard_repair/pbakaus_impeccable/manifest.json', json_encode([
            'case_id' => 'saas_dashboard_repair',
            'system' => 'pbakaus_impeccable',
            'status' => 'complete',
            'run_id' => 'workspace-api-replay-templates',
            'task_spec_hash' => $taskSpecHash,
            'task_spec_ref' => '../task-spec.json',
            'evidence_pack_ref' => 'evidence/evidence-pack.json',
            'output_artifact_ref' => 'artifact://workspace-api-replay-templates',
            'output_artifact_hash' => $hashes['output_artifact'],
            'screenshot_hashes' => [$hashes['screenshot_set']],
            'anti_slop_report_hash' => $hashes['anti_slop_report'],
            'verification_hashes' => [$hashes['verification_report']],
            'score_breakdown' => [
                'product_intent_fit' => 2,
                'visual_craft' => 2,
                'interaction_completeness' => 2,
                'engineering_integration' => 1,
                'evidence_quality' => 2,
            ],
            'score_total' => 9,
            'score_max' => 100,
            'completed_at' => '2026-05-25T00:00:00Z',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $receipt = $this->withHeaders($this->headers())->postJson('/atlas-code/frontend/replay-external-receipt-template', [
            'workspace' => $repo,
            'frontend_app' => 'apps/web',
            'task' => 'Gerar receipt externo',
            'evidence' => $output,
            'case_id' => 'saas_dashboard_repair',
            'system' => 'pbakaus_impeccable',
        ]);
        $score = $this->withHeaders($this->headers())->postJson('/atlas-code/frontend/replay-score-template', [
            'workspace' => $repo,
            'frontend_app' => 'apps/web',
            'task' => 'Gerar score attestation',
            'evidence' => $output,
            'case_id' => 'saas_dashboard_repair',
            'system' => 'pbakaus_impeccable',
            'reviewer_ref_hash' => hash('sha256', 'workspace-api-reviewer'),
        ]);

        $receipt
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.frontend.workspace_api.replay_external_receipt_template.v1')
            ->assertJsonPath('surface', 'atlas_code_frontend_rival_replay_external_receipt_template')
            ->assertJsonPath('rival_replay_external_receipt_template.status', 'ready')
            ->assertJsonPath('rival_replay_external_receipt_template.template_schema_version', 'atlas.frontend.rival_replay.external_execution_receipt_template.v1')
            ->assertJsonPath('rival_replay_external_receipt_template.claim_policy.template_is_not_replay_evidence', true)
            ->assertJsonPath('rival_replay_external_receipt_template.claim_policy.apply_patch_required_after_operator_approval', true)
            ->assertJsonPath('rival_replay_external_receipt_template.claim_policy.raw_absolute_path_returned', false)
            ->assertJsonPath('meta.world_best_claim_allowed', false);
        $score
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.frontend.workspace_api.replay_score_template.v1')
            ->assertJsonPath('surface', 'atlas_code_frontend_rival_replay_score_template')
            ->assertJsonPath('rival_replay_score_template.status', 'ready')
            ->assertJsonPath('rival_replay_score_template.template_schema_version', 'atlas.frontend.rival_replay.score_attestation_template.v1')
            ->assertJsonPath('rival_replay_score_template.claim_policy.template_is_not_replay_evidence', true)
            ->assertJsonPath('rival_replay_score_template.claim_policy.apply_patch_required_after_operator_approval', true)
            ->assertJsonPath('rival_replay_score_template.claim_policy.raw_absolute_path_returned', false)
            ->assertJsonPath('meta.world_best_claim_allowed', false);

        $receiptPayload = $receipt->json();
        $scorePayload = $score->json();
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($receiptPayload, 'rival_replay_external_receipt_template.rival_replay_external_receipt_template_hash'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($scorePayload, 'rival_replay_score_template.rival_replay_score_template_hash'));
        $this->assertStringNotContainsString($repo, json_encode($receiptPayload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($root, json_encode($receiptPayload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($output, json_encode($receiptPayload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($repo, json_encode($scorePayload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($root, json_encode($scorePayload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($output, json_encode($scorePayload, JSON_THROW_ON_ERROR));
    }

    public function test_proof_bundle_api_writes_safe_competitive_index_without_returning_paths(): void
    {
        $root = sys_get_temp_dir().'/atlas-frontend-workspace-api-proof-bundle-'.bin2hex(random_bytes(4));
        $repo = $this->frontendRepo($root, 'atlas-shop');
        $output = $repo.'/.atlas/frontend-evidence/apps-web/rival-replay';

        app(AtlasFrontendRivalReplayHarnessService::class)->writeRunnerKit($output);

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/frontend/proof-bundle', [
            'workspace' => $repo,
            'frontend_app' => 'apps/web',
            'task' => 'Compilar proof bundle competitivo',
            'evidence' => $output,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.frontend.workspace_api.proof_bundle.v1')
            ->assertJsonPath('surface', 'atlas_code_frontend_competitive_proof_bundle')
            ->assertJsonPath('rival_replay_proof_bundle.schema_version', 'atlas.frontend.workspace_competitive_proof_bundle.v1')
            ->assertJsonPath('rival_replay_proof_bundle.status', 'pending_external_replay_evidence')
            ->assertJsonPath('rival_replay_proof_bundle.proof_bundle_schema_version', 'atlas.frontend.rival_replay_competitive_proof_bundle.v1')
            ->assertJsonPath('rival_replay_proof_bundle.operator_packet_verification_status', 'passed')
            ->assertJsonPath('rival_replay_proof_bundle.run_manifest_count', 15)
            ->assertJsonPath('rival_replay_proof_bundle.artifact_refs.proof_bundle.relative_name', 'rival-replay/replay-competitive-proof-bundle.json')
            ->assertJsonPath('rival_replay_proof_bundle.claim_policy.proof_bundle_is_not_raw_artifact_storage', true)
            ->assertJsonPath('rival_replay_proof_bundle.claim_policy.external_provider_dispatch_performed', false)
            ->assertJsonPath('rival_replay_proof_bundle.claim_policy.may_claim_world_best_frontend_system', false)
            ->assertJsonPath('rival_replay_proof_bundle.claim_policy.public_distribution_receipt_still_required_for_product_claim', true)
            ->assertJsonPath('rival_replay_proof_bundle.claim_policy.raw_absolute_path_returned', false)
            ->assertJsonPath('rival_replay_proof_bundle.claim_policy.space_runtime_required', false)
            ->assertJsonPath('meta.provider_dispatch_performed', false)
            ->assertJsonPath('meta.frontend_completion_claim_allowed', false)
            ->assertJsonPath('meta.selected_repository_is_primary_workspace', true)
            ->assertJsonPath('meta.frontend_app_is_subscope_only', true)
            ->assertJsonPath('meta.space_runtime_required', false)
            ->assertJsonPath('meta.world_best_claim_allowed', false);

        $this->assertFileExists($output.'/replay-competitive-proof-bundle.json');

        $payload = $response->json();
        $this->assertContains('fill_and_hash_missing_rival_replay_evidence_packs', data_get($payload, 'rival_replay_proof_bundle.required_next_actions'));
        $this->assertContains('external_rival_replay_receipts_or_decisive_lead_missing', data_get($payload, 'rival_replay_proof_bundle.blockers'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'rival_replay_proof_bundle.proof_bundle_hash'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'rival_replay_proof_bundle.competitive_proof_bundle_hash'));
        $this->assertStringNotContainsString($repo, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($root, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($output, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_competitive_benchmark_plan_api_exposes_improvement_action_queue_without_claim_or_paths(): void
    {
        $root = sys_get_temp_dir().'/atlas-frontend-workspace-api-competitive-benchmark-plan-'.bin2hex(random_bytes(4));
        $repo = $this->frontendRepo($root, 'atlas-shop');
        $output = $repo.'/.atlas/frontend-evidence/apps-web/rival-replay';
        $bundle = $repo.'/.atlas/frontend-evidence/apps-web/product-proof';

        app(AtlasFrontendRivalReplayHarnessService::class)->writeRunnerKit($output);

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/frontend/competitive-benchmark-plan', [
            'workspace' => $repo,
            'frontend_app' => 'apps/web',
            'task' => 'Planejar benchmark competitivo',
            'rival_evidence' => $output,
            'bundle' => $bundle,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('schema_version', 'atlas.frontend.workspace_api.competitive_benchmark_plan.v1')
            ->assertJsonPath('surface', 'atlas_code_frontend_competitive_benchmark_plan')
            ->assertJsonPath('competitive_benchmark_plan.schema_version', 'atlas.frontend.competitive_benchmark_plan.v1')
            ->assertJsonPath('competitive_benchmark_plan.plan_type', 'private_competitive_benchmark_and_improvement_loop')
            ->assertJsonPath('competitive_benchmark_plan.intent.primary_rival', 'pbakaus_impeccable')
            ->assertJsonPath('competitive_benchmark_plan.private_policy.public_claims_disabled', true)
            ->assertJsonPath('competitive_benchmark_plan.private_policy.world_best_claim_allowed', false)
            ->assertJsonPath('competitive_benchmark_plan.next_private_improvement_action.id', 'improve_live_visual_iteration')
            ->assertJsonPath('competitive_benchmark_plan.next_private_work_packet.schema_version', 'atlas.frontend.private_improvement_work_packet.v1')
            ->assertJsonPath('competitive_benchmark_plan.next_private_work_packet.target_scenario_id', 'live_visual_iteration')
            ->assertJsonPath('competitive_benchmark_plan.next_private_work_packet.claim_policy.public_claims_disabled', true)
            ->assertJsonPath('meta.provider_dispatch_performed', false)
            ->assertJsonPath('meta.selected_repository_is_primary_workspace', true)
            ->assertJsonPath('meta.frontend_app_is_subscope_only', true)
            ->assertJsonPath('meta.space_runtime_required', false)
            ->assertJsonPath('meta.world_best_claim_allowed', false);

        $payload = $response->json();
        $this->assertContains('private_rival_replay_evidence_incomplete', data_get($payload, 'competitive_benchmark_plan.blockers'));
        $this->assertContains('complete_private_rival_replay_evidence', data_get($payload, 'competitive_benchmark_plan.required_next_actions'));
        $this->assertContains('php artisan atlas:frontend:live prepare --workspace=<repo> --file=<relative-file> --target=<exact-snippet> --variant=<id:path> --json', data_get($payload, 'competitive_benchmark_plan.next_private_work_packet.safe_execution_commands'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'competitive_benchmark_plan.competitive_benchmark_plan_hash'));
        $this->assertStringNotContainsString($repo, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($root, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($output, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($bundle, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_live_source_patch_api_prepares_and_accepts_workspace_scoped_variant_file(): void
    {
        $root = sys_get_temp_dir().'/atlas-frontend-workspace-api-live-source-patch-'.bin2hex(random_bytes(4));
        $repo = $this->frontendRepo($root, 'atlas-shop');
        File::ensureDirectoryExists($repo.'/src/components');
        File::put($repo.'/src/components/Hero.tsx', "export function Hero() {\n  return <button>Checkout</button>;\n}\n");
        File::ensureDirectoryExists($repo.'/.atlas/frontend-live/variants');
        File::put($repo.'/.atlas/frontend-live/variants/hero-premium.tsx', 'return <button className="premium">Checkout seguro</button>;');

        $prepare = $this->withHeaders($this->headers())->postJson('/atlas-code/frontend/live-source-patch', [
            'workspace' => $repo,
            'action' => 'prepare',
            'file' => 'src/components/Hero.tsx',
            'target' => 'return <button>Checkout</button>;',
            'variants' => [
                ['id' => 'premium', 'path' => '.atlas/frontend-live/variants/hero-premium.tsx'],
            ],
            'visual_selection' => [
                'route' => '/checkout',
                'selector' => '[data-testid="checkout-cta"]',
                'text_excerpt' => 'Checkout private cart draft',
                'component_hint' => 'CheckoutHeroCta',
                'screenshot_hash' => str_repeat('b', 64),
                'confidence' => 0.94,
                'bounding_box' => ['x' => 32, 'y' => 128, 'width' => 220, 'height' => 48],
                'viewport' => ['width' => 1440, 'height' => 900],
            ],
            'session' => 'hero-premium-session',
        ]);

        $prepare
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.frontend.workspace_api.live_source_patch.v1')
            ->assertJsonPath('surface', 'atlas_code_frontend_live_source_patch')
            ->assertJsonPath('live_source_patch.schema_version', 'atlas.frontend.live_source_patch_result.v1')
            ->assertJsonPath('live_source_patch.operation', 'prepared')
            ->assertJsonPath('live_source_patch.status', 'prepared')
            ->assertJsonPath('live_source_patch.session.variant_count', 1)
            ->assertJsonPath('live_source_patch.session.variants.0.id', 'premium')
            ->assertJsonPath('live_source_patch.session.visual_selection.status', 'provided')
            ->assertJsonPath('live_source_patch.session.visual_selection.bounding_box.width', 220)
            ->assertJsonPath('live_source_patch.session.visual_selection.viewport.height', 900)
            ->assertJsonPath('live_source_patch.session.visual_selection.policy.raw_text_returned', false)
            ->assertJsonPath('live_source_patch.decision_receipt.schema_version', 'atlas.frontend.live_source_patch_decision_receipt.v1')
            ->assertJsonPath('live_source_patch.decision_receipt.visual_selection_status', 'provided')
            ->assertJsonPath('live_source_patch.decision_receipt.claim_policy.receipt_is_decision_evidence_not_delivery_completion', true)
            ->assertJsonPath('live_source_patch.decision_receipt.claim_policy.visual_selection_is_not_visual_quality_proof', true)
            ->assertJsonPath('meta.provider_dispatch_allowed', false)
            ->assertJsonPath('meta.frontend_completion_claim_allowed', false)
            ->assertJsonPath('meta.selected_repository_is_primary_workspace', true)
            ->assertJsonPath('meta.frontend_app_is_subscope_only', true)
            ->assertJsonPath('meta.space_runtime_required', false)
            ->assertJsonPath('meta.world_best_claim_allowed', false);

        $preparePayload = $prepare->json();
        $prepareJson = json_encode($preparePayload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Checkout seguro', $prepareJson);
        $this->assertStringNotContainsString('[data-testid="checkout-cta"]', $prepareJson);
        $this->assertStringNotContainsString('Checkout private cart draft', $prepareJson);
        $this->assertStringNotContainsString('CheckoutHeroCta', $prepareJson);
        $this->assertStringNotContainsString('/checkout', $prepareJson);
        $this->assertStringNotContainsString($repo, $prepareJson);
        $this->assertStringNotContainsString($root, $prepareJson);
        $this->assertStringNotContainsString($repo.'/.atlas/frontend-live/variants/hero-premium.tsx', $prepareJson);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($preparePayload, 'live_source_patch.result_hash'));

        $accept = $this->withHeaders($this->headers())->postJson('/atlas-code/frontend/live-source-patch', [
            'workspace' => $repo,
            'action' => 'accept',
            'session' => 'hero-premium-session',
            'accept_variant' => 'premium',
        ]);

        $accept
            ->assertOk()
            ->assertJsonPath('live_source_patch.operation', 'accepted')
            ->assertJsonPath('live_source_patch.status', 'accepted')
            ->assertJsonPath('live_source_patch.decision_receipt.status', 'accepted')
            ->assertJsonPath('live_source_patch.decision_receipt.source_policy.raw_original_or_variants_returned', false)
            ->assertJsonPath('live_source_patch.claim_policy.accepted_live_patch_requires_visual_quality_gate', true)
            ->assertJsonPath('live_source_patch.claim_policy.accepted_live_patch_requires_run_certification_before_completion_claim', true)
            ->assertJsonPath('meta.frontend_completion_claim_allowed', false)
            ->assertJsonPath('meta.world_best_claim_allowed', false);

        $this->assertStringContainsString('Checkout seguro', File::get($repo.'/src/components/Hero.tsx'));
    }

    public function test_live_source_patch_api_rejects_variant_file_outside_workspace(): void
    {
        $root = sys_get_temp_dir().'/atlas-frontend-workspace-api-live-source-patch-outside-'.bin2hex(random_bytes(4));
        $repo = $this->frontendRepo($root, 'atlas-shop');
        File::ensureDirectoryExists($repo.'/src/components');
        File::put($repo.'/src/components/Hero.tsx', "export function Hero() {\n  return <button>Checkout</button>;\n}\n");
        $outside = sys_get_temp_dir().'/atlas-live-source-outside-'.bin2hex(random_bytes(4)).'.tsx';
        File::put($outside, 'return <button>Outside</button>;');

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/frontend/live-source-patch', [
            'workspace' => $repo,
            'action' => 'prepare',
            'file' => 'src/components/Hero.tsx',
            'target' => 'return <button>Checkout</button>;',
            'variants' => [
                ['id' => 'outside', 'path' => $outside],
            ],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('schema_version', 'atlas.frontend.workspace_api.live_source_patch.v1')
            ->assertJsonPath('surface', 'atlas_code_frontend_live_source_patch')
            ->assertJsonPath('live_source_patch.status', 'failed')
            ->assertJsonPath('live_source_patch.error', 'variant_file_outside_workspace')
            ->assertJsonPath('meta.provider_dispatch_allowed', false)
            ->assertJsonPath('meta.frontend_completion_claim_allowed', false)
            ->assertJsonPath('meta.world_best_claim_allowed', false);

        $payloadJson = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($repo, $payloadJson);
        $this->assertStringNotContainsString($root, $payloadJson);
        $this->assertStringNotContainsString($outside, $payloadJson);
    }

    public function test_live_source_patch_api_accepts_browser_bridge_prehashed_visual_selection(): void
    {
        $root = sys_get_temp_dir().'/atlas-frontend-workspace-api-live-source-patch-browser-selection-'.bin2hex(random_bytes(4));
        $repo = $this->frontendRepo($root, 'atlas-shop');
        File::ensureDirectoryExists($repo.'/src/components');
        File::put($repo.'/src/components/Hero.tsx', "export function Hero() {\n  return <button>Checkout</button>;\n}\n");
        File::ensureDirectoryExists($repo.'/.atlas/frontend-live/variants');
        File::put($repo.'/.atlas/frontend-live/variants/hero-premium.tsx', 'return <button class="premium">Checkout seguro</button>;');
        $selectorHash = str_repeat('c', 64);

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/frontend/live-source-patch', [
            'workspace' => $repo,
            'action' => 'prepare',
            'file' => 'src/components/Hero.tsx',
            'target' => 'return <button>Checkout</button>;',
            'variants' => [
                ['id' => 'premium', 'path' => '.atlas/frontend-live/variants/hero-premium.tsx'],
            ],
            'visual_selection' => [
                'route_hash' => str_repeat('b', 64),
                'selector_hash' => $selectorHash,
                'text_excerpt_hash' => str_repeat('d', 64),
                'component_hint_hash' => str_repeat('e', 64),
                'bounding_box' => ['x' => 21, 'y' => 45, 'width' => 180, 'height' => 44],
                'viewport' => ['width' => 1280, 'height' => 720],
                'confidence' => 0.82,
            ],
            'session' => 'hero-browser-selection-session',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('live_source_patch.status', 'prepared')
            ->assertJsonPath('live_source_patch.session.visual_selection.status', 'provided')
            ->assertJsonPath('live_source_patch.session.visual_selection.selector_hash', $selectorHash)
            ->assertJsonPath('live_source_patch.session.visual_selection.bounding_box.width', 180)
            ->assertJsonPath('live_source_patch.decision_receipt.visual_selection_status', 'provided')
            ->assertJsonPath('live_source_patch.decision_receipt.claim_policy.visual_selection_is_not_visual_quality_proof', true)
            ->assertJsonPath('meta.frontend_completion_claim_allowed', false);

        $payloadJson = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($repo, $payloadJson);
        $this->assertStringNotContainsString($root, $payloadJson);
    }

    public function test_live_visual_selection_api_records_and_recovers_provider_safe_session_inbox(): void
    {
        $root = sys_get_temp_dir().'/atlas-frontend-workspace-api-live-visual-selection-'.bin2hex(random_bytes(4));
        $repo = $this->frontendRepo($root, 'atlas-shop');
        $selectorHash = str_repeat('a', 64);

        $record = $this->withHeaders($this->headers())->postJson('/atlas-code/frontend/live-visual-selection', [
            'workspace' => $repo,
            'action' => 'record',
            'session' => 'checkout-session',
            'selection' => [
                'route' => '/checkout/private',
                'selector_hash' => $selectorHash,
                'text_excerpt' => 'Checkout private cart draft',
                'component_hint' => 'CheckoutHeroCta',
                'screenshot_hash' => str_repeat('b', 64),
                'confidence' => 0.88,
                'bounding_box' => ['x' => 14, 'y' => 40, 'width' => 180, 'height' => 44],
                'viewport' => ['width' => 1280, 'height' => 720],
            ],
        ]);

        $record
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.frontend.workspace_api.live_visual_selection.v1')
            ->assertJsonPath('surface', 'atlas_code_frontend_live_visual_selection')
            ->assertJsonPath('live_visual_selection.schema_version', 'atlas.frontend.live_visual_selection_inbox.v1')
            ->assertJsonPath('live_visual_selection.status', 'recorded')
            ->assertJsonPath('live_visual_selection.selection.schema_version', 'atlas.frontend.live_visual_selection.v1')
            ->assertJsonPath('live_visual_selection.selection.selector_hash', $selectorHash)
            ->assertJsonPath('live_visual_selection.selection.bounding_box.width', 180)
            ->assertJsonPath('live_visual_selection.selection.viewport.height', 720)
            ->assertJsonPath('live_visual_selection.selection.policy.raw_text_returned', false)
            ->assertJsonPath('live_visual_selection.source_policy.provider_safe_only', true)
            ->assertJsonPath('live_visual_selection.source_policy.selection_is_not_visual_quality_proof', true)
            ->assertJsonPath('meta.provider_dispatch_allowed', false)
            ->assertJsonPath('meta.frontend_completion_claim_allowed', false)
            ->assertJsonPath('meta.world_best_claim_allowed', false);

        $latest = $this->withHeaders($this->headers())->postJson('/atlas-code/frontend/live-visual-selection', [
            'workspace' => $repo,
            'action' => 'latest',
            'session' => 'checkout-session',
        ]);

        $latest
            ->assertOk()
            ->assertJsonPath('live_visual_selection.status', 'found')
            ->assertJsonPath('live_visual_selection.selection.selector_hash', $selectorHash)
            ->assertJsonPath('live_visual_selection.source_policy.absolute_path_returned', false);

        $payloadJson = json_encode($latest->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('/checkout/private', $payloadJson);
        $this->assertStringNotContainsString('Checkout private cart draft', $payloadJson);
        $this->assertStringNotContainsString('CheckoutHeroCta', $payloadJson);
        $this->assertStringNotContainsString($repo, $payloadJson);
        $this->assertStringNotContainsString($root, $payloadJson);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($latest->json(), 'live_visual_selection.inbox_hash'));
    }

    public function test_live_target_suggestions_api_finds_operator_local_patch_target_without_absolute_paths(): void
    {
        $root = sys_get_temp_dir().'/atlas-frontend-workspace-api-live-target-suggestions-'.bin2hex(random_bytes(4));
        $repo = $this->frontendRepo($root, 'atlas-shop');
        File::ensureDirectoryExists($repo.'/src/components');
        File::put($repo.'/src/components/CheckoutHeroCta.tsx', <<<'TSX'
export function CheckoutHeroCta() {
  return <button data-testid="checkout-cta">Checkout private cart draft</button>
}
TSX);

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/frontend/live-target-suggestions', [
            'workspace' => $repo,
            'session' => 'checkout-session',
            'file_hint' => 'CheckoutHeroCta',
            'max_candidates' => 3,
            'visual_selection' => [
                'selector' => 'checkout-cta',
                'text_excerpt' => 'Checkout private cart draft',
                'component_hint' => 'CheckoutHeroCta',
                'confidence' => 0.92,
                'bounding_box' => ['x' => 14, 'y' => 40, 'width' => 180, 'height' => 44],
                'viewport' => ['width' => 1280, 'height' => 720],
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.frontend.workspace_api.live_target_suggestions.v1')
            ->assertJsonPath('surface', 'atlas_code_frontend_live_target_suggestions')
            ->assertJsonPath('live_target_suggestions.schema_version', 'atlas.frontend.live_target_suggestions.v1')
            ->assertJsonPath('live_target_suggestions.status', 'suggested')
            ->assertJsonPath('live_target_suggestions.candidate_count', 1)
            ->assertJsonPath('live_target_suggestions.candidates.0.file', 'src/components/CheckoutHeroCta.tsx')
            ->assertJsonPath('live_target_suggestions.candidates.0.target_occurrence_count', 1)
            ->assertJsonPath('live_target_suggestions.candidates.0.can_prepare_directly', true)
            ->assertJsonPath('live_target_suggestions.candidates.0.operator_local', true)
            ->assertJsonPath('live_target_suggestions.candidates.0.target_snippet_is_not_provider_safe', true)
            ->assertJsonPath('live_target_suggestions.policy.operator_local_target_snippet_returned', true)
            ->assertJsonPath('live_target_suggestions.policy.target_snippet_is_not_provider_safe', true)
            ->assertJsonPath('live_target_suggestions.policy.provider_dispatch_allowed', false)
            ->assertJsonPath('live_target_suggestions.policy.absolute_path_returned', false)
            ->assertJsonPath('live_target_suggestions.policy.suggestion_is_not_delivery_evidence', true)
            ->assertJsonPath('meta.provider_dispatch_allowed', false)
            ->assertJsonPath('meta.frontend_completion_claim_allowed', false)
            ->assertJsonPath('meta.world_best_claim_allowed', false);

        $payload = $response->json();
        $this->assertStringContainsString('<button data-testid="checkout-cta">Checkout private cart draft</button>', (string) data_get($payload, 'live_target_suggestions.candidates.0.target_snippet'));
        $this->assertStringNotContainsString($repo, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($root, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'live_target_suggestions.suggestions_hash'));
    }

    public function test_publication_receipt_template_api_writes_operator_template_without_returning_paths(): void
    {
        $root = sys_get_temp_dir().'/atlas-frontend-workspace-api-publication-template-'.bin2hex(random_bytes(4));
        $repo = $this->frontendRepo($root, 'atlas-shop');
        $bundle = $repo.'/.atlas/frontend-evidence/apps-web/product-proof';
        $output = $repo.'/.atlas/frontend-evidence/apps-web';

        app(AtlasFrontendProductProofRuntimeService::class)->buildStaticBundle($bundle, 'apps/web');

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/frontend/publication-receipt-template', [
            'workspace' => $repo,
            'frontend_app' => 'apps/web',
            'task' => 'Gerar receipt publico',
            'bundle' => $bundle,
            'output' => $output,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.frontend.workspace_api.publication_receipt_template.v1')
            ->assertJsonPath('surface', 'atlas_code_frontend_publication_receipt_template')
            ->assertJsonPath('publication_receipt_template.schema_version', 'atlas.frontend.workspace_publication_receipt_template.v1')
            ->assertJsonPath('publication_receipt_template.status', 'ready')
            ->assertJsonPath('publication_receipt_template.template_schema_version', 'atlas.frontend.publication_receipt_template.v1')
            ->assertJsonPath('publication_receipt_template.bundle_context.prefilled_from_bundle', true)
            ->assertJsonPath('publication_receipt_template.artifact_refs.publication_receipt.relative_name', 'publication-receipt.json')
            ->assertJsonPath('publication_receipt_template.claim_policy.template_is_not_public_verification', true)
            ->assertJsonPath('publication_receipt_template.claim_policy.raw_absolute_path_returned', false)
            ->assertJsonPath('publication_receipt_template.claim_policy.space_runtime_required', false)
            ->assertJsonPath('meta.provider_dispatch_performed', false)
            ->assertJsonPath('meta.selected_repository_is_primary_workspace', true)
            ->assertJsonPath('meta.frontend_app_is_subscope_only', true)
            ->assertJsonPath('meta.space_runtime_required', false)
            ->assertJsonPath('meta.world_best_claim_allowed', false);

        $this->assertFileExists($output.'/publication-receipt.json');

        $payload = $response->json();
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'publication_receipt_template.template_hash'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'publication_receipt_template.publication_receipt_template_hash'));
        $this->assertStringNotContainsString($repo, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($root, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($bundle, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($output, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_publication_verify_api_reports_public_distribution_gate_without_returning_paths(): void
    {
        $root = sys_get_temp_dir().'/atlas-frontend-workspace-api-publication-verify-'.bin2hex(random_bytes(4));
        $repo = $this->frontendRepo($root, 'atlas-shop');
        $bundle = $repo.'/.atlas/frontend-evidence/apps-web/product-proof';

        app(AtlasFrontendProductProofRuntimeService::class)->buildStaticBundle($bundle, 'apps/web');

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/frontend/publication-verify', [
            'workspace' => $repo,
            'frontend_app' => 'apps/web',
            'task' => 'Verificar publicacao',
            'bundle' => $bundle,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.frontend.workspace_api.publication_verify.v1')
            ->assertJsonPath('surface', 'atlas_code_frontend_publication_verification')
            ->assertJsonPath('publication_verification.schema_version', 'atlas.frontend.workspace_publication_verification.v1')
            ->assertJsonPath('publication_verification.status', 'local_ready')
            ->assertJsonPath('publication_verification.publication_schema_version', 'atlas.frontend.publication_verifier.v1')
            ->assertJsonPath('publication_verification.frontend_app_scope.status', 'subscope_selected')
            ->assertJsonPath('publication_verification.product_site_assets.schema_version', 'atlas.frontend.product_proof_site_assets.v1')
            ->assertJsonPath('publication_verification.public_receipt_status', 'missing')
            ->assertJsonPath('publication_verification.claim_policy.local_bundle_claim_allowed', true)
            ->assertJsonPath('publication_verification.claim_policy.public_distribution_claim_allowed', false)
            ->assertJsonPath('publication_verification.claim_policy.public_distribution_requires_verified_receipt', true)
            ->assertJsonPath('publication_verification.claim_policy.raw_absolute_path_returned', false)
            ->assertJsonPath('publication_verification.claim_policy.space_runtime_required', false)
            ->assertJsonPath('meta.customer_handoff_allowed', false)
            ->assertJsonPath('meta.provider_dispatch_performed', false)
            ->assertJsonPath('meta.selected_repository_is_primary_workspace', true)
            ->assertJsonPath('meta.frontend_app_is_subscope_only', true)
            ->assertJsonPath('meta.space_runtime_required', false)
            ->assertJsonPath('meta.world_best_claim_allowed', false);

        $payload = $response->json();
        $this->assertContains('fill_operator_approved_publication_receipt', data_get($payload, 'publication_verification.required_next_actions'));
        $this->assertContains('public_receipt_missing', data_get($payload, 'publication_verification.warnings'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'publication_verification.publication_hash'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'publication_verification.publication_verification_hash'));
        $this->assertStringNotContainsString($repo, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($root, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($bundle, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function frontendRepo(string $root, string $name): string
    {
        $workspace = $root.'/'.$name;
        File::ensureDirectoryExists($workspace.'/src/components/ui');
        File::ensureDirectoryExists($workspace.'/src/pages');
        File::put($workspace.'/pnpm-lock.yaml', 'lockfileVersion: 9.0');
        File::put($workspace.'/index.html', '<div id="root"></div>');
        File::put($workspace.'/src/main.tsx', 'import React from "react";');
        File::put($workspace.'/src/pages/Dashboard.tsx', 'export function Dashboard() { return <main />; }');
        File::put($workspace.'/src/components/ui/Button.tsx', 'export function Button() { return <button className="bg-primary text-white" />; }');
        File::put($workspace.'/src/styles.css', ':root { --color-primary: #123456; --space-2: 8px; }');
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
                'tailwindcss' => '^latest',
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
     * @return array<string,string>
     */
    private function writeReplayEvidencePack(string $dir, string $case, string $system, string $taskSpecHash): array
    {
        $root = $dir.'/'.$case.'/'.$system;
        $artifactDir = $root.'/evidence/artifacts';
        File::ensureDirectoryExists($artifactDir);
        $hashes = [];

        foreach (app(AtlasFrontendEvidencePackVerifierService::class)->requiredArtifactKinds() as $kind) {
            $path = $artifactDir.'/'.$kind.'.json';
            File::put($path, json_encode([
                'kind' => $kind,
                'case_id' => $case,
                'system' => $system,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
            $hashes[$kind] = hash_file('sha256', $path);
        }

        File::put($root.'/evidence/evidence-pack.json', json_encode([
            'schema_version' => 'atlas.frontend.evidence_pack.v1',
            'pack_id' => $case.'-'.$system,
            'case_id' => $case,
            'system' => $system,
            'task_spec_hash' => $taskSpecHash,
            'artifacts' => array_map(fn (string $kind): array => [
                'kind' => $kind,
                'path' => 'artifacts/'.$kind.'.json',
                'sha256' => $hashes[$kind],
            ], array_keys($hashes)),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $hashes;
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
        $body = ['# '.$title, '', 'Status: canonical'];
        foreach ($sections as $section) {
            $body[] = '## '.$section;
            $body[] = 'Approved design context for premium company frontend work, including product goals, UX constraints, visual quality bars, interaction states, accessibility expectations, responsive behavior, and measurable evidence required before delivery.';
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

    private function createWorkspaceProfilesTable(): void
    {
        if (Schema::hasTable('atlas_workspace_profiles')) {
            AtlasWorkspaceProfile::query()->delete();

            return;
        }

        Schema::create('atlas_workspace_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 120)->unique();
            $table->string('name', 200);
            $table->string('kind', 80)->default('product');
            $table->string('workspace_path', 1000)->nullable();
            $table->string('repo_root', 1000)->nullable();
            $table->string('production_status', 80)->default('development');
            $table->text('stack_summary')->nullable();
            $table->json('commands')->nullable();
            $table->json('test_commands')->nullable();
            $table->json('build_commands')->nullable();
            $table->string('dev_server_command', 1000)->nullable();
            $table->json('critical_areas')->nullable();
            $table->string('docs_status', 80)->default('unknown');
            $table->string('default_risk', 40)->default('medium');
            $table->text('deployment_notes')->nullable();
            $table->json('surfaces_enabled')->nullable();
            $table->string('source', 80)->default('operator');
            $table->string('status', 40)->default('active');
            $table->timestamps();
        });
    }
}
