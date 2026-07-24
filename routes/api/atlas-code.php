<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AtlasCodeWorkCompanyController;
use App\Http\Controllers\Ai\AgenticEngineeringOs\AtlasMissionControlCockpitController;
use App\Http\Controllers\Ai\AtlasObraReplayController;
use App\Http\Controllers\Ai\Programming\AtlasDevPlanVisibleController;
use App\Http\Controllers\AtlasCodeAttentionControlPlaneController;
use App\Http\Controllers\AtlasCodeBootController;
use App\Http\Controllers\AtlasCodeCheckpointController;
use App\Http\Controllers\AtlasCodeDevToForgePromotionController;
use App\Http\Controllers\AtlasCodeDiffController;
use App\Http\Controllers\AtlasCodeEnterpriseCertificationController;
use App\Http\Controllers\AtlasCodeEvidenceController;
use App\Http\Controllers\AtlasCodeForgeExecutionController;
use App\Http\Controllers\AtlasCodeForgeFastPathController;
use App\Http\Controllers\AtlasCodeForgeFastPathStatusController;
use App\Http\Controllers\AtlasCodeForgeProviderCapacityController;
use App\Http\Controllers\AtlasCodeForgeProviderInvocationController;
use App\Http\Controllers\AtlasCodeForgeProviderTopologyController;
use App\Http\Controllers\AtlasCodeForgeReviewCompletionController;
use App\Http\Controllers\AtlasCodeForgeReviewController;
use App\Http\Controllers\AtlasCodeForgeRuntimeDispatchController;
use App\Http\Controllers\AtlasCodeForgeUxOrchestratorController;
use App\Http\Controllers\AtlasCodeForgeWorkIntakeController;
use App\Http\Controllers\AtlasCodeMcpStatusController;
use App\Http\Controllers\AtlasCodeObraCommandCenterController;
use App\Http\Controllers\AtlasCodeObservedSessionController;
use App\Http\Controllers\AtlasCodeProgrammingWorkItemController;
use App\Http\Controllers\AtlasCodeProviderGovernanceController;
use App\Http\Controllers\AtlasCodeProviderOperatingRoomController;
use App\Http\Controllers\AtlasCodeReceiptController;
use App\Http\Controllers\AtlasCodeReceiptShowController;
use App\Http\Controllers\AtlasCodeSelfImprovementActivationCockpitController;
use App\Http\Controllers\AtlasCodeSelfImprovementClosedLoopController;
use App\Http\Controllers\AtlasCodeSelfImprovementForgeActivationController;
use App\Http\Controllers\AtlasCodeSelfImprovementGovernanceController;
use App\Http\Controllers\AtlasCodeSelfImprovementNextCycleController;
use App\Http\Controllers\AtlasCodeSelfImprovementProposalBacklogController;
use App\Http\Controllers\AtlasCodeSelfImprovementResultLedgerController;
use App\Http\Controllers\AtlasCodeSessionController;
use App\Http\Controllers\AtlasCodeThreadController;
use App\Http\Controllers\AtlasCodeWorkController;
use App\Http\Controllers\AtlasCodeWorkPacketController;
use App\Http\Controllers\AtlasCodeWorkspaceController;
use App\Http\Controllers\AtlasFrontendWorkspaceController;
use App\Http\Controllers\AtlasProgrammingGovernanceController;
use App\Http\Controllers\AtlasSddAgentRoleController;
use App\Http\Controllers\AtlasSddController;
use App\Http\Controllers\AtlasSddMcpResourceController;
use App\Http\Controllers\AtlasWorkspaceIntelligenceController;

/**
 * Atlas Code HTTP surface (full-pass routes density split from api.php).
 */
return static function (): void {
    Route::prefix('atlas-code')->group(function () {
        // BOOT · unified contract for the Desktop topbar
        Route::get('/boot', AtlasCodeBootController::class);

        // MCP · pill status
        Route::get('/mcp/status', AtlasCodeMcpStatusController::class);

        // PROJECT / WORKSPACE · multi-project read-model
        // canon: docs/engineering-knowledge-base/atlas-code-multi-project-workspace-os.md
        Route::get('/projects/workspaces', [AtlasCodeWorkspaceController::class, 'index']);
        Route::post('/projects/workspaces', [AtlasCodeWorkspaceController::class, 'store']);
        Route::get('/projects/workspaces/{slug}', [AtlasCodeWorkspaceController::class, 'show']);
        Route::patch('/projects/workspaces/{slug}', [AtlasCodeWorkspaceController::class, 'update']);
        Route::delete('/projects/workspaces/{slug}', [AtlasCodeWorkspaceController::class, 'destroy']);
        Route::get('/frontend/portfolio', [AtlasFrontendWorkspaceController::class, 'portfolio']);
        Route::post('/frontend/selected-workspace', [AtlasFrontendWorkspaceController::class, 'selected']);
        Route::post('/frontend/selection-receipt', [AtlasFrontendWorkspaceController::class, 'selectionReceipt']);
        Route::post('/frontend/project-activation', [AtlasFrontendWorkspaceController::class, 'activateProjectWorkspace']);
        Route::post('/frontend/runtime-projection', [AtlasFrontendWorkspaceController::class, 'runtimeProjection']);
        Route::post('/frontend/control-plane', [AtlasFrontendWorkspaceController::class, 'controlPlane']);
        Route::post('/frontend/competitive-benchmark-plan', [AtlasFrontendWorkspaceController::class, 'competitiveBenchmarkPlan']);
        Route::post('/frontend/live-source-patch', [AtlasFrontendWorkspaceController::class, 'liveSourcePatch']);
        Route::post('/frontend/live-visual-selection', [AtlasFrontendWorkspaceController::class, 'liveVisualSelection']);
        Route::post('/frontend/live-target-suggestions', [AtlasFrontendWorkspaceController::class, 'liveTargetSuggestions']);
        Route::post('/frontend/prepare-evidence', [AtlasFrontendWorkspaceController::class, 'prepareEvidence']);
        Route::post('/frontend/prepare-rival-replay', [AtlasFrontendWorkspaceController::class, 'prepareRivalReplay']);
        Route::post('/frontend/inspect-rival-replay', [AtlasFrontendWorkspaceController::class, 'inspectRivalReplay']);
        Route::post('/frontend/replay-external-receipt-template', [AtlasFrontendWorkspaceController::class, 'replayExternalReceiptTemplate']);
        Route::post('/frontend/replay-score-template', [AtlasFrontendWorkspaceController::class, 'replayScoreTemplate']);
        Route::post('/frontend/replay-apply-patch', [AtlasFrontendWorkspaceController::class, 'applyReplayPatch']);
        Route::post('/frontend/proof-bundle', [AtlasFrontendWorkspaceController::class, 'proofBundle']);
        Route::post('/frontend/publication-receipt-template', [AtlasFrontendWorkspaceController::class, 'publicationReceiptTemplate']);
        Route::post('/frontend/publication-verify', [AtlasFrontendWorkspaceController::class, 'verifyPublication']);
        Route::post('/frontend/run-certification', [AtlasFrontendWorkspaceController::class, 'runCertification']);
        Route::post('/frontend/handoff', [AtlasFrontendWorkspaceController::class, 'handoff']);
        Route::get('/workspace-intelligence', [AtlasWorkspaceIntelligenceController::class, 'show']);
        Route::get('/workspace-intelligence/twin', [AtlasWorkspaceIntelligenceController::class, 'twin']);
        Route::get('/workspace-intelligence/artifacts', [AtlasWorkspaceIntelligenceController::class, 'artifacts']);
        Route::get('/workspace-intelligence/contracts', [AtlasWorkspaceIntelligenceController::class, 'contracts']);
        Route::get('/workspace-intelligence/evolution', [AtlasWorkspaceIntelligenceController::class, 'evolution']);
        Route::get('/workspace-intelligence/learning-loop', [AtlasWorkspaceIntelligenceController::class, 'learningLoop']);
        Route::get('/workspace-intelligence/live-execution-memory', [AtlasWorkspaceIntelligenceController::class, 'liveExecutionMemory']);
        Route::get('/workspace-intelligence/next-session-brain', [AtlasWorkspaceIntelligenceController::class, 'nextSessionBrain']);
        Route::get('/workspace-intelligence/artifact-intelligence', [AtlasWorkspaceIntelligenceController::class, 'artifactIntelligence']);
        Route::get('/workspace-intelligence/artifact-lake', [AtlasWorkspaceIntelligenceController::class, 'artifactLake']);
        Route::get('/workspace-intelligence/artifact-lake/{artifact}', [AtlasWorkspaceIntelligenceController::class, 'artifactLakeShow']);
        Route::get('/workspace-intelligence/artifact-workroom', [AtlasWorkspaceIntelligenceController::class, 'artifactWorkroom']);
        Route::get('/workspace-intelligence/artifact-timeline', [AtlasWorkspaceIntelligenceController::class, 'artifactTimeline']);
        Route::post('/workspace-intelligence/artifact-outcome', [AtlasWorkspaceIntelligenceController::class, 'artifactOutcome']);
        Route::post('/workspace-intelligence/artifact-retirement', [AtlasWorkspaceIntelligenceController::class, 'artifactRetirement']);
        Route::get('/workspace-intelligence/artifact-retirement-queue', [AtlasWorkspaceIntelligenceController::class, 'artifactRetirementQueue']);
        Route::post('/workspace-intelligence/artifact-retirement-apply', [AtlasWorkspaceIntelligenceController::class, 'artifactRetirementApply']);
        Route::get('/workspace-intelligence/handoff-pack', [AtlasWorkspaceIntelligenceController::class, 'handoffPack']);
        Route::get('/workspace-intelligence/gate', [AtlasWorkspaceIntelligenceController::class, 'gate']);

        // PROVIDER GOVERNANCE · subscription-only contract
        // canon: docs/engineering-knowledge-base/atlas-claude-code-subscription-governance-v1.md
        Route::get('/providers/governance', [AtlasCodeProviderGovernanceController::class, 'show']);

        // ATTENTION CONTROL PLANE · routes the next human decision across Obras
        // canon: docs/engineering-knowledge-base/atlas-code-attention-control-plane-v1.md
        Route::get('/attention', [AtlasCodeAttentionControlPlaneController::class, 'index']);
        Route::post('/attention/{project}/decision', [AtlasCodeAttentionControlPlaneController::class, 'decide']);

        // DEV → FORGE PROMOTION · bridges Atlas Dev threads to Obras/Forge
        // canon: docs/engineering-knowledge-base/atlas-ai-conversation-surface-and-atlas-dev-v1.md
        Route::get('/dev-to-forge/threads/{thread}/promotion-preview', [AtlasCodeDevToForgePromotionController::class, 'preview']);
        Route::post('/dev-to-forge/threads/{thread}/promote', [AtlasCodeDevToForgePromotionController::class, 'promote']);
        Route::get('/dev-to-forge/candidates', [AtlasCodeDevToForgePromotionController::class, 'index']);
        Route::get('/dev-to-forge/candidates/{candidate}', [AtlasCodeDevToForgePromotionController::class, 'show']);
        Route::post('/dev-to-forge/candidates/{candidate}/dismiss', [AtlasCodeDevToForgePromotionController::class, 'dismiss']);

        // Meta 8.5 · Canonical Dev-to-Forge Promotion routes live at
        // /atlas-code/dev-to-forge/* above. The earlier `/atlas-code/promotion/*`
        // mount was removed during reconciliation — single schema, single
        // controller, single signal detector.

        // PROVIDER OPERATING ROOM (per-Obra read-model)
        // canon: docs/engineering-knowledge-base/atlas-code-adaptive-provider-operating-room-v1.md
        Route::get('/works/{project}/forge/operating-room', [AtlasCodeProviderOperatingRoomController::class, 'show']);

        // WORK PACKETS (per-Obra)
        // canon: docs/engineering-knowledge-base/atlas-code-interactive-observed-provider-workflow-v1.md
        Route::get('/works/{project}/work-packets', [AtlasCodeWorkPacketController::class, 'index']);
        Route::post('/works/{project}/work-packets', [AtlasCodeWorkPacketController::class, 'store']);
        Route::get('/works/{project}/work-packets/{packet}', [AtlasCodeWorkPacketController::class, 'show']);
        Route::post('/works/{project}/work-packets/{packet}/export', [AtlasCodeWorkPacketController::class, 'exportPreview']);

        // OBSERVED SESSIONS (Interactive Observed Provider Workflow)
        Route::get('/works/{project}/observed-sessions', [AtlasCodeObservedSessionController::class, 'index']);
        Route::post('/works/{project}/observed-sessions', [AtlasCodeObservedSessionController::class, 'store']);
        // One-shot "Abrir Claude Code observado" — creates packet + opens session.
        Route::post('/works/{project}/observed-sessions/claude-code', [AtlasCodeObservedSessionController::class, 'claudeCodeOneShot']);
        Route::get('/works/{project}/observed-sessions/{session}', [AtlasCodeObservedSessionController::class, 'show']);
        Route::post('/works/{project}/observed-sessions/{session}/state', [AtlasCodeObservedSessionController::class, 'state']);
        Route::post('/works/{project}/observed-sessions/{session}/mark-running', [AtlasCodeObservedSessionController::class, 'markRunning']);
        Route::post('/works/{project}/observed-sessions/{session}/import', [AtlasCodeObservedSessionController::class, 'import']);
        Route::post('/works/{project}/observed-sessions/{session}/import-result', [AtlasCodeObservedSessionController::class, 'import']);
        Route::post('/works/{project}/observed-sessions/{session}/run-gates', [AtlasCodeObservedSessionController::class, 'runGates']);
        Route::get('/works/{project}/observed-sessions/{session}/verification-runs', [AtlasCodeObservedSessionController::class, 'verificationRunIndex']);
        Route::post('/works/{project}/observed-sessions/{session}/verification-runs', [AtlasCodeObservedSessionController::class, 'verificationRun']);
        Route::post('/works/{project}/observed-sessions/{session}/decide', [AtlasCodeObservedSessionController::class, 'decide']);

        // WORKS · normalized Obra surface
        Route::get('/works', [AtlasCodeWorkController::class, 'index']);
        Route::post('/works', [AtlasCodeWorkController::class, 'store']);
        // Gap3 F2 — Engineering Company Runtime HTTP entry.
        // Flag-gated via ATLAS_HTTP_COMPANY_RUNTIME / config(atlas.http_company_runtime.mode).
        // Canon: docs/engineering-knowledge-base/atlas-engineering-company-runtime-http-promotion.md
        Route::post('/work/company', [\App\Http\Controllers\AtlasCodeWorkCompanyController::class, 'store']);
        Route::get('/certification', [AtlasCodeEnterpriseCertificationController::class, 'show']);
        Route::post('/certification', [AtlasCodeEnterpriseCertificationController::class, 'store']);
        Route::get('/works/{project}', [AtlasCodeWorkController::class, 'show']);
        Route::get('/works/{project}/state', [AtlasCodeWorkController::class, 'state']);
        Route::post('/works/{project}/forge/live-executions', [AtlasCodeForgeExecutionController::class, 'store']);
        Route::post('/works/{project}/forge/live-executions/async', [AtlasCodeForgeExecutionController::class, 'startAsync']);
        Route::get('/works/{project}/forge/live-executions/history/{historyId}', [AtlasCodeForgeExecutionController::class, 'showHistory']);
        Route::get('/works/{project}/forge/live-executions/{executionId}', [AtlasCodeForgeExecutionController::class, 'showAsync']);
        Route::post('/works/{project}/forge/reviews', [AtlasCodeForgeReviewController::class, 'store']);
        Route::post('/works/{project}/forge/promotions/{promotionId}/rollback', [AtlasCodeForgeReviewController::class, 'rollback']);
        Route::post('/works/{project}/forge/fast-path', [AtlasCodeForgeFastPathController::class, 'store']);
        Route::get('/works/{project}/forge/fast-path/{run}/status', [AtlasCodeForgeFastPathStatusController::class, 'show']);
        Route::post('/works/{project}/forge/fast-path/{run}/resume', [AtlasCodeForgeFastPathStatusController::class, 'resume']);
        Route::get('/works/{project}/forge/fast-path/{run}/review', [AtlasCodeForgeReviewCompletionController::class, 'show']);
        Route::post('/works/{project}/forge/fast-path/{run}/review/approve', [AtlasCodeForgeReviewCompletionController::class, 'approve']);
        Route::post('/works/{project}/forge/fast-path/{run}/review/reject', [AtlasCodeForgeReviewCompletionController::class, 'reject']);
        Route::post('/works/{project}/forge/fast-path/{run}/review/rollback', [AtlasCodeForgeReviewCompletionController::class, 'rollback']);
        Route::get('/works/{project}/forge/intake', [AtlasCodeForgeWorkIntakeController::class, 'show']);
        Route::post('/works/{project}/forge/intake', [AtlasCodeForgeWorkIntakeController::class, 'store']);
        Route::get('/works/{project}/forge/provider-topology', [AtlasCodeForgeProviderTopologyController::class, 'show']);
        Route::get('/works/{project}/forge/continuum-certification', [AtlasCodeForgeProviderTopologyController::class, 'certification']);
        Route::get('/works/{project}/forge/runtime-dispatch', [AtlasCodeForgeRuntimeDispatchController::class, 'show']);
        Route::post('/works/{project}/forge/runtime-dispatch', [AtlasCodeForgeRuntimeDispatchController::class, 'store']);
        Route::get('/works/{project}/forge/ux-orchestrator', [AtlasCodeForgeUxOrchestratorController::class, 'show']);
        Route::get('/works/{project}/obra-command-center', [AtlasCodeObraCommandCenterController::class, 'show']);
        Route::get('/works/{project}/forge/provider-invocations/latest', [AtlasCodeForgeProviderInvocationController::class, 'latest']);
        Route::get('/works/{project}/forge/provider-invocations/drivers', [AtlasCodeForgeProviderInvocationController::class, 'drivers']);
        Route::post('/works/{project}/forge/provider-invocations/plan-driver', [AtlasCodeForgeProviderInvocationController::class, 'planDriver']);
        Route::post('/works/{project}/forge/provider-invocations', [AtlasCodeForgeProviderInvocationController::class, 'store']);
        Route::get('/forge/provider-capacity', [AtlasCodeForgeProviderCapacityController::class, 'global']);
        Route::get('/works/{project}/forge/provider-capacity', [AtlasCodeForgeProviderCapacityController::class, 'show']);
        Route::post('/works/{project}/forge/provider-failures', [AtlasCodeForgeProviderCapacityController::class, 'recordFailure']);
        Route::get('/self-improvement/strategy-portfolio', [AtlasCodeSelfImprovementGovernanceController::class, 'strategyPortfolio']);
        Route::post('/self-improvement/proposal-gate', [AtlasCodeSelfImprovementGovernanceController::class, 'proposalGate']);
        Route::post('/self-improvement/before-after', [AtlasCodeSelfImprovementGovernanceController::class, 'beforeAfter']);
        Route::post('/self-improvement/invariant-lock', [AtlasCodeSelfImprovementGovernanceController::class, 'invariantLock']);
        Route::post('/self-improvement/regression-sentinel', [AtlasCodeSelfImprovementGovernanceController::class, 'regressionSentinel']);
        Route::post('/self-improvement/maturity-score', [AtlasCodeSelfImprovementGovernanceController::class, 'maturityScore']);
        Route::get('/works/{project}/self-improvement/trust-ledger', [AtlasCodeSelfImprovementGovernanceController::class, 'trustLedgerShow']);
        Route::post('/works/{project}/self-improvement/trust-ledger', [AtlasCodeSelfImprovementGovernanceController::class, 'trustLedgerRecord']);
        Route::get('/self-improvement/forge-activations', [AtlasCodeSelfImprovementForgeActivationController::class, 'index']);
        Route::post('/self-improvement/forge-activations', [AtlasCodeSelfImprovementForgeActivationController::class, 'store']);
        Route::get('/self-improvement/forge-activations/{activation}', [AtlasCodeSelfImprovementForgeActivationController::class, 'show']);
        Route::post('/self-improvement/forge-activations/{activation}/accept', [AtlasCodeSelfImprovementForgeActivationController::class, 'accept']);
        Route::post('/self-improvement/forge-activations/{activation}/reject', [AtlasCodeSelfImprovementForgeActivationController::class, 'reject']);
        Route::get('/self-improvement/activation-cockpit', [AtlasCodeSelfImprovementActivationCockpitController::class, 'index']);
        Route::get('/self-improvement/activation-cockpit/{activation}', [AtlasCodeSelfImprovementActivationCockpitController::class, 'show']);
        Route::get('/self-improvement/proposals', [AtlasCodeSelfImprovementProposalBacklogController::class, 'index']);
        Route::post('/self-improvement/proposals', [AtlasCodeSelfImprovementProposalBacklogController::class, 'store']);
        Route::get('/self-improvement/proposals/{proposal}', [AtlasCodeSelfImprovementProposalBacklogController::class, 'show']);
        Route::post('/self-improvement/proposals/{proposal}/evaluate', [AtlasCodeSelfImprovementProposalBacklogController::class, 'evaluate']);
        Route::post('/self-improvement/proposals/{proposal}/prioritize', [AtlasCodeSelfImprovementProposalBacklogController::class, 'prioritize']);
        Route::get('/self-improvement/proposals/{proposal}/closed-loop', [AtlasCodeSelfImprovementClosedLoopController::class, 'show']);
        Route::post('/self-improvement/proposals/{proposal}/measure-result', [AtlasCodeSelfImprovementResultLedgerController::class, 'measureResult']);
        Route::get('/self-improvement/result-ledger', [AtlasCodeSelfImprovementResultLedgerController::class, 'index']);
        Route::get('/self-improvement/next-cycle-recommendations', [AtlasCodeSelfImprovementNextCycleController::class, 'index']);
        Route::post('/works/{project}/checkpoints', [AtlasCodeCheckpointController::class, 'store']);
        Route::post('/works/{project}/programming/work-items', [AtlasCodeProgrammingWorkItemController::class, 'store']);
        Route::post('/works/{project}/programming/work-items/{workItem}/spec', [AtlasCodeProgrammingWorkItemController::class, 'compileSpecPlan']);

        // 4 · sessions for an obra (project) · NEW
        Route::get('/works/{project}/sessions', [AtlasCodeSessionController::class, 'indexForWork']);

        // 10 · evidence aggregator per obra · NEW (wraps tools/evidence + engineering/runs)
        Route::get('/works/{project}/evidence', [AtlasCodeEvidenceController::class, 'indexForWork']);

        // THREAD · normalized thread+messages contract
        Route::get('/threads/{thread}', [AtlasCodeThreadController::class, 'show']);

        // RECEIPT · Receipt v2 direct
        Route::get('/decisions/{decision}/receipt', [AtlasCodeReceiptShowController::class, 'show']);

        // 9 · sign decision receipt · NEW
        Route::post('/decisions/{decision}/sign', [AtlasCodeReceiptController::class, 'sign']);

        // 12 · apply diff · NEW
        Route::post('/diffs/{patch}/apply', [AtlasCodeDiffController::class, 'apply']);

        // PROGRAMMING GOVERNANCE · WorkItem timeline as live objects (SCOR-1 cockpit feed)
        Route::middleware('atlas.token')->group(function (): void {
            Route::get('/programming/work-items', [AtlasProgrammingGovernanceController::class, 'index']);
            Route::get('/programming/work-items/{code}', [AtlasProgrammingGovernanceController::class, 'show']);
            Route::get('/programming/work-items/{code}/adaptive-control-plane', [AtlasProgrammingGovernanceController::class, 'adaptiveControlPlane']);
            Route::get('/programming/work-items/{code}/gate-runs', [AtlasProgrammingGovernanceController::class, 'gateRuns']);
            Route::get('/programming/work-items/{code}/spec-compile', [AtlasProgrammingGovernanceController::class, 'compileSpec']);

            // Atlas Dev A2 Plan-Visible HTTP read model (AP-700).
            Route::get('/programming/plan-visible', [AtlasDevPlanVisibleController::class, 'index']);
            Route::get('/programming/work-items/{workItem}/plan-visible', [AtlasDevPlanVisibleController::class, 'show']);

            // AAEOS Mission Control Cockpit baseline snapshot (AP-702).
            Route::get('/aaeos/cockpit', [AtlasMissionControlCockpitController::class, 'show']);

            // Atlas Obra Deterministic Replay HTTP read model (AP-705).
            Route::get('/obras/{trace}/replay', [AtlasObraReplayController::class, 'show']);

            // SDD · read-only surface for specs, requirements, decision receipts, traceability, drift, learning
            Route::get('/sdd/operations', [AtlasSddController::class, 'operations']);
            Route::get('/sdd/specs', [AtlasSddController::class, 'specs']);
            Route::get('/sdd/specs/{id}', [AtlasSddController::class, 'showSpec']);
            Route::get('/sdd/specs/{id}/traceability', [AtlasSddController::class, 'traceability']);
            Route::get('/sdd/decision-receipts', [AtlasSddController::class, 'decisionReceipts']);
            Route::get('/sdd/decision-receipts/{receiptId}', [AtlasSddController::class, 'showDecisionReceipt']);
            Route::get('/sdd/drift-reports', [AtlasSddController::class, 'driftReports']);
            Route::get('/sdd/learning-proposals', [AtlasSddController::class, 'learningProposals']);
            Route::get('/sdd/mcp/resources', AtlasSddMcpResourceController::class);
            Route::get('/sdd/agent-roles', [AtlasSddAgentRoleController::class, 'index']);
            Route::get('/sdd/agent-roles/{name}', [AtlasSddAgentRoleController::class, 'show']);
        });
    });
};
