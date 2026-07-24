<?php

declare(strict_types=1);

use App\Http\Controllers\AtlasMemoryController;
use App\Http\Controllers\AtlasTaskController;
use App\Http\Controllers\AtlasToolRuntimeController;
use App\Http\Controllers\EngineeringBenchmarkController;
use App\Http\Controllers\EngineeringKnowledgeController;
use App\Http\Controllers\EngineeringRunController;
use App\Http\Controllers\EngineeringTaskGateController;
use App\Http\Controllers\EngineeringToolScanController;
use Illuminate\Support\Facades\Route;

/**
 * Engineering / tools / benchmarks HTTP surface (full-pass routes density split).
 * Inside atlas.token. Includes task-scoped engineering actions that share controllers.
 *
 * @return \Closure(): void
 */
return static function (): void {
    Route::get('/tasks/{task}/engineering', [AtlasTaskController::class, 'engineering']);
    Route::get('/tasks/{task}/engineering/runs', [EngineeringRunController::class, 'indexForTask']);
    Route::post('/tasks/{task}/engineering/runs', [EngineeringRunController::class, 'storeForTask']);
    Route::post('/tasks/{task}/engineering/blueprint/freeze', [AtlasTaskController::class, 'freezeEngineeringBlueprint']);
    Route::post('/tasks/{task}/engineering/evidence', [AtlasTaskController::class, 'engineeringEvidence']);
    Route::post('/tasks/{task}/engineering/qa', [EngineeringTaskGateController::class, 'qa']);
    Route::post('/tasks/{task}/engineering/review/deep', [EngineeringTaskGateController::class, 'deepReview']);
    Route::post('/tasks/{task}/engineering/db/review', [EngineeringTaskGateController::class, 'dbReview']);
    Route::post('/tasks/{task}/engineering/db/explain', [EngineeringTaskGateController::class, 'dbExplain']);
    Route::get('/engineering/runs/{run}', [EngineeringRunController::class, 'show']);
    Route::post('/engineering/runs/{run}/replay', [EngineeringRunController::class, 'replay']);
    Route::post('/engineering/runs/{run}/attempts/{attempt}/replay', [EngineeringRunController::class, 'replayAttempt']);
    Route::post('/engineering/runs/{run}/cancel', [EngineeringRunController::class, 'cancel']);
    Route::post('/engineering/runs/{run}/operator-action', [EngineeringRunController::class, 'operatorAction']);
    Route::get('/engineering/runs/{run}/patch-artifacts/{patch}/diff', [EngineeringRunController::class, 'showPatchDiff']);
    Route::get('/engineering/runs/{run}/test-runs/{testRun}/artifacts', [EngineeringRunController::class, 'testRunArtifacts']);
    Route::get('/engineering/runs/{run}/test-runs/{testRun}/artifacts/content', [EngineeringRunController::class, 'showTestRunArtifact']);
    Route::get('/engineering/runs/{run}/memory', [AtlasMemoryController::class, 'forRun']);
    Route::get('/engineering/runs/{run}/review-findings', [EngineeringRunController::class, 'reviewFindings']);
    Route::post('/engineering/runs/{run}/review-findings', [EngineeringRunController::class, 'storeReviewFinding']);
    Route::patch('/engineering/review-findings/{finding}', [EngineeringRunController::class, 'updateReviewFinding']);
    Route::get('/engineering/controls', [EngineeringRunController::class, 'controls']);
    Route::get('/engineering/harnessability', [EngineeringRunController::class, 'harnessability']);
    Route::get('/engineering/harnessability/calibration', [EngineeringRunController::class, 'harnessabilityCalibration']);
    Route::post('/engineering/harnessability/calibrate', [EngineeringRunController::class, 'calibrateHarnessability']);
    Route::post('/engineering/api-contract', [EngineeringToolScanController::class, 'apiContract']);
    Route::post('/engineering/security-scan', [EngineeringToolScanController::class, 'securityScan']);
    Route::post('/engineering/sbom', [EngineeringToolScanController::class, 'sbom']);
    Route::get('/tools', [AtlasToolRuntimeController::class, 'index']);
    Route::get('/tools/doctor', [AtlasToolRuntimeController::class, 'doctor']);
    Route::get('/tools/authority', [AtlasToolRuntimeController::class, 'authority']);
    Route::get('/tools/authority/policies', [AtlasToolRuntimeController::class, 'authorityPolicies']);
    Route::put('/tools/authority/policies/{authorityGroup}', [AtlasToolRuntimeController::class, 'setAuthorityPolicy']);
    Route::delete('/tools/authority/policies/{authorityGroup}', [AtlasToolRuntimeController::class, 'revokeAuthorityPolicy']);
    Route::get('/tools/evidence', [AtlasToolRuntimeController::class, 'evidence']);
    Route::get('/tools/evidence/{run}', [AtlasToolRuntimeController::class, 'evidenceShow']);
    Route::get('/tools/evidence/{run}/export', [AtlasToolRuntimeController::class, 'evidenceExport']);
    Route::get('/tools/gate', [AtlasToolRuntimeController::class, 'gate']);
    Route::get('/tools/release-gate', [AtlasToolRuntimeController::class, 'releaseGate']);
    Route::get('/tools/policies', [AtlasToolRuntimeController::class, 'policies']);
    Route::post('/tools/findings/{finding}/waiver', [AtlasToolRuntimeController::class, 'waiveFinding']);
    Route::delete('/tools/findings/{finding}/waiver', [AtlasToolRuntimeController::class, 'revokeFindingWaiver']);
    Route::get('/tools/{tool}/commands', [AtlasToolRuntimeController::class, 'commands']);
    Route::post('/tools/{tool}/commands/{recipe}/run', [AtlasToolRuntimeController::class, 'runRecipe']);
    Route::get('/tools/{tool}', [AtlasToolRuntimeController::class, 'show']);
    Route::post('/tools/{tool}/run', [AtlasToolRuntimeController::class, 'run']);
    Route::post('/tools/{tool}/approval', [AtlasToolRuntimeController::class, 'approve']);
    Route::delete('/tools/{tool}/approval', [AtlasToolRuntimeController::class, 'revoke']);
    Route::get('/engineering/knowledge', [EngineeringKnowledgeController::class, 'index']);
    Route::get('/engineering/knowledge/context', [EngineeringKnowledgeController::class, 'context']);
    Route::post('/engineering/knowledge/sync', [EngineeringKnowledgeController::class, 'sync']);
    Route::post('/engineering/knowledge/code/index', [EngineeringKnowledgeController::class, 'codeIndex']);
    Route::get('/engineering/knowledge/code/audit', [EngineeringKnowledgeController::class, 'codeAudit']);
    Route::get('/engineering/knowledge/code/modules', [EngineeringKnowledgeController::class, 'codeModules']);
    Route::get('/engineering/knowledge/code/modules/{module}', [EngineeringKnowledgeController::class, 'codeModule']);
    Route::get('/engineering/knowledge/code/symbols', [EngineeringKnowledgeController::class, 'codeSymbols']);
    Route::get('/engineering/knowledge/items/{item}', [EngineeringKnowledgeController::class, 'show']);
    Route::get('/engineering/benchmarks/suites', [EngineeringBenchmarkController::class, 'indexSuites']);
    Route::post('/engineering/benchmarks/suites', [EngineeringBenchmarkController::class, 'storeSuite']);
    Route::post('/engineering/benchmarks/suites/default', [EngineeringBenchmarkController::class, 'ensureDefaultSuite']);
    Route::post('/engineering/benchmarks/fair-claude/prepare', [EngineeringBenchmarkController::class, 'prepareFairClaudeSuite']);
    Route::get('/engineering/benchmarks/suites/{suite}/trends', [EngineeringBenchmarkController::class, 'showTrends']);
    Route::get('/engineering/benchmarks/suites/{suite}/fair-claude-report', [EngineeringBenchmarkController::class, 'showFairClaudeReport']);
    Route::post('/engineering/benchmarks/suites/{suite}/corpus/refresh', [EngineeringBenchmarkController::class, 'refreshCorpus']);
    Route::post('/engineering/benchmarks/suites/{suite}/calibrate', [EngineeringBenchmarkController::class, 'calibrateSuite']);
    Route::get('/engineering/benchmarks/suites/{suite}', [EngineeringBenchmarkController::class, 'showSuite']);
    Route::post('/engineering/benchmarks/suites/{suite}/cases', [EngineeringBenchmarkController::class, 'storeCase']);
    Route::post('/engineering/benchmarks/suites/{suite}/cases/from-run', [EngineeringBenchmarkController::class, 'promoteRunCase']);
    Route::post('/engineering/benchmarks/suites/{suite}/run', [EngineeringBenchmarkController::class, 'runSuite']);
    Route::get('/engineering/benchmarks/runs/{benchmarkRun}/replay-manifest', [EngineeringBenchmarkController::class, 'replayManifest']);
    Route::get('/engineering/benchmarks/runs/{benchmarkRun}', [EngineeringBenchmarkController::class, 'showRun']);
    Route::patch('/engineering/benchmarks/runs/{benchmarkRun}/outcome', [EngineeringBenchmarkController::class, 'recordOutcome']);
};
