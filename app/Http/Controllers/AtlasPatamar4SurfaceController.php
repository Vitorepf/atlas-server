<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\AtlasDecide\AtlasSwarmConductorService;
use App\Services\Ai\AtlasDecide\AtlasSwarmExecutorService;
use App\Services\Ai\AtlasDecide\AtlasSwarmProductionResolverService;
use App\Services\Ai\Cognition\AtlasCognitiveFunctionAtlasService;
use App\Services\Ai\Cognition\AtlasCognitiveFunctionDecomposerService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Governance\AtlasConstitutionalVaultService;
use App\Services\Ai\Governance\AtlasTrustBudgetService;
use App\Services\Ai\Patamar4\AtlasNightlyCounterfactualsService;
use App\Services\Ai\Patamar4\AtlasSchedulerHealthService;
use App\Services\Ai\Patamar4\AtlasSubsystemAutoRebalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Patamar 4 Surface Facade — 7 endpoints canon que o Atlas UI (mobile e
 * desktop) consome para renderizar Madrugada inbox, LiveActivity chips,
 * ResponseAudit footer, Truth/Trust pins.
 *
 * Cada endpoint é read-only, provider-safe, claim-policy hardcoded.
 * `decompose` é o único POST — entrega vetor cognitivo on-the-fly.
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-patamar4-surface-facade.md
 */
final class AtlasPatamar4SurfaceController extends Controller
{
    private function clampTail(Request $r, int $default = 10, int $max = 50): int
    {
        return max(1, min($max, (int) $r->query('tail', (string) $default)));
    }

    public function scheduler(Request $r, AtlasSchedulerHealthService $svc): JsonResponse
    {
        $tail = $this->clampTail($r);
        $status = $svc->status();

        return response()->json([
            'schema_version' => 'atlas.patamar4.surface.scheduler.v1',
            'status' => $status,
            'recent_heartbeats' => $svc->listHeartbeats($tail),
            'claim_policy' => $svc->claimPolicy(),
        ]);
    }

    public function swarm(
        Request $r,
        AtlasSwarmConductorService $conductor,
        AtlasSwarmExecutorService $executor,
        AtlasSwarmProductionResolverService $resolver,
    ): JsonResponse {
        $tail = $this->clampTail($r);
        $dispatches = $conductor->listDispatches();
        $executions = $executor->listExecutions();

        return response()->json([
            'schema_version' => 'atlas.patamar4.surface.swarm.v1',
            'dispatch_count' => count($dispatches),
            'recent_dispatches' => array_slice($dispatches, -$tail),
            'execution_count' => count($executions),
            'recent_executions' => array_slice($executions, -$tail),
            'production_resolver' => [
                'flag_enabled' => (bool) config('atlas.patamar4.swarm_production_resolver_enabled', false),
                'circuit_state' => $resolver->circuitState(),
            ],
        ]);
    }

    public function rebalance(Request $r, AtlasSubsystemAutoRebalanceService $svc): JsonResponse
    {
        $tail = $this->clampTail($r);
        $receipts = $svc->listReceipts();
        $probes = [];
        foreach (AtlasSubsystemAutoRebalanceService::VALID_KINDS as $kind) {
            $env = $svc->plan($kind); // read-only plan, persisted (canon behaviour)
            $probes[$kind] = $env['diagnostics'] ?? null;
        }

        return response()->json([
            'schema_version' => 'atlas.patamar4.surface.rebalance.v1',
            'receipt_count' => count($receipts),
            'recent_receipts' => array_slice($receipts, -$tail),
            'probes' => $probes,
        ]);
    }

    public function cognitiveFunction(
        Request $r,
        AtlasCognitiveFunctionAtlasService $cfa,
        AtlasCognitiveFunctionDecomposerService $decomposer,
    ): JsonResponse {
        $tail = $this->clampTail($r, 5, 20);

        return response()->json([
            'schema_version' => 'atlas.patamar4.surface.cognitive_function.v1',
            'self_model' => $cfa->selfModel(),
            'gaps_by_group' => $cfa->gapsByGroup(),
            'recent_decompositions' => $decomposer->listDecompositions($tail),
        ]);
    }

    public function governance(
        Request $r,
        AtlasConstitutionalKernelService $kernel,
        AtlasTrustBudgetService $trust,
        AtlasConstitutionalVaultService $vault,
    ): JsonResponse {
        $tail = $this->clampTail($r);
        $vaultVerify = null;
        try {
            $vaultVerify = $vault->verify();
        } catch (\Throwable $e) {
            $vaultVerify = ['status' => 'verify_error', 'error' => substr($e->getMessage(), 0, 120)];
        }

        return response()->json([
            'schema_version' => 'atlas.patamar4.surface.governance.v1',
            'kernel' => [
                'kernel_hash' => $kernel->kernelHash(),
                'invariant_count' => count($kernel->listInvariants()),
                'recent_violations' => array_slice($kernel->listViolations(), -$tail),
            ],
            'trust_budget' => [
                'canonical_budget' => $trust->canonicalBudget(),
                'recent_receipts' => array_slice($trust->listReceipts(), -$tail),
            ],
            'vault' => $vaultVerify,
        ]);
    }

    public function decompose(Request $r, AtlasCognitiveFunctionDecomposerService $svc): JsonResponse
    {
        $input = (string) $r->input('input', '');
        $context = [
            'role' => $r->input('role'),
            'framework' => $r->input('framework'),
            'privacy_class' => $r->input('privacy_class', 'normal'),
        ];

        return response()->json($svc->decompose($input, $context));
    }

    public function madrugadaInbox(
        Request $r,
        AtlasNightlyCounterfactualsService $nightly,
        AtlasDecideLiveOutcomeFeedbackService $adml,
    ): JsonResponse {
        $tail = $this->clampTail($r);

        return response()->json([
            'schema_version' => 'atlas.patamar4.surface.madrugada_inbox.v1',
            'nightly_counterfactuals' => $nightly->inbox($tail),
            'adml_outcomes' => array_slice($adml->listOutcomes(), -$tail),
        ]);
    }
}
