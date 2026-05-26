<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\Frontend\AtlasFrontendCompanyPortfolioService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendCompetitiveBenchmarkPlanService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendControlPlaneService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendDeliveryHandoffService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendEvidenceKitService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendLiveSourcePatchRuntimeService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendLiveTargetSuggestionService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendLiveVisualSelectionInboxService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendProviderInstructionPacketService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendPublicationVerifierService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendRivalReplayHarnessService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendRunCertificationService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendSelectedWorkspaceService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendWorkspaceRuntimeProjectionService;
use App\Services\AtlasCode\AtlasCodeWorkspaceProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class AtlasFrontendWorkspaceController extends Controller
{
    public function portfolio(Request $request, AtlasFrontendCompanyPortfolioService $portfolio): JsonResponse
    {
        $payload = $request->validate([
            'root' => ['required', 'string', 'max:1000'],
            'task' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'max_depth' => ['sometimes', 'integer', 'min:1', 'max:4'],
            'max_repos' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $report = $portfolio->scan([
            'root' => $payload['root'],
            'task' => (string) ($payload['task'] ?? ''),
            'max_depth' => (int) ($payload['max_depth'] ?? 2),
            'max_repos' => (int) ($payload['max_repos'] ?? 30),
        ]);

        return response()->json([
            'schema_version' => 'atlas.frontend.workspace_api.portfolio.v1',
            'surface' => 'atlas_code_frontend_workspace_selection',
            'portfolio' => $report,
            'meta' => [
                'execution_allowed' => false,
                'provider_dispatch_allowed' => false,
                'next_required_contract' => AtlasFrontendSelectedWorkspaceService::SCHEMA_VERSION,
                'portfolio_scan_is_inventory_only' => true,
                'selected_repository_is_primary_workspace' => true,
                'frontend_app_is_subscope_only' => true,
                'space_runtime_required' => false,
                'world_best_claim_allowed' => false,
            ],
        ]);
    }

    public function selected(Request $request, AtlasFrontendSelectedWorkspaceService $selectedWorkspace): JsonResponse
    {
        $payload = $request->validate([
            'workspace' => ['required', 'string', 'max:1000'],
            'task' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'frontend_app' => ['sometimes', 'nullable', 'string', 'max:500'],
            'selection_source' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        $selection = $selectedWorkspace->resolve([
            'workspace' => $payload['workspace'],
            'task' => (string) ($payload['task'] ?? ''),
            'frontend_app' => (string) ($payload['frontend_app'] ?? ''),
            'selection_source' => (string) ($payload['selection_source'] ?? 'atlas_code'),
        ]);

        $invalidFrontendApp = data_get($selection, 'frontend_app_candidates.status') === 'requested_frontend_app_subscope_invalid';
        $status = ($selection['status'] ?? null) === 'selected' && ! $invalidFrontendApp ? 200 : 422;

        return response()->json([
            'schema_version' => 'atlas.frontend.workspace_api.selected_workspace.v1',
            'surface' => 'atlas_code_frontend_workspace_selection',
            'selected_workspace' => $selection,
            'meta' => [
                'execution_allowed' => (bool) data_get($selection, 'frontend_runtime_projection.runtime_projection_allowed', false),
                'provider_dispatch_allowed' => false,
                'selected_repository_is_primary_workspace' => true,
                'frontend_app_is_subscope_only' => true,
                'space_runtime_required' => false,
            ],
        ], $status);
    }

    public function selectionReceipt(Request $request, AtlasFrontendSelectedWorkspaceService $selectedWorkspace): JsonResponse
    {
        $payload = $request->validate([
            'workspace' => ['required', 'string', 'max:1000'],
            'task' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'frontend_app' => ['sometimes', 'nullable', 'string', 'max:500'],
            'selection_source' => ['sometimes', 'nullable', 'string', 'max:80'],
            'portfolio_root' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'output' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $receipt = $selectedWorkspace->selectionReceipt([
            'workspace' => $payload['workspace'],
            'task' => (string) ($payload['task'] ?? ''),
            'frontend_app' => (string) ($payload['frontend_app'] ?? ''),
            'selection_source' => (string) ($payload['selection_source'] ?? 'atlas_code'),
            'portfolio_root' => (string) ($payload['portfolio_root'] ?? ''),
            'output' => (string) ($payload['output'] ?? ''),
        ]);

        return response()->json([
            'schema_version' => 'atlas.frontend.workspace_api.selection_receipt.v1',
            'surface' => 'atlas_code_frontend_workspace_selection_receipt',
            'selection_receipt' => $receipt,
            'meta' => [
                'execution_allowed' => false,
                'provider_dispatch_allowed' => false,
                'provider_dispatch_performed' => false,
                'frontend_completion_claim_allowed' => false,
                'selected_repository_is_primary_workspace' => true,
                'frontend_app_is_subscope_only' => true,
                'space_runtime_required' => false,
                'world_best_claim_allowed' => false,
            ],
        ], ($receipt['status'] ?? null) === 'ready' ? 200 : 422);
    }

    public function activateProjectWorkspace(
        Request $request,
        AtlasFrontendSelectedWorkspaceService $selectedWorkspace,
        AtlasCodeWorkspaceProfileService $profiles,
    ): JsonResponse {
        $payload = $request->validate([
            'workspace' => ['required', 'string', 'max:1000'],
            'task' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'frontend_app' => ['sometimes', 'nullable', 'string', 'max:500'],
            'selection_source' => ['sometimes', 'nullable', 'string', 'max:80'],
            'project_slug' => ['sometimes', 'nullable', 'string', 'max:120'],
            'project_name' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        $selection = $selectedWorkspace->resolve([
            'workspace' => $payload['workspace'],
            'task' => (string) ($payload['task'] ?? ''),
            'frontend_app' => (string) ($payload['frontend_app'] ?? ''),
            'selection_source' => (string) ($payload['selection_source'] ?? 'atlas_code'),
        ]);
        $invalidFrontendApp = data_get($selection, 'frontend_app_candidates.status') === 'requested_frontend_app_subscope_invalid';
        $selected = ($selection['status'] ?? null) === 'selected' && ! $invalidFrontendApp;
        $workspace = rtrim((string) ($payload['workspace'] ?? ''), DIRECTORY_SEPARATOR);
        $projectSlug = $this->frontendProjectSlug((string) ($payload['project_slug'] ?? ''), $workspace);
        $projectName = trim((string) ($payload['project_name'] ?? ''));
        if ($projectName === '') {
            $projectName = basename($workspace) ?: $projectSlug;
        }

        $blockers = $selected ? [] : array_values(array_unique(array_merge(
            (array) ($selection['blockers'] ?? []),
            $invalidFrontendApp ? ['requested_frontend_app_subscope_not_found'] : [],
        )));
        $profile = null;

        if ($selected) {
            try {
                $profile = $profiles->upsertPersistedProfile([
                    'slug' => $projectSlug,
                    'name' => $projectName,
                    'kind' => 'frontend_product_repo',
                    'workspace_path' => $workspace,
                    'repo_root' => $workspace,
                    'production_status' => 'development',
                    'stack_summary' => 'Atlas Frontend selected repository workspace',
                    'commands' => [],
                    'test_commands' => [],
                    'build_commands' => [],
                    'critical_areas' => [],
                    'docs_status' => 'unknown',
                    'default_risk' => 'medium',
                    'deployment_notes' => 'Created from Atlas Frontend multi-repo selected workspace activation.',
                    'surfaces_enabled' => ['atlas_ai', 'cartografia', 'code', 'atencao'],
                    'source' => 'atlas_frontend_selected_workspace',
                    'status' => 'active',
                ]);
            } catch (InvalidArgumentException $exception) {
                $blockers[] = $exception->getMessage();
            }
        }

        $ready = $selected && $profile !== null && empty($blockers);
        $activation = [
            'schema_version' => 'atlas.frontend.selected_workspace.project_activation.v1',
            'status' => $ready ? 'ready' : 'blocked',
            'activation_type' => 'selected_repository_to_atlas_code_project_workspace',
            'selected_workspace_schema_version' => AtlasFrontendSelectedWorkspaceService::SCHEMA_VERSION,
            'selected_workspace_status' => $selection['status'] ?? 'unknown',
            'project_workspace_schema_version' => AtlasCodeWorkspaceProfileService::SCHEMA_VERSION,
            'project_workspace' => [
                'slug' => $profile['slug'] ?? $projectSlug,
                'name' => $profile['name'] ?? $projectName,
                'kind' => $profile['kind'] ?? 'frontend_product_repo',
                'status' => $profile['status'] ?? 'unknown',
                'workspace_path_hash' => hash('sha256', (string) (realpath($workspace) ?: $workspace)),
                'workspace_path_exists' => (bool) ($profile['workspace_path_exists'] ?? is_dir($workspace)),
                'source' => $profile['source'] ?? 'atlas_frontend_selected_workspace',
            ],
            'frontend_app_scope' => [
                'status' => data_get($selection, 'confirmed_frontend_app_scope.status', 'repo_root'),
                'relative_name_hash' => data_get($selection, 'confirmed_frontend_app_scope.relative_name_hash'),
                'frontend_app_is_subscope_only' => true,
            ],
            'runtime_policy' => [
                'selected_repository_is_primary_workspace' => true,
                'atlas_code_project_workspace_persisted' => $profile !== null,
                'frontend_app_is_subscope_only' => true,
                'space_runtime_required' => false,
                'provider_dispatch_allowed' => false,
                'provider_dispatch_performed' => false,
                'execution_performed' => false,
                'world_best_claim_allowed' => false,
            ],
            'required_next_actions' => $ready
                ? ['switch_atlas_code_active_workspace_to_project_slug', 'open_atlas_frontend_runtime_cockpit_for_selected_repo']
                : ['repair_selected_workspace_project_activation_before_frontend_runtime'],
            'blockers' => array_values(array_unique(array_filter($blockers, 'is_string'))),
            'warnings' => ['project_activation_does_not_authorize_provider_dispatch'],
        ];
        $activation['project_activation_hash'] = MissionCanonicalHash::sha256($activation);

        return response()->json([
            'schema_version' => 'atlas.frontend.workspace_api.project_activation.v1',
            'surface' => 'atlas_code_frontend_project_workspace_activation',
            'project_activation' => $activation,
            'meta' => [
                'execution_allowed' => false,
                'provider_dispatch_allowed' => false,
                'provider_dispatch_performed' => false,
                'frontend_completion_claim_allowed' => false,
                'selected_repository_is_primary_workspace' => true,
                'frontend_app_is_subscope_only' => true,
                'space_runtime_required' => false,
                'world_best_claim_allowed' => false,
            ],
        ], $ready ? 200 : 422);
    }

    public function runtimeProjection(Request $request, AtlasFrontendWorkspaceRuntimeProjectionService $projection): JsonResponse
    {
        $payload = $request->validate([
            'workspace' => ['required', 'string', 'max:1000'],
            'task' => ['required', 'string', 'max:4000'],
            'frontend_app' => ['sometimes', 'nullable', 'string', 'max:500'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:120'],
            'selection_source' => ['sometimes', 'nullable', 'string', 'max:80'],
            'acceptance_criteria' => ['sometimes', 'boolean'],
            'test_plan' => ['sometimes', 'boolean'],
            'visual_quality_plan' => ['sometimes', 'boolean'],
            'evidence_plan' => ['sometimes', 'boolean'],
            'senior_design_review' => ['sometimes', 'boolean'],
            'asset_context' => ['sometimes', 'boolean'],
            'prototype' => ['sometimes', 'boolean'],
            'live' => ['sometimes', 'boolean'],
            'company_profile_ready' => ['sometimes', 'boolean'],
        ]);

        $report = $projection->project($payload + [
            'selection_source' => 'atlas_code',
            'provider' => 'provider_neutral',
        ]);

        return response()->json([
            'schema_version' => 'atlas.frontend.workspace_api.runtime_projection.v1',
            'surface' => 'atlas_code_frontend_runtime_projection',
            'runtime_projection' => $report,
            'meta' => [
                'execution_allowed' => (bool) data_get($report, 'operator_cockpit.provider_dispatch_ready', false),
                'provider_dispatch_performed' => false,
                'selected_repository_is_primary_workspace' => true,
                'frontend_app_is_subscope_only' => true,
                'space_runtime_required' => false,
            ],
        ], ($report['status'] ?? null) === 'blocked' ? 422 : 200);
    }

    public function controlPlane(Request $request, AtlasFrontendControlPlaneService $controlPlane): JsonResponse
    {
        $payload = $request->validate([
            'workspace' => ['required', 'string', 'max:1000'],
            'task' => ['required', 'string', 'max:4000'],
            'frontend_app' => ['sometimes', 'nullable', 'string', 'max:500'],
            'surface' => ['sometimes', 'nullable', 'string', 'max:120'],
            'acceptance_criteria' => ['sometimes', 'boolean'],
            'asset_context' => ['sometimes', 'boolean'],
            'company_profile_ready' => ['sometimes', 'boolean'],
            'prototype' => ['sometimes', 'boolean'],
            'live' => ['sometimes', 'boolean'],
            'test_plan' => ['sometimes', 'boolean'],
            'visual_quality_plan' => ['sometimes', 'boolean'],
            'evidence_plan' => ['sometimes', 'boolean'],
            'senior_design_review' => ['sometimes', 'boolean'],
            'benchmark_run' => ['sometimes', 'boolean'],
            'rival_evidence' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'bundle' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'publication_receipt' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $report = $controlPlane->snapshot($payload + [
            'surface' => 'programming.frontend',
        ]);

        return response()->json([
            'schema_version' => 'atlas.frontend.workspace_api.control_plane.v1',
            'surface' => 'atlas_code_frontend_control_plane',
            'control_plane' => $report,
            'meta' => [
                'execution_allowed' => (bool) data_get($report, 'claim_policy.provider_dispatch_allowed', false),
                'provider_dispatch_allowed' => (bool) data_get($report, 'claim_policy.provider_dispatch_allowed', false),
                'provider_dispatch_performed' => false,
                'frontend_completion_claim_allowed' => (bool) data_get($report, 'claim_policy.premium_frontend_claim_allowed', false),
                'customer_handoff_allowed' => (bool) data_get($report, 'claim_policy.public_distribution_claim_allowed', false),
                'selected_repository_is_primary_workspace' => true,
                'frontend_app_is_subscope_only' => true,
                'space_runtime_required' => false,
                'world_best_claim_allowed' => (bool) data_get($report, 'claim_policy.world_best_claim_allowed', false),
            ],
        ], ($report['status'] ?? null) === 'blocked' ? 422 : 200);
    }

    public function competitiveBenchmarkPlan(Request $request, AtlasFrontendCompetitiveBenchmarkPlanService $benchmarkPlan): JsonResponse
    {
        $payload = $request->validate([
            'workspace' => ['required', 'string', 'max:1000'],
            'task' => ['required', 'string', 'max:4000'],
            'frontend_app' => ['sometimes', 'nullable', 'string', 'max:500'],
            'rival_evidence' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'bundle' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'publication_receipt' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $report = $benchmarkPlan->plan([
            'rival_evidence' => (string) ($payload['rival_evidence'] ?? ''),
            'bundle' => (string) ($payload['bundle'] ?? ''),
            'publication_receipt' => (string) ($payload['publication_receipt'] ?? ''),
        ]);

        return response()->json([
            'schema_version' => 'atlas.frontend.workspace_api.competitive_benchmark_plan.v1',
            'surface' => 'atlas_code_frontend_competitive_benchmark_plan',
            'competitive_benchmark_plan' => $report,
            'meta' => [
                'execution_allowed' => false,
                'provider_dispatch_allowed' => false,
                'provider_dispatch_performed' => false,
                'frontend_completion_claim_allowed' => false,
                'customer_handoff_allowed' => false,
                'selected_repository_is_primary_workspace' => true,
                'frontend_app_is_subscope_only' => true,
                'space_runtime_required' => false,
                'world_best_claim_allowed' => false,
            ],
        ], ($report['status'] ?? null) === 'blocked' ? 422 : 200);
    }

    public function liveSourcePatch(Request $request, AtlasFrontendLiveSourcePatchRuntimeService $live): JsonResponse
    {
        $payload = $request->validate([
            'workspace' => ['required', 'string', 'max:1000'],
            'action' => ['required', 'string', 'in:prepare,accept,discard,recover,status'],
            'file' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'target' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'variants' => ['sometimes', 'array', 'max:20'],
            'variants.*.id' => ['required_with:variants', 'string', 'max:120'],
            'variants.*.content' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'variants.*.path' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'visual_selection' => ['sometimes', 'array'],
            'visual_selection.route' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'visual_selection.route_hash' => ['sometimes', 'nullable', 'string', 'size:64'],
            'visual_selection.selector' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'visual_selection.selector_hash' => ['sometimes', 'nullable', 'string', 'size:64'],
            'visual_selection.text_excerpt' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'visual_selection.text_excerpt_hash' => ['sometimes', 'nullable', 'string', 'size:64'],
            'visual_selection.component_hint' => ['sometimes', 'nullable', 'string', 'max:500'],
            'visual_selection.component_hint_hash' => ['sometimes', 'nullable', 'string', 'size:64'],
            'visual_selection.screenshot_hash' => ['sometimes', 'nullable', 'string', 'size:64'],
            'visual_selection.confidence' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'visual_selection.bounding_box' => ['sometimes', 'array'],
            'visual_selection.bounding_box.x' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'visual_selection.bounding_box.y' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'visual_selection.bounding_box.width' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'visual_selection.bounding_box.height' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'visual_selection.viewport' => ['sometimes', 'array'],
            'visual_selection.viewport.width' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'visual_selection.viewport.height' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'session' => ['sometimes', 'nullable', 'string', 'max:200'],
            'accept_variant' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        try {
            $action = (string) $payload['action'];
            $workspace = (string) $payload['workspace'];
            $report = match ($action) {
                'prepare' => $live->prepare(
                    $workspace,
                    (string) ($payload['file'] ?? ''),
                    (string) ($payload['target'] ?? ''),
                    $this->liveVariants($workspace, (array) ($payload['variants'] ?? [])),
                    is_string($payload['session'] ?? null) ? trim((string) $payload['session']) ?: null : null,
                    is_array($payload['visual_selection'] ?? null) ? $this->liveVisualSelectionPayload((array) $payload['visual_selection']) : null,
                ),
                'accept' => $live->accept($workspace, (string) ($payload['session'] ?? ''), (string) ($payload['accept_variant'] ?? '')),
                'discard' => $live->discard($workspace, (string) ($payload['session'] ?? '')),
                'recover' => $live->recover($workspace, (string) ($payload['session'] ?? '')),
                'status' => $live->status($workspace, (string) ($payload['session'] ?? '')),
            };
        } catch (RuntimeException $exception) {
            $report = [
                'schema_version' => AtlasFrontendLiveSourcePatchRuntimeService::RESULT_SCHEMA_VERSION,
                'operation' => (string) ($payload['action'] ?? 'unknown'),
                'status' => 'failed',
                'error' => $exception->getMessage(),
                'claim_policy' => [
                    'live_patch_decision_is_not_delivery_evidence' => true,
                    'frontend_completion_claim_allowed' => false,
                    'world_best_claim_allowed' => false,
                ],
            ];
        }

        return response()->json([
            'schema_version' => 'atlas.frontend.workspace_api.live_source_patch.v1',
            'surface' => 'atlas_code_frontend_live_source_patch',
            'live_source_patch' => $report,
            'meta' => [
                'execution_allowed' => false,
                'provider_dispatch_allowed' => false,
                'provider_dispatch_performed' => false,
                'frontend_completion_claim_allowed' => false,
                'customer_handoff_allowed' => false,
                'selected_repository_is_primary_workspace' => true,
                'frontend_app_is_subscope_only' => true,
                'space_runtime_required' => false,
                'world_best_claim_allowed' => false,
            ],
        ], ($report['status'] ?? null) === 'failed' ? 422 : 200);
    }

    public function liveVisualSelection(Request $request, AtlasFrontendLiveVisualSelectionInboxService $inbox): JsonResponse
    {
        $payload = $request->validate([
            'workspace' => ['required', 'string', 'max:1000'],
            'action' => ['required', 'string', 'in:record,latest'],
            'session' => ['sometimes', 'nullable', 'string', 'max:200'],
            'selection' => ['sometimes', 'array'],
            'selection.detail' => ['sometimes', 'array'],
            'selection.route' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'selection.route_hash' => ['sometimes', 'nullable', 'string', 'size:64'],
            'selection.selector' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'selection.selector_hash' => ['sometimes', 'nullable', 'string', 'size:64'],
            'selection.text_excerpt' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'selection.text_excerpt_hash' => ['sometimes', 'nullable', 'string', 'size:64'],
            'selection.component_hint' => ['sometimes', 'nullable', 'string', 'max:500'],
            'selection.component_hint_hash' => ['sometimes', 'nullable', 'string', 'size:64'],
            'selection.screenshot_hash' => ['sometimes', 'nullable', 'string', 'size:64'],
            'selection.confidence' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'selection.bounding_box' => ['sometimes', 'array'],
            'selection.bounding_box.x' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'selection.bounding_box.y' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'selection.bounding_box.width' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'selection.bounding_box.height' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'selection.viewport' => ['sometimes', 'array'],
            'selection.viewport.width' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'selection.viewport.height' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        try {
            $action = (string) $payload['action'];
            $report = $action === 'record'
                ? $inbox->record(
                    (string) $payload['workspace'],
                    (string) ($payload['session'] ?? 'atlas-live-session'),
                    is_array($payload['selection'] ?? null) ? (array) $payload['selection'] : [],
                )
                : $inbox->latest((string) $payload['workspace'], (string) ($payload['session'] ?? 'atlas-live-session'));
        } catch (RuntimeException $exception) {
            $report = [
                'schema_version' => AtlasFrontendLiveVisualSelectionInboxService::SCHEMA_VERSION,
                'status' => 'failed',
                'session' => (string) ($payload['session'] ?? 'atlas-live-session'),
                'error' => $exception->getMessage(),
                'source_policy' => [
                    'provider_safe_only' => true,
                    'raw_selector_returned' => false,
                    'raw_text_returned' => false,
                    'raw_screenshot_returned' => false,
                    'absolute_path_returned' => false,
                    'selection_is_not_visual_quality_proof' => true,
                ],
            ];
            $report['inbox_hash'] = MissionCanonicalHash::sha256($report);
        }

        return response()->json([
            'schema_version' => 'atlas.frontend.workspace_api.live_visual_selection.v1',
            'surface' => 'atlas_code_frontend_live_visual_selection',
            'live_visual_selection' => $report,
            'meta' => [
                'execution_allowed' => false,
                'provider_dispatch_allowed' => false,
                'provider_dispatch_performed' => false,
                'frontend_completion_claim_allowed' => false,
                'customer_handoff_allowed' => false,
                'selected_repository_is_primary_workspace' => true,
                'frontend_app_is_subscope_only' => true,
                'space_runtime_required' => false,
                'world_best_claim_allowed' => false,
            ],
        ], ($report['status'] ?? null) === 'failed' ? 422 : 200);
    }

    public function liveTargetSuggestions(
        Request $request,
        AtlasFrontendLiveTargetSuggestionService $suggestions,
        AtlasFrontendLiveVisualSelectionInboxService $inbox,
    ): JsonResponse {
        $payload = $request->validate([
            'workspace' => ['required', 'string', 'max:1000'],
            'session' => ['sometimes', 'nullable', 'string', 'max:200'],
            'file_hint' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'max_candidates' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'visual_selection' => ['sometimes', 'array'],
            'visual_selection.route' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'visual_selection.route_hash' => ['sometimes', 'nullable', 'string', 'size:64'],
            'visual_selection.selector' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'visual_selection.selector_hash' => ['sometimes', 'nullable', 'string', 'size:64'],
            'visual_selection.text_excerpt' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'visual_selection.text_excerpt_hash' => ['sometimes', 'nullable', 'string', 'size:64'],
            'visual_selection.component_hint' => ['sometimes', 'nullable', 'string', 'max:500'],
            'visual_selection.component_hint_hash' => ['sometimes', 'nullable', 'string', 'size:64'],
            'visual_selection.screenshot_hash' => ['sometimes', 'nullable', 'string', 'size:64'],
            'visual_selection.confidence' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'visual_selection.bounding_box' => ['sometimes', 'array'],
            'visual_selection.bounding_box.x' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'visual_selection.bounding_box.y' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'visual_selection.bounding_box.width' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'visual_selection.bounding_box.height' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'visual_selection.viewport' => ['sometimes', 'array'],
            'visual_selection.viewport.width' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'visual_selection.viewport.height' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        try {
            $selection = is_array($payload['visual_selection'] ?? null)
                ? $this->liveVisualSelectionPayload((array) $payload['visual_selection'])
                : $this->selectionFromInbox($inbox, (string) $payload['workspace'], (string) ($payload['session'] ?? 'atlas-live-session'));
            $report = $suggestions->suggest([
                'workspace' => (string) $payload['workspace'],
                'session' => (string) ($payload['session'] ?? 'atlas-live-session'),
                'file_hint' => (string) ($payload['file_hint'] ?? ''),
                'max_candidates' => (int) ($payload['max_candidates'] ?? 5),
                'visual_selection' => $selection,
            ]);
        } catch (RuntimeException $exception) {
            $report = [
                'schema_version' => AtlasFrontendLiveTargetSuggestionService::SCHEMA_VERSION,
                'status' => 'failed',
                'error' => $exception->getMessage(),
                'policy' => [
                    'operator_local_target_snippet_returned' => false,
                    'target_snippet_is_not_provider_safe' => true,
                    'provider_dispatch_allowed' => false,
                    'raw_customer_source_returned_to_provider' => false,
                    'absolute_path_returned' => false,
                    'suggestion_is_not_delivery_evidence' => true,
                    'selected_repository_is_primary_workspace' => true,
                    'frontend_app_is_subscope_only' => true,
                    'space_runtime_required' => false,
                    'world_best_claim_allowed' => false,
                ],
            ];
            $report['suggestions_hash'] = MissionCanonicalHash::sha256($report);
        }

        return response()->json([
            'schema_version' => 'atlas.frontend.workspace_api.live_target_suggestions.v1',
            'surface' => 'atlas_code_frontend_live_target_suggestions',
            'live_target_suggestions' => $report,
            'meta' => [
                'execution_allowed' => false,
                'provider_dispatch_allowed' => false,
                'provider_dispatch_performed' => false,
                'frontend_completion_claim_allowed' => false,
                'customer_handoff_allowed' => false,
                'selected_repository_is_primary_workspace' => true,
                'frontend_app_is_subscope_only' => true,
                'space_runtime_required' => false,
                'world_best_claim_allowed' => false,
            ],
        ], ($report['status'] ?? null) === 'failed' ? 422 : 200);
    }

    public function runCertification(Request $request, AtlasFrontendRunCertificationService $certification): JsonResponse
    {
        $payload = $request->validate([
            'provider_packet' => ['required', 'string', 'max:1000'],
            'visual_report' => ['required', 'string', 'max:1000'],
            'design_review_report' => ['required', 'string', 'max:1000'],
            'quality_budget_report' => ['required', 'string', 'max:1000'],
            'evidence_manifest' => ['required', 'string', 'max:1000'],
            'evidence_root' => ['required', 'string', 'max:1000'],
            'outcome_store' => ['required', 'string', 'max:1000'],
            'bundle' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'publication_receipt' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $report = $certification->certify($payload);
        $claimAllowed = (bool) data_get($report, 'claim_policy.frontend_completion_claim_allowed', false);

        return response()->json([
            'schema_version' => 'atlas.frontend.workspace_api.run_certification.v1',
            'surface' => 'atlas_code_frontend_delivery_certification',
            'run_certification' => $report,
            'meta' => [
                'execution_certified' => in_array($report['status'] ?? null, ['certified', 'warning'], true),
                'frontend_completion_claim_allowed' => $claimAllowed,
                'provider_dispatch_performed' => false,
                'selected_repository_is_primary_workspace' => true,
                'frontend_app_is_subscope_only' => true,
                'space_runtime_required' => false,
                'world_best_claim_allowed' => false,
            ],
        ], ($report['status'] ?? null) === 'blocked' ? 422 : 200);
    }

    /**
     * @param  array<int,mixed>  $variants
     * @return array<int,array{id:string,content:string}>
     */
    private function liveVariants(string $workspace, array $variants): array
    {
        $normalized = [];
        $workspaceReal = realpath($workspace);
        if ($workspaceReal === false || ! File::isDirectory($workspaceReal)) {
            throw new RuntimeException('workspace_not_found');
        }

        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            $id = trim((string) ($variant['id'] ?? ''));
            $content = (string) ($variant['content'] ?? '');
            $path = trim((string) ($variant['path'] ?? ''));
            if ($id === '') {
                continue;
            }
            if ($content === '' && $path !== '') {
                $content = $this->liveVariantFileContent($workspaceReal, $path);
            }
            $normalized[] = ['id' => $id, 'content' => $content];
        }

        return $normalized;
    }

    private function liveVariantFileContent(string $workspaceReal, string $path): string
    {
        $candidate = str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : $workspaceReal.'/'.ltrim($path, DIRECTORY_SEPARATOR);
        $real = realpath($candidate);
        if ($real === false || ! File::isFile($real)) {
            throw new RuntimeException('variant_file_not_found');
        }

        $workspacePrefix = rtrim($workspaceReal, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (! str_starts_with($real, $workspacePrefix)) {
            throw new RuntimeException('variant_file_outside_workspace');
        }

        return File::get($real);
    }

    /**
     * @param  array<string,mixed>  $selection
     * @return array<string,mixed>
     */
    private function liveVisualSelectionPayload(array $selection): array
    {
        return [
            'route' => trim((string) ($selection['route'] ?? '')),
            'route_hash' => trim((string) ($selection['route_hash'] ?? '')),
            'selector' => trim((string) ($selection['selector'] ?? '')),
            'selector_hash' => trim((string) ($selection['selector_hash'] ?? '')),
            'text_excerpt' => trim((string) ($selection['text_excerpt'] ?? '')),
            'text_excerpt_hash' => trim((string) ($selection['text_excerpt_hash'] ?? '')),
            'component_hint' => trim((string) ($selection['component_hint'] ?? '')),
            'component_hint_hash' => trim((string) ($selection['component_hint_hash'] ?? '')),
            'screenshot_hash' => trim((string) ($selection['screenshot_hash'] ?? '')),
            'confidence' => (float) ($selection['confidence'] ?? 0.0),
            'bounding_box' => [
                'x' => (float) data_get($selection, 'bounding_box.x', 0.0),
                'y' => (float) data_get($selection, 'bounding_box.y', 0.0),
                'width' => (float) data_get($selection, 'bounding_box.width', 0.0),
                'height' => (float) data_get($selection, 'bounding_box.height', 0.0),
            ],
            'viewport' => [
                'width' => (int) data_get($selection, 'viewport.width', 0),
                'height' => (int) data_get($selection, 'viewport.height', 0),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function selectionFromInbox(AtlasFrontendLiveVisualSelectionInboxService $inbox, string $workspace, string $session): array
    {
        $latest = $inbox->latest($workspace, $session);
        $selection = data_get($latest, 'selection');

        return is_array($selection) ? $selection : [];
    }

    public function prepareEvidence(
        Request $request,
        AtlasFrontendEvidenceKitService $evidenceKit,
        AtlasFrontendProviderInstructionPacketService $providerPacket,
    ): JsonResponse {
        $payload = $request->validate([
            'workspace' => ['required', 'string', 'max:1000'],
            'task' => ['required', 'string', 'max:4000'],
            'output' => ['required', 'string', 'max:1000'],
            'frontend_app' => ['sometimes', 'nullable', 'string', 'max:500'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:120'],
            'selection_source' => ['sometimes', 'nullable', 'string', 'max:80'],
            'acceptance_criteria' => ['sometimes', 'boolean'],
            'test_plan' => ['sometimes', 'boolean'],
            'visual_quality_plan' => ['sometimes', 'boolean'],
            'evidence_plan' => ['sometimes', 'boolean'],
            'senior_design_review' => ['sometimes', 'boolean'],
            'asset_context' => ['sometimes', 'boolean'],
            'prototype' => ['sometimes', 'boolean'],
            'live' => ['sometimes', 'boolean'],
            'company_profile_ready' => ['sometimes', 'boolean'],
        ]);
        $input = $payload + [
            'surface' => 'programming.frontend',
            'provider' => 'provider_neutral',
            'acceptance_criteria' => true,
            'test_plan' => true,
            'visual_quality_plan' => true,
            'evidence_plan' => true,
        ];

        $kit = $evidenceKit->prepare($input);
        $packet = $providerPacket->compile($input);
        $packetPath = rtrim((string) $payload['output'], DIRECTORY_SEPARATOR).'/provider-instruction-packet.json';
        File::ensureDirectoryExists(dirname($packetPath));
        File::put($packetPath, json_encode($packet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        $blockers = array_values(array_unique(array_merge(
            (array) ($kit['blockers'] ?? []),
            collect((array) ($packet['blockers'] ?? []))->map(fn (string $blocker): string => 'provider_packet_'.$blocker)->all(),
        )));
        $warnings = array_values(array_unique(array_merge(
            (array) ($kit['warnings'] ?? []),
            collect((array) ($packet['warnings'] ?? []))->map(fn (string $warning): string => 'provider_packet_'.$warning)->all(),
        )));
        $status = $blockers === [] && ($kit['status'] ?? null) === 'ready' && ($packet['status'] ?? null) === 'ready'
            ? 'ready_for_provider_dispatch'
            : 'blocked';
        $report = [
            'schema_version' => 'atlas.frontend.workspace_evidence_preparation.v1',
            'status' => $status,
            'evidence_kit_status' => $kit['status'] ?? 'unknown',
            'provider_packet_status' => $packet['status'] ?? 'unknown',
            'evidence_kit_hash' => $kit['evidence_kit_hash'] ?? null,
            'provider_instruction_packet_hash' => $packet['provider_instruction_packet_hash'] ?? null,
            'provider_packet_file_hash' => hash_file('sha256', $packetPath),
            'frontend_app_scope' => $kit['frontend_app_scope'] ?? ($packet['frontend_app_scope'] ?? []),
            'artifact_refs' => [
                'provider_instruction_packet' => [
                    'relative_name' => basename(dirname($packetPath)).'/'.basename($packetPath),
                    'sha256' => hash_file('sha256', $packetPath),
                ],
                'kit_artifacts' => $kit['kit_artifacts'] ?? [],
                'manifest' => $kit['manifest'] ?? null,
            ],
            'required_next_actions' => $status === 'ready_for_provider_dispatch'
                ? ['dispatch_provider_with_provider_instruction_packet', 'replace_templates_with_measured_receipts', 'run_atlas_frontend_run_certify']
                : array_values(array_unique(array_merge(
                    (array) ($kit['required_next_actions'] ?? []),
                    (array) ($packet['required_next_actions'] ?? []),
                ))),
            'claim_policy' => [
                'preparation_is_not_execution' => true,
                'provider_dispatch_performed_by_this_endpoint' => false,
                'prepared_templates_are_not_completion_evidence' => true,
                'frontend_completion_claim_allowed' => false,
                'customer_handoff_allowed' => false,
                'raw_absolute_path_returned' => false,
                'selected_repository_remains_primary_workspace' => true,
                'frontend_app_is_subscope_only' => true,
                'space_runtime_required' => false,
                'world_best_claim_allowed' => false,
            ],
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
        $report['evidence_preparation_hash'] = MissionCanonicalHash::sha256($report);

        return response()->json([
            'schema_version' => 'atlas.frontend.workspace_api.prepare_evidence.v1',
            'surface' => 'atlas_code_frontend_evidence_preparation',
            'evidence_preparation' => $report,
            'meta' => [
                'execution_allowed' => $status === 'ready_for_provider_dispatch',
                'provider_dispatch_allowed' => $status === 'ready_for_provider_dispatch',
                'provider_dispatch_performed' => false,
                'frontend_completion_claim_allowed' => false,
                'customer_handoff_allowed' => false,
                'selected_repository_is_primary_workspace' => true,
                'frontend_app_is_subscope_only' => true,
                'space_runtime_required' => false,
                'world_best_claim_allowed' => false,
            ],
        ], $status === 'ready_for_provider_dispatch' ? 200 : 422);
    }

    public function handoff(Request $request, AtlasFrontendDeliveryHandoffService $handoff): JsonResponse
    {
        $payload = $request->validate([
            'run_certification_report' => ['required', 'string', 'max:1000'],
            'evidence_manifest' => ['required', 'string', 'max:1000'],
            'publication_report' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $report = $handoff->compile(
            $payload['run_certification_report'],
            $payload['evidence_manifest'],
            (string) ($payload['publication_report'] ?? ''),
        );

        return response()->json([
            'schema_version' => 'atlas.frontend.workspace_api.delivery_handoff.v1',
            'surface' => 'atlas_code_frontend_delivery_handoff',
            'delivery_handoff' => $report,
            'meta' => [
                'customer_handoff_allowed' => (bool) data_get($report, 'claim_policy.customer_handoff_allowed', false),
                'provider_dispatch_performed' => false,
                'selected_repository_is_primary_workspace' => true,
                'frontend_app_is_subscope_only' => true,
                'space_runtime_required' => false,
                'world_best_claim_allowed' => false,
            ],
        ], ($report['status'] ?? null) === 'ready' ? 200 : 422);
    }

    public function prepareRivalReplay(Request $request, AtlasFrontendRivalReplayHarnessService $replay): JsonResponse
    {
        $payload = $request->validate([
            'workspace' => ['required', 'string', 'max:1000'],
            'task' => ['required', 'string', 'max:4000'],
            'output' => ['required', 'string', 'max:1000'],
            'frontend_app' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $directory = rtrim((string) $payload['output'], DIRECTORY_SEPARATOR);
        $operatorPacket = $replay->writeOperatorPacket($directory);
        $operatorPacketVerification = $replay->verifyOperatorPacket($directory);
        $runnerKit = File::isFile($directory.'/replay-runner-kit.json')
            ? (json_decode((string) File::get($directory.'/replay-runner-kit.json'), true) ?: [])
            : [];
        $worklist = $replay->compileEvidenceWorklist($directory);
        $inspect = $replay->inspect($directory);
        $proofContractFile = File::isFile($directory.'/replay-competitive-proof-contract.json')
            ? (json_decode((string) File::get($directory.'/replay-competitive-proof-contract.json'), true) ?: [])
            : [];

        $caseIds = collect((array) ($inspect['cases'] ?? []))
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values()
            ->all();
        $systemIds = collect((array) ($inspect['systems'] ?? []))
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values()
            ->all();
        $workItems = $this->rivalReplayWorkItems($worklist, $directory);

        $report = [
            'schema_version' => 'atlas.frontend.workspace_rival_replay_preparation.v1',
            'status' => 'ready_for_external_rival_replay',
            'preparation_type' => 'selected_repo_external_rival_replay_runner_kit',
            'evidence_directory_hash' => hash('sha256', $directory),
            'runner_kit_schema_version' => AtlasFrontendRivalReplayHarnessService::RUNNER_KIT_SCHEMA_VERSION,
            'runner_kit_hash' => $runnerKit['runner_kit_hash'] ?? null,
            'replay_hash' => $inspect['replay_hash'] ?? null,
            'worklist_schema_version' => AtlasFrontendRivalReplayHarnessService::EVIDENCE_WORKLIST_SCHEMA_VERSION,
            'worklist_hash' => $worklist['worklist_hash'] ?? null,
            'proof_contract_schema_version' => AtlasFrontendRivalReplayHarnessService::PROOF_CONTRACT_FILE_SCHEMA_VERSION,
            'proof_contract_file_hash' => $proofContractFile['proof_contract_file_hash'] ?? null,
            'operator_packet_schema_version' => AtlasFrontendRivalReplayHarnessService::OPERATOR_PACKET_SCHEMA_VERSION,
            'operator_packet_hash' => $operatorPacket['operator_packet_hash'] ?? null,
            'operator_packet_status' => $operatorPacket['status'] ?? 'unknown',
            'operator_packet_verification_schema_version' => AtlasFrontendRivalReplayHarnessService::OPERATOR_PACKET_VERIFICATION_SCHEMA_VERSION,
            'operator_packet_verification_status' => $operatorPacketVerification['status'] ?? 'unknown',
            'operator_packet_verification_hash' => $operatorPacketVerification['operator_packet_verification_hash'] ?? null,
            'external_operator_run_count' => (int) ($operatorPacket['external_run_count'] ?? 0),
            'run_packet_count' => (int) ($runnerKit['run_packet_count'] ?? 0),
            'work_item_count' => (int) ($worklist['work_item_count'] ?? 0),
            'action_queue' => [
                'schema_version' => data_get($worklist, 'schema_version'),
                'status' => data_get($worklist, 'status'),
                'worklist_hash' => data_get($worklist, 'worklist_hash'),
                'work_item_count' => (int) data_get($worklist, 'work_item_count', 0),
                'work_items' => $workItems,
                'next_actions' => $this->rivalReplayNextActions($workItems),
                'raw_absolute_path_returned' => false,
            ],
            'case_ids' => $caseIds,
            'system_ids' => $systemIds,
            'artifact_refs' => [
                'runner_kit' => [
                    'relative_name' => 'rival-replay/replay-runner-kit.json',
                    'sha256' => File::isFile($directory.'/replay-runner-kit.json') ? hash_file('sha256', $directory.'/replay-runner-kit.json') : null,
                ],
                'worklist' => [
                    'relative_name' => 'rival-replay/replay-evidence-worklist.json',
                    'sha256' => File::isFile($directory.'/replay-evidence-worklist.json') ? hash_file('sha256', $directory.'/replay-evidence-worklist.json') : null,
                ],
                'proof_contract' => [
                    'relative_name' => 'rival-replay/replay-competitive-proof-contract.json',
                    'sha256' => File::isFile($directory.'/replay-competitive-proof-contract.json') ? hash_file('sha256', $directory.'/replay-competitive-proof-contract.json') : null,
                ],
                'operator_packet' => [
                    'relative_name' => 'rival-replay/replay-operator-packet.json',
                    'sha256' => File::isFile($directory.'/replay-operator-packet.json') ? hash_file('sha256', $directory.'/replay-operator-packet.json') : null,
                ],
            ],
            'required_next_actions' => [
                'open_replay_operator_packet',
                'run_external_rivals_against_unchanged_task_specs',
                'fill_external_execution_receipts_and_score_attestations',
                'verify_rival_evidence_packs',
                'rerun_atlas_frontend_replay_inspect',
                'rerun_atlas_frontend_competitive_benchmark_plan',
            ],
            'claim_policy' => [
                'runner_kit_is_not_replay_evidence' => true,
                'worklist_is_not_replay_evidence' => true,
                'operator_packet_is_not_replay_evidence' => true,
                'operator_packet_verification_is_not_replay_evidence' => true,
                'external_rival_replay_receipts_required' => true,
                'frontend_completion_claim_allowed' => false,
                'world_best_claim_allowed' => false,
                'raw_absolute_path_returned' => false,
                'raw_customer_source_returned' => false,
                'selected_repository_remains_primary_workspace' => true,
                'frontend_app_is_subscope_only' => true,
                'space_runtime_required' => false,
            ],
            'blockers' => ['external_rival_replay_receipts_missing'],
            'warnings' => ['prepared_runner_kit_does_not_authorize_market_claims'],
        ];
        $report['rival_replay_preparation_hash'] = MissionCanonicalHash::sha256($report);

        return response()->json([
            'schema_version' => 'atlas.frontend.workspace_api.prepare_rival_replay.v1',
            'surface' => 'atlas_code_frontend_rival_replay_preparation',
            'rival_replay_preparation' => $report,
            'meta' => [
                'execution_allowed' => false,
                'provider_dispatch_allowed' => false,
                'provider_dispatch_performed' => false,
                'frontend_completion_claim_allowed' => false,
                'customer_handoff_allowed' => false,
                'selected_repository_is_primary_workspace' => true,
                'frontend_app_is_subscope_only' => true,
                'space_runtime_required' => false,
                'world_best_claim_allowed' => false,
            ],
        ]);
    }

    public function inspectRivalReplay(Request $request, AtlasFrontendRivalReplayHarnessService $replay): JsonResponse
    {
        $payload = $request->validate([
            'workspace' => ['required', 'string', 'max:1000'],
            'task' => ['required', 'string', 'max:4000'],
            'evidence' => ['required', 'string', 'max:1000'],
            'frontend_app' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $directory = rtrim((string) $payload['evidence'], DIRECTORY_SEPARATOR);
        $inspect = $replay->inspect($directory);
        $worklist = $replay->compileEvidenceWorklist($directory);
        $worldBestAllowed = (bool) data_get($inspect, 'competitive_proof_contract.claim_policy.may_claim_world_best_frontend_system', false)
            && (bool) data_get($inspect, 'claim_policy.may_claim_world_best_frontend_system', false);
        $externalReplayCompleted = (bool) data_get($inspect, 'summary.external_replay_completed', false);
        $runs = collect((array) ($inspect['runs'] ?? []))
            ->map(fn (array $run): array => [
                'case_id' => (string) ($run['case_id'] ?? ''),
                'system' => (string) ($run['system'] ?? ''),
                'status' => (string) ($run['status'] ?? 'unknown'),
                'score_total' => $run['score_total'] ?? null,
                'run_packet_hash' => $run['run_packet_hash'] ?? null,
                'blockers' => array_values(array_filter((array) ($run['blockers'] ?? []), 'is_string')),
                'warnings' => array_values(array_filter((array) ($run['warnings'] ?? []), 'is_string')),
            ])
            ->values()
            ->all();
        $workItems = $this->rivalReplayWorkItems($worklist, $directory);

        $report = [
            'schema_version' => 'atlas.frontend.workspace_rival_replay_inspection.v1',
            'status' => $inspect['status'] ?? 'unknown',
            'inspection_type' => 'selected_repo_external_rival_replay_evidence_inspection',
            'evidence_directory_hash' => hash('sha256', $directory),
            'replay_hash' => $inspect['replay_hash'] ?? null,
            'summary' => $inspect['summary'] ?? [],
            'scoreboard' => $inspect['scoreboard'] ?? [],
            'fairness' => $inspect['fairness'] ?? [],
            'evidence_pack_readiness' => [
                'status' => data_get($inspect, 'evidence_pack_readiness.status'),
                'summary' => data_get($inspect, 'evidence_pack_readiness.summary'),
                'required_artifact_kinds' => data_get($inspect, 'evidence_pack_readiness.required_artifact_kinds', []),
            ],
            'competitive_diagnostics' => $inspect['competitive_diagnostics'] ?? [],
            'competitive_proof_contract' => $inspect['competitive_proof_contract'] ?? [],
            'runs' => $runs,
            'action_queue' => [
                'schema_version' => data_get($worklist, 'schema_version'),
                'status' => data_get($worklist, 'status'),
                'worklist_hash' => data_get($worklist, 'worklist_hash'),
                'work_item_count' => (int) data_get($worklist, 'work_item_count', 0),
                'work_items' => $workItems,
                'next_actions' => $this->rivalReplayNextActions($workItems),
                'raw_absolute_path_returned' => false,
            ],
            'remaining_gaps' => array_values(array_filter((array) ($inspect['remaining_gaps'] ?? []), 'is_string')),
            'claim_policy' => [
                'may_claim_external_replay_completed' => $externalReplayCompleted,
                'may_claim_world_best_frontend_system' => $worldBestAllowed,
                'world_best_requires_external_execution_receipts' => true,
                'world_best_requires_no_tied_cases' => true,
                'world_best_requires_no_dimension_gaps_against_best_rival' => true,
                'documentation_only_claim_forbidden' => true,
                'raw_absolute_path_returned' => false,
                'raw_customer_source_returned' => false,
                'selected_repository_remains_primary_workspace' => true,
                'frontend_app_is_subscope_only' => true,
                'space_runtime_required' => false,
            ],
            'blockers' => $worldBestAllowed ? [] : ['external_rival_replay_receipts_or_decisive_lead_missing'],
            'warnings' => $externalReplayCompleted ? [] : ['external_rival_replay_not_complete'],
        ];
        $report['rival_replay_inspection_hash'] = MissionCanonicalHash::sha256($report);

        return response()->json([
            'schema_version' => 'atlas.frontend.workspace_api.inspect_rival_replay.v1',
            'surface' => 'atlas_code_frontend_rival_replay_inspection',
            'rival_replay_inspection' => $report,
            'meta' => [
                'execution_allowed' => false,
                'provider_dispatch_allowed' => false,
                'provider_dispatch_performed' => false,
                'frontend_completion_claim_allowed' => false,
                'customer_handoff_allowed' => false,
                'selected_repository_is_primary_workspace' => true,
                'frontend_app_is_subscope_only' => true,
                'space_runtime_required' => false,
                'world_best_claim_allowed' => $worldBestAllowed,
            ],
        ]);
    }

    public function proofBundle(Request $request, AtlasFrontendRivalReplayHarnessService $replay): JsonResponse
    {
        $payload = $request->validate([
            'workspace' => ['required', 'string', 'max:1000'],
            'task' => ['required', 'string', 'max:4000'],
            'evidence' => ['required', 'string', 'max:1000'],
            'frontend_app' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $directory = rtrim((string) $payload['evidence'], DIRECTORY_SEPARATOR);
        $bundle = $replay->writeCompetitiveProofBundle($directory);
        $worldBestAllowed = (bool) data_get($bundle, 'claim_policy.may_claim_world_best_frontend_system', false);

        $report = [
            'schema_version' => 'atlas.frontend.workspace_competitive_proof_bundle.v1',
            'status' => $bundle['status'] ?? 'unknown',
            'bundle_type' => 'selected_repo_provider_safe_competitive_replay_proof_index',
            'evidence_directory_hash' => hash('sha256', $directory),
            'proof_bundle_schema_version' => AtlasFrontendRivalReplayHarnessService::PROOF_BUNDLE_SCHEMA_VERSION,
            'proof_bundle_hash' => $bundle['proof_bundle_hash'] ?? null,
            'replay_hash' => $bundle['replay_hash'] ?? null,
            'operator_packet_verification_hash' => $bundle['operator_packet_verification_hash'] ?? null,
            'operator_packet_verification_status' => data_get($bundle, 'operator_packet_verification.status', 'unknown'),
            'proof_contract_hash' => $bundle['proof_contract_hash'] ?? null,
            'readiness' => $bundle['readiness'] ?? [],
            'run_manifest_count' => count((array) ($bundle['run_manifest_index'] ?? [])),
            'scoreboard' => $bundle['scoreboard'] ?? [],
            'artifact_refs' => [
                'proof_bundle' => [
                    'relative_name' => 'rival-replay/replay-competitive-proof-bundle.json',
                    'sha256' => File::isFile($directory.'/replay-competitive-proof-bundle.json')
                        ? hash_file('sha256', $directory.'/replay-competitive-proof-bundle.json')
                        : null,
                ],
            ],
            'required_next_actions' => array_values(array_filter((array) ($bundle['required_next_actions'] ?? []), 'is_string')),
            'claim_policy' => [
                'proof_bundle_is_not_raw_artifact_storage' => true,
                'external_provider_dispatch_performed' => false,
                'may_claim_external_replay_completed' => (bool) data_get($bundle, 'claim_policy.may_claim_external_replay_completed', false),
                'may_claim_world_best_replay_proof' => (bool) data_get($bundle, 'claim_policy.may_claim_world_best_replay_proof', false),
                'may_claim_world_best_frontend_system' => $worldBestAllowed,
                'public_distribution_receipt_still_required_for_product_claim' => true,
                'raw_absolute_path_returned' => false,
                'raw_customer_source_returned' => false,
                'selected_repository_remains_primary_workspace' => true,
                'frontend_app_is_subscope_only' => true,
                'space_runtime_required' => false,
            ],
            'blockers' => $worldBestAllowed ? [] : ['external_rival_replay_receipts_or_decisive_lead_missing'],
            'warnings' => $worldBestAllowed ? ['public_distribution_receipt_still_required_for_product_claim'] : ['proof_bundle_pending_external_replay_evidence'],
        ];
        $report['competitive_proof_bundle_hash'] = MissionCanonicalHash::sha256($report);

        return response()->json([
            'schema_version' => 'atlas.frontend.workspace_api.proof_bundle.v1',
            'surface' => 'atlas_code_frontend_competitive_proof_bundle',
            'rival_replay_proof_bundle' => $report,
            'meta' => [
                'execution_allowed' => false,
                'provider_dispatch_allowed' => false,
                'provider_dispatch_performed' => false,
                'frontend_completion_claim_allowed' => false,
                'customer_handoff_allowed' => false,
                'selected_repository_is_primary_workspace' => true,
                'frontend_app_is_subscope_only' => true,
                'space_runtime_required' => false,
                'world_best_claim_allowed' => $worldBestAllowed,
            ],
        ]);
    }

    public function applyReplayPatch(Request $request, AtlasFrontendRivalReplayHarnessService $replay): JsonResponse
    {
        $payload = $request->validate([
            'workspace' => ['required', 'string', 'max:1000'],
            'task' => ['required', 'string', 'max:4000'],
            'evidence' => ['required', 'string', 'max:1000'],
            'patch' => ['required', 'string', 'max:1000'],
            'frontend_app' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $directory = rtrim((string) $payload['evidence'], DIRECTORY_SEPARATOR);
        $application = $replay->applyManifestPatch($directory, (string) $payload['patch']);
        $applied = ($application['status'] ?? null) === 'applied';

        $report = [
            'schema_version' => 'atlas.frontend.workspace_rival_replay_manifest_patch_application.v1',
            'status' => $application['status'] ?? 'unknown',
            'application_type' => 'selected_repo_provider_safe_rival_replay_manifest_patch',
            'application_schema_version' => AtlasFrontendRivalReplayHarnessService::MANIFEST_PATCH_APPLICATION_SCHEMA_VERSION,
            'application_hash' => $application['manifest_patch_application_hash'] ?? null,
            'patch_schema_version' => $application['patch_schema_version'] ?? null,
            'evidence_directory_hash' => $application['evidence_directory_hash'] ?? hash('sha256', $directory),
            'patch_path_hash' => $application['patch_path_hash'] ?? hash('sha256', (string) $payload['patch']),
            'manifest_ref' => $application['manifest_ref'] ?? null,
            'previous_manifest_hash' => $application['previous_manifest_hash'] ?? null,
            'applied_manifest_hash' => $application['applied_manifest_hash'] ?? null,
            'applied_keys' => array_values(array_filter((array) ($application['applied_keys'] ?? []), 'is_string')),
            'post_apply_run_status' => $application['post_apply_run_status'] ?? null,
            'post_apply_run_issues' => array_values(array_filter((array) ($application['post_apply_run_issues'] ?? []), 'is_string')),
            'write_performed' => (bool) ($application['write_performed'] ?? false),
            'required_next_actions' => $applied
                ? ['rerun_atlas_frontend_rival_replay_inspection', 'rerun_atlas_frontend_proof_bundle']
                : ['repair_manifest_patch_and_rerun_apply_patch'],
            'claim_policy' => [
                'manifest_patch_application_is_not_world_best_evidence' => true,
                'world_best_claim_forbidden_until_replay_inspect_passes' => true,
                'raw_absolute_path_returned' => false,
                'raw_customer_source_returned' => false,
                'selected_repository_remains_primary_workspace' => true,
                'frontend_app_is_subscope_only' => true,
                'space_runtime_required' => false,
                'world_best_claim_allowed' => false,
            ],
            'blockers' => array_values(array_filter((array) ($application['blockers'] ?? []), 'is_string')),
            'warnings' => array_values(array_filter((array) ($application['warnings'] ?? []), 'is_string')),
        ];
        $report['workspace_manifest_patch_application_hash'] = MissionCanonicalHash::sha256($report);

        return response()->json([
            'schema_version' => 'atlas.frontend.workspace_api.replay_apply_patch.v1',
            'surface' => 'atlas_code_frontend_rival_replay_manifest_patch_application',
            'rival_replay_manifest_patch_application' => $report,
            'meta' => [
                'execution_allowed' => false,
                'provider_dispatch_allowed' => false,
                'provider_dispatch_performed' => false,
                'frontend_completion_claim_allowed' => false,
                'customer_handoff_allowed' => false,
                'selected_repository_is_primary_workspace' => true,
                'frontend_app_is_subscope_only' => true,
                'space_runtime_required' => false,
                'world_best_claim_allowed' => false,
            ],
        ], $applied ? 200 : 422);
    }

    public function replayExternalReceiptTemplate(Request $request, AtlasFrontendRivalReplayHarnessService $replay): JsonResponse
    {
        $payload = $request->validate([
            'workspace' => ['required', 'string', 'max:1000'],
            'task' => ['required', 'string', 'max:4000'],
            'evidence' => ['required', 'string', 'max:1000'],
            'case_id' => ['required', 'string', 'max:120'],
            'system' => ['required', 'string', 'max:120'],
            'output' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'execution_surface' => ['sometimes', 'nullable', 'string', 'max:120'],
            'frontend_app' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $directory = rtrim((string) $payload['evidence'], DIRECTORY_SEPARATOR);
        $template = $replay->writeExternalExecutionReceiptTemplate(
            $directory,
            (string) $payload['case_id'],
            (string) $payload['system'],
            trim((string) ($payload['output'] ?? '')) !== '' ? (string) $payload['output'] : null,
            trim((string) ($payload['execution_surface'] ?? '')) !== '' ? (string) $payload['execution_surface'] : null,
        );

        return $this->replayTemplateResponse(
            'atlas.frontend.workspace_api.replay_external_receipt_template.v1',
            'atlas_code_frontend_rival_replay_external_receipt_template',
            'rival_replay_external_receipt_template',
            [
                'schema_version' => 'atlas.frontend.workspace_rival_replay_external_receipt_template.v1',
                'status' => $template['status'] ?? 'unknown',
                'template_schema_version' => AtlasFrontendRivalReplayHarnessService::EXTERNAL_EXECUTION_RECEIPT_TEMPLATE_SCHEMA_VERSION,
                'template_hash' => $template['external_receipt_template_hash'] ?? null,
                'case_id' => $template['case_id'] ?? null,
                'system' => $template['system'] ?? null,
                'manifest_ref' => $template['manifest_ref'] ?? null,
                'manifest_hash' => $template['manifest_hash'] ?? null,
                'output_ref_hash' => $template['output_ref_hash'] ?? null,
                'write_performed' => (bool) ($template['write_performed'] ?? false),
                'required_next_actions' => array_values(array_filter((array) ($template['required_next_actions'] ?? []), 'is_string')),
                'claim_policy' => [
                    'template_is_not_replay_evidence' => true,
                    'operator_approval_required_before_complete_manifest' => true,
                    'apply_patch_required_after_operator_approval' => true,
                    'raw_absolute_path_returned' => false,
                    'selected_repository_remains_primary_workspace' => true,
                    'frontend_app_is_subscope_only' => true,
                    'space_runtime_required' => false,
                    'world_best_claim_allowed' => false,
                ],
                'blockers' => array_values(array_filter((array) ($template['blockers'] ?? []), 'is_string')),
                'warnings' => array_values(array_filter((array) ($template['warnings'] ?? []), 'is_string')),
            ],
        );
    }

    public function replayScoreTemplate(Request $request, AtlasFrontendRivalReplayHarnessService $replay): JsonResponse
    {
        $payload = $request->validate([
            'workspace' => ['required', 'string', 'max:1000'],
            'task' => ['required', 'string', 'max:4000'],
            'evidence' => ['required', 'string', 'max:1000'],
            'case_id' => ['required', 'string', 'max:120'],
            'system' => ['required', 'string', 'max:120'],
            'output' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'reviewer_ref_hash' => ['sometimes', 'nullable', 'string', 'max:120'],
            'frontend_app' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $template = $replay->writeScoreAttestationTemplate(
            rtrim((string) $payload['evidence'], DIRECTORY_SEPARATOR),
            (string) $payload['case_id'],
            (string) $payload['system'],
            trim((string) ($payload['output'] ?? '')) !== '' ? (string) $payload['output'] : null,
            trim((string) ($payload['reviewer_ref_hash'] ?? '')) !== '' ? (string) $payload['reviewer_ref_hash'] : null,
        );

        return $this->replayTemplateResponse(
            'atlas.frontend.workspace_api.replay_score_template.v1',
            'atlas_code_frontend_rival_replay_score_template',
            'rival_replay_score_template',
            [
                'schema_version' => 'atlas.frontend.workspace_rival_replay_score_template.v1',
                'status' => $template['status'] ?? 'unknown',
                'template_schema_version' => AtlasFrontendRivalReplayHarnessService::SCORE_ATTESTATION_TEMPLATE_SCHEMA_VERSION,
                'template_hash' => $template['score_attestation_template_hash'] ?? null,
                'case_id' => $template['case_id'] ?? null,
                'system' => $template['system'] ?? null,
                'manifest_ref' => $template['manifest_ref'] ?? null,
                'manifest_hash' => $template['manifest_hash'] ?? null,
                'output_ref_hash' => $template['output_ref_hash'] ?? null,
                'write_performed' => (bool) ($template['write_performed'] ?? false),
                'required_next_actions' => array_values(array_filter((array) ($template['required_next_actions'] ?? []), 'is_string')),
                'claim_policy' => [
                    'template_is_not_replay_evidence' => true,
                    'operator_approval_required_before_complete_manifest' => true,
                    'apply_patch_required_after_operator_approval' => true,
                    'raw_absolute_path_returned' => false,
                    'selected_repository_remains_primary_workspace' => true,
                    'frontend_app_is_subscope_only' => true,
                    'space_runtime_required' => false,
                    'world_best_claim_allowed' => false,
                ],
                'blockers' => array_values(array_filter((array) ($template['blockers'] ?? []), 'is_string')),
                'warnings' => array_values(array_filter((array) ($template['warnings'] ?? []), 'is_string')),
            ],
        );
    }

    public function verifyPublication(Request $request, AtlasFrontendPublicationVerifierService $publication): JsonResponse
    {
        $payload = $request->validate([
            'workspace' => ['required', 'string', 'max:1000'],
            'task' => ['required', 'string', 'max:4000'],
            'bundle' => ['required', 'string', 'max:1000'],
            'receipt' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'frontend_app' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $report = $publication->verify(
            (string) $payload['bundle'],
            trim((string) ($payload['receipt'] ?? '')) !== '' ? (string) $payload['receipt'] : null,
        );
        $publicVerified = (bool) data_get($report, 'claim_policy.public_distribution_claim_allowed', false);
        $localReady = (bool) data_get($report, 'claim_policy.local_bundle_claim_allowed', false);

        $summary = [
            'schema_version' => 'atlas.frontend.workspace_publication_verification.v1',
            'status' => $report['status'] ?? 'unknown',
            'verification_type' => 'selected_repo_product_proof_publication',
            'publication_schema_version' => AtlasFrontendPublicationVerifierService::SCHEMA_VERSION,
            'publication_hash' => $report['publication_hash'] ?? null,
            'bundle_directory_hash' => $report['bundle_directory_hash'] ?? null,
            'bundle_manifest_hash' => $report['bundle_manifest_hash'] ?? null,
            'bundle_hash' => $report['bundle_hash'] ?? null,
            'frontend_app_scope' => $report['frontend_app_scope'] ?? [],
            'product_site_assets' => $report['product_site_assets'] ?? null,
            'public_receipt_status' => data_get($report, 'public_receipt.status', 'unknown'),
            'public_receipt_hash' => data_get($report, 'public_receipt.receipt_hash'),
            'required_next_actions' => $publicVerified
                ? []
                : ($localReady
                    ? ['fill_operator_approved_publication_receipt', 'rerun_atlas_frontend_publication_verify', 'attach_publication_report_to_handoff']
                    : ['repair_product_proof_bundle_and_rerun_publication_verify']),
            'claim_policy' => [
                'local_bundle_claim_allowed' => $localReady,
                'public_distribution_claim_allowed' => $publicVerified,
                'world_best_claim_allowed' => false,
                'public_distribution_requires_verified_receipt' => true,
                'public_distribution_requires_matching_frontend_app_scope' => true,
                'raw_absolute_path_returned' => false,
                'raw_customer_source_returned' => false,
                'selected_repository_remains_primary_workspace' => true,
                'frontend_app_is_subscope_only' => true,
                'space_runtime_required' => false,
            ],
            'blockers' => array_values(array_filter((array) ($report['blockers'] ?? []), 'is_string')),
            'warnings' => array_values(array_filter((array) ($report['warnings'] ?? []), 'is_string')),
        ];
        $summary['publication_verification_hash'] = MissionCanonicalHash::sha256($summary);

        return response()->json([
            'schema_version' => 'atlas.frontend.workspace_api.publication_verify.v1',
            'surface' => 'atlas_code_frontend_publication_verification',
            'publication_verification' => $summary,
            'meta' => [
                'execution_allowed' => false,
                'provider_dispatch_allowed' => false,
                'provider_dispatch_performed' => false,
                'frontend_completion_claim_allowed' => false,
                'customer_handoff_allowed' => $publicVerified,
                'selected_repository_is_primary_workspace' => true,
                'frontend_app_is_subscope_only' => true,
                'space_runtime_required' => false,
                'world_best_claim_allowed' => false,
            ],
        ], ($summary['status'] ?? null) === 'blocked' ? 422 : 200);
    }

    public function publicationReceiptTemplate(Request $request, AtlasFrontendPublicationVerifierService $publication): JsonResponse
    {
        $payload = $request->validate([
            'workspace' => ['required', 'string', 'max:1000'],
            'task' => ['required', 'string', 'max:4000'],
            'output' => ['required', 'string', 'max:1000'],
            'bundle' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'frontend_app' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $template = $publication->writeReceiptTemplate(
            (string) $payload['output'],
            trim((string) ($payload['bundle'] ?? '')) !== '' ? (string) $payload['bundle'] : null,
        );

        $report = [
            'schema_version' => 'atlas.frontend.workspace_publication_receipt_template.v1',
            'status' => $template['status'] ?? 'unknown',
            'template_schema_version' => AtlasFrontendPublicationVerifierService::TEMPLATE_SCHEMA_VERSION,
            'template_hash' => $template['template_hash'] ?? null,
            'receipt_path_hash' => $template['receipt_path_hash'] ?? null,
            'bundle_context' => $template['bundle_context'] ?? [],
            'artifact_refs' => [
                'publication_receipt' => [
                    'relative_name' => 'publication-receipt.json',
                    'sha256' => File::isFile(rtrim((string) $payload['output'], DIRECTORY_SEPARATOR).'/publication-receipt.json')
                        ? hash_file('sha256', rtrim((string) $payload['output'], DIRECTORY_SEPARATOR).'/publication-receipt.json')
                        : null,
                ],
            ],
            'claim_policy' => [
                'template_is_not_public_verification' => true,
                'operator_approval_required' => true,
                'bundle_hash_match_required' => true,
                'raw_absolute_path_returned' => false,
                'selected_repository_remains_primary_workspace' => true,
                'frontend_app_is_subscope_only' => true,
                'space_runtime_required' => false,
                'world_best_claim_allowed' => false,
            ],
            'blockers' => array_values(array_filter((array) ($template['blockers'] ?? []), 'is_string')),
            'warnings' => array_values(array_filter((array) ($template['warnings'] ?? []), 'is_string')),
        ];
        $report['publication_receipt_template_hash'] = MissionCanonicalHash::sha256($report);

        return response()->json([
            'schema_version' => 'atlas.frontend.workspace_api.publication_receipt_template.v1',
            'surface' => 'atlas_code_frontend_publication_receipt_template',
            'publication_receipt_template' => $report,
            'meta' => [
                'execution_allowed' => false,
                'provider_dispatch_allowed' => false,
                'provider_dispatch_performed' => false,
                'frontend_completion_claim_allowed' => false,
                'customer_handoff_allowed' => false,
                'selected_repository_is_primary_workspace' => true,
                'frontend_app_is_subscope_only' => true,
                'space_runtime_required' => false,
                'world_best_claim_allowed' => false,
            ],
        ], ($report['status'] ?? null) === 'blocked' ? 422 : 200);
    }

    /**
     * @param  array<string,mixed>  $worklist
     * @return array<int,array<string,mixed>>
     */
    private function rivalReplayWorkItems(array $worklist, string $evidenceDirectory = ''): array
    {
        return collect((array) ($worklist['work_items'] ?? []))
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'id' => (string) ($item['id'] ?? ''),
                'case_id' => (string) ($item['case_id'] ?? ''),
                'system' => (string) ($item['system'] ?? ''),
                'status' => (string) ($item['status'] ?? 'unknown'),
                'blockers' => array_values(array_filter((array) ($item['blockers'] ?? []), 'is_string')),
                'pack_manifest_ref' => (string) ($item['pack_manifest_ref'] ?? ''),
                'run_manifest_ref' => (string) ($item['run_manifest_ref'] ?? ''),
                'task_spec_ref' => (string) ($item['task_spec_ref'] ?? ''),
                'completion_steps' => array_values(array_filter((array) ($item['completion_steps'] ?? []), 'is_string')),
                'requires_score_attestation' => isset($item['score_attestation_schema_version']),
                'requires_external_execution_receipt' => isset($item['external_execution_receipt_schema_version']),
                'requires_evidence_pack' => str_starts_with((string) ($item['id'] ?? ''), 'fill_evidence_pack_'),
                'commands' => $this->safeRivalReplayCommands((array) ($item['commands'] ?? []), $evidenceDirectory),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $commands
     * @return array<string,string>
     */
    private function safeRivalReplayCommands(array $commands, string $evidenceDirectory): array
    {
        $directory = rtrim($evidenceDirectory, DIRECTORY_SEPARATOR);

        return collect($commands)
            ->filter(fn (mixed $command, mixed $key): bool => is_string($key) && is_string($command) && $command !== '')
            ->mapWithKeys(function (string $command, string $key) use ($directory): array {
                $safe = $directory !== ''
                    ? str_replace($directory, '${ATLAS_FRONTEND_REPLAY_EVIDENCE}', $command)
                    : $command;

                return [$key => $safe];
            })
            ->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $workItems
     * @return array<int,string>
     */
    private function rivalReplayNextActions(array $workItems): array
    {
        $items = collect($workItems);
        $actions = [];

        if ($items->contains(fn (array $item): bool => (bool) ($item['requires_evidence_pack'] ?? false))) {
            $actions[] = 'fill_and_hash_missing_rival_replay_evidence_packs';
        }
        if ($items->contains(fn (array $item): bool => (bool) ($item['requires_external_execution_receipt'] ?? false))) {
            $actions[] = 'run_external_rivals_and_embed_execution_receipts';
        }
        if ($items->contains(fn (array $item): bool => (bool) ($item['requires_score_attestation'] ?? false))) {
            $actions[] = 'review_scores_and_embed_score_attestations';
        }
        $actions[] = 'rerun_atlas_frontend_rival_replay_inspection';

        return array_values(array_unique($actions));
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function replayTemplateResponse(string $schemaVersion, string $surface, string $payloadKey, array $report): JsonResponse
    {
        $report[$payloadKey.'_hash'] = MissionCanonicalHash::sha256($report);

        return response()->json([
            'schema_version' => $schemaVersion,
            'surface' => $surface,
            $payloadKey => $report,
            'meta' => [
                'execution_allowed' => false,
                'provider_dispatch_allowed' => false,
                'provider_dispatch_performed' => false,
                'frontend_completion_claim_allowed' => false,
                'customer_handoff_allowed' => false,
                'selected_repository_is_primary_workspace' => true,
                'frontend_app_is_subscope_only' => true,
                'space_runtime_required' => false,
                'world_best_claim_allowed' => false,
            ],
        ], ($report['status'] ?? null) === 'blocked' ? 422 : 200);
    }

    private function frontendProjectSlug(string $requested, string $workspace): string
    {
        $slug = Str::slug(trim($requested) !== '' ? $requested : (basename($workspace) ?: 'frontend-project'));
        if (strlen($slug) < 3) {
            $slug = 'frontend-'.$slug;
        }

        return trim(substr($slug, 0, 120), '-') ?: 'frontend-project';
    }
}
