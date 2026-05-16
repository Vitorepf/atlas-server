<?php

declare(strict_types=1);

namespace App\Http\Controllers\AtlasDev;

use App\Http\Controllers\Controller;
use App\Http\Requests\AtlasDev\PlanRequest;
use App\Services\Ai\Programming\AtlasDev\Pipeline\AtlasDevFastPathOrchestrator;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RoutingDecision;
use App\Services\Ai\Programming\AtlasDev\Security\ConfirmationTokenKeyMissingException;
use App\Services\Ai\Programming\AtlasDev\Security\ConfirmationTokenService;
use App\Services\Ai\Programming\AtlasDev\Surface\HttpResponseRedactor;
use App\Services\AtlasCode\AtlasCodeWorkspaceProfileService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * POST /ai/interactions/atlas-dev/plan
 *
 * Invariants (per atlas-dev-efficient-programming-flow-v1.md §26.1):
 *   - Never calls provider.
 *   - Never applies patch.
 *   - Token cost = 0 (plan layer is purely deterministic).
 *   - Persists all canonical artifacts via ReceiptStorage.
 *   - Returns task_contract_hash so Run can validate referential integrity.
 *   - Mints a single-use confirmation_token IFF the routing decision is
 *     atlas_dev_fast_path (executable). Read-only / Forge preview / blocked
 *     routes return no token.
 *
 * Confirmation token is the DB+HMAC implementation (F-05 canonical). The
 * legacy filesystem ConfirmationTokenStore was removed; plaintext is shown
 * exactly once in this response and never persisted.
 */
final class PlanController extends Controller
{
    public function __construct(
        private readonly AtlasDevFastPathOrchestrator $orchestrator,
        private readonly ConfirmationTokenService $tokens,
        private readonly ConfigRepository $config,
        private readonly AtlasCodeWorkspaceProfileService $workspaces,
        private readonly HttpResponseRedactor $redactor = new HttpResponseRedactor,
    ) {}

    public function __invoke(PlanRequest $request): JsonResponse
    {
        if (! $this->config->get('atlas_dev.efficient.plan_enabled', false)) {
            return response()->json([
                'error' => [
                    'code' => 'ATLAS_DEV_PLAN_DISABLED',
                    'message' => 'Atlas Dev plan endpoint is disabled by feature flag.',
                ],
            ], 503);
        }

        $surfaceId = (string) $request->input('surface_id');
        if ($surfaceId === 'atlas_desktop_ai' && ! $this->config->get('atlas_dev.efficient.desktop_enabled', false)) {
            return response()->json([
                'error' => [
                    'code' => 'ATLAS_DEV_DESKTOP_DISABLED',
                    'message' => 'Atlas Dev Desktop integration is disabled by feature flag.',
                ],
            ], 503);
        }

        try {
            $workspace = $this->resolveWorkspaceForSurface(
                surfaceId: $surfaceId,
                requested: (string) $request->input('workspace'),
            );

            $plan = $this->orchestrator->planOnly(
                surfaceId: $surfaceId,
                workspace: $workspace,
                rawIntent: (string) $request->input('raw_intent'),
                userConstraints: $request->userConstraintsList(),
                surfaceHints: $request->surfaceHints(),
            );
        } catch (Throwable $e) {
            return response()->json([
                'error' => [
                    'code' => 'ATLAS_DEV_PLAN_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }

        $summary = $plan->toSummaryArray();

        $confirmation = null;
        if ($plan->routing->kind === RoutingDecision::ATLAS_DEV_FAST_PATH) {
            try {
                $issue = $this->tokens->issue(
                    runId: $plan->envelope->runId,
                    taskContractHash: $plan->taskContract->taskContractHash,
                    surfaceId: $surfaceId,
                    // F-03 tamper-protection: pin the CompactSDD hash inside
                    // the (HMAC-signed) token row so Run can detect any post-
                    // plan modification of compact_sdd.json on disk.
                    compactSddHash: $plan->compactSdd->compactSddHash,
                );
            } catch (ConfirmationTokenKeyMissingException $e) {
                // F-09: fail closed at the surface boundary. Operator must
                // rotate APP_KEY before Atlas Dev can mint tokens.
                return response()->json([
                    'error' => [
                        'code' => 'ATLAS_DEV_KEY_MISSING',
                        'message' => $e->getMessage(),
                    ],
                ], 500);
            }

            $confirmation = [
                'token' => $issue->plaintext,
                'expires_at' => $issue->expiresAt->getTimestamp(),
                'task_contract_hash' => $plan->taskContract->taskContractHash,
            ];
        }

        $data = array_merge($summary, [
            'confirmation' => $confirmation,
            'routing' => [
                'kind' => $plan->routing->kind,
                'reasons' => array_values($plan->routing->reasons),
                'blockers' => array_values($plan->blockers),
                'is_executable' => $plan->routing->kind === RoutingDecision::ATLAS_DEV_FAST_PATH,
            ],
        ]);

        // F-04: strip any absolute workspace prefix from string values that
        // survived the summary (e.g. mini_spec.expected_files still carries
        // absolute paths from the discovery step). The redactor walks the
        // body recursively and never touches non-strings.
        $data = $this->redactor->redactWorkspaceIn($data, $plan->envelope->workspace);

        return response()->json(['data' => $data], 200);
    }

    private function resolveWorkspaceForSurface(string $surfaceId, string $requested): string
    {
        $workspace = trim($requested);
        if ($workspace !== '' && @is_dir($workspace)) {
            return realpath($workspace) ?: $workspace;
        }

        $profile = $this->workspaces->findBySlug($workspace);
        if ($profile !== null) {
            $path = (string) ($profile['workspace_path'] ?? '');
            if ($path !== '' && @is_dir($path)) {
                return realpath($path) ?: $path;
            }

            throw new \RuntimeException("Atlas Dev workspace slug '{$workspace}' does not resolve to an accessible workspace_path.");
        }

        if ($surfaceId === 'atlas_desktop_ai') {
            throw new \RuntimeException("Atlas Dev Desktop workspace '{$workspace}' is not an accessible path or configured project slug.");
        }

        return $workspace;
    }
}
