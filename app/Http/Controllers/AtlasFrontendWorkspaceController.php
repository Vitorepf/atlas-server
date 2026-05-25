<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\Frontend\AtlasFrontendCompanyPortfolioService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendDeliveryHandoffService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendEvidenceKitService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendProviderInstructionPacketService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendRunCertificationService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendSelectedWorkspaceService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendWorkspaceRuntimeProjectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

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
}
