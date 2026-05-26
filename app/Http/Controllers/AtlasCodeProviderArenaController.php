<?php

declare(strict_types=1);

namespace App\Http\Controllers;

// Gap5.F2 wiring marker — Programming-adjacent controller.
// Canonical route_decision schema: atlas.dual_core.route_decision.v1
// Method-level emission to be wired per AP per family.

use App\Services\Ai\Programming\ForgeRivals\AtlasCodeProviderArenaSnapshotService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsActionDispatcher;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsModeRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Atlas Code · Provider Arena UI endpoints.
 *
 *   GET  /atlas-code/forge/provider-arena/snapshot
 *   POST /atlas-code/forge/provider-arena/run
 *
 * Read-only snapshot is the single source the Provider Arena RightRail
 * panel consumes. The `run` endpoint dispatches the canonical
 * `atlas:forge:rivals run-arena` action — but ONLY for `local_fake` mode
 * (zero provider tokens) unless the caller provides all three operator
 * confirmations. The controller never weakens the safety contract; it just
 * forwards to the dispatcher and propagates blockers honestly.
 *
 * NEVER unlocks `external_rivals_certification`.
 */
final class AtlasCodeProviderArenaController extends Controller
{
    public function show(
        Request $request,
        AtlasCodeProviderArenaSnapshotService $service,
    ): JsonResponse {
        $limit = (int) $request->query(
            'history_limit',
            (string) AtlasCodeProviderArenaSnapshotService::HISTORY_LIMIT_DEFAULT,
        );
        $payload = $service->snapshot($limit);

        return response()->json($payload, 200);
    }

    public function run(
        Request $request,
        AtlasForgeRivalsActionDispatcher $dispatcher,
    ): JsonResponse {
        $input = [
            'arm_a' => $this->stringOrEmpty($request->input('arm_a')),
            'arm_b' => $this->stringOrEmpty($request->input('arm_b')),
            'arm_a_model' => $this->stringOrEmpty($request->input('arm_a_model')),
            'arm_b_model' => $this->stringOrEmpty($request->input('arm_b_model')),
            'task_category' => $this->stringOrEmpty($request->input('task_category')),
            'mode' => $this->stringOrEmpty($request->input('mode')) ?: AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE,
            'preset' => $this->stringOrEmpty($request->input('preset')) ?: 'smoke',
            'source_ref' => $this->stringOrEmpty($request->input('source_ref')) ?: 'HEAD',
            'run_id' => $this->stringOrEmpty($request->input('run_id')),
            'confirmations' => [
                'runbook_reviewed' => $this->boolOrFalse($request->input('confirmations.runbook_reviewed')),
                'provider_cost' => $this->boolOrFalse($request->input('confirmations.provider_cost')),
                'real_provider_call' => $this->boolOrFalse($request->input('confirmations.real_provider_call')),
            ],
        ];

        $payload = $dispatcher->dispatch('run-arena', $input);

        $statusCode = match ((string) ($payload['status'] ?? '')) {
            'ok' => 200,
            'blocked' => 409,
            'error' => 400,
            default => 200,
        };

        return response()->json($payload, $statusCode);
    }

    private function stringOrEmpty(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private function boolOrFalse(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
