<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Ai\SelfImprovement\AtlasSelfImprovementActivationCockpitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Atlas Code Self-Improvement Activation Cockpit endpoints (read-only).
 *
 *   GET  /atlas-code/self-improvement/activation-cockpit                 · list + counters + summary
 *   GET  /atlas-code/self-improvement/activation-cockpit/{activation}    · detail (humanised)
 *
 * Mutations (accept/reject/plan) continue to live on
 * `AtlasCodeSelfImprovementForgeActivationController` — this cockpit layer
 * is a pure read-model. It NEVER triggers Fast Path, NEVER calls a provider
 * and NEVER auto-creates an Obra.
 *
 * Schema: atlas.self_improvement.activation_cockpit.v1
 *
 * Doc: docs/engineering-knowledge-base/atlas-self-improvement-activation-cockpit-v1.md
 */
final class AtlasCodeSelfImprovementActivationCockpitController extends Controller
{
    public function index(
        Request $request,
        AtlasSelfImprovementActivationCockpitService $service,
    ): JsonResponse {
        $payload = $service->cockpit([
            'status' => $request->query('status'),
            'bucket' => $request->query('bucket'),
            'has_obra' => $request->query('has_obra'),
        ]);

        return response()->json($payload, 200);
    }

    public function show(
        string $activation,
        AtlasSelfImprovementActivationCockpitService $service,
    ): JsonResponse {
        $payload = $service->cockpit([
            'activation_id' => $activation,
        ]);

        $selected = $payload['selected_activation'] ?? null;
        if (! is_array($selected) || ($selected['status'] ?? null) === 'blocked' && in_array('activation_not_found', (array) ($selected['blockers'] ?? []), true)) {
            return response()->json($payload, 404);
        }

        return response()->json($payload, 200);
    }
}
