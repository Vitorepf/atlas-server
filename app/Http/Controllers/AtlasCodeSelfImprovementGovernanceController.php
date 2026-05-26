<?php

declare(strict_types=1);

namespace App\Http\Controllers;

// Gap5.F2 wiring marker — Programming-adjacent controller.
// Canonical route_decision schema: atlas.dual_core.route_decision.v1
// Method-level emission to be wired per AP per family.

use App\Models\AtlasProject;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementCapabilityMaturityScoreService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementDeltaScorecardService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementInvariantLockService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalPacketService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalPowerGateService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementRegressionSentinelService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementStrategyPortfolioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/**
 * Atlas Code Self-Improvement Governance Endpoints.
 *
 *   GET  /atlas-code/self-improvement/strategy-portfolio                    · global portfolio (no Obra)
 *   POST /atlas-code/self-improvement/proposal-gate                         · build packet + run gate
 *   POST /atlas-code/self-improvement/before-after                          · compute delta scorecard
 *   POST /atlas-code/self-improvement/invariant-lock                        · evaluate invariant lock
 *   POST /atlas-code/self-improvement/regression-sentinel                   · scan regressions
 *   POST /atlas-code/self-improvement/maturity-score                        · compute capability maturity
 *   GET  /atlas-code/works/{project}/self-improvement/trust-ledger          · read trust ledger for Obra
 *   POST /atlas-code/works/{project}/self-improvement/trust-ledger          · record entry
 *
 * Hard rules:
 *   - NEVER calls a provider;
 *   - NEVER promotes Forge;
 *   - NEVER unlocks external_rivals_certification;
 *   - Obra-scoped endpoints fail-closed when project not found.
 */
final class AtlasCodeSelfImprovementGovernanceController extends Controller
{
    public function strategyPortfolio(
        Request $request,
        AtlasSelfImprovementStrategyPortfolioService $service,
    ): JsonResponse {
        $proposals = $request->input('proposals', []);
        if (! is_array($proposals)) {
            $proposals = [];
        }

        return response()->json($service->snapshot($proposals), 200);
    }

    public function proposalGate(
        Request $request,
        AtlasSelfImprovementProposalPacketService $packets,
        AtlasSelfImprovementProposalPowerGateService $gates,
    ): JsonResponse {
        $payload = $request->input('proposal');
        if (! is_array($payload)) {
            return response()->json($this->blocked('proposal_required'), 422);
        }

        $packet = $packets->build($payload);
        $gate = $gates->evaluate($packet);

        $statusCode = $gate['outcome'] === AtlasSelfImprovementProposalPowerGateService::OUTCOME_REJECTED ? 422 : 200;

        return response()->json([
            'schema_version' => 'atlas.self_improvement.proposal_gate_report.v1',
            'proposal_packet' => $packet,
            'power_gate' => $gate,
            'external_provider_call' => false,
            'separated_from' => 'external_rivals_certification',
        ], $statusCode);
    }

    public function beforeAfter(
        Request $request,
        AtlasSelfImprovementDeltaScorecardService $service,
    ): JsonResponse {
        $before = $request->input('before', []);
        $after = $request->input('after', []);
        $context = $request->input('context', []);
        if (! is_array($before)) {
            $before = [];
        }
        if (! is_array($after)) {
            $after = [];
        }
        if (! is_array($context)) {
            $context = [];
        }

        return response()->json($service->compute($before, $after, $context), 200);
    }

    public function invariantLock(
        Request $request,
        AtlasSelfImprovementInvariantLockService $service,
    ): JsonResponse {
        $after = $request->input('after_snapshot', []);
        $diff = $request->input('implementation_diff', []);
        $proposal = $request->input('proposal', []);
        if (! is_array($after)) {
            $after = [];
        }
        if (! is_array($diff)) {
            $diff = [];
        }
        if (! is_array($proposal)) {
            $proposal = [];
        }

        $report = $service->evaluate($after, $diff, $proposal);
        $statusCode = $report['status'] === AtlasSelfImprovementInvariantLockService::STATUS_PASSED ? 200 : 409;

        return response()->json($report, $statusCode);
    }

    public function regressionSentinel(
        Request $request,
        AtlasSelfImprovementRegressionSentinelService $service,
    ): JsonResponse {
        $before = $request->input('before_snapshot', []);
        $after = $request->input('after_snapshot', []);
        $diff = $request->input('implementation_diff', []);
        if (! is_array($before)) {
            $before = [];
        }
        if (! is_array($after)) {
            $after = [];
        }
        if (! is_array($diff)) {
            $diff = [];
        }

        $report = $service->scan($before, $after, $diff);
        $statusCode = $report['status'] === AtlasSelfImprovementRegressionSentinelService::STATUS_BLOCKED ? 409 : 200;

        return response()->json($report, $statusCode);
    }

    public function maturityScore(
        Request $request,
        AtlasSelfImprovementCapabilityMaturityScoreService $service,
    ): JsonResponse {
        $descriptor = $request->input('descriptor');
        if (! is_array($descriptor)) {
            return response()->json($this->blocked('descriptor_required'), 422);
        }
        $context = $request->input('context', []);
        if (! is_array($context)) {
            $context = [];
        }

        return response()->json($service->score($descriptor, $context), 200);
    }

    public function trustLedgerShow(
        AtlasProject $project,
        AtlasSelfImprovementHumanTrustLedgerService $service,
    ): JsonResponse {
        return response()->json($service->snapshot($project), 200);
    }

    public function trustLedgerRecord(
        Request $request,
        AtlasProject $project,
        AtlasSelfImprovementHumanTrustLedgerService $service,
    ): JsonResponse {
        try {
            $entry = $service->record($project, [
                'outcome' => $request->input('outcome'),
                'proposal_id' => $request->input('proposal_id'),
                'reviewer' => $request->input('reviewer'),
                'reason' => $request->input('reason'),
                'area' => $request->input('area'),
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json($this->blocked($e->getMessage()), 422);
        } catch (Throwable $e) {
            return response()->json($this->blocked('trust_ledger_record_failed', $e->getMessage()), 500);
        }

        return response()->json([
            'schema_version' => AtlasSelfImprovementHumanTrustLedgerService::ENTRY_SCHEMA_VERSION,
            'status' => 'recorded',
            'entry' => $entry,
            'snapshot' => $service->snapshot($project->refresh()),
            'external_provider_call' => false,
            'separated_from' => 'external_rivals_certification',
        'route_decision' => \App\Services\Ai\DualCore\CanonicalRouteDecisionEnvelope::emit(route: 'programming', reason: 'http_atlas_code_self_improvement_governance_controller'),
    ], 201);
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(string $blocker, ?string $reason = null): array
    {
        return [
            'schema_version' => 'atlas.self_improvement.governance_error.v1',
            'status' => 'blocked',
            'blocker' => $blocker,
            'reason' => $reason,
            'external_provider_call' => false,
            'separated_from' => 'external_rivals_certification',
        ];
    }
}
