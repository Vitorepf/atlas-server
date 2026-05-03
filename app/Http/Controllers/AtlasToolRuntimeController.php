<?php

namespace App\Http\Controllers;

use App\Models\AtlasToolFinding;
use App\Services\Tools\AtlasToolApprovalService;
use App\Services\Tools\AtlasToolAuthorityMatrixService;
use App\Services\Tools\AtlasToolEvidenceQueryService;
use App\Services\Tools\AtlasToolExecutor;
use App\Services\Tools\AtlasToolFindingWaiverService;
use App\Services\Tools\AtlasToolGateService;
use App\Services\Tools\AtlasToolRegistryService;
use App\Services\Tools\AtlasToolReleaseGateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtlasToolRuntimeController extends Controller
{
    public function index(AtlasToolRegistryService $registry): JsonResponse
    {
        return response()->json([
            'data' => $registry->definitions()->values(),
        ]);
    }

    public function doctor(Request $request, AtlasToolRegistryService $registry): JsonResponse
    {
        return response()->json($registry->doctor($this->workspace($request)));
    }

    public function authority(AtlasToolAuthorityMatrixService $authority): JsonResponse
    {
        return response()->json($authority->matrix());
    }

    public function show(string $tool, Request $request, AtlasToolRegistryService $registry): JsonResponse
    {
        $definition = $registry->definition($tool);
        if (! $definition) {
            return response()->json(['message' => 'Tool not registered.'], 404);
        }

        return response()->json(['data' => $registry->detect($definition, $this->workspace($request))]);
    }

    public function commands(string $tool, Request $request, AtlasToolRegistryService $registry): JsonResponse
    {
        $catalog = $registry->commandCatalog($tool, $this->workspace($request));
        if (! $catalog) {
            return response()->json(['message' => 'Tool not registered.'], 404);
        }

        return response()->json($catalog);
    }

    public function run(string $tool, Request $request, AtlasToolExecutor $executor): JsonResponse
    {
        $validated = $request->validate([
            'workspace' => ['nullable', 'string'],
            'command' => ['required', 'array', 'min:1'],
            'command.*' => ['required', 'string'],
            'dry_run' => ['sometimes', 'boolean'],
            'approved' => ['sometimes', 'boolean'],
            'required' => ['sometimes', 'boolean'],
            'network_allowed' => ['sometimes', 'boolean'],
            'max_execution_tier' => ['sometimes', 'string', 'in:T0,T1,T2,T3,t0,t1,t2,t3'],
            'sandbox_mode' => ['sometimes', 'string', 'in:workspace,worktree,docker,host,none'],
            'privacy_level' => ['sometimes', 'string', 'in:standard,sensitive,restricted'],
            'task_type' => ['sometimes', 'string', 'max:64', 'regex:/^[A-Za-z][A-Za-z0-9_-]*$/'],
            'requires_provider_safe' => ['sometimes', 'boolean'],
            'env' => ['sometimes', 'array', 'max:20'],
            'env.*' => ['string', 'max:2100'],
            'output_limit' => ['sometimes', 'integer', 'min:1000', 'max:200000'],
        ]);

        try {
            $run = $executor->execute($tool, $this->workspace($request), array_values($validated['command']), [
                'dry_run' => (bool) ($validated['dry_run'] ?? false),
                'approved' => (bool) ($validated['approved'] ?? false),
                'required' => (bool) ($validated['required'] ?? false),
                'network_allowed' => (bool) ($validated['network_allowed'] ?? false),
                'max_execution_tier' => $validated['max_execution_tier'] ?? null,
                'sandbox_mode' => $validated['sandbox_mode'] ?? null,
                'privacy_level' => $validated['privacy_level'] ?? null,
                'task_type' => $validated['task_type'] ?? null,
                'requires_provider_safe' => array_key_exists('requires_provider_safe', $validated)
                    ? (bool) $validated['requires_provider_safe']
                    : null,
                'env' => $validated['env'] ?? [],
                'output_limit' => $validated['output_limit'] ?? null,
                'surface' => 'api',
            ]);
        } catch (\InvalidArgumentException $exception) {
            $status = str_contains($exception->getMessage(), 'not registered') ? 404 : 422;

            return response()->json(['message' => $exception->getMessage()], $status);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json(['data' => $run->load(['artifacts', 'findings'])], 201);
    }

    public function runRecipe(string $tool, string $recipe, Request $request, AtlasToolExecutor $executor): JsonResponse
    {
        $validated = $request->validate([
            'workspace' => ['nullable', 'string'],
            'dry_run' => ['sometimes', 'boolean'],
            'approved' => ['sometimes', 'boolean'],
            'required' => ['sometimes', 'boolean'],
            'env' => ['sometimes', 'array', 'max:20'],
            'env.*' => ['string', 'max:2100'],
            'output_limit' => ['sometimes', 'integer', 'min:1000', 'max:200000'],
        ]);

        try {
            $options = [
                'approved' => (bool) ($validated['approved'] ?? false),
                'required' => (bool) ($validated['required'] ?? false),
                'env' => $validated['env'] ?? [],
                'output_limit' => $validated['output_limit'] ?? null,
                'surface' => 'api_recipe',
            ];
            if (array_key_exists('dry_run', $validated)) {
                $options['dry_run'] = (bool) $validated['dry_run'];
            }

            $run = $executor->executeRecipe($tool, $recipe, $this->workspace($request), $options);
        } catch (\InvalidArgumentException $exception) {
            $status = str_contains($exception->getMessage(), 'not registered') ? 404 : 422;

            return response()->json(['message' => $exception->getMessage()], $status);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json(['data' => $run->load(['artifacts', 'findings'])], 201);
    }

    public function approve(string $tool, Request $request, AtlasToolApprovalService $approvals): JsonResponse
    {
        $validated = $request->validate([
            'workspace' => ['nullable', 'string'],
            'scope_type' => ['sometimes', 'string', 'in:workspace,global'],
            'reason' => ['nullable', 'string', 'max:500'],
            'ttl_hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
            'network_allowed' => ['sometimes', 'boolean'],
            'max_execution_tier' => ['sometimes', 'string', 'in:T0,T1,T2,T3,t0,t1,t2,t3'],
            'sandbox_mode' => ['sometimes', 'string', 'in:workspace,worktree,docker,host,none'],
            'privacy_level' => ['sometimes', 'string', 'in:standard,sensitive,restricted'],
            'task_type' => ['sometimes', 'string', 'max:64', 'regex:/^[A-Za-z][A-Za-z0-9_-]*$/'],
            'requires_provider_safe' => ['sometimes', 'boolean'],
        ]);

        try {
            $policy = $approvals->approve($tool, $this->workspace($request), [
                'scope_type' => $validated['scope_type'] ?? 'workspace',
                'reason' => $validated['reason'] ?? 'operator_approved_tool_execution',
                'ttl_hours' => $validated['ttl_hours'] ?? 24,
                'network_allowed' => (bool) ($validated['network_allowed'] ?? false),
                'max_execution_tier' => $validated['max_execution_tier'] ?? 'T3',
                'sandbox_mode' => $validated['sandbox_mode'] ?? 'workspace',
                'privacy_level' => $validated['privacy_level'] ?? 'standard',
                'task_type' => $validated['task_type'] ?? 'manual',
                'requires_provider_safe' => (bool) ($validated['requires_provider_safe'] ?? false),
                'approved_by' => 'atlas_api',
                'source' => 'atlas_tools_api',
            ]);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }

        return response()->json([
            'data' => $policy,
            'approval_status' => $approvals->approvalStatus($policy),
        ], 201);
    }

    public function revoke(string $tool, Request $request, AtlasToolApprovalService $approvals): JsonResponse
    {
        $validated = $request->validate([
            'workspace' => ['nullable', 'string'],
            'scope_type' => ['sometimes', 'string', 'in:workspace,global'],
        ]);

        $policy = $approvals->revoke($tool, $this->workspace($request), (string) ($validated['scope_type'] ?? 'workspace'));

        return response()->json([
            'data' => $policy,
            'approval_status' => $policy ? $approvals->approvalStatus($policy) : 'not_configured',
        ]);
    }

    public function policies(Request $request, AtlasToolApprovalService $approvals): JsonResponse
    {
        $limit = max(1, min(100, (int) $request->integer('limit', 20)));

        return response()->json([
            'data' => $approvals->policies($this->workspace($request), $limit),
        ]);
    }

    public function evidence(Request $request, AtlasToolEvidenceQueryService $evidenceQuery): JsonResponse
    {
        $validated = $request->validate([
            'workspace' => ['nullable', 'string'],
            'tool_slug' => ['nullable', 'string'],
            'tool' => ['nullable', 'string'],
            'surface' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'policy_decision' => ['nullable', 'string'],
            'run_context_type' => ['nullable', 'string'],
            'run_context_id' => ['nullable', 'string'],
            'required' => ['sometimes', 'boolean'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json([
            'data' => $evidenceQuery->recent([
                ...$validated,
                'workspace' => $request->filled('workspace') ? $this->workspace($request) : null,
                'tool_slug' => $validated['tool_slug'] ?? $validated['tool'] ?? null,
                'limit' => $validated['limit'] ?? 20,
            ]),
        ]);
    }

    public function evidenceShow(string $run, Request $request, AtlasToolEvidenceQueryService $evidenceQuery): JsonResponse
    {
        $toolRun = $evidenceQuery->findRun($run, [
            'workspace' => $request->filled('workspace') ? $this->workspace($request) : null,
        ]);

        if (! $toolRun) {
            return response()->json(['message' => 'Tool run not found.'], 404);
        }

        return response()->json(['data' => $toolRun]);
    }

    public function evidenceExport(string $run, Request $request, AtlasToolEvidenceQueryService $evidenceQuery): JsonResponse
    {
        $payload = $evidenceQuery->exportRun($run, [
            'workspace' => $request->filled('workspace') ? $this->workspace($request) : null,
        ]);

        if (! $payload) {
            return response()->json(['message' => 'Tool run not found.'], 404);
        }

        return response()->json($payload);
    }

    public function gate(Request $request, AtlasToolGateService $gate): JsonResponse
    {
        $validated = $request->validate([
            'workspace' => ['nullable', 'string'],
            'tool_slug' => ['nullable', 'string'],
            'tool' => ['nullable', 'string'],
            'surface' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'policy_decision' => ['nullable', 'string'],
            'run_context_type' => ['nullable', 'string'],
            'run_context_id' => ['nullable', 'string'],
            'required' => ['sometimes', 'boolean'],
            'required_tool' => ['nullable'],
            'fail_status' => ['nullable'],
            'require_evidence' => ['sometimes', 'boolean'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json($gate->evaluate([
            ...$validated,
            'workspace' => $request->filled('workspace') ? $this->workspace($request) : null,
            'tool_slug' => $validated['tool_slug'] ?? $validated['tool'] ?? null,
            'limit' => $validated['limit'] ?? 20,
        ], [
            'required_tools' => $validated['required_tool'] ?? [],
            'fail_statuses' => $validated['fail_status'] ?? [],
            'require_evidence' => (bool) ($validated['require_evidence'] ?? false),
        ]));
    }

    public function releaseGate(Request $request, AtlasToolReleaseGateService $releaseGate): JsonResponse
    {
        $validated = $request->validate([
            'workspace' => ['nullable', 'string'],
            'surface' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'policy_decision' => ['nullable', 'string'],
            'run_context_type' => ['nullable', 'string'],
            'run_context_id' => ['nullable', 'string'],
            'required' => ['sometimes', 'boolean'],
            'fail_status' => ['nullable'],
            'release_profile' => ['nullable', 'string', 'in:security_sbom_release'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json($releaseGate->evaluate([
            ...$validated,
            'workspace' => $request->filled('workspace') ? $this->workspace($request) : null,
            'surface' => $validated['surface'] ?? 'engineering_quality_scan',
            'limit' => $validated['limit'] ?? 100,
        ], [
            'release_profile' => $validated['release_profile'] ?? 'security_sbom_release',
            'fail_statuses' => $validated['fail_status'] ?? [],
        ]));
    }

    public function waiveFinding(string $finding, Request $request, AtlasToolFindingWaiverService $waivers): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'ttl_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
        ]);

        $toolFinding = AtlasToolFinding::query()->with('run')->find($finding);
        if (! $toolFinding) {
            return response()->json(['message' => 'Tool finding not found.'], 404);
        }

        return response()->json([
            'data' => $waivers->waive($toolFinding, [
                'reason' => $validated['reason'] ?? 'operator_waived_tool_finding',
                'ttl_hours' => $validated['ttl_hours'] ?? null,
                'waived_by' => 'atlas_api',
                'source' => 'atlas_tools_api',
            ])->load('run'),
        ], 201);
    }

    public function revokeFindingWaiver(string $finding, Request $request, AtlasToolFindingWaiverService $waivers): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $toolFinding = AtlasToolFinding::query()->with('run')->find($finding);
        if (! $toolFinding) {
            return response()->json(['message' => 'Tool finding not found.'], 404);
        }

        return response()->json([
            'data' => $waivers->revoke($toolFinding, [
                'reason' => $validated['reason'] ?? 'operator_revoked_tool_finding_waiver',
                'revoked_by' => 'atlas_api',
            ])->load('run'),
        ]);
    }

    private function workspace(Request $request): string
    {
        $workspace = (string) ($request->input('workspace') ?: base_path());
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }
}
