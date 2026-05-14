<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Ai\SelfImprovement\AtlasSelfImprovementActivationCockpitService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementForgeActivationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Atlas Code Self-Improvement → Forge Activation endpoints.
 *
 *   POST /atlas-code/self-improvement/forge-activations                   · plan
 *   GET  /atlas-code/self-improvement/forge-activations                   · registry
 *   GET  /atlas-code/self-improvement/forge-activations/{activation}      · show
 *   POST /atlas-code/self-improvement/forge-activations/{activation}/accept · accept
 *   POST /atlas-code/self-improvement/forge-activations/{activation}/reject · reject
 *
 * Hard rules:
 *   - NEVER calls a provider;
 *   - NEVER auto-executes Fast Path;
 *   - NEVER promotes completion claim;
 *   - Accept requires reviewer + reason; Reject requires reviewer + reason;
 *   - Activation not found → 404;
 *   - blocked / needs_revision / pending_human_review → 200 (read-model
 *     payload includes status + blockers; UI renders honestly).
 *
 * Backward-compatible enrichment: every successful response (plan/show/
 * accept/reject) now also carries `human_summary` + `next_safe_action` so
 * the Self-Improvement Activation Cockpit panel can render uniformly
 * without re-fetching the cockpit endpoint. The underlying service
 * contract is preserved — existing consumers ignore the extra keys.
 */
final class AtlasCodeSelfImprovementForgeActivationController extends Controller
{
    public function index(AtlasSelfImprovementForgeActivationService $service): JsonResponse
    {
        return response()->json($service->registry(), 200);
    }

    public function store(
        Request $request,
        AtlasSelfImprovementForgeActivationService $service,
        AtlasSelfImprovementActivationCockpitService $cockpit,
    ): JsonResponse {
        $proposal = $request->input('proposal');
        $proposalId = $request->input('proposal_id');
        $obraTitle = $request->input('obra_title');
        $dryRun = (bool) $request->input('dry_run', false);

        $payload = $service->plan([
            'proposal' => is_array($proposal) ? $proposal : null,
            'proposal_id' => is_string($proposalId) ? $proposalId : null,
            'obra_title' => is_string($obraTitle) ? $obraTitle : null,
            'dry_run' => $dryRun,
        ]);

        $statusCode = $payload['status'] === AtlasSelfImprovementForgeActivationService::STATUS_BLOCKED
            ? 422
            : 201;

        return response()->json($this->enrich($payload, $cockpit), $statusCode);
    }

    public function show(
        string $activation,
        AtlasSelfImprovementForgeActivationService $service,
        AtlasSelfImprovementActivationCockpitService $cockpit,
    ): JsonResponse {
        $payload = $service->get($activation);
        if ($payload === null) {
            return response()->json([
                'schema_version' => AtlasSelfImprovementForgeActivationService::SCHEMA_VERSION,
                'status' => 'blocked',
                'blocker' => 'activation_not_found',
                'human_summary' => 'Activation não encontrada — verifique o id.',
                'next_safe_action' => 'Listar activations existentes ou planejar nova proposta.',
            ], 404);
        }

        return response()->json($this->enrich($payload, $cockpit), 200);
    }

    public function accept(
        Request $request,
        string $activation,
        AtlasSelfImprovementForgeActivationService $service,
        AtlasSelfImprovementActivationCockpitService $cockpit,
    ): JsonResponse {
        $payload = $service->accept($activation, [
            'reviewer' => $request->input('reviewer'),
            'reason' => $request->input('reason'),
        ]);

        $status = (string) ($payload['status'] ?? '');
        $statusCode = match ($status) {
            AtlasSelfImprovementForgeActivationService::STATUS_OBRA_CREATED,
            AtlasSelfImprovementForgeActivationService::STATUS_ACCEPTED => 201,
            AtlasSelfImprovementForgeActivationService::STATUS_REJECTED,
            AtlasSelfImprovementForgeActivationService::STATUS_BLOCKED => 409,
            default => 200,
        };
        if (($payload['blocker'] ?? null) === 'activation_not_found') {
            $statusCode = 404;
        }

        return response()->json($this->enrich($payload, $cockpit), $statusCode);
    }

    public function reject(
        Request $request,
        string $activation,
        AtlasSelfImprovementForgeActivationService $service,
        AtlasSelfImprovementActivationCockpitService $cockpit,
    ): JsonResponse {
        $payload = $service->reject($activation, [
            'reviewer' => $request->input('reviewer'),
            'reason' => $request->input('reason'),
        ]);

        if (($payload['blocker'] ?? null) === 'activation_not_found') {
            return response()->json($this->enrich($payload, $cockpit), 404);
        }

        return response()->json($this->enrich($payload, $cockpit), 200);
    }

    /**
     * Attach `human_summary` + `next_safe_action` from the cockpit projection
     * without breaking the existing payload shape. Safe when the activation
     * is a blocked stub (cockpit returns blank summary in that case).
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function enrich(array $payload, AtlasSelfImprovementActivationCockpitService $cockpit): array
    {
        if (! is_array($payload) || $payload === []) {
            return $payload;
        }
        try {
            $detail = $cockpit->humaniseActivation($payload);
            if (isset($detail['human_summary']) && ! isset($payload['human_summary'])) {
                $payload['human_summary'] = $detail['human_summary'];
            }
            if (isset($detail['next_safe_action']) && ! isset($payload['next_safe_action'])) {
                $payload['next_safe_action'] = $detail['next_safe_action'];
            }
        } catch (\Throwable) {
            // Projection is best-effort; never breaks the underlying service contract.
        }

        return $payload;
    }
}
