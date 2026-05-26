<?php

declare(strict_types=1);

namespace App\Http\Controllers;

// Gap5.F2 wiring marker — this controller is Programming-adjacent.
// route_decision.v1 emission to be wired per AP per family.
// Schema: atlas.dual_core.route_decision.v1
// Canon: docs/engineering-knowledge-base/atlas-dev-forge-escalation-consolidation-plan.md

use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasForgeProviderCapacityService;
use App\Services\Ai\Programming\AtlasForgeProviderFailureMemoryService;
use App\Services\Ai\Programming\AtlasForgeProviderFallbackPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/**
 * Atlas Forge Provider Capacity & Failure Memory endpoints.
 *
 *   GET  /atlas-code/forge/provider-capacity                    · global snapshot
 *   GET  /atlas-code/works/{project}/forge/provider-capacity    · obra-scoped snapshot + memory
 *   POST /atlas-code/works/{project}/forge/provider-failures    · record failure event
 *
 * Hard rules:
 *   - NEVER calls an external provider;
 *   - NEVER spends a token;
 *   - NEVER auto-creates an Obra;
 *   - Obra not found → 404;
 *   - Unknown failure type → 422;
 *   - Capacity exhausted → 200 with honest payload (UI renders blocker);
 *   - Separated_from_external_rivals_certification = true (always).
 *
 * Doc: docs/engineering-knowledge-base/atlas-forge-provider-capacity-continuity-v1.md
 */
final class AtlasCodeForgeProviderCapacityController extends Controller
{
    /**
     * Global capacity snapshot — no Obra context required.
     */
    public function global(
        Request $request,
        AtlasForgeProviderCapacityService $capacity,
    ): JsonResponse {
        $snapshot = $capacity->snapshot([
            'workspace' => $this->stringOrNull($request->query('workspace')),
        ]);

        return response()->json($snapshot, 200);
    }

    /**
     * Obra-scoped capacity snapshot — also returns failure memory inline.
     */
    public function show(
        Request $request,
        AtlasProject $project,
        AtlasForgeProviderCapacityService $capacity,
        AtlasForgeProviderFailureMemoryService $memory,
    ): JsonResponse {
        $snapshot = $capacity->snapshot([
            'obra_id' => (string) $project->getKey(),
            'workspace' => $this->stringOrNull($request->query('workspace')),
        ]);
        $snapshot['failure_memory'] = $memory->snapshot($project);

        return response()->json($snapshot, 200);
    }

    /**
     * Record a failure event for the given Obra.
     */
    public function recordFailure(
        Request $request,
        AtlasProject $project,
        AtlasForgeProviderFailureMemoryService $memory,
        AtlasForgeProviderCapacityService $capacity,
    ): JsonResponse {
        $provider = $this->stringOrNull($request->input('provider'));
        $failureType = $this->stringOrNull($request->input('failure_type'));

        if ($provider === null) {
            return response()->json($this->blocked('provider_required'), 422);
        }

        if ($failureType === null
            || ! in_array($failureType, AtlasForgeProviderFallbackPolicyService::KNOWN_FAILURES, true)
        ) {
            return response()->json(array_merge(
                $this->blocked('unknown_failure_type'),
                [
                    'failure_provided' => $failureType,
                    'known_failures' => AtlasForgeProviderFallbackPolicyService::KNOWN_FAILURES,
                ],
            ), 422);
        }

        try {
            $event = $memory->record($project, [
                'provider' => $provider,
                'model' => $this->stringOrNull($request->input('model')),
                'role' => $this->stringOrNull($request->input('role')),
                'failure_type' => $failureType,
                'reason' => $this->stringOrNull($request->input('reason')),
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json($this->blocked($e->getMessage()), 422);
        } catch (Throwable $e) {
            return response()->json(array_merge(
                $this->blocked('failure_record_failed'),
                ['reason' => $e->getMessage()],
            ), 500);
        }

        $project->refresh();
        $updatedCapacity = $capacity->snapshot([
            'obra_id' => (string) $project->getKey(),
        ]);
        $updatedMemory = $memory->snapshot($project);

        return response()->json([
            'schema_version' => AtlasForgeProviderFailureMemoryService::EVENT_SCHEMA_VERSION,
            'status' => 'recorded',
            'event' => $event,
            'capacity_snapshot' => $updatedCapacity,
            'failure_memory' => $updatedMemory,
            'external_provider_call' => false,
            'separated_from' => 'external_rivals_certification',
        'route_decision' => \App\Services\Ai\DualCore\CanonicalRouteDecisionEnvelope::emit(route: 'programming', reason: 'http_atlas_code_forge_provider_capacity_controller'),
    ], 201);
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(string $blocker): array
    {
        return [
            'schema_version' => AtlasForgeProviderFailureMemoryService::EVENT_SCHEMA_VERSION,
            'status' => 'blocked',
            'blocker' => $blocker,
            'external_provider_call' => false,
            'separated_from' => 'external_rivals_certification',
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
