<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\AtlasDecide\AtlasEngineeringRunConductorService;
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

    /**
     * Governed engineering run from the operator's natural-language surface
     * (mobile/desktop). Drives the connective conductor: dispatch -> execute
     * -> (verify) -> governed envelope.
     *
     * Sovereignty: SHADOW by default. LIVE over HTTP is impossible unless the
     * server-side flag `atlas.patamar4.swarm_production_resolver_enabled` is on
     * AND admission authorizes it — the conductor's allowlist enforces this, so
     * request input alone can never escalate to real provider spend.
     */
    public function conduct(Request $r, AtlasEngineeringRunConductorService $conductor): JsonResponse
    {
        $task = trim((string) $r->input('task', ''));
        if ($task === '') {
            return response()->json([
                'schema_version' => 'atlas.patamar4.surface.conduct.v1',
                'error' => 'task_required',
            ], 422);
        }

        $privacy = (string) $r->input('privacy_class', 'normal');
        $work = [
            'task_category' => $task,
            'role' => (string) $r->input('role', 'primary'),
            'framework' => $r->input('framework') ?: null,
            'parallelism' => (int) $r->input('parallelism', 2),
            'requested_autonomy' => (string) $r->input('requested_autonomy', 'execute_with_approval'),
            'privacy_class' => $privacy,
            'scope' => ['privacy_class' => $privacy],
            'input' => (string) $r->input('input', $task),
            'forced_provider' => ($fp = trim((string) $r->input('provider', ''))) !== '' ? $fp : null,
            'forced_model' => ($fm = trim((string) $r->input('provider_model', ''))) !== '' ? $fm : null,
        ];
        $options = [
            'mode' => (string) $r->input('mode', AtlasEngineeringRunConductorService::MODE_SHADOW),
            'operator_approved' => (bool) $r->input('operator_approved', false),
            'verify' => (bool) $r->input('verify', false),
            'changed_files' => array_values(array_filter((array) $r->input('changed_files', []), 'is_string')),
            'spec' => (array) $r->input('spec', []),
            'evidence_refs' => array_values(array_filter((array) $r->input('evidence_refs', []), 'is_string')),
            'rich_context' => (bool) $r->input('rich_context', false),
            'compound' => (bool) $r->input('compound', false),
            'deliver_code' => (bool) $r->input('deliver_code', false),
            'target_file' => (string) $r->input('target_file', 'AtlasGeneratedSnippet.php'),
            'verify_run' => (bool) $r->input('verify_run', false),
            'multi_file' => (bool) $r->input('multi_file', false),
        ];

        return response()->json([
            'schema_version' => 'atlas.patamar4.surface.conduct.v1',
            'run' => $conductor->run($work, $options),
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
