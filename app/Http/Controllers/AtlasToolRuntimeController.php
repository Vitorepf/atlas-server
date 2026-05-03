<?php

namespace App\Http\Controllers;

use App\Models\AtlasToolFinding;
use App\Services\Tools\AtlasToolApprovalService;
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

    public function show(string $tool, Request $request, AtlasToolRegistryService $registry): JsonResponse
    {
        $definition = $registry->definition($tool);
        if (! $definition) {
            return response()->json(['message' => 'Tool not registered.'], 404);
        }

        return response()->json(['data' => $registry->detect($definition, $this->workspace($request))]);
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
        ]);

        try {
            $run = $executor->execute($tool, $this->workspace($request), array_values($validated['command']), [
                'dry_run' => (bool) ($validated['dry_run'] ?? false),
                'approved' => (bool) ($validated['approved'] ?? false),
                'required' => (bool) ($validated['required'] ?? false),
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

    public function approve(string $tool, Request $request, AtlasToolApprovalService $approvals): JsonResponse
    {
        $validated = $request->validate([
            'workspace' => ['nullable', 'string'],
            'scope_type' => ['sometimes', 'string', 'in:workspace,global'],
            'reason' => ['nullable', 'string', 'max:500'],
            'ttl_hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
            'network_allowed' => ['sometimes', 'boolean'],
        ]);

        try {
            $policy = $approvals->approve($tool, $this->workspace($request), [
                'scope_type' => $validated['scope_type'] ?? 'workspace',
                'reason' => $validated['reason'] ?? 'operator_approved_tool_execution',
                'ttl_hours' => $validated['ttl_hours'] ?? 24,
                'network_allowed' => (bool) ($validated['network_allowed'] ?? false),
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
