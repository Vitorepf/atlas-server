<?php

declare(strict_types=1);

namespace App\Http\Controllers;

// Gap5.F2 wiring marker — this controller is Programming-adjacent.
// route_decision.v1 emission to be wired per AP per family.
// Schema: atlas.dual_core.route_decision.v1
// Canon: docs/engineering-knowledge-base/atlas-dev-forge-escalation-consolidation-plan.md

use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasCodeAttentionControlPlaneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Atlas Code Attention Control Plane endpoints.
 *
 *   GET  /atlas-code/attention                            → read-model snapshot
 *   POST /atlas-code/attention/{project}/decision         → record human decision
 *
 * Read-only nas listas. A acao mutante exige que `action` exista no
 * `allowed_actions` do item; o service emite um receipt append-only e nunca
 * chama provider externo.
 *
 * Doc: docs/engineering-knowledge-base/atlas-code-attention-control-plane-v1.md
 */
final class AtlasCodeAttentionControlPlaneController extends Controller
{
    public function index(
        Request $request,
        AtlasCodeAttentionControlPlaneService $service,
    ): JsonResponse {
        $data = $request->validate([
            'workspace' => ['nullable', 'string', 'max:120'],
        ]);

        $payload = $service->snapshot([
            'workspace_slug' => isset($data['workspace']) && $data['workspace'] !== ''
                ? (string) $data['workspace']
                : null,
        ]);

        return response()->json($payload, 200);
    }

    public function decide(
        Request $request,
        AtlasProject $project,
        AtlasCodeAttentionControlPlaneService $service,
    ): JsonResponse {
        $data = $request->validate([
            'item_key' => ['required', 'string', 'max:80'],
            'action' => ['required', 'string', 'max:60'],
            'reason' => ['nullable', 'string', 'max:600'],
            'decided_by' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'pause_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
        ]);

        if (! in_array($data['action'], $service->allowedActionsVocabulary(), true)) {
            return response()->json([
                'error' => 'action_outside_canonical_vocabulary',
                'allowed_actions_vocabulary' => $service->allowedActionsVocabulary(),
            ], 422);
        }

        try {
            $receipt = $service->recordDecision(
                obra: $project,
                itemKey: $data['item_key'],
                action: $data['action'],
                context: [
                    'reason' => $data['reason'] ?? null,
                    'decided_by' => $data['decided_by'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'pause_hours' => $data['pause_hours'] ?? null,
                ],
            );
        } catch (\InvalidArgumentException $e) {
            $status = $e->getMessage() === 'attention_item_not_found' ? 404 : 422;

            return response()->json(['error' => $e->getMessage()], $status);
        }

        $snapshot = $service->snapshot([]);

        return response()->json([
            'receipt' => $receipt,
            'snapshot' => $snapshot,
        'route_decision' => \App\Services\Ai\DualCore\CanonicalRouteDecisionEnvelope::emit(route: 'programming', reason: 'http_atlas_code_attention_control_plane_controller'),
    ], 200);
    }
}
