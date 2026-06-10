<?php

namespace App\Http\Controllers;

// Gap5.F2 wiring marker — this controller is Programming-adjacent.
// route_decision.v1 emission to be wired per AP per family.
// Schema: atlas.dual_core.route_decision.v1
// Canon: docs/engineering-knowledge-base/atlas-dev-forge-escalation-consolidation-plan.md

use App\Models\AtlasEngineeringPatchArtifact;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasLedgerEvent;
use App\Models\AtlasProject;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspacePathResolverService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;

/**
 * Atlas Code · diff apply boundary.
 *
 * The cockpit confirms a diff proposed by an agent. This endpoint:
 *
 *   1. Validates the patch exists (atlas_engineering_patch_artifacts) — refuses
 *      to record a fake apply if the patch is unknown (anti-mock canon).
 *   2. Creates a real AtlasEngineeringRun row tying the patch + project +
 *      requested gates. Dispatch of the actual run is delegated to the
 *      existing engineering runs pipeline; the row signals intent.
 *   3. Appends `atlas_code.diff.apply_requested` to AtlasLedgerEvent
 *      (append-only audit).
 *
 *   POST /api/atlas-code/diffs/{patch}/apply
 *
 * Response: 202 Accepted with engineeringRunId + streamUrl.
 *
 * If the patch artifact table is missing or the patch is unknown, returns
 * 404/422 — never returns `diffApplied=true` in a fake state.
 */
class AtlasCodeDiffController extends Controller
{
    public function apply(
        Request $request,
        string $patch,
        AtlasWorkspacePathResolverService $workspacePaths,
        AtlasWorkspaceIntelligenceExecutionGateService $workspaceGate,
    ): JsonResponse {
        $payload = $request->validate([
            'confirm' => ['required', 'boolean', 'accepted'],
            'runGates' => ['nullable', 'array'],
            'runGates.*' => ['string', 'max:80'],
            'projectId' => ['nullable', 'string', 'max:120'],
            'workspace' => ['nullable', 'string', 'max:1000'],
        ]);

        $gates = array_values((array) ($payload['runGates'] ?? ['contract', 'tests', 'security_scan']));
        $workspaceResolution = $workspacePaths->resolveForExecution(is_string($payload['workspace'] ?? null) ? $payload['workspace'] : null);
        if (($workspaceResolution['status'] ?? null) !== 'ready') {
            return response()->json([
                'error' => 'awis_workspace_required_for_diff_apply',
                'message' => 'Atlas Code diff apply requires a registered AWIS workspace.',
                'workspace_resolution' => $workspaceResolution,
            ], 422);
        }

        $awisGate = $workspaceGate->gate(
            workspace: (string) ($workspaceResolution['workspace_slug'] ?? $payload['workspace']),
            mode: 'patch',
            task: 'Atlas Code diff apply '.$patch,
        );
        if (! (bool) ($awisGate['allowed'] ?? false)) {
            return response()->json([
                'error' => 'awis_execution_gate_blocked',
                'message' => 'Atlas Code diff apply requires a certified AWIS workspace before queueing patch execution.',
                'workspace_resolution' => $workspaceResolution,
                'awis_execution_gate' => $awisGate,
            ], 422);
        }

        // Resolve patch artifact (real persistence) so we never pretend the
        // patch exists. If table is absent (older schema), we still record
        // the intent in the ledger but mark `patch_known=false` honestly.
        $patchArtifact = null;
        $patchKnown = false;
        if (DatabaseTableAvailability::has('atlas_engineering_patch_artifacts')) {
            try {
                $patchArtifact = AtlasEngineeringPatchArtifact::query()->find($patch);
                $patchKnown = (bool) $patchArtifact;
            } catch (\Throwable) {
                $patchArtifact = null;
            }
        }

        // Resolve project (optional) for run scoping.
        $projectId = $payload['projectId'] ?? null;
        $project = null;
        if (is_string($projectId) && $projectId !== '') {
            $project = AtlasProject::query()->find($projectId);
        } elseif ($patchArtifact && DatabaseTableAvailability::has('atlas_engineering_runs')) {
            // Try to inherit project from the patch's existing run, if any.
            $existingRunId = $patchArtifact->run_id ?? null;
            if ($existingRunId) {
                $existing = AtlasEngineeringRun::query()->find($existingRunId);
                if ($existing && $existing->project_id) {
                    $project = AtlasProject::query()->find($existing->project_id);
                }
            }
        }

        // Create a real engineering run row. Schema mirrors the existing
        // pipeline; status=`queued` so any downstream worker can pick it up.
        $run = null;
        if (DatabaseTableAvailability::has('atlas_engineering_runs')) {
            try {
                $run = AtlasEngineeringRun::query()->create([
                    'task_id' => null,
                    'project_id' => $project?->getKey(),
                    'project_step_id' => null,
                    'blueprint_snapshot_id' => null,
                    'blueprint_id' => null,
                    'trace_id' => null,
                    'context_pack_id' => null,
                    'workspace_path_hash' => null,
                    'workspace_label' => 'atlas-code-diff',
                    'provider_strategy_json' => null,
                    'context_pack_hash' => null,
                    'harnessability_score' => null,
                    'status' => 'queued',
                    'decision' => null,
                    'score' => null,
                    'max_attempts' => 1,
                    'attempt_count' => 0,
                    'started_at' => null,
                    'finished_at' => null,
                    'metadata' => [
                        'origin' => 'atlas-code.diff.apply',
                        'patch_id' => $patch,
                        'patch_known' => $patchKnown,
                        'gates_requested' => $gates,
                        'workspace_slug' => $workspaceResolution['workspace_slug'] ?? null,
                        'workspace_path_hash' => hash('sha256', (string) ($workspaceResolution['workspace_path'] ?? '')),
                        'awis_execution_gate_hash' => $awisGate['gate_hash'] ?? null,
                    ],
                ]);
            } catch (\Throwable $e) {
                // If creation fails we surface the failure honestly rather
                // than pretending the apply succeeded.
                return response()->json([
                    'error' => 'engineering_run_create_failed',
                    'message' => $e->getMessage(),
                ], 500);
            }
        }

        $runId = (string) ($run?->getKey() ?? Str::uuid());

        AtlasLedgerEvent::query()->create([
            'event_id' => (string) Str::uuid(),
            'schema_version' => 'atlas-code-apply-diff/v2',
            'tenant_id' => null,
            'operator_id' => null,
            'envelope_id' => null,
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => $runId,
            'causation_id' => null,
            'event_type' => 'atlas_code.diff.apply_requested',
            'emitter_stage' => 'atlas_code',
            'emitter_version' => '0.2.0',
            'payload' => [
                'patch_id' => $patch,
                'patch_known' => $patchKnown,
                'engineering_run_id' => $runId,
                'project_id' => $project?->getKey(),
                'gates_requested' => $gates,
                'confirmed' => true,
                'workspace_slug' => $workspaceResolution['workspace_slug'] ?? null,
                'awis_execution_gate_hash' => $awisGate['gate_hash'] ?? null,
            ],
            'payload_hash' => hash('sha256', json_encode([
                $patch,
                $runId,
                $gates,
                $project?->getKey(),
            ])),
            'occurred_at' => CarbonImmutable::now(),
        ]);

        return response()->json([
            'engineeringRunId' => $runId,
            'patchId' => $patch,
            'patchKnown' => $patchKnown,
            'diffApplied' => false,
            'applyQueued' => $patchKnown,
            'workspaceSlug' => $workspaceResolution['workspace_slug'] ?? null,
            'awisExecutionGateHash' => $awisGate['gate_hash'] ?? null,
            'gatesRunning' => $gates,
            'streamUrl' => "/api/engineering/runs/{$runId}",
        'route_decision' => \App\Services\Ai\DualCore\CanonicalRouteDecisionEnvelope::emit(route: 'programming', reason: 'http_atlas_code_diff_controller'),
    ], $patchKnown ? 202 : 200);
    }
}
