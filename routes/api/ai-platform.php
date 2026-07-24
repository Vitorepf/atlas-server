<?php

declare(strict_types=1);

use App\Http\Controllers\AiDecisionController;
use App\Http\Controllers\AiInteractionController;
use App\Http\Controllers\AtlasAiAgentBehaviorReportController;
use App\Http\Controllers\AtlasAiArchitectureOperationsController;
use App\Http\Controllers\AtlasAiArchitectureValidateController;
use App\Http\Controllers\AtlasAiDecisionReceiptReportController;
use App\Http\Controllers\AtlasAiDomainCatalogController;
use App\Http\Controllers\AtlasAiDynamicComputeMarketController;
use App\Http\Controllers\AtlasAiExternalGraphHarnessController;
use App\Http\Controllers\AtlasAiGovernanceController;
use App\Http\Controllers\AtlasAiHyperflowCertificationController;
use App\Http\Controllers\AtlasAiInboxActionReportController;
use App\Http\Controllers\AtlasAiKernelPipelineReportController;
use App\Http\Controllers\AtlasAiLedgerController;
use App\Http\Controllers\AtlasAiPipelineController;
use App\Http\Controllers\AtlasAiPolicyController;
use App\Http\Controllers\AtlasAiProviderPerformanceController;
use App\Http\Controllers\AtlasAiProviderReleaseReviewController;
use App\Http\Controllers\AtlasAiProviderReleaseSourcesController;
use App\Http\Controllers\AtlasAiQualitativeLevelsController;
use App\Http\Controllers\AtlasAiRepairController;
use App\Http\Controllers\AtlasAiRepairReportController;
use App\Http\Controllers\AtlasAiRouterRuntimeBootstrapController;
use App\Http\Controllers\AtlasAiRouterRuntimeReadinessController;
use App\Http\Controllers\AtlasAiRuntimeBoundaryController;
use App\Http\Controllers\AtlasAiSelfImprovementScheduleController;
use App\Http\Controllers\AtlasAiSelfImprovementScheduleHealthController;
use App\Http\Controllers\AtlasAiSelfImprovementScheduleReportController;
use App\Http\Controllers\AtlasAiSloController;
use App\Http\Controllers\AtlasAiStrategicDecisionController;
use App\Http\Controllers\AtlasAiStructureMotherAuditController;
use App\Http\Controllers\AtlasMobilePushReplayController;
use Illuminate\Support\Facades\Route;

/**
 * AI platform: decisions, policies, architecture, governance reports, SI schedule (full-pass). Inside atlas.token.
 *
 * @return \Closure(): void
 */
return static function (): void {
    Route::get('/ai/interactions', [AiInteractionController::class, 'index']);
    Route::get('/ai/sessions/live', [\App\Http\Controllers\Ai\AtlasLiveActivityController::class, 'liveSessions']);
    Route::post('/ai/live-activities', [\App\Http\Controllers\Ai\AtlasLiveActivityController::class, 'store']);
    Route::post('/ai/live-activities/start-tokens', [\App\Http\Controllers\Ai\AtlasLiveActivityController::class, 'storeStartToken']);
    Route::post('/ai/live-activities/{activityId}/invalidate', [\App\Http\Controllers\Ai\AtlasLiveActivityController::class, 'invalidate']);
    Route::get('/ai/decisions', [AiDecisionController::class, 'index']);
    Route::post('/ai/decisions/preview', [AiDecisionController::class, 'preview']);
    Route::get('/ai/decisions/{decision}', [AiDecisionController::class, 'show']);
    Route::get('/ai/policies/profiles', [AtlasAiPolicyController::class, 'profiles']);
    Route::post('/ai/policies/preview', [AtlasAiPolicyController::class, 'preview']);
    Route::patch('/ai/policies/domains/{domain}', [AtlasAiPolicyController::class, 'updateDomain']);
    Route::patch('/ai/policies/flows/{flow}', [AtlasAiPolicyController::class, 'updateFlow']);
    Route::get('/ai/domains', AtlasAiDomainCatalogController::class);
    Route::get('/ai/dynamic-compute-market', AtlasAiDynamicComputeMarketController::class);
    Route::match(['GET', 'POST'], '/ai/external-graph-harness', AtlasAiExternalGraphHarnessController::class);
    Route::get('/ai/architecture/validate', AtlasAiArchitectureValidateController::class);
    Route::get('/ai/architecture/operations', AtlasAiArchitectureOperationsController::class);
    Route::get('/ai/architecture/readiness', [AtlasAiGovernanceController::class, 'architectureReadiness']);
    Route::get('/ai/router-runtime/bootstrap', AtlasAiRouterRuntimeBootstrapController::class);
    Route::get('/ai/router-runtime/readiness', AtlasAiRouterRuntimeReadinessController::class);
    Route::get('/ai/hyperflow/certification', AtlasAiHyperflowCertificationController::class);
    Route::get('/ai/runtime-boundary', AtlasAiRuntimeBoundaryController::class);
    Route::get('/ai/structure-mother-audit', AtlasAiStructureMotherAuditController::class);
    Route::post('/ai/mobile/push/replay', AtlasMobilePushReplayController::class);
    Route::get('/ai/session-bootstrap', [AtlasAiGovernanceController::class, 'sessionBootstrap']);
    Route::get('/ai/feature-placement', [AtlasAiGovernanceController::class, 'placeFeature']);
    Route::get('/ai/docs-split-plan', [AtlasAiGovernanceController::class, 'docsSplitPlan']);
    Route::get('/ai/agent-behavior/report', AtlasAiAgentBehaviorReportController::class);
    Route::get('/ai/decision-receipts/report', AtlasAiDecisionReceiptReportController::class);
    Route::get('/ai/ledger/{envelope}', [AtlasAiLedgerController::class, 'show']);
    Route::get('/ai/inbox-actions/report', AtlasAiInboxActionReportController::class);
    Route::get('/ai/kernel-pipeline/report', AtlasAiKernelPipelineReportController::class)
        ->middleware('throttle:60,1');
    Route::post('/ai/pipeline', AtlasAiPipelineController::class);
    Route::get('/ai/provider-performance', AtlasAiProviderPerformanceController::class);
    Route::get('/ai/provider-release-sources', AtlasAiProviderReleaseSourcesController::class);
    Route::get('/ai/provider-release-review', AtlasAiProviderReleaseReviewController::class);
    Route::get('/ai/qualitative-levels', AtlasAiQualitativeLevelsController::class);
    Route::post('/ai/repair', AtlasAiRepairController::class);
    Route::get('/ai/repair/report', AtlasAiRepairReportController::class);
    Route::get('/ai/self-improvement/schedule', AtlasAiSelfImprovementScheduleController::class);
    Route::get('/ai/self-improvement/schedule/health', AtlasAiSelfImprovementScheduleHealthController::class);
    Route::get('/ai/self-improvement/schedule/report', AtlasAiSelfImprovementScheduleReportController::class);
    Route::get('/ai/slo', AtlasAiSloController::class);
    Route::post('/ai/strategic-decision/review', AtlasAiStrategicDecisionController::class);
};
