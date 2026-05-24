<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\Schema\AtlasForgeRivalsSchemaContractService;

/**
 * Atlas Forge Rivals · Premium Report (v3).
 *
 * Renders the executive-grade `report.md` for a run by combining the
 * evidence manifest, the replay outcome, and the adjudication scorecard,
 * and projects the result into a human-first JSON envelope
 * (`atlas.forge.rivals.report.v3`).
 *
 * v3 is additive over v2: every v2 field continues to be emitted so
 * downstream tools that already read v2 stay green. The new layer aggregates
 * a run into a structured shape with category_results, case_results,
 * confidence ladder, suspicious_results, atlas_decide_recommendations and
 * an explicit claim_status block that separates the battery's internal
 * validity from any external claim.
 *
 * When a multi-case layout exists at `runs/<run_id>/cases/<case_id>/...`
 * the report aggregates over each sub-run. When only the single-case layout
 * is present (current pipeline) the report emits a degenerate v3 with one
 * case and one category — confidence is capped at `flow_validated` so the
 * report never falsely promotes a quick run into a trusted battery.
 *
 * Refuses to declare a quality winner unless ALL of these are true:
 *   - manifest.verdict === 'comparable'
 *   - replay.replay_passes === true
 *   - scorecard.hard_failures is empty
 *   - scorecard.winner is one of {atlas, rival, human_review_required_tie}
 *
 * One-sided test failures may declare `gate_winner` when the adjudicator marks
 * `score_source=gate_outcome`, but `winner=null`, scores remain null, and
 * `claim_ready=false` remains absolute. Other hard-gate failures force
 * `winner=null`, `score=null`, `claim_ready=false`, and a "ZERO claim" line.
 * Tie outcomes force `human_review_required=true` and surface a checklist for
 * the operator.
 *
 * Read-only. Never invokes provider. NEVER unlocks
 * `external_rivals_certification`.
 */
final class AtlasForgeRivalsReportService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.report.v3';

    /** Schema for the structured Provider Performance Signal block. */
    public const SIGNAL_SCHEMA_VERSION = 'atlas.forge.rivals.provider_performance_signal.v1';

    /** Confidence ladder labels (see benchmark-strategy-v1.md). */
    public const CONFIDENCE_FLOW_VALIDATED = 'flow_validated';

    public const CONFIDENCE_DIRECTIONAL = 'directional_signal';

    public const CONFIDENCE_TRUSTED = 'trusted_battery';

    public const CONFIDENCE_INCONCLUSIVE = 'inconclusive';

    /** Score threshold below which a strong provider result is suspicious. */
    public const SUSPICIOUS_PROVIDER_SCORE_THRESHOLD = 70.0;

    /** Score margin treated as "large" for a single case (suspicious signal). */
    public const SUSPICIOUS_LARGE_MARGIN = 30.0;

    /** Patch byte size below which we consider an arm to have done "no real work". */
    public const SUSPICIOUS_TINY_PATCH_BYTES = 64;

    /** External rivals claim is gated by operator approval by design. */
    public const EXTERNAL_RIVALS_STATUS = 'blocked_requires_operator_approval';

    /** Mandatory per-case evidence — every multi-case sub-run must declare
     *  these artifacts before the matrix can promote `battery_result_valid`. */
    public const REQUIRED_CASE_ARTIFACTS = [
        'manifest',
        'scorecard',
        'atlas_receipt',
        'rival_receipt',
        'atlas_patch',
        'rival_patch',
        'atlas_test_log',
        'rival_test_log',
        'workspace_hashes',
        'difficulty_band',
    ];

    /** Threshold above which the matrix invalid_cases ratio blocks claim. */
    public const MATRIX_INVALID_RATIO_BLOCK = 0.10; // 10% of cases invalid → block

    /** Hard floor: any one case missing required evidence taints the matrix. */
    public const MATRIX_INVALID_HARD_FLOOR = 1;

    /** Canonical difficulty bands. `unknown` is a tolerated fallback when
     *  the manifest doesn't declare a difficulty — but `unknown` never feeds
     *  a difficulty_fit signal. */
    public const DIFFICULTY_BANDS = ['L1', 'L2', 'L3', 'L4', 'L5'];

    public const DIFFICULTY_UNKNOWN = 'unknown';

    /** Categories that the report treats as "planning" work. */
    public const PLANNING_CATEGORIES = ['architecture', 'refactor', 'docs', 'planning'];

    /** Categories that the report treats as "execution" work. */
    public const EXECUTION_CATEGORIES = ['frontend', 'backend', 'bugfix', 'tests', 'performance', 'security', 'integration'];

    /** Atlas Forge modes that the report contrasts (fair vs full_power). */
    public const ATLAS_MODES = ['fair', 'full_power'];

    /** Minimum repeated observations before a capability axis is useful for 360 claims. */
    public const MIN_CASES_PER_CAPABILITY_SIGNAL = 3;

    /** Capabilities required for the "extreme/360" programming runner view. */
    public const REQUIRED_360_CAPABILITIES = [
        'long_context_retention',
        'multi_step_reasoning',
        'rollback_safety',
        'scope_boundary_discipline',
        'replayable_evidence_quality',
        'honest_blocker_behavior',
        'ambiguous_human_prompt_handling',
        'adversarial_constraint_handling',
        'non_obvious_regression_detection',
        'uncertainty_boundary_quality',
        'production_invariant_reasoning',
        'capability_separation_signal',
    ];

    /** Validity statuses emitted on every ranking surface. */
    public const VALIDITY_VALID = 'valid';

    public const VALIDITY_SUSPECT = 'suspect';

    public const VALIDITY_INSUFFICIENT = 'insufficient';

    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $paths,
        private readonly AtlasForgeRivalsReplayService $replay,
        private readonly AtlasForgeRivalsSchemaContractService $schemaContract = new AtlasForgeRivalsSchemaContractService,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function render(array $input): array
    {
        $runId = trim((string) ($input['run_id'] ?? ''));
        if ($runId === '') {
            return [
                'status' => 'blocked',
                'blockers' => ['run_id_required'],
                'next_command' => 'php artisan atlas:forge:rivals report --run-id=<id> --json',
            ];
        }
        $paths = $this->paths->paths($runId);
        if (! is_dir($paths['base'])) {
            return [
                'status' => 'blocked',
                'blockers' => ['run_not_found:'.$paths['run_id']],
                'next_command' => '',
            ];
        }
        $manifest = $this->readJson($paths['manifest_json']);
        if ($manifest === []) {
            return [
                'status' => 'blocked',
                'blockers' => ['manifest_missing'],
                'next_command' => 'php artisan atlas:forge:rivals collect-evidence --run-id='.$paths['run_id'].' --json',
            ];
        }

        $subRuns = $this->discoverSubRuns($paths['base']);
        $isMultiCase = $subRuns !== [];

        if ($isMultiCase) {
            $parentCaseResultsById = $this->parentCaseResultsById($manifest);
            $caseEntries = $this->collectMultiCaseEntries($subRuns, $parentCaseResultsById);
            $primary = $caseEntries[0] ?? [];
            // Top-level scorecard, when present, is the aggregate roll-up.
            $primaryScorecard = $this->readJson($paths['scorecard_json']);
            if ($primaryScorecard === [] && is_array($primary['scorecard'] ?? null)) {
                $primaryScorecard = (array) $primary['scorecard'];
            }
            $primaryScorecard = $this->buildMultiCaseAggregateScorecard(
                manifest: $manifest,
                caseEntries: $caseEntries,
                existingScorecard: $primaryScorecard,
            );
            $primaryReplay = $this->aggregateReplay($caseEntries);
        } else {
            $caseEntries = [$this->collectSingleCaseEntry($paths, $manifest)];
            $primary = $caseEntries[0] ?? [];
            $primaryScorecard = is_array($primary['scorecard'] ?? null) ? $primary['scorecard'] : [];
            $primaryReplay = is_array($primary['replay'] ?? null) ? $primary['replay'] : [];
        }

        $hardFailures = (array) ($primaryScorecard['hard_failures'] ?? []);
        $scoreCardWinner = $primaryScorecard['winner'] ?? null;
        $gateWinner = $primaryScorecard['gate_winner'] ?? null;
        $atlasScore = $primaryScorecard['atlas_score'] ?? null;
        $rivalScore = $primaryScorecard['rival_score'] ?? null;
        $threshold = $primaryScorecard['tie_threshold'] ?? AtlasForgeRivalsAdjudicatorService::DEFAULT_TIE_THRESHOLD;
        $qualityDimensions = $primaryScorecard['quality_dimensions'] ?? null;
        $hardGates = (array) ($primaryScorecard['hard_gates'] ?? []);
        $scoreSource = (string) ($primaryScorecard['score_source'] ?? '');
        $verdict = (string) ($primaryScorecard['verdict'] ?? $manifest['verdict'] ?? 'unknown');
        $replayOk = (bool) ($primaryReplay['replay_passes'] ?? false);
        $adjudicationMissing = $primaryScorecard === [];
        $gateOutcomeAvailable = $scoreSource === 'gate_outcome'
            && $replayOk
            && in_array($gateWinner, [AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS, AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL], true);
        $multiCaseGateOutcomeAvailable = $scoreSource === 'multi_case_deterministic_gate_rollup'
            && $replayOk
            && $hardFailures === []
            && is_numeric($atlasScore)
            && is_numeric($rivalScore)
            && in_array($scoreCardWinner, [
                AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS,
                AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL,
                AtlasForgeRivalsAdjudicatorService::WINNER_TIE,
            ], true);

        $winner = null;
        $declaredWhy = null;
        $humanReviewRequired = false;
        $claimReady = false;

        if ($multiCaseGateOutcomeAvailable) {
            $winner = $scoreCardWinner;
            $humanReviewRequired = $scoreCardWinner === AtlasForgeRivalsAdjudicatorService::WINNER_TIE
                || (int) ($primaryScorecard['case_rollup']['both_failed_cases'] ?? 0) > 0
                || (int) ($primaryScorecard['case_rollup']['atlas_only_failures'] ?? 0) > 0
                || (int) ($primaryScorecard['case_rollup']['rival_only_failures'] ?? 0) > 0;
            $declaredWhy = $scoreCardWinner === AtlasForgeRivalsAdjudicatorService::WINNER_TIE
                ? 'multi_case_gate_tie_no_quality_score'
                : 'multi_case_gate_winner:'.$scoreCardWinner.'_no_quality_score';
            $claimReady = false;
        } elseif ($gateOutcomeAvailable) {
            $declaredWhy = 'gate_winner:'.$gateWinner.'_no_quality_score';
            $claimReady = false;
        } elseif (str_starts_with($verdict, 'invalid')) {
            $declaredWhy = 'invalid:'.$verdict;
        } elseif (! $replayOk) {
            $declaredWhy = 'replay_failed';
        } elseif ($adjudicationMissing) {
            $declaredWhy = 'adjudication_missing';
        } elseif ($hardFailures !== []) {
            $declaredWhy = 'hard_failures:'.implode(',', array_map(static fn ($f): string => (string) $f, $hardFailures));
        } elseif ($scoreCardWinner === AtlasForgeRivalsAdjudicatorService::WINNER_TIE) {
            $winner = AtlasForgeRivalsAdjudicatorService::WINNER_TIE;
            $declaredWhy = 'statistical_tie_human_review_required';
            $humanReviewRequired = true;
        } elseif (in_array($scoreCardWinner, [AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS, AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL], true)) {
            $winner = $scoreCardWinner;
            $declaredWhy = 'quality_winner:'.$winner;
            $claimReady = true;
        } else {
            $declaredWhy = 'unknown_scorecard_state';
        }

        $arms = $this->deriveArms($manifest);
        $filters = $this->normaliseFilters($input);
        $caseResults = $this->buildCaseResults($caseEntries, $manifest);
        $caseResults = $this->applyFilters($caseResults, $filters);
        $categoryResults = $this->buildCategoryResults($caseResults, $threshold);
        $difficultyResults = $this->buildDifficultyResults($caseResults, $threshold);
        $planningExecutionResults = $this->buildPlanningExecutionResults($caseResults, $threshold);
        $providerResults = $this->buildProviderResults($caseResults, $threshold);
        $modeResults = $this->buildModeResults($caseResults, $threshold);
        $capabilityResults = $this->buildCapabilityResults($caseResults, $threshold);
        $overallResult = $this->buildOverallResult($caseResults, $categoryResults, $winner, $atlasScore, $rivalScore, $threshold);
        $suspicious = $this->detectSuspicious($caseResults, $arms);
        $validity = [
            'evidence_state' => $isMultiCase ? 'multi_case_aggregated' : ($manifest === [] ? 'missing' : 'single_case'),
            'replay_passes' => $replayOk,
            'adjudication_present' => ! $adjudicationMissing,
            'hard_failures_clean' => $hardFailures === [],
            'verdict' => $verdict,
        ];
        $confidence = $this->deriveConfidence(
            preset: (string) ($manifest['preset'] ?? 'unknown'),
            caseResults: $caseResults,
            categoryResults: $categoryResults,
            validity: $validity,
            suspiciousCount: count($suspicious),
            winner: $winner,
        );
        $atlasDecide = $this->buildAtlasDecideRecommendations($categoryResults, $confidence);
        $humanReview = $this->buildHumanReview(
            humanReviewRequired: $humanReviewRequired,
            hardFailures: $hardFailures,
            winner: $winner,
            isInvalid: str_starts_with($verdict, 'invalid'),
            replayOk: $replayOk,
            suspicious: $suspicious,
            confidence: $confidence,
        );
        $costTime = $this->buildCostTime($caseEntries);
        $evidenceState = $this->buildEvidenceState($paths, $caseEntries, $isMultiCase, $replayOk, $adjudicationMissing);
        $matrixLock = $this->buildMatrixEvidenceLock(
            caseEntries: $caseEntries,
            caseResults: $caseResults,
            isMultiCase: $isMultiCase,
            replayOk: $replayOk,
        );
        $providerSignal = $this->buildProviderPerformanceSignal(
            caseResults: $caseResults,
            arms: $arms,
            confidence: $confidence,
            categoryResults: $categoryResults,
            difficultyResults: $difficultyResults,
            capabilityResults: $capabilityResults,
            modeResults: $modeResults,
            providerResults: $providerResults,
            suspicious: $suspicious,
            validity: $validity,
        );
        $claimStatus = $this->buildClaimStatus(
            winner: $winner,
            claimReady: $claimReady,
            humanReviewRequired: $humanReviewRequired,
            confidence: $confidence,
            evidenceState: $evidenceState,
            suspicious: $suspicious,
            matrixLock: $matrixLock,
            measurementBlockers: (array) ($providerSignal['ledger_blockers'] ?? []),
        );
        $headline = $this->buildHeadline($overallResult, $confidence, $validity, $suspicious, $manifest);
        $executiveSummary = $this->buildExecutiveSummary(
            preset: (string) ($manifest['preset'] ?? 'unknown'),
            arms: $arms,
            caseResults: $caseResults,
            categoryResults: $categoryResults,
            confidence: $confidence,
            costTime: $costTime,
            validity: $validity,
            suspicious: $suspicious,
        );
        $nextActions = $this->buildNextActions(
            runId: $paths['run_id'],
            validity: $validity,
            adjudicationMissing: $adjudicationMissing,
            replayOk: $replayOk,
            confidence: $confidence,
            humanReviewRequired: $humanReviewRequired,
            suspicious: $suspicious,
            canFeedLedger: (bool) ($claimStatus['can_feed_ledger'] ?? false),
            ledgerBlockers: array_values(array_map(
                'strval',
                (array) ($claimStatus['ledger_blockers'] ?? []),
            )),
        );

        $reportMd = $this->renderMarkdown(
            runId: $paths['run_id'],
            manifest: $manifest,
            replay: $primaryReplay,
            scorecard: $primaryScorecard,
            atlasReceipt: $primary['atlas_receipt'] ?? [],
            rivalReceipt: $primary['rival_receipt'] ?? [],
            winner: $winner,
            declaredWhy: $declaredWhy,
            humanReviewRequired: $humanReviewRequired,
            claimReady: $claimReady,
            paths: $paths,
            headline: $headline,
            executiveSummary: $executiveSummary,
            arms: $arms,
            categoryResults: $categoryResults,
            difficultyResults: $difficultyResults,
            planningExecutionResults: $planningExecutionResults,
            providerResults: $providerResults,
            modeResults: $modeResults,
            capabilityResults: $capabilityResults,
            caseResults: $caseResults,
            confidence: $confidence,
            suspicious: $suspicious,
            nextActions: $nextActions,
            atlasDecide: $atlasDecide,
            humanReview: $humanReview,
            providerSignal: $providerSignal,
            filters: $filters,
        );
        @mkdir($paths['evidence'], 0o755, true);
        file_put_contents($paths['report_md'], $reportMd);

        $artifacts = array_values(array_filter([
            'manifest' => $paths['manifest_json'],
            'events_jsonl' => $paths['events_jsonl'],
            'atlas_receipt' => $paths['evidence'].'/atlas_receipt.json',
            'rival_receipt' => $paths['evidence'].'/rival_receipt.json',
            'workspace_hashes' => $paths['evidence'].'/workspace_hashes.json',
            'atlas_patch' => $paths['evidence'].'/atlas_patch.diff',
            'rival_patch' => $paths['evidence'].'/rival_patch.diff',
            'atlas_test_log' => $paths['evidence'].'/atlas_test.log',
            'rival_test_log' => $paths['evidence'].'/rival_test.log',
            'scorecard' => $paths['scorecard_json'],
            'report' => $paths['report_md'],
        ], static fn (string $p): bool => is_file($p)));

        $envelope = [
            'status' => 'ok',
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $paths['run_id'],
            'preset' => (string) ($manifest['preset'] ?? 'unknown'),
            'mode' => (string) ($manifest['mode'] ?? 'unknown'),
            'prompt_mode' => (string) ($manifest['prompt_mode'] ?? 'spec-perfect'),
            'arms' => $arms,
            'headline' => $headline,
            'executive_summary' => $executiveSummary,
            'overall_result' => $overallResult,
            'category_results' => $categoryResults,
            'difficulty_results' => $difficultyResults,
            'planning_vs_execution_results' => $planningExecutionResults,
            'provider_results' => $providerResults,
            'mode_results' => $modeResults,
            'capability_results' => $capabilityResults,
            'case_results' => $caseResults,
            'confidence' => $confidence,
            'validity' => $validity,
            'suspicious_results' => $suspicious,
            'hard_failures' => $hardFailures,
            'evidence' => $evidenceState,
            'replay' => $this->summariseReplay($primaryReplay),
            'matrix_evidence_lock' => $matrixLock,
            'cost_time' => $costTime,
            'filters_applied' => $filters,
            'provider_performance_signal' => $providerSignal,
            'atlas_decide_recommendations' => $atlasDecide,
            'human_review' => $humanReview,
            'next_actions' => $nextActions,
            'artifacts' => $artifacts,
            'safety' => [
                'external_rivals_certification_status' => self::EXTERNAL_RIVALS_STATUS,
                'no_external_provider_call' => true,
                'separated_from_external_rivals_certification' => true,
            ],
            'claim_status' => $claimStatus,
            // v2-compatible fields below — keep verbatim for downstream tools.
            'verdict' => $verdict,
            'winner' => $winner,
            'gate_winner' => $gateWinner,
            'gate_result' => $primaryScorecard['gate_result'] ?? null,
            'winner_reason' => $primaryScorecard['winner_reason'] ?? [],
            'atlas_score' => $atlasScore,
            'rival_score' => $rivalScore,
            'threshold' => $threshold,
            'human_review_required' => $humanReviewRequired,
            'claim_ready' => (bool) ($claimStatus['claim_ready'] ?? false),
            'replay_passes' => $replayOk,
            'declared_why' => $declaredWhy,
            'quality_dimensions' => $qualityDimensions,
            'hard_gates' => $hardGates,
            'report_path' => $paths['report_md'],
            'scorecard_path' => $paths['scorecard_json'],
            'evidence_paths' => $artifacts,
            'external_provider_call' => false,
            'separated_from_external_rivals_certification' => true,
            'unlocks_external_rivals_certification' => false,
            'score_source' => $scoreSource,
            'quality_score_available' => (bool) ($primaryScorecard['quality_score_available'] ?? ($qualityDimensions !== null)),
            'quality_score_reason' => $primaryScorecard['quality_score_reason'] ?? null,
            'note' => 'Premium report v3 — battery_result_valid != external_rivals_certification. external_rivals_certification stays BLOCKED.',
            'next_command' => $nextActions[0]['command'] ?? ('php artisan atlas:forge:rivals reset --run-id='.$paths['run_id'].' --reason=<text> --json'),
        ];

        $envelope['deterministic_hash'] = $this->computeDeterministicHash($envelope);

        return $envelope;
    }

    /**
     * Discover sub-runs at `runs/<run_id>/cases/<case_id>/` for multi-case
     * battery aggregation. Returns empty when only the single-case layout is
     * present.
     *
     * @return list<array{case_id:string,base:string,evidence:string,manifest:string,scorecard:string}>
     */
    private function discoverSubRuns(string $base): array
    {
        $casesDir = $base.'/cases';
        if (! is_dir($casesDir)) {
            return [];
        }
        $entries = @scandir($casesDir) ?: [];
        $out = [];
        foreach ($entries as $entry) {
            if ($entry === '' || $entry[0] === '.') {
                continue;
            }
            $caseBase = $casesDir.'/'.$entry;
            if (! is_dir($caseBase)) {
                continue;
            }
            $evidence = $caseBase.'/evidence';
            $out[] = [
                'case_id' => $entry,
                'base' => $caseBase,
                'evidence' => $evidence,
                'manifest' => $evidence.'/manifest.json',
                'scorecard' => $evidence.'/scorecard.json',
            ];
        }
        sort($out);

        return $out;
    }

    /**
     * @param  array<string,mixed>  $paths
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    private function collectSingleCaseEntry(array $paths, array $manifest): array
    {
        $replayReport = $this->replay->replay(['run_id' => $paths['run_id']]);
        $atlasReceipt = $this->readJson($paths['evidence'].'/atlas_receipt.json');
        $rivalReceipt = $this->readJson($paths['evidence'].'/rival_receipt.json');
        $scorecard = $this->readJson($paths['scorecard_json']);

        return [
            'case_id' => (string) ($manifest['case_id'] ?? 'unknown-case'),
            'task_category' => (string) ($manifest['task_category'] ?? $this->inferCategoryFromCaseId((string) ($manifest['case_id'] ?? ''))),
            'manifest' => $manifest,
            'scorecard' => $scorecard,
            'replay' => $replayReport,
            'atlas_receipt' => $atlasReceipt,
            'rival_receipt' => $rivalReceipt,
            'paths' => $paths,
        ];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,array<string,mixed>>
     */
    private function parentCaseResultsById(array $manifest): array
    {
        $rows = is_array($manifest['case_results'] ?? null) ? (array) $manifest['case_results'] : [];
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $caseId = (string) ($row['case_id'] ?? '');
            if ($caseId === '') {
                continue;
            }
            $out[$caseId] = $row;
        }

        return $out;
    }

    /**
     * @param  list<array<string,string>>  $subRuns
     * @param  array<string,array<string,mixed>>  $parentCaseResultsById
     * @return list<array<string,mixed>>
     */
    private function collectMultiCaseEntries(array $subRuns, array $parentCaseResultsById = []): array
    {
        $out = [];
        foreach ($subRuns as $sub) {
            $manifest = $this->readJson($sub['manifest']);
            $caseId = (string) ($manifest['case_id'] ?? $sub['case_id']);
            $parent = $parentCaseResultsById[$caseId] ?? [];
            $scorecard = $this->readJson($sub['scorecard']);
            $artifactEvidence = $this->artifactEvidenceDir($sub);
            $atlasReceipt = $this->readJson($artifactEvidence.'/atlas_receipt.json');
            $rivalReceipt = $this->readJson($artifactEvidence.'/rival_receipt.json');
            $replayReport = $this->safeReplay($sub);

            $out[] = [
                'case_id' => $caseId,
                'task_category' => $this->resolveCanonicalCategory($manifest, $parent, $caseId),
                'manifest' => $manifest,
                'scorecard' => $scorecard,
                'replay' => $replayReport,
                'atlas_receipt' => $atlasReceipt,
                'rival_receipt' => $rivalReceipt,
                'parent_case_result' => $parent,
                'sub_run' => $sub,
                'artifact_evidence' => $artifactEvidence,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string,string>  $sub
     */
    private function artifactEvidenceDir(array $sub): string
    {
        $caseBase = (string) ($sub['base'] ?? '');
        $runBase = dirname(dirname($caseBase));
        $caseId = (string) ($sub['case_id'] ?? basename($caseBase));
        $topLevel = $runBase.'/evidence/cases/'.$caseId;

        if (is_dir($topLevel)) {
            return $topLevel;
        }

        $legacy = (string) ($sub['evidence'] ?? '');
        if ($legacy !== '') {
            return $legacy;
        }

        return $topLevel;
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $parent
     */
    private function resolveCanonicalCategory(array $manifest, array $parent, string $caseId): string
    {
        foreach ([$manifest['category'] ?? null, $parent['category'] ?? null] as $explicit) {
            if (is_string($explicit) && trim($explicit) !== '') {
                return strtolower(trim($explicit));
            }
        }

        $id = strtolower($caseId);
        if (str_starts_with($id, 'planning-')) {
            return 'planning';
        }
        if (str_starts_with($id, 'frontend-')) {
            return 'frontend_ui';
        }
        if (str_starts_with($id, 'bugfix-') || $id === 'backend-pagination-off-by-one') {
            return 'realistic_bugfix';
        }
        if (str_starts_with($id, 'refactor-')) {
            return 'refactor';
        }
        if (str_starts_with($id, 'testdesign-') || str_starts_with($id, 'test-regression-')) {
            return 'test_design';
        }
        if (str_starts_with($id, 'architecture-')) {
            return 'architecture';
        }
        if (str_starts_with($id, 'intperf-')
            || str_starts_with($id, 'performance-')
            || str_starts_with($id, 'integration-')
            || $id === 'backend-idempotent-webhook'
        ) {
            return 'integration_performance';
        }
        if (str_starts_with($id, 'backend-')) {
            return 'backend_logic';
        }

        $legacy = strtolower((string) ($manifest['task_category'] ?? $parent['task_category'] ?? ''));

        return match ($legacy) {
            'docs' => 'planning',
            'frontend' => 'frontend_ui',
            'backend' => 'backend_logic',
            'bugfix' => 'realistic_bugfix',
            'tests' => 'test_design',
            'performance', 'integration' => 'integration_performance',
            default => $this->inferCategoryFromCaseId($caseId),
        };
    }

    /**
     * Aggregate replay state for a multi-case battery: replay_passes is true
     * only if every sub-case is replay-clean. Mismatches list any failing
     * sub-case so the operator can drill in.
     *
     * @param  list<array<string,mixed>>  $caseEntries
     * @return array<string,mixed>
     */
    private function aggregateReplay(array $caseEntries): array
    {
        $mismatches = [];
        $allPass = true;
        foreach ($caseEntries as $entry) {
            $replay = (array) ($entry['replay'] ?? []);
            $passes = (bool) ($replay['replay_passes'] ?? false);
            if (! $passes) {
                $allPass = false;
                $mismatches[] = (string) $entry['case_id'];
            }
        }

        return [
            'replay_passes' => $allPass,
            'mismatches' => $mismatches,
            'event_count' => null,
            'aggregated_from' => count($caseEntries),
        ];
    }

    /**
     * Multi-case real batteries intentionally contain cases where one or both
     * arms fail. Those are valid measurement outcomes, not harness hard
     * failures. This roll-up scores only deterministic gates from the parent
     * battery manifest: pass/fail per arm, weighted by difficulty.
     *
     * @param  array<string,mixed>  $manifest
     * @param  list<array<string,mixed>>  $caseEntries
     * @param  array<string,mixed>  $existingScorecard
     * @return array<string,mixed>
     */
    private function buildMultiCaseAggregateScorecard(array $manifest, array $caseEntries, array $existingScorecard): array
    {
        $parents = array_values(array_filter(array_map(
            static fn (array $entry): array => (array) ($entry['parent_case_result'] ?? []),
            $caseEntries,
        ), static fn (array $parent): bool => $parent !== []));

        if ($parents === []) {
            return $existingScorecard;
        }

        $workspaceBlockers = array_values(array_filter(array_merge(
            (array) ($manifest['workspace_blockers'] ?? []),
            (array) ($manifest['aggregate_workspace_blockers'] ?? []),
            (array) ($manifest['blocking_reasons'] ?? []),
        )));
        if ($workspaceBlockers !== []) {
            return array_merge($existingScorecard, [
                'winner' => null,
                'atlas_score' => null,
                'rival_score' => null,
                'score_source' => 'multi_case_deterministic_gate_rollup',
                'quality_score_available' => false,
                'quality_score_reason' => 'harness_workspace_blockers_present',
                'hard_failures' => ['harness_workspace_blockers_present'],
                'verdict' => 'invalid_harness_workspace_blockers',
                'winner_reason' => $workspaceBlockers,
                'hard_gates' => [
                    ['code' => 'no_harness_workspace_blockers', 'ok' => false, 'detail' => implode(', ', array_map(static fn (mixed $v): string => (string) $v, $workspaceBlockers))],
                ],
                'quality_dimensions' => null,
            ]);
        }

        $totalWeight = 0.0;
        $atlasPoints = 0.0;
        $rivalPoints = 0.0;
        $bothPassed = 0;
        $bothFailed = 0;
        $atlasOnlyPassed = 0;
        $rivalOnlyPassed = 0;
        $rows = [];

        foreach ($caseEntries as $entry) {
            $parent = (array) ($entry['parent_case_result'] ?? []);
            if ($parent === []) {
                continue;
            }
            $caseManifest = (array) ($entry['manifest'] ?? []);
            $weight = $this->caseWeight($parent, $caseManifest);
            $totalWeight += $weight;
            $atlasPassed = $this->armPassed((array) ($parent['atlas_arm'] ?? []));
            $rivalPassed = $this->armPassed((array) ($parent['rival_arm'] ?? []));

            if ($atlasPassed) {
                $atlasPoints += $weight;
            }
            if ($rivalPassed) {
                $rivalPoints += $weight;
            }
            if ($atlasPassed && $rivalPassed) {
                $bothPassed++;
            } elseif ($atlasPassed && ! $rivalPassed) {
                $atlasOnlyPassed++;
            } elseif (! $atlasPassed && $rivalPassed) {
                $rivalOnlyPassed++;
            } else {
                $bothFailed++;
            }

            $rows[] = [
                'case_id' => (string) ($parent['case_id'] ?? $entry['case_id'] ?? 'unknown-case'),
                'category' => $this->resolveCanonicalCategory($caseManifest, $parent, (string) ($parent['case_id'] ?? $entry['case_id'] ?? '')),
                'difficulty_band' => $this->canonicalDifficulty(array_merge($caseManifest, $parent)),
                'difficulty_weight' => $weight,
                'atlas_passed' => $atlasPassed,
                'rival_passed' => $rivalPassed,
                'verdict' => (string) ($parent['verdict'] ?? $caseManifest['verdict'] ?? 'unknown'),
            ];
        }

        if ($totalWeight <= 0.0 || $rows === []) {
            return array_merge($existingScorecard, [
                'winner' => null,
                'atlas_score' => null,
                'rival_score' => null,
                'score_source' => 'multi_case_deterministic_gate_rollup',
                'quality_score_available' => false,
                'quality_score_reason' => 'multi_case_rollup_without_weighted_cases',
                'hard_failures' => ['multi_case_rollup_without_weighted_cases'],
                'verdict' => 'invalid_multi_case_rollup_without_weighted_cases',
            ]);
        }

        $atlasScore = round(($atlasPoints / $totalWeight) * 100, 2);
        $rivalScore = round(($rivalPoints / $totalWeight) * 100, 2);
        $threshold = (float) ($existingScorecard['tie_threshold'] ?? AtlasForgeRivalsAdjudicatorService::DEFAULT_TIE_THRESHOLD);
        $margin = round($atlasScore - $rivalScore, 2);
        $winner = match (true) {
            abs($margin) < $threshold => AtlasForgeRivalsAdjudicatorService::WINNER_TIE,
            $margin > 0 => AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS,
            default => AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL,
        };
        $caseFailures = $atlasOnlyPassed + $rivalOnlyPassed + $bothFailed;
        $verdict = $caseFailures > 0 ? 'multi_case_valid_with_case_failures' : 'multi_case_comparable';

        return array_merge($existingScorecard, [
            'winner' => $winner,
            'atlas_score' => $atlasScore,
            'rival_score' => $rivalScore,
            'score_source' => 'multi_case_deterministic_gate_rollup',
            'quality_score_available' => false,
            'quality_score_reason' => 'deterministic_gate_score_only_no_llm_quality_judge',
            'hard_failures' => [],
            'tie_threshold' => $threshold,
            'verdict' => $verdict,
            'winner_reason' => [
                'weighted_gate_rollup_over_'.count($rows).'_case(s)',
                'atlas_points='.round($atlasPoints, 3).' rival_points='.round($rivalPoints, 3).' total_weight='.round($totalWeight, 3),
                'atlas_score='.$atlasScore.' rival_score='.$rivalScore.' margin='.$margin,
                $winner === AtlasForgeRivalsAdjudicatorService::WINNER_TIE
                    ? 'margin_below_tie_threshold_human_review_required'
                    : 'winner_by_weighted_gate_margin',
                $caseFailures > 0
                    ? $caseFailures.'_case(s)_failed_one_or_both_arms_but_harness_evidence_is_valid'
                    : 'all_cases_passed_both_arms',
            ],
            'hard_gates' => [
                ['code' => 'multi_case_parent_manifest_present', 'ok' => true, 'detail' => count($rows).' case result(s) observed'],
                ['code' => 'multi_case_evidence_manifest_present', 'ok' => true, 'detail' => 'top-level evidence manifest loaded'],
                ['code' => 'no_harness_workspace_blockers', 'ok' => true, 'detail' => 'no top-level workspace blockers'],
                ['code' => 'deterministic_case_rollup_available', 'ok' => true, 'detail' => 'pass/fail gates available per arm'],
                ['code' => 'case_failures_do_not_invalidate_harness', 'ok' => true, 'detail' => $caseFailures.' case failure outcome(s) retained as measurement data'],
            ],
            'quality_dimensions' => null,
            'case_rollup' => [
                'total_cases' => count($rows),
                'comparable_cases' => $bothPassed,
                'failed_cases' => $caseFailures,
                'both_passed_cases' => $bothPassed,
                'both_failed_cases' => $bothFailed,
                'atlas_only_passed_cases' => $atlasOnlyPassed,
                'rival_only_passed_cases' => $rivalOnlyPassed,
                'atlas_only_failures' => $rivalOnlyPassed,
                'rival_only_failures' => $atlasOnlyPassed,
                'atlas_points' => round($atlasPoints, 3),
                'rival_points' => round($rivalPoints, 3),
                'total_weight' => round($totalWeight, 3),
                'atlas_pass_rate_weighted' => $atlasScore,
                'rival_pass_rate_weighted' => $rivalScore,
                'rows' => $rows,
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $arm
     */
    private function armPassed(array $arm): bool
    {
        if ($arm === []) {
            return false;
        }

        return (int) ($arm['exit_code'] ?? 1) === 0
            && (int) ($arm['test_exit_code'] ?? 1) === 0
            && (bool) ($arm['killed'] ?? false) === false
            && ((string) ($arm['timeout_reason'] ?? '') === '')
            && (array) ($arm['out_of_scope_files'] ?? []) === []
            && (array) ($arm['bytecode_artifacts'] ?? []) === [];
    }

    /**
     * @param  array<string,mixed>  $parent
     * @param  array<string,mixed>  $manifest
     */
    private function caseWeight(array $parent, array $manifest): float
    {
        $explicit = $parent['difficulty_weight'] ?? $manifest['difficulty_weight'] ?? null;
        if (is_int($explicit) || is_float($explicit)) {
            return max(0.1, (float) $explicit);
        }

        return $this->difficultyWeightFromBand($this->canonicalDifficulty(array_merge($manifest, $parent)));
    }

    private function difficultyWeightFromBand(string $band): float
    {
        return match ($band) {
            'L1' => 1.0,
            'L2' => 1.5,
            'L3' => 2.0,
            'L4' => 2.5,
            'L5' => 3.0,
            default => 2.0,
        };
    }

    /**
     * Sub-case "replay" — sub-cases under runs/<id>/cases/<case_id>/ are not
     * top-level runs from the resolver's perspective, so the ReplayService
     * cannot hash them directly. A sub-case can fail its own gates and still
     * be replayable evidence; only missing manifest/scorecard makes the
     * harness evidence unusable.
     *
     * @param  array<string,string>  $sub
     * @return array<string,mixed>
     */
    private function safeReplay(array $sub): array
    {
        $scorecardPath = $sub['scorecard'] ?? '';
        $manifestPath = $sub['manifest'] ?? '';
        if (! is_file($manifestPath)) {
            return ['replay_passes' => false, 'mismatches' => ['manifest_missing'], 'event_count' => null];
        }
        if (! is_file($scorecardPath)) {
            return ['replay_passes' => false, 'mismatches' => ['scorecard_missing'], 'event_count' => null];
        }

        return ['replay_passes' => true, 'mismatches' => [], 'event_count' => null, 'derived_from' => 'case_manifest_and_scorecard_present'];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return list<array<string,mixed>>
     */
    private function deriveArms(array $manifest): array
    {
        $arenaContracts = is_array($manifest['arena_contracts'] ?? null) ? (array) $manifest['arena_contracts'] : [];
        if (is_array($arenaContracts['arm_a'] ?? null) && is_array($arenaContracts['arm_b'] ?? null)) {
            return [
                $this->deriveArenaArm('arm_a', (array) $arenaContracts['arm_a'], 'atlas'),
                $this->deriveArenaArm('arm_b', (array) $arenaContracts['arm_b'], 'rival'),
            ];
        }

        $atlasModel = (string) ($manifest['atlas_model'] ?? 'unknown');
        $rivalModel = (string) ($manifest['rival_model'] ?? 'unknown');

        return [
            ['id' => 'atlas', 'role' => 'atlas', 'label' => 'Atlas Forge', 'provider' => null, 'model' => $atlasModel, 'model_id' => null],
            ['id' => 'rival', 'role' => 'rival', 'label' => 'Rival baseline', 'provider' => null, 'model' => $rivalModel, 'model_id' => null],
        ];
    }

    /**
     * @param  array<string,mixed>  $contract
     * @return array<string,mixed>
     */
    private function deriveArenaArm(string $role, array $contract, string $legacyRole): array
    {
        $armId = (string) ($contract['arm_id'] ?? $legacyRole);
        $label = (string) ($contract['human_label'] ?? $this->humanizeArmId($armId));
        $model = (string) ($contract['resolved_model'] ?? $contract['legacy_model_id'] ?? 'unknown');
        $modelId = $contract['resolved_model_id'] ?? null;

        return [
            'id' => $armId !== '' ? $armId : $legacyRole,
            'role' => $role,
            'legacy_role' => $legacyRole,
            'label' => $label,
            'runner_type' => $contract['runner_type'] ?? null,
            'provider' => $contract['provider'] ?? null,
            'provider_kind' => $contract['provider_kind'] ?? null,
            'meta_provider' => (bool) ($contract['meta_provider'] ?? false),
            'meta_provider_parent' => $contract['meta_provider_parent'] ?? null,
            'provider_metadata' => (array) ($contract['provider_metadata'] ?? []),
            'model' => $model,
            'model_id' => is_string($modelId) && $modelId !== '' ? $modelId : null,
            'model_alias' => $contract['model_alias'] ?? $contract['requested_model'] ?? null,
            'model_id_config_key' => $contract['resolved_model_id_config_key'] ?? null,
            'model_label' => $contract['resolved_model_label'] ?? null,
            'legacy_model_id' => $contract['legacy_model_id'] ?? null,
            'command_builder' => $contract['command_builder'] ?? null,
            'capabilities' => $contract['capabilities'] ?? [],
        ];
    }

    private function humanizeArmId(string $armId): string
    {
        return match ($armId) {
            'atlas_forge' => 'Atlas Forge',
            'atlas_dev' => 'Atlas Dev',
            'claude_code' => 'Claude Code',
            'codex_cli' => 'Codex CLI',
            'gemini_cli' => 'Gemini CLI',
            'cursor_cli' => 'Cursor CLI',
            'composer_2_5' => 'Composer 2.5',
            'manual_runner' => 'Manual Runner',
            'scripted_runner' => 'Scripted Runner',
            'future_runner' => 'Future Runner',
            default => str_replace('_', ' ', $armId),
        };
    }

    /**
     * @param  list<array<string,mixed>>  $caseEntries
     * @return list<array<string,mixed>>
     */
    private function buildCaseResults(array $caseEntries, array $runManifest = []): array
    {
        $out = [];
        $runArenaContracts = is_array($runManifest['arena_contracts'] ?? null) ? (array) $runManifest['arena_contracts'] : [];
        foreach ($caseEntries as $entry) {
            $manifest = (array) $entry['manifest'];
            $arenaContracts = is_array($manifest['arena_contracts'] ?? null) ? (array) $manifest['arena_contracts'] : $runArenaContracts;
            $armA = is_array($arenaContracts['arm_a'] ?? null) ? $this->deriveArenaArm('arm_a', (array) $arenaContracts['arm_a'], 'atlas') : null;
            $armB = is_array($arenaContracts['arm_b'] ?? null) ? $this->deriveArenaArm('arm_b', (array) $arenaContracts['arm_b'], 'rival') : null;
            $scorecard = (array) $entry['scorecard'];
            $replay = (array) $entry['replay'];
            $atlasReceipt = (array) $entry['atlas_receipt'];
            $rivalReceipt = (array) $entry['rival_receipt'];
            $parent = (array) ($entry['parent_case_result'] ?? []);
            $parentAtlas = (array) ($parent['atlas_arm'] ?? []);
            $parentRival = (array) ($parent['rival_arm'] ?? []);
            $hasParent = $parent !== [];
            $atlasGatePassed = $hasParent ? $this->armPassed($parentAtlas) : null;
            $rivalGatePassed = $hasParent ? $this->armPassed($parentRival) : null;

            $hardFailures = (array) ($scorecard['hard_failures'] ?? []);
            $winner = $scorecard['winner'] ?? null;
            $scoreSource = (string) ($scorecard['score_source'] ?? '');
            $verdict = (string) ($parent['verdict'] ?? $manifest['verdict'] ?? 'unknown');
            $isInvalid = str_starts_with($verdict, 'invalid');
            $replayOk = (bool) ($replay['replay_passes'] ?? false);
            $atlasScore = $scorecard['atlas_score'] ?? null;
            $rivalScore = $scorecard['rival_score'] ?? null;
            if ($hasParent && (! is_numeric($atlasScore) || ! is_numeric($rivalScore))) {
                $atlasScore = $atlasGatePassed === true ? 100.0 : 0.0;
                $rivalScore = $rivalGatePassed === true ? 100.0 : 0.0;
                $winner = match (true) {
                    $atlasGatePassed === true && $rivalGatePassed === false => AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS,
                    $atlasGatePassed === false && $rivalGatePassed === true => AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL,
                    $atlasGatePassed === true && $rivalGatePassed === true => AtlasForgeRivalsAdjudicatorService::WINNER_TIE,
                    default => null,
                };
                $scoreSource = 'multi_case_deterministic_gate_rollup';
            }

            $evidenceStatus = match (true) {
                ! $replayOk => 'replay_failed',
                $scorecard === [] => 'adjudication_missing',
                $hasParent && $isInvalid => 'case_failed:'.$verdict,
                ! $hasParent && $isInvalid => 'invalid:'.$verdict,
                ! $hasParent && $hardFailures !== [] => 'hard_failures',
                default => 'evidence_ok',
            };

            $replayStatus = match (true) {
                $replayOk => 'passes',
                $replay === [] => 'absent',
                default => 'failed',
            };

            $shortReason = $hasParent
                ? $this->deterministicCaseReason($atlasGatePassed === true, $rivalGatePassed === true, $verdict)
                : $this->shortReason($scorecard, $verdict, $hardFailures, $replayOk);

            $caseId = (string) ($entry['case_id'] ?? 'unknown-case');
            $title = (string) ($manifest['title'] ?? $caseId);
            $difficultyBand = $this->canonicalDifficulty(array_merge($manifest, $parent));
            $difficultyScore = $this->resolveDifficultyScore($manifest, $difficultyBand);
            $difficultyMultiplier = $this->schemaContract->difficultyMultiplier($difficultyScore);
            $promptProbe = $this->humanPromptProbeSummary($manifest, $parent);
            $complexityProfile = is_array($promptProbe['complexity_profile'] ?? null)
                ? (array) $promptProbe['complexity_profile']
                : null;
            $weightedAtlas = is_numeric($atlasScore)
                ? $this->schemaContract->difficultyWeightedScore((float) $atlasScore, $difficultyScore)
                : null;
            $weightedRival = is_numeric($rivalScore)
                ? $this->schemaContract->difficultyWeightedScore((float) $rivalScore, $difficultyScore)
                : null;

            $out[] = [
                'case_id' => $caseId,
                'title' => $title,
                'task_category' => (string) $entry['task_category'],
                'difficulty_band' => $difficultyBand,
                'role_focus' => $this->canonicalRoleFocus($manifest, (string) $entry['task_category']),
                'work_kind' => $this->canonicalWorkKind($manifest, (string) $entry['task_category']),
                'mode' => (string) ($manifest['mode'] ?? 'unknown'),
                'prompt_mode' => (string) ($manifest['prompt_mode'] ?? 'spec-perfect'),
                'human_prompt_contract' => $promptProbe,
                'complexity_profile' => $complexityProfile,
                'measurement_tags' => array_values(array_unique(array_merge(
                    $this->stringList($manifest['measurement_tags'] ?? []),
                    $this->stringList($parent['measurement_tags'] ?? []),
                ))),
                'measured_capabilities' => array_values(array_unique(array_merge(
                    $this->stringList($manifest['measured_capabilities'] ?? []),
                    $this->stringList($parent['measured_capabilities'] ?? []),
                    $this->stringList(data_get($manifest, 'extreme_differentiator.capability_axes', [])),
                    $this->stringList(data_get($parent, 'extreme_differentiator.capability_axes', [])),
                ))),
                'atlas_model' => (string) ($manifest['atlas_model'] ?? 'unknown'),
                'rival_model' => (string) ($manifest['rival_model'] ?? 'unknown'),
                'arm_a' => $armA,
                'arm_b' => $armB,
                'provider_pair' => $this->providerPairKey($armA, $armB, (string) ($manifest['atlas_model'] ?? 'unknown'), (string) ($manifest['rival_model'] ?? 'unknown')),
                'winner' => $winner,
                'atlas_score' => $atlasScore,
                'rival_score' => $rivalScore,
                'score_source' => $scoreSource,
                'quality_dimensions' => is_array($scorecard['quality_dimensions'] ?? null)
                    ? (array) $scorecard['quality_dimensions']
                    : null,
                'raw_score' => [
                    'atlas' => is_numeric($atlasScore) ? (float) $atlasScore : null,
                    'rival' => is_numeric($rivalScore) ? (float) $rivalScore : null,
                ],
                'difficulty_multiplier' => $difficultyMultiplier,
                'difficulty_weighted_score' => [
                    'atlas' => $weightedAtlas,
                    'rival' => $weightedRival,
                ],
                'margin' => ($atlasScore !== null && $rivalScore !== null)
                    ? round((float) $atlasScore - (float) $rivalScore, 2)
                    : null,
                'verdict' => $verdict,
                'hard_failures' => $hardFailures,
                'suspicious' => false,
                'evidence_status' => $evidenceStatus,
                'replay_status' => $replayStatus,
                'replay_passes' => $replayOk,
                'artifact_paths' => $this->caseArtifactPaths($entry),
                'short_reason' => $shortReason,
                'atlas_gate_passed' => $atlasGatePassed,
                'rival_gate_passed' => $rivalGatePassed,
                'atlas_test_exit_code' => (int) ($parentAtlas['test_exit_code'] ?? $atlasReceipt['test_exit_code'] ?? -1),
                'rival_test_exit_code' => (int) ($parentRival['test_exit_code'] ?? $rivalReceipt['test_exit_code'] ?? -1),
                'atlas_patch_bytes' => (int) ($parentAtlas['patch_diff_bytes'] ?? $atlasReceipt['patch_diff_bytes'] ?? 0),
                'rival_patch_bytes' => (int) ($parentRival['patch_diff_bytes'] ?? $rivalReceipt['patch_diff_bytes'] ?? 0),
                'tokens_used_atlas' => $atlasReceipt['tokens_used'] ?? null,
                'tokens_used_rival' => $rivalReceipt['tokens_used'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $parent
     * @return array<string,mixed>
     */
    private function humanPromptProbeSummary(array $manifest, array $parent): array
    {
        $probe = is_array($manifest['human_prompt_probe'] ?? null)
            ? (array) $manifest['human_prompt_probe']
            : (is_array($parent['human_prompt_probe'] ?? null) ? (array) $parent['human_prompt_probe'] : []);
        $context = is_array($manifest['context_profile'] ?? null)
            ? (array) $manifest['context_profile']
            : (is_array($parent['context_profile'] ?? null) ? (array) $parent['context_profile'] : []);
        $complexity = is_array($probe['complexity_profile'] ?? null)
            ? (array) $probe['complexity_profile']
            : (is_array($context['complexity_profile'] ?? null) ? (array) $context['complexity_profile'] : []);
        $hasComplexity = ($complexity['schema_version'] ?? null) === 'atlas.forge.rivals.case_complexity_profile.v1';

        $sections = $this->stringList($probe['requires_sections'] ?? []);
        $required = [
            'facts_observed',
            'assumptions',
            'reversible_decisions',
            'scope_boundaries',
            'evidence_plan',
            'replay_matrix',
            'tradeoffs',
            'honest_blockers',
        ];
        $missing = array_values(array_diff($required, $sections));

        return [
            'schema_version' => 'atlas.forge.rivals.report_human_prompt_contract.v1',
            'probe_schema_version' => (string) ($probe['schema_version'] ?? ''),
            'context_profile_schema_version' => (string) ($context['schema_version'] ?? ''),
            'prompt_style' => (string) ($context['prompt_style'] ?? ''),
            'long_context_required' => (bool) ($context['long_context_required'] ?? false),
            'requires_assumption_log' => (bool) ($context['requires_assumption_log'] ?? false),
            'requires_scope_boundary_reasoning' => (bool) ($context['requires_scope_boundary_reasoning'] ?? false),
            'requires_replayable_evidence' => (bool) ($context['requires_replayable_evidence'] ?? false),
            'has_complexity_profile' => $hasComplexity,
            'complexity_profile' => $hasComplexity ? $complexity : null,
            'estimated_context_tokens' => $hasComplexity ? (int) ($complexity['estimated_context_tokens'] ?? 0) : null,
            'reasoning_depth' => $hasComplexity ? (int) ($complexity['reasoning_depth'] ?? 0) : null,
            'ambiguity_score' => $hasComplexity ? (int) ($complexity['ambiguity_score'] ?? 0) : null,
            'risk_score' => $hasComplexity ? (int) ($complexity['risk_score'] ?? 0) : null,
            'requires_evidence_matrix' => (bool) ($complexity['requires_evidence_matrix'] ?? false),
            'required_sections' => $sections,
            'missing_sections' => $missing,
            'complete' => ($probe['schema_version'] ?? null) === 'atlas.forge.rivals.human_prompt_probe.v1'
                && ($context['schema_version'] ?? null) === 'atlas.forge.rivals.context_profile.v1'
                && $hasComplexity
                && $missing === [],
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $value,
        ), static fn (string $item): bool => $item !== ''));
    }

    /**
     * Pull a canonical difficulty score (1.0..5.0) from the manifest. Prefers
     * the explicit `difficulty_score` declared by the corpus case; falls back
     * to deriving from `difficulty_band` (L1..L5) when only the band is
     * present. Returns 3.0 (L3, neutral) when neither is declared — the same
     * fallback the SchemaContract uses so the multiplier stays at 1.0 for
     * legacy cases instead of poisoning the weighted aggregate.
     *
     * @param  array<string,mixed>  $manifest
     */
    private function resolveDifficultyScore(array $manifest, string $band): float
    {
        $explicit = $manifest['difficulty_score'] ?? null;
        if (is_int($explicit) || is_float($explicit)) {
            $value = (float) $explicit;
            if ($value >= 1.0 && $value <= 5.0) {
                return $value;
            }
        }
        $map = AtlasForgeRivalsSchemaContractService::DIFFICULTY_LEVEL_SCORE;

        return $map[$band] ?? 3.0;
    }

    /**
     * Difficulty canon: must be one of L1..L5. `unknown` is the only legal
     * fallback when the manifest does not declare it; any other value gets
     * coerced to `unknown` so downstream rankings can't be poisoned.
     *
     * @param  array<string,mixed>  $manifest
     */
    private function canonicalDifficulty(array $manifest): string
    {
        $raw = $manifest['difficulty_band'] ?? $manifest['difficulty_level'] ?? $manifest['difficulty'] ?? null;
        if (! is_string($raw)) {
            return self::DIFFICULTY_UNKNOWN;
        }
        $value = strtoupper(trim($raw));
        if (in_array($value, self::DIFFICULTY_BANDS, true)) {
            return $value;
        }

        return self::DIFFICULTY_UNKNOWN;
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function canonicalRoleFocus(array $manifest, string $taskCategory): string
    {
        $raw = $manifest['role_focus'] ?? $manifest['role'] ?? null;
        if (is_string($raw) && trim($raw) !== '') {
            return strtolower(trim($raw));
        }

        return strtolower($taskCategory);
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function canonicalWorkKind(array $manifest, string $taskCategory): string
    {
        $explicit = $manifest['work_kind'] ?? null;
        if (is_string($explicit) && in_array(strtolower($explicit), ['planning', 'execution'], true)) {
            return strtolower($explicit);
        }
        $cat = strtolower($taskCategory);
        if (in_array($cat, self::PLANNING_CATEGORIES, true)) {
            return 'planning';
        }
        if (in_array($cat, self::EXECUTION_CATEGORIES, true)) {
            return 'execution';
        }
        $role = strtolower((string) ($manifest['role_focus'] ?? $manifest['role'] ?? ''));
        if (in_array($role, ['architect', 'planner', 'context_scout', 'reviewer'], true)) {
            return 'planning';
        }
        if (in_array($role, ['builder', 'repair_agent', 'test_generator'], true)) {
            return 'execution';
        }

        return 'unknown';
    }

    private function deterministicCaseReason(bool $atlasPassed, bool $rivalPassed, string $verdict): string
    {
        if ($atlasPassed && $rivalPassed) {
            return 'Ambos passaram os gates determinísticos do caso.';
        }
        if ($atlasPassed && ! $rivalPassed) {
            return 'Atlas passou os gates; rival falhou neste caso ('.$verdict.').';
        }
        if (! $atlasPassed && $rivalPassed) {
            return 'Rival passou os gates; Atlas falhou neste caso ('.$verdict.').';
        }

        return 'Ambos falharam os gates deste caso ('.$verdict.').';
    }

    /**
     * @param  array<string,mixed>  $scorecard
     * @param  list<mixed>  $hardFailures
     */
    private function shortReason(array $scorecard, string $verdict, array $hardFailures, bool $replayOk): string
    {
        if (str_starts_with($verdict, 'invalid')) {
            return 'Run inválido: '.$verdict;
        }
        if (! $replayOk) {
            return 'Replay falhou — evidência não confiável.';
        }
        if ($scorecard === []) {
            return 'Adjudicação ausente — rode adjudicate.';
        }
        if ($hardFailures !== []) {
            return 'Hard fail: '.implode(', ', array_map(static fn ($f): string => (string) $f, $hardFailures));
        }
        $winner = (string) ($scorecard['winner'] ?? '');
        if ($winner === AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS) {
            return 'Atlas venceu o caso por margem de qualidade.';
        }
        if ($winner === AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL) {
            return 'Rival venceu o caso por margem de qualidade.';
        }
        if ($winner === AtlasForgeRivalsAdjudicatorService::WINNER_TIE) {
            return 'Empate técnico — necessária revisão humana.';
        }

        return 'Resultado indeterminado.';
    }

    /**
     * @param  list<array<string,mixed>>  $caseResults
     * @return list<array<string,mixed>>
     */
    private function buildCategoryResults(array $caseResults, mixed $threshold): array
    {
        $thresholdF = (float) $threshold;
        $byCat = [];
        foreach ($caseResults as $case) {
            $cat = (string) ($case['task_category'] ?? 'unknown');
            if ($cat === '') {
                $cat = 'unknown';
            }
            if (! isset($byCat[$cat])) {
                $byCat[$cat] = [];
            }
            $byCat[$cat][] = $case;
        }
        ksort($byCat);
        $out = [];
        foreach ($byCat as $cat => $cases) {
            $atlasScores = array_values(array_filter(array_map(
                static fn (array $c): ?float => is_numeric($c['atlas_score'] ?? null) ? (float) $c['atlas_score'] : null,
                $cases,
            ), static fn (?float $v): bool => $v !== null));
            $rivalScores = array_values(array_filter(array_map(
                static fn (array $c): ?float => is_numeric($c['rival_score'] ?? null) ? (float) $c['rival_score'] : null,
                $cases,
            ), static fn (?float $v): bool => $v !== null));
            $atlasAvg = $atlasScores !== [] ? round(array_sum($atlasScores) / count($atlasScores), 2) : null;
            $rivalAvg = $rivalScores !== [] ? round(array_sum($rivalScores) / count($rivalScores), 2) : null;
            $margin = ($atlasAvg !== null && $rivalAvg !== null)
                ? round($atlasAvg - $rivalAvg, 2)
                : null;

            $winner = null;
            if ($margin === null) {
                $winner = null;
            } elseif (abs($margin) < $thresholdF) {
                $winner = AtlasForgeRivalsAdjudicatorService::WINNER_TIE;
            } elseif ($margin > 0) {
                $winner = AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS;
            } else {
                $winner = AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL;
            }

            $best = null;
            $worst = null;
            foreach ($cases as $c) {
                $a = is_numeric($c['atlas_score'] ?? null) ? (float) $c['atlas_score'] : null;
                $r = is_numeric($c['rival_score'] ?? null) ? (float) $c['rival_score'] : null;
                if ($a === null || $r === null) {
                    continue;
                }
                $m = $a - $r;
                if ($best === null || abs($m) > abs($best['margin'])) {
                    $best = ['case_id' => $c['case_id'], 'margin' => $m];
                }
                if ($worst === null || abs($m) < abs($worst['margin'])) {
                    $worst = ['case_id' => $c['case_id'], 'margin' => $m];
                }
            }

            $confidence = match (true) {
                count($cases) >= 3 => 'medium',
                count($cases) === 2 => 'low',
                default => 'directional_only',
            };

            $why = $this->categoryWhy($winner, $margin, $cases);
            $measuredAhead = match ($winner) {
                AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS => 'atlas',
                AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL => 'rival',
                AtlasForgeRivalsAdjudicatorService::WINNER_TIE => 'tie',
                default => null,
            };

            $out[] = [
                'category' => $cat,
                'cases' => count($cases),
                'atlas_score' => $atlasAvg,
                'rival_score' => $rivalAvg,
                'winner' => $winner,
                'margin' => $margin,
                'confidence' => $confidence,
                'why' => $why,
                'best_case' => $best,
                'worst_case' => $worst,
                'caveats' => $this->categoryCaveats($cases),
                'measured_ahead_for_this_category' => $measuredAhead,
                'routing_effect' => 'none',
                'case_ids' => array_values(array_map(static fn (array $c): string => (string) $c['case_id'], $cases)),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $cases
     */
    private function categoryWhy(?string $winner, ?float $margin, array $cases): string
    {
        if (count($cases) === 1) {
            $case = $cases[0];
            $atlas = $case['atlas_score'];
            $rival = $case['rival_score'];
            if ($atlas === null || $rival === null) {
                return 'Caso único sem score comparável — sinal apenas direcional.';
            }

            return sprintf(
                'Caso único: Atlas %.1f vs Rival %.1f (margem %.1f).',
                (float) $atlas,
                (float) $rival,
                (float) $atlas - (float) $rival,
            );
        }

        if ($margin === null) {
            return 'Categoria sem score agregado válido (ver hard failures por caso).';
        }
        if ($winner === AtlasForgeRivalsAdjudicatorService::WINNER_TIE) {
            return sprintf('Empate técnico (margem %.2f < threshold) — revisão humana sugerida.', abs($margin));
        }
        $side = $winner === AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS ? 'Atlas' : 'Rival';

        return sprintf('%s venceu a categoria com margem média de %.2f pontos em %d casos.', $side, $margin, count($cases));
    }

    /**
     * @param  list<array<string,mixed>>  $cases
     * @return list<string>
     */
    private function categoryCaveats(array $cases): array
    {
        $caveats = [];
        if (count($cases) < 3) {
            $caveats[] = 'amostra_pequena';
        }
        foreach ($cases as $c) {
            if (! empty($c['hard_failures'])) {
                $caveats[] = 'hard_failures_em_'.$c['case_id'];
            }
            if (($c['evidence_status'] ?? '') === 'replay_failed') {
                $caveats[] = 'replay_failed_em_'.$c['case_id'];
            }
        }

        return array_values(array_unique($caveats));
    }

    /**
     * Aggregate by an arbitrary categorical key (difficulty band, work_kind,
     * mode, provider/model). Each entry repeats the same shape so downstream
     * tooling can iterate uniformly.
     *
     * @param  list<array<string,mixed>>  $caseResults
     * @return list<array<string,mixed>>
     */
    private function aggregateByKey(array $caseResults, string $axis, callable $keyFn, mixed $threshold): array
    {
        $thresholdF = (float) $threshold;
        $groups = [];
        foreach ($caseResults as $case) {
            $key = (string) $keyFn($case);
            if ($key === '') {
                $key = 'unknown';
            }
            $groups[$key][] = $case;
        }
        ksort($groups);
        $out = [];
        foreach ($groups as $key => $cases) {
            $atlasScores = array_values(array_filter(array_map(
                static fn (array $c): ?float => is_numeric($c['atlas_score'] ?? null) ? (float) $c['atlas_score'] : null,
                $cases,
            ), static fn (?float $v): bool => $v !== null));
            $rivalScores = array_values(array_filter(array_map(
                static fn (array $c): ?float => is_numeric($c['rival_score'] ?? null) ? (float) $c['rival_score'] : null,
                $cases,
            ), static fn (?float $v): bool => $v !== null));
            $atlasAvg = $atlasScores !== [] ? round(array_sum($atlasScores) / count($atlasScores), 2) : null;
            $rivalAvg = $rivalScores !== [] ? round(array_sum($rivalScores) / count($rivalScores), 2) : null;
            $margin = ($atlasAvg !== null && $rivalAvg !== null)
                ? round($atlasAvg - $rivalAvg, 2)
                : null;
            $winner = match (true) {
                $margin === null => null,
                abs($margin) < $thresholdF => AtlasForgeRivalsAdjudicatorService::WINNER_TIE,
                $margin > 0 => AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS,
                default => AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL,
            };
            $suspicious = array_values(array_filter($cases, static fn (array $c): bool => ! empty($c['suspicious'])));
            $validity = $this->validityForBucket(
                cases: $cases,
                key: $key,
                axis: $axis,
                suspicious: count($suspicious),
            );

            $out[] = [
                'axis' => $axis,
                'key' => $key,
                'cases' => count($cases),
                'atlas_score' => $atlasAvg,
                'rival_score' => $rivalAvg,
                'winner' => $winner,
                'margin' => $margin,
                'suspicious_count' => count($suspicious),
                'validity' => $validity['status'],
                'validity_reason' => $validity['reason'],
                'measured_ahead' => $this->bucketMeasuredAhead($winner, $validity['status']),
                'routing_effect' => 'none',
                'case_ids' => array_values(array_map(static fn (array $c): string => (string) $c['case_id'], $cases)),
            ];
        }

        return $out;
    }

    /**
     * Aggregate cases into multiple buckets when a single case measures more
     * than one capability axis. This is the 360 read-model: a tie stays a tie,
     * but now we know which skills were actually exercised.
     *
     * @param  list<array<string,mixed>>  $caseResults
     * @return list<array<string,mixed>>
     */
    private function aggregateByKeys(array $caseResults, string $axis, callable $keysFn, mixed $threshold): array
    {
        $expanded = [];
        foreach ($caseResults as $case) {
            $keys = array_values(array_unique(array_filter(array_map(
                static fn (mixed $key): string => trim((string) $key),
                (array) $keysFn($case),
            ), static fn (string $key): bool => $key !== '')));
            if ($keys === []) {
                $keys = ['unknown'];
            }
            foreach ($keys as $key) {
                $row = $case;
                $row['_axis_key'] = $key;
                $expanded[] = $row;
            }
        }

        return $this->aggregateByKey(
            $expanded,
            $axis,
            static fn (array $c): string => (string) ($c['_axis_key'] ?? 'unknown'),
            $threshold,
        );
    }

    /**
     * @param  list<array<string,mixed>>  $cases
     * @return array{status:string,reason:string}
     */
    private function validityForBucket(array $cases, string $key, string $axis, int $suspicious): array
    {
        if ($axis === 'difficulty' && $key === self::DIFFICULTY_UNKNOWN) {
            return ['status' => self::VALIDITY_INSUFFICIENT, 'reason' => 'difficulty_band_unknown'];
        }
        if ($cases === []) {
            return ['status' => self::VALIDITY_INSUFFICIENT, 'reason' => 'empty_bucket'];
        }
        if ($suspicious > 0) {
            return ['status' => self::VALIDITY_SUSPECT, 'reason' => 'suspicious_cases_in_bucket'];
        }
        foreach ($cases as $c) {
            $evidenceStatus = (string) ($c['evidence_status'] ?? '');
            $deterministicCase = ($c['score_source'] ?? '') === 'multi_case_deterministic_gate_rollup';
            if (! $deterministicCase && ! empty($c['hard_failures'])) {
                return ['status' => self::VALIDITY_SUSPECT, 'reason' => 'hard_failures_in_bucket'];
            }
            if ($evidenceStatus !== 'evidence_ok' && ! str_starts_with($evidenceStatus, 'case_failed:')) {
                return ['status' => self::VALIDITY_INSUFFICIENT, 'reason' => 'evidence_incomplete_in_bucket'];
            }
        }
        if (count($cases) < 3) {
            return ['status' => self::VALIDITY_INSUFFICIENT, 'reason' => 'small_sample_less_than_three'];
        }

        return ['status' => self::VALIDITY_VALID, 'reason' => 'sample_size_and_evidence_ok'];
    }

    private function bucketMeasuredAhead(?string $winner, string $validity): ?string
    {
        if ($validity !== self::VALIDITY_VALID) {
            return null;
        }

        return match ($winner) {
            AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS => 'atlas',
            AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL => 'rival',
            AtlasForgeRivalsAdjudicatorService::WINNER_TIE => 'tie',
            default => null,
        };
    }

    /**
     * @param  list<array<string,mixed>>  $caseResults
     * @return list<array<string,mixed>>
     */
    private function buildDifficultyResults(array $caseResults, mixed $threshold): array
    {
        return $this->aggregateByKey(
            $caseResults,
            'difficulty',
            static fn (array $c): string => (string) ($c['difficulty_band'] ?? AtlasForgeRivalsReportService::DIFFICULTY_UNKNOWN),
            $threshold,
        );
    }

    /**
     * @param  list<array<string,mixed>>  $caseResults
     * @return list<array<string,mixed>>
     */
    private function buildPlanningExecutionResults(array $caseResults, mixed $threshold): array
    {
        return $this->aggregateByKey(
            $caseResults,
            'work_kind',
            static fn (array $c): string => (string) ($c['work_kind'] ?? 'unknown'),
            $threshold,
        );
    }

    /**
     * Provider/model ranking groups cases by the pair (atlas_model::rival_model).
     * Per-arm provider strengths are surfaced separately in the signal block.
     *
     * @param  list<array<string,mixed>>  $caseResults
     * @return list<array<string,mixed>>
     */
    private function buildProviderResults(array $caseResults, mixed $threshold): array
    {
        return $this->aggregateByKey(
            $caseResults,
            'provider',
            static fn (array $c): string => (string) ($c['provider_pair'] ?? ((string) ($c['atlas_model'] ?? 'unknown').'_vs_'.(string) ($c['rival_model'] ?? 'unknown'))),
            $threshold,
        );
    }

    private function providerPairKey(?array $armA, ?array $armB, string $atlasModel, string $rivalModel): string
    {
        if ($armA !== null && $armB !== null) {
            $left = implode(':', array_values(array_filter([
                (string) ($armA['id'] ?? 'arm_a'),
                (string) ($armA['provider'] ?? ''),
                (string) ($armA['model'] ?? ''),
                (string) ($armA['model_id'] ?? ''),
            ], static fn (string $part): bool => $part !== '')));
            $right = implode(':', array_values(array_filter([
                (string) ($armB['id'] ?? 'arm_b'),
                (string) ($armB['provider'] ?? ''),
                (string) ($armB['model'] ?? ''),
                (string) ($armB['model_id'] ?? ''),
            ], static fn (string $part): bool => $part !== '')));

            return $left.'_vs_'.$right;
        }

        return $atlasModel.'_vs_'.$rivalModel;
    }

    /**
     * Atlas mode ranking (fair vs full_power). Cases in local_fake stay in
     * their own bucket so they never inflate the comparison.
     *
     * @param  list<array<string,mixed>>  $caseResults
     * @return list<array<string,mixed>>
     */
    private function buildModeResults(array $caseResults, mixed $threshold): array
    {
        return $this->aggregateByKey(
            $caseResults,
            'mode',
            static fn (array $c): string => (string) ($c['mode'] ?? 'unknown'),
            $threshold,
        );
    }

    /**
     * Capability axes are derived from explicit complexity measured_dimensions
     * plus measurement_tags. They are advisory evidence axes, never routing
     * instructions.
     *
     * @param  list<array<string,mixed>>  $caseResults
     * @return list<array<string,mixed>>
     */
    private function buildCapabilityResults(array $caseResults, mixed $threshold): array
    {
        return $this->aggregateByKeys(
            $caseResults,
            'capability',
            function (array $case): array {
                $profile = is_array($case['complexity_profile'] ?? null) ? (array) $case['complexity_profile'] : [];
                $dimensions = $this->stringList($profile['measured_dimensions'] ?? []);
                $tags = $this->stringList($case['measurement_tags'] ?? []);
                $declared = $this->stringList($case['measured_capabilities'] ?? []);
                $derived = [];
                if (($profile['requires_rollback_plan'] ?? false) === true) {
                    $derived[] = 'rollback_safety';
                }
                if (($profile['requires_multi_step_plan'] ?? false) === true) {
                    $derived[] = 'multi_step_reasoning';
                }
                if (($profile['requires_evidence_matrix'] ?? false) === true) {
                    $derived[] = 'replayable_evidence_quality';
                }
                if (($profile['long_context_required'] ?? false) === true) {
                    $derived[] = 'long_context_retention';
                }

                return $this->normaliseCapabilityKeys(array_merge($declared, $dimensions, $tags, $derived));
            },
            $threshold,
        );
    }

    /**
     * @param  list<string>  $capabilities
     * @return list<string>
     */
    private function normaliseCapabilityKeys(array $capabilities): array
    {
        $out = [];
        foreach ($capabilities as $capability) {
            $key = trim($capability);
            if ($key === '') {
                continue;
            }

            $out[] = match ($key) {
                'multi_step_execution' => 'multi_step_reasoning',
                'evidence_replay_completeness' => 'replayable_evidence_quality',
                'ambiguity_resolution',
                'ambiguity_handling',
                'assumption_quality',
                'scope_boundary_probe' => 'ambiguous_human_prompt_handling',
                'contract_safety' => 'scope_boundary_discipline',
                default => $key,
            };
        }

        return array_values(array_unique($out));
    }

    /**
     * Filter input normalisation. Operators may pass any of the four filter
     * dimensions; report aggregation then runs only over matching cases.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,?string>
     */
    private function normaliseFilters(array $input): array
    {
        $clean = static function (mixed $v): ?string {
            if (! is_string($v)) {
                return null;
            }
            $trim = trim($v);

            return $trim === '' ? null : strtolower($trim);
        };

        $difficulty = $input['difficulty'] ?? null;
        if (is_string($difficulty)) {
            $upper = strtoupper(trim($difficulty));
            if (in_array($upper, self::DIFFICULTY_BANDS, true)) {
                $difficulty = $upper;
            } elseif ($upper === '') {
                $difficulty = null;
            } else {
                $difficulty = $upper;
            }
        } else {
            $difficulty = null;
        }

        return [
            'category' => $clean($input['category'] ?? null),
            'difficulty' => $difficulty,
            'provider' => $clean($input['provider'] ?? null),
            'mode' => $clean($input['mode_filter'] ?? $input['mode'] ?? null),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $caseResults
     * @param  array<string,?string>  $filters
     * @return list<array<string,mixed>>
     */
    private function applyFilters(array $caseResults, array $filters): array
    {
        if (array_filter($filters) === []) {
            return $caseResults;
        }

        return array_values(array_filter($caseResults, static function (array $c) use ($filters): bool {
            if ($filters['category'] !== null && strtolower((string) $c['task_category']) !== $filters['category']) {
                return false;
            }
            if ($filters['difficulty'] !== null && (string) ($c['difficulty_band'] ?? AtlasForgeRivalsReportService::DIFFICULTY_UNKNOWN) !== $filters['difficulty']) {
                return false;
            }
            if ($filters['provider'] !== null) {
                $atlasModel = strtolower((string) ($c['atlas_model'] ?? ''));
                $rivalModel = strtolower((string) ($c['rival_model'] ?? ''));
                $providerPair = strtolower((string) ($c['provider_pair'] ?? ''));
                $armAProvider = strtolower((string) data_get($c, 'arm_a.provider', ''));
                $armBProvider = strtolower((string) data_get($c, 'arm_b.provider', ''));
                $armAId = strtolower((string) data_get($c, 'arm_a.id', ''));
                $armBId = strtolower((string) data_get($c, 'arm_b.id', ''));
                if (! str_contains($atlasModel, $filters['provider'])
                    && ! str_contains($rivalModel, $filters['provider'])
                    && ! str_contains($providerPair, $filters['provider'])
                    && ! str_contains($armAProvider, $filters['provider'])
                    && ! str_contains($armBProvider, $filters['provider'])
                    && ! str_contains($armAId, $filters['provider'])
                    && ! str_contains($armBId, $filters['provider'])
                ) {
                    return false;
                }
            }
            if ($filters['mode'] !== null && strtolower((string) ($c['mode'] ?? '')) !== $filters['mode']) {
                return false;
            }

            return true;
        }));
    }

    /**
     * @param  list<array<string,mixed>>  $caseResults
     * @param  list<array<string,mixed>>  $categoryResults
     * @return array<string,mixed>
     */
    private function buildOverallResult(
        array $caseResults,
        array $categoryResults,
        ?string $winner,
        mixed $atlasScore,
        mixed $rivalScore,
        mixed $threshold,
    ): array {
        $atlasWins = 0;
        $rivalWins = 0;
        $ties = 0;
        foreach ($categoryResults as $cat) {
            $w = $cat['winner'];
            if ($w === AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS) {
                $atlasWins++;
            } elseif ($w === AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL) {
                $rivalWins++;
            } elseif ($w === AtlasForgeRivalsAdjudicatorService::WINNER_TIE) {
                $ties++;
            }
        }

        return [
            'winner' => $winner,
            'atlas_score' => $atlasScore,
            'rival_score' => $rivalScore,
            'threshold' => (float) $threshold,
            'margin' => ($atlasScore !== null && $rivalScore !== null)
                ? round((float) $atlasScore - (float) $rivalScore, 2)
                : null,
            'cases_total' => count($caseResults),
            'cases_with_winner' => count(array_filter($caseResults, static fn (array $c): bool => $c['winner'] !== null)),
            'categories_total' => count($categoryResults),
            'atlas_category_wins' => $atlasWins,
            'rival_category_wins' => $rivalWins,
            'category_ties' => $ties,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $caseResults
     * @param  list<array<string,mixed>>  $arms
     * @return list<array<string,mixed>>
     */
    private function detectSuspicious(array &$caseResults, array $arms): array
    {
        $rivalModel = strtolower((string) ($arms[1]['model'] ?? ''));
        $strongRival = str_contains($rivalModel, 'claude') || str_contains($rivalModel, 'codex') || str_contains($rivalModel, 'opus');
        $suspicious = [];
        foreach ($caseResults as $idx => $case) {
            $reasons = [];
            $rival = $case['rival_score'];
            $atlas = $case['atlas_score'];
            $deterministicCase = ($case['score_source'] ?? '') === 'multi_case_deterministic_gate_rollup';
            if (! $deterministicCase && $strongRival && is_numeric($rival) && (float) $rival < self::SUSPICIOUS_PROVIDER_SCORE_THRESHOLD) {
                $reasons[] = sprintf('rival_score_below_%d_for_strong_provider', (int) self::SUSPICIOUS_PROVIDER_SCORE_THRESHOLD);
            }
            if (! $deterministicCase && is_numeric($atlas) && is_numeric($rival) && abs((float) $atlas - (float) $rival) >= self::SUSPICIOUS_LARGE_MARGIN) {
                $reasons[] = 'large_margin_in_single_case';
            }
            $atlasPatchTiny = ($case['atlas_patch_bytes'] ?? 0) <= self::SUSPICIOUS_TINY_PATCH_BYTES;
            $rivalPatchTiny = ($case['rival_patch_bytes'] ?? 0) <= self::SUSPICIOUS_TINY_PATCH_BYTES;
            if ($deterministicCase ? (($case['atlas_gate_passed'] ?? false) === true && $atlasPatchTiny) : $atlasPatchTiny) {
                $reasons[] = 'atlas_patch_almost_empty';
            }
            if ($deterministicCase ? (($case['rival_gate_passed'] ?? false) === true && $rivalPatchTiny) : $rivalPatchTiny) {
                $reasons[] = 'rival_patch_almost_empty';
            }
            if ($reasons !== []) {
                $caseResults[$idx]['suspicious'] = true;
                $suspicious[] = [
                    'case_id' => $case['case_id'],
                    'reasons' => $reasons,
                    'atlas_score' => $atlas,
                    'rival_score' => $rival,
                    'recommendation' => 'triage_before_trusting',
                ];
            }
        }

        return $suspicious;
    }

    /**
     * @param  list<array<string,mixed>>  $caseResults
     * @param  list<array<string,mixed>>  $categoryResults
     * @param  array<string,mixed>  $validity
     * @return array<string,mixed>
     */
    private function deriveConfidence(
        string $preset,
        array $caseResults,
        array $categoryResults,
        array $validity,
        int $suspiciousCount,
        ?string $winner,
    ): array {
        $caseCount = count($caseResults);
        $catCount = count($categoryResults);
        $evidenceOk = ($validity['replay_passes'] ?? false) && ($validity['adjudication_present'] ?? false) && ($validity['hard_failures_clean'] ?? false) && ! str_starts_with((string) ($validity['verdict'] ?? ''), 'invalid');

        if (! $evidenceOk) {
            return [
                'level' => self::CONFIDENCE_INCONCLUSIVE,
                'reason' => 'evidence_or_replay_invalid',
                'is_trusted' => false,
                'preset' => $preset,
                'cases' => $caseCount,
                'categories' => $catCount,
                'suspicious_count' => $suspiciousCount,
            ];
        }

        if ($suspiciousCount > 0) {
            return [
                'level' => self::CONFIDENCE_INCONCLUSIVE,
                'reason' => 'suspicious_results_present',
                'is_trusted' => false,
                'preset' => $preset,
                'cases' => $caseCount,
                'categories' => $catCount,
                'suspicious_count' => $suspiciousCount,
            ];
        }

        if ($preset === 'release' && $caseCount >= 12 && $catCount >= 8) {
            return [
                'level' => self::CONFIDENCE_TRUSTED,
                'reason' => 'release_with_12_cases_8_categories',
                'is_trusted' => true,
                'preset' => $preset,
                'cases' => $caseCount,
                'categories' => $catCount,
                'suspicious_count' => 0,
            ];
        }

        if ($caseCount >= 5 && $catCount >= 2) {
            return [
                'level' => self::CONFIDENCE_DIRECTIONAL,
                'reason' => 'five_cases_two_categories_evidence_ok',
                'is_trusted' => false,
                'preset' => $preset,
                'cases' => $caseCount,
                'categories' => $catCount,
                'suspicious_count' => 0,
            ];
        }

        return [
            'level' => self::CONFIDENCE_FLOW_VALIDATED,
            'reason' => $preset === 'quick'
                ? 'quick_proves_harness_not_superiority'
                : 'small_sample_flow_validated_only',
            'is_trusted' => false,
            'preset' => $preset,
            'cases' => $caseCount,
            'categories' => $catCount,
            'suspicious_count' => 0,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $categoryResults
     * @param  array<string,mixed>  $confidence
     * @return array<string,mixed>
     */
    private function buildAtlasDecideRecommendations(array $categoryResults, array $confidence): array
    {
        $signals = [];
        $trusted = (bool) ($confidence['is_trusted'] ?? false);
        foreach ($categoryResults as $cat) {
            $measuredAhead = $cat['measured_ahead_for_this_category']
                ?? null;
            $signals[] = [
                'category' => (string) ($cat['category'] ?? 'unknown'),
                'measured_ahead' => $measuredAhead,
                'winner' => $cat['winner'] ?? null,
                'margin' => $cat['margin'] ?? null,
                'cases' => (int) ($cat['cases'] ?? 0),
                'confidence' => (string) ($cat['confidence'] ?? 'unknown'),
                'routing_effect' => 'none',
                'reason' => match ($measuredAhead) {
                    'atlas' => 'atlas_forge_measured_ahead_in_this_battery',
                    'rival' => 'rival_measured_ahead_in_this_battery',
                    'tie' => 'tie_human_review_required',
                    default => 'inconclusive',
                },
            ];
        }

        return [
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'reason_topology_not_updated' => $trusted
                ? 'rivals_signal_is_consultative_atlas_decide_owns_topology'
                : 'confidence_below_trusted_battery',
            'note' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
            'measured_signal_by_category' => $signals,
            // Deprecated compatibility fields intentionally stay empty. Rivals
            // does not choose builder/reviewer topology; Atlas Decide owns it.
            'primary_builder_by_category' => [],
            'reviewer_by_category' => [],
            'deprecated_fields_empty_because_routing_is_atlas_decide' => true,
            'confidence' => $confidence['level'],
            'is_trusted_signal' => $trusted,
        ];
    }

    /**
     * @param  list<mixed>  $hardFailures
     * @param  list<array<string,mixed>>  $suspicious
     * @param  array<string,mixed>  $confidence
     * @return array<string,mixed>
     */
    private function buildHumanReview(
        bool $humanReviewRequired,
        array $hardFailures,
        ?string $winner,
        bool $isInvalid,
        bool $replayOk,
        array $suspicious,
        array $confidence,
    ): array {
        $checklist = [];
        if ($isInvalid) {
            $checklist[] = 'Investigar verdict invalid_* no manifest antes de tudo.';
            $checklist[] = 'Tratar a run como ZERO claim até resolver a invalidação.';
        } elseif (! $replayOk) {
            $checklist[] = 'Replay falhou — evidence pack não é confiável.';
            $checklist[] = 'Re-rodar `atlas:forge:rivals run-real` em vez de confiar em evidência parcial.';
        } elseif ($hardFailures !== []) {
            foreach ($hardFailures as $f) {
                $checklist[] = 'Corrigir hard-fail gate `'.(string) $f.'` antes de declarar claim.';
            }
        } elseif ($humanReviewRequired) {
            $checklist[] = 'Inspecionar `evidence/atlas_patch.diff` vs `evidence/rival_patch.diff`.';
            $checklist[] = 'Ler `evidence/atlas_test.log` vs `evidence/rival_test.log`.';
            $checklist[] = 'Decidir qual patch é qualitativamente melhor — o adjudicator só viu heurísticas.';
            $checklist[] = 'Se ambos parecerem equivalentes, aceitar o empate — não forçar winner.';
            $checklist[] = 'Nunca elevar empate a claim sem assinatura do operador.';
        } else {
            $checklist[] = 'Adjudicator retornou winner determinado por qualidade ('.(string) $winner.').';
            $checklist[] = 'Confirmar `replay_passes=true` e `dirty_after_run=false`.';
            $checklist[] = 'Inspecionar diff antes de comunicar resultado.';
        }
        if ($suspicious !== []) {
            $checklist[] = 'Triagem obrigatória de '.count($suspicious).' caso(s) marcado(s) como suspicious.';
        }
        if (($confidence['level'] ?? '') === self::CONFIDENCE_FLOW_VALIDATED) {
            $checklist[] = 'Confiança apenas flow_validated — não declarar superioridade global.';
        }

        return [
            'required' => $humanReviewRequired || $suspicious !== [] || $hardFailures !== [] || ! $replayOk || $isInvalid,
            'checklist' => $checklist,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $caseEntries
     * @return array<string,mixed>
     */
    private function buildCostTime(array $caseEntries): array
    {
        $atlasWall = 0.0;
        $rivalWall = 0.0;
        $atlasTokens = 0;
        $rivalTokens = 0;
        $atlasStdoutBytes = 0;
        $rivalStdoutBytes = 0;
        $available = false;
        foreach ($caseEntries as $entry) {
            $atlasReceipt = (array) ($entry['atlas_receipt'] ?? []);
            $rivalReceipt = (array) ($entry['rival_receipt'] ?? []);
            if (isset($atlasReceipt['tokens_used'])) {
                $atlasTokens += (int) $atlasReceipt['tokens_used'];
                $available = true;
            }
            if (isset($rivalReceipt['tokens_used'])) {
                $rivalTokens += (int) $rivalReceipt['tokens_used'];
                $available = true;
            }
            $atlasStdoutBytes += (int) ($atlasReceipt['stdout_bytes'] ?? 0);
            $rivalStdoutBytes += (int) ($rivalReceipt['stdout_bytes'] ?? 0);

            $wallA = $this->computeWallSeconds($atlasReceipt);
            $wallR = $this->computeWallSeconds($rivalReceipt);
            if ($wallA !== null) {
                $atlasWall += $wallA;
            }
            if ($wallR !== null) {
                $rivalWall += $wallR;
            }
        }

        return [
            'available' => $available || $atlasStdoutBytes > 0 || $rivalStdoutBytes > 0,
            'atlas_wall_seconds' => round($atlasWall, 2),
            'rival_wall_seconds' => round($rivalWall, 2),
            'atlas_stdout_bytes' => $atlasStdoutBytes,
            'rival_stdout_bytes' => $rivalStdoutBytes,
            'atlas_tokens_used' => $available ? $atlasTokens : null,
            'rival_tokens_used' => $available ? $rivalTokens : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function computeWallSeconds(array $receipt): ?float
    {
        $start = (string) ($receipt['started_at'] ?? '');
        $end = (string) ($receipt['finished_at'] ?? '');
        if ($start === '' || $end === '') {
            return null;
        }
        try {
            $startTs = new \DateTimeImmutable($start);
            $endTs = new \DateTimeImmutable($end);

            return max(0.0, (float) ($endTs->getTimestamp() - $startTs->getTimestamp()));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $paths
     * @param  list<array<string,mixed>>  $caseEntries
     * @return array<string,mixed>
     */
    private function buildEvidenceState(array $paths, array $caseEntries, bool $isMultiCase, bool $replayOk, bool $adjudicationMissing): array
    {
        $missing = [];
        $primary = $caseEntries[0] ?? [];
        $atlasReceipt = (array) ($primary['atlas_receipt'] ?? []);
        $rivalReceipt = (array) ($primary['rival_receipt'] ?? []);

        if ($atlasReceipt === []) {
            $missing[] = 'atlas_receipt';
        }
        if ($rivalReceipt === []) {
            $missing[] = 'rival_receipt';
        }
        if ($adjudicationMissing) {
            $missing[] = 'scorecard';
        }
        if (! $replayOk) {
            $missing[] = 'replay_passes_false';
        }

        return [
            'state' => $missing === [] ? 'ok' : 'incomplete',
            'is_multi_case' => $isMultiCase,
            'cases_observed' => count($caseEntries),
            'missing_artifacts' => $missing,
            'evidence_dir' => $paths['evidence'] ?? '',
        ];
    }

    /**
     * Builds the structured Provider Performance Signal block emitted for
     * Atlas Decide as a *consultative* read-model. Schema:
     * `atlas.forge.rivals.provider_performance_signal.v1`.
     *
     * The signal is the canonical output that turns a Rivals battery into
     * measured evidence per arm, category and difficulty band. It never
     * decides model routing; Atlas Decide remains the owner of topology.
     *
     * @param  list<array<string,mixed>>  $caseResults
     * @param  list<array<string,mixed>>  $arms
     * @param  array<string,mixed>  $confidence
     * @param  list<array<string,mixed>>  $categoryResults
     * @param  list<array<string,mixed>>  $difficultyResults
     * @param  list<array<string,mixed>>  $capabilityResults
     * @param  list<array<string,mixed>>  $modeResults
     * @param  list<array<string,mixed>>  $providerResults
     * @param  list<array<string,mixed>>  $suspicious
     * @param  array<string,mixed>  $validity
     * @return array<string,mixed>
     */
    private function buildProviderPerformanceSignal(
        array $caseResults,
        array $arms,
        array $confidence,
        array $categoryResults,
        array $difficultyResults,
        array $capabilityResults,
        array $modeResults,
        array $providerResults,
        array $suspicious,
        array $validity,
    ): array {
        $atlasModel = (string) ($arms[0]['model'] ?? 'unknown');
        $rivalModel = (string) ($arms[1]['model'] ?? 'unknown');
        $rows = [];
        foreach ($caseResults as $case) {
            $rows[] = [
                'case_id' => $case['case_id'],
                'task_category' => $case['task_category'],
                'difficulty_band' => $case['difficulty_band'] ?? self::DIFFICULTY_UNKNOWN,
                'work_kind' => $case['work_kind'] ?? 'unknown',
                'mode' => $case['mode'] ?? 'unknown',
                'prompt_mode' => $case['prompt_mode'] ?? 'spec-perfect',
                'atlas_model' => (string) ($case['atlas_model'] ?? $atlasModel),
                'rival_model' => (string) ($case['rival_model'] ?? $rivalModel),
                'arm_a' => $case['arm_a'] ?? ($arms[0] ?? null),
                'arm_b' => $case['arm_b'] ?? ($arms[1] ?? null),
                'provider_pair' => $case['provider_pair'] ?? null,
                'human_prompt_contract' => $case['human_prompt_contract'] ?? null,
                'complexity_profile' => $case['complexity_profile'] ?? null,
                'measurement_tags' => $case['measurement_tags'] ?? [],
                'quality_dimensions' => $case['quality_dimensions'] ?? null,
                'atlas_score' => $case['atlas_score'],
                'rival_score' => $case['rival_score'],
                'winner' => $case['winner'],
                'suspicious' => $case['suspicious'],
                'replay_passes' => $case['replay_passes'],
            ];
        }

        $providerRecs = $this->buildProviderRecommendations($caseResults, $arms, $atlasModel, $rivalModel, $confidence);
        $categoryFit = $this->buildAxisFit($categoryResults, 'category');
        $difficultyFit = $this->buildAxisFit($difficultyResults, 'difficulty');
        $difficultyPressure = $this->buildDifficultyPressure($difficultyResults);
        $humanPromptCoverage = $this->buildHumanPromptCoverage($caseResults);
        $complexityCoverage = $this->buildComplexityProfileCoverage($caseResults);
        $capabilityCoverage = $this->buildCapabilityCoverage($capabilityResults);
        $ceilingContractSignal = $this->buildCeiling360ContractSignal($caseResults);
        $nextMeasurementCommands = $this->buildNextMeasurementCommands($capabilityCoverage, $arms);
        if (is_array($capabilityCoverage['next_measurement_plan'] ?? null)) {
            $capabilityCoverage['next_measurement_plan']['recommended_commands'] = $nextMeasurementCommands;
        }
        $doNotUseWhen = $this->buildDoNotUseWhen($caseResults, $arms, $suspicious, $complexityCoverage, $capabilityCoverage);
        $fallbackHint = $this->buildFallbackHint($confidence, $suspicious, $validity);
        $confidenceCanFeedLedger = (bool) ($confidence['is_trusted'] ?? false)
            || ($confidence['level'] === self::CONFIDENCE_DIRECTIONAL && empty($confidence['suspicious_count']));
        $canFeedLedger = $confidenceCanFeedLedger && $doNotUseWhen === [];

        return [
            'schema_version' => self::SIGNAL_SCHEMA_VERSION,
            'advisory_only' => true,
            'never_changes_atlas_decide_topology' => true,
            'should_update_provider_topology' => false,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'note' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
            'can_feed_ledger' => $canFeedLedger,
            'ledger_blockers' => $canFeedLedger
                ? []
                : array_values(array_map(
                    static fn (array $reason): string => (string) ($reason['condition'] ?? 'unknown'),
                    $doNotUseWhen,
                )),
            'confidence' => [
                'level' => (string) ($confidence['level'] ?? 'unknown'),
                'reason' => (string) ($confidence['reason'] ?? ''),
                'is_trusted' => (bool) ($confidence['is_trusted'] ?? false),
            ],
            'provider_measurement' => $providerRecs,
            'provider_recommendation' => $providerRecs,
            'category_fit' => $categoryFit,
            'difficulty_fit' => $difficultyFit,
            'difficulty_pressure' => $difficultyPressure,
            'capability_fit' => $this->buildAxisFit($capabilityResults, 'capability'),
            'mode_fit' => $this->buildAxisFit($modeResults, 'mode'),
            'provider_pair_fit' => $this->buildAxisFit($providerResults, 'provider'),
            'human_prompt_contract_coverage' => $humanPromptCoverage,
            'complexity_profile_coverage' => $complexityCoverage,
            'capability_coverage' => $capabilityCoverage,
            'ceiling_360_contract_signal' => $ceilingContractSignal,
            'next_measurement_commands' => $nextMeasurementCommands,
            'do_not_use_when' => $doNotUseWhen,
            'fallback_hint' => $fallbackHint,
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $difficultyResults
     * @return array<string,mixed>
     */
    private function buildDifficultyPressure(array $difficultyResults): array
    {
        $bands = [];
        foreach ($difficultyResults as $row) {
            $band = strtoupper(trim((string) ($row['key'] ?? '')));
            if (! in_array($band, self::DIFFICULTY_BANDS, true)) {
                continue;
            }
            $bands[$band] = [
                'cases' => (int) ($row['cases'] ?? 0),
                'winner' => $row['winner'] ?? null,
                'margin' => $row['margin'] ?? null,
                'validity' => (string) ($row['validity'] ?? self::VALIDITY_INSUFFICIENT),
                'validity_reason' => (string) ($row['validity_reason'] ?? ''),
                'routing_effect' => 'none',
            ];
        }

        $highestObserved = null;
        $highestValid = null;
        foreach (array_reverse(self::DIFFICULTY_BANDS) as $band) {
            if (($bands[$band]['cases'] ?? 0) > 0 && $highestObserved === null) {
                $highestObserved = $band;
            }
            if (($bands[$band]['validity'] ?? null) === self::VALIDITY_VALID && $highestValid === null) {
                $highestValid = $band;
            }
        }

        $l5 = $bands['L5'] ?? [
            'cases' => 0,
            'winner' => null,
            'margin' => null,
            'validity' => self::VALIDITY_INSUFFICIENT,
            'validity_reason' => 'missing_l5_cases',
            'routing_effect' => 'none',
        ];
        $l5Differentiated = ($l5['validity'] ?? null) === self::VALIDITY_VALID
            && in_array($l5['winner'] ?? null, [
                AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS,
                AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL,
            ], true);
        $l5Tie = (int) ($l5['cases'] ?? 0) > 0
            && ($l5['winner'] ?? null) === AtlasForgeRivalsAdjudicatorService::WINNER_TIE;
        $l5ValidTie = ($l5['validity'] ?? null) === self::VALIDITY_VALID && $l5Tie;
        $validBands = array_values(array_filter(
            $bands,
            static fn (array $row): bool => ($row['validity'] ?? null) === AtlasForgeRivalsReportService::VALIDITY_VALID,
        ));
        $allValidBandsTied = $validBands !== [] && array_reduce(
            $validBands,
            static fn (bool $carry, array $row): bool => $carry && ($row['winner'] ?? null) === AtlasForgeRivalsAdjudicatorService::WINNER_TIE,
            true,
        );

        $status = match (true) {
            $l5Differentiated => 'l5_differentiates',
            $l5ValidTie => 'l5_tied_needs_extreme_pressure',
            $highestValid === null => 'needs_valid_difficulty_sample',
            default => 'difficulty_ceiling_not_reached',
        };

        return [
            'schema_version' => 'atlas.forge.rivals.difficulty_pressure.v1',
            'purpose' => 'detect_when_a_40_case_or_l5_battery_is_too_easy_to_separate_strong_runners',
            'status' => $status,
            'highest_observed_difficulty_band' => $highestObserved,
            'highest_valid_difficulty_band' => $highestValid,
            'l5_cases' => (int) ($l5['cases'] ?? 0),
            'l5_winner' => $l5['winner'] ?? null,
            'l5_margin' => $l5['margin'] ?? null,
            'l5_validity' => (string) ($l5['validity'] ?? self::VALIDITY_INSUFFICIENT),
            'l5_tie_is_diagnostic_not_claim' => $l5Tie,
            'all_valid_bands_tied' => $allValidBandsTied,
            'difficulty_ceiling_reached' => $l5Differentiated,
            'requires_harder_followup' => ! $l5Differentiated,
            'recommended_case_sets' => [
                'ceiling-360',
                'extreme-differentiator',
                'meta-provider-stress',
                'statistical-repeat',
            ],
            'bands' => $bands,
            'advisory_only' => true,
            'never_changes_atlas_decide_topology' => true,
            'should_update_provider_topology' => false,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'note' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $caseResults
     * @return array<string,mixed>
     */
    private function buildHumanPromptCoverage(array $caseResults): array
    {
        $total = count($caseResults);
        $present = 0;
        $complete = 0;
        $missingSections = [];
        $longContext = 0;
        $assumptionLog = 0;
        $scopeReasoning = 0;

        foreach ($caseResults as $case) {
            $contract = is_array($case['human_prompt_contract'] ?? null) ? (array) $case['human_prompt_contract'] : [];
            if ($contract === []) {
                continue;
            }
            $present++;
            if (($contract['complete'] ?? false) === true) {
                $complete++;
            }
            if (($contract['long_context_required'] ?? false) === true) {
                $longContext++;
            }
            if (($contract['requires_assumption_log'] ?? false) === true) {
                $assumptionLog++;
            }
            if (($contract['requires_scope_boundary_reasoning'] ?? false) === true) {
                $scopeReasoning++;
            }
            foreach ($this->stringList($contract['missing_sections'] ?? []) as $section) {
                $missingSections[$section] = ($missingSections[$section] ?? 0) + 1;
            }
        }

        ksort($missingSections);

        return [
            'schema_version' => 'atlas.forge.rivals.human_prompt_contract_coverage.v1',
            'case_count' => $total,
            'cases_with_contract' => $present,
            'complete_contract_cases' => $complete,
            'incomplete_contract_cases' => max(0, $present - $complete),
            'coverage_ratio' => $total > 0 ? round($present / $total, 4) : 0.0,
            'complete_ratio' => $present > 0 ? round($complete / $present, 4) : 0.0,
            'long_context_required_cases' => $longContext,
            'assumption_log_required_cases' => $assumptionLog,
            'scope_boundary_reasoning_required_cases' => $scopeReasoning,
            'missing_sections' => $missingSections,
            'advisory_only' => true,
            'routing_effect' => 'none',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $capabilityResults
     * @return array<string,mixed>
     */
    private function buildCapabilityCoverage(array $capabilityResults): array
    {
        $observed = [];
        foreach ($capabilityResults as $row) {
            $key = trim((string) ($row['key'] ?? ''));
            if ($key === '' || $key === 'unknown') {
                continue;
            }
            $observed[$key] = [
                'cases' => (int) ($row['cases'] ?? 0),
                'validity' => (string) ($row['validity'] ?? self::VALIDITY_INSUFFICIENT),
                'winner' => $row['winner'] ?? null,
                'margin' => $row['margin'] ?? null,
                'routing_effect' => 'none',
            ];
        }
        ksort($observed);

        $missing = [];
        $underSampled = [];
        foreach (self::REQUIRED_360_CAPABILITIES as $capability) {
            $cases = (int) ($observed[$capability]['cases'] ?? 0);
            if ($cases <= 0) {
                $missing[] = $capability;
            } elseif ($cases < self::MIN_CASES_PER_CAPABILITY_SIGNAL) {
                $underSampled[$capability] = $cases;
            }
        }
        $separation = $this->buildCapabilitySeparation($observed);
        $nextMeasurementPlan = $this->buildCapabilityMeasurementPlan($missing, $underSampled);

        return [
            'schema_version' => 'atlas.forge.rivals.capability_coverage.v1',
            'required_capabilities' => self::REQUIRED_360_CAPABILITIES,
            'min_cases_per_capability_signal' => self::MIN_CASES_PER_CAPABILITY_SIGNAL,
            'observed_capabilities' => $observed,
            'observed_capability_count' => count($observed),
            'missing_required_capabilities' => $missing,
            'under_sampled_required_capabilities' => $underSampled,
            'separation' => $separation,
            'next_measurement_plan' => $nextMeasurementPlan,
            'recommended_case_sets' => [
                'ceiling-360',
                'extreme-differentiator',
                'meta-provider-stress',
                'statistical-repeat',
            ],
            'coverage_ratio' => count(self::REQUIRED_360_CAPABILITIES) > 0
                ? round((count(self::REQUIRED_360_CAPABILITIES) - count($missing)) / count(self::REQUIRED_360_CAPABILITIES), 4)
                : 0.0,
            'floor_met' => $missing === [] && $underSampled === [],
            'advisory_only' => true,
            'routing_effect' => 'none',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $caseResults
     * @return array<string,mixed>
     */
    private function buildCeiling360ContractSignal(array $caseResults): array
    {
        $cases = [];
        $differentiated = 0;
        $atlasAhead = 0;
        $rivalAhead = 0;
        $ties = 0;
        $missing = 0;
        $floorScore = 100.0;
        $belowFloor = 0;
        $sharedMissingMarkers = [];
        $anyMissingMarkers = [];

        foreach ($caseResults as $case) {
            $dimension = data_get($case, 'quality_dimensions.ceiling_360_contract');
            if (! is_array($dimension) || ($dimension['markers']['required'] ?? false) !== true) {
                $missing++;

                continue;
            }

            $atlasMarkers = is_array($dimension['markers']['atlas'] ?? null) ? (array) $dimension['markers']['atlas'] : [];
            $rivalMarkers = is_array($dimension['markers']['rival'] ?? null) ? (array) $dimension['markers']['rival'] : [];
            $atlasScore = is_numeric($dimension['atlas'] ?? null) ? (float) $dimension['atlas'] : null;
            $rivalScore = is_numeric($dimension['rival'] ?? null) ? (float) $dimension['rival'] : null;
            $markerDelta = [];
            foreach (array_unique(array_merge(array_keys($atlasMarkers), array_keys($rivalMarkers))) as $marker) {
                $atlasHas = (bool) ($atlasMarkers[$marker] ?? false);
                $rivalHas = (bool) ($rivalMarkers[$marker] ?? false);
                if (! $atlasHas || ! $rivalHas) {
                    $anyMissingMarkers[$marker] = ($anyMissingMarkers[$marker] ?? 0) + 1;
                }
                if (! $atlasHas && ! $rivalHas) {
                    $sharedMissingMarkers[$marker] = ($sharedMissingMarkers[$marker] ?? 0) + 1;
                }
                if ($atlasHas !== $rivalHas) {
                    $markerDelta[$marker] = [
                        'atlas' => $atlasHas,
                        'rival' => $rivalHas,
                    ];
                }
            }

            $leader = null;
            if ($atlasScore !== null && $rivalScore !== null) {
                if ($atlasScore < $floorScore || $rivalScore < $floorScore) {
                    $belowFloor++;
                }
                if ($atlasScore > $rivalScore) {
                    $leader = 'atlas';
                    $atlasAhead++;
                    $differentiated++;
                } elseif ($rivalScore > $atlasScore) {
                    $leader = 'rival';
                    $rivalAhead++;
                    $differentiated++;
                } else {
                    $leader = 'tie';
                    $ties++;
                }
            }

            $cases[] = [
                'case_id' => (string) ($case['case_id'] ?? 'unknown-case'),
                'atlas_score' => $atlasScore,
                'rival_score' => $rivalScore,
                'leader' => $leader,
                'floor_met' => $atlasScore !== null && $rivalScore !== null
                    && $atlasScore >= $floorScore
                    && $rivalScore >= $floorScore,
                'marker_delta' => $markerDelta,
                'global_winner' => $case['winner'] ?? null,
                'routing_effect' => 'none',
            ];
        }

        $observed = count($cases);
        ksort($sharedMissingMarkers);
        ksort($anyMissingMarkers);
        $status = match (true) {
            $observed === 0 => 'not_observed',
            $belowFloor > 0 && $differentiated > 0 => 'differentiated_with_contract_floor_gap',
            $belowFloor > 0 => 'contract_floor_gap',
            $differentiated > 0 => 'differentiated',
            default => 'tied_at_contract_floor',
        };

        return [
            'schema_version' => 'atlas.forge.rivals.ceiling_360_contract_signal.v1',
            'purpose' => 'surface_l5_plus_contract_differences_even_when_global_score_ties',
            'status' => $status,
            'observed_cases' => $observed,
            'missing_contract_cases' => $missing,
            'differentiated_cases' => $differentiated,
            'atlas_ahead_cases' => $atlasAhead,
            'rival_ahead_cases' => $rivalAhead,
            'tie_cases' => $ties,
            'contract_floor_score' => $floorScore,
            'below_contract_floor_cases' => $belowFloor,
            'shared_missing_markers' => $sharedMissingMarkers,
            'any_missing_markers' => $anyMissingMarkers,
            'separation_ratio' => $observed > 0 ? round($differentiated / $observed, 4) : 0.0,
            'tie_is_diagnostic_not_claim' => true,
            'advisory_only' => true,
            'never_changes_atlas_decide_topology' => true,
            'should_update_provider_topology' => false,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'note' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
            'cases' => $cases,
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $observed
     * @return array<string,mixed>
     */
    private function buildCapabilitySeparation(array $observed): array
    {
        $rows = [];
        $differentiated = [];
        $tied = [];
        $insufficient = [];
        foreach (self::REQUIRED_360_CAPABILITIES as $capability) {
            $row = (array) ($observed[$capability] ?? []);
            $cases = (int) ($row['cases'] ?? 0);
            $winner = (string) ($row['winner'] ?? '');
            $margin = is_numeric($row['margin'] ?? null) ? abs((float) $row['margin']) : null;
            $validity = (string) ($row['validity'] ?? self::VALIDITY_INSUFFICIENT);
            $state = 'missing';

            if ($cases > 0 && ($validity !== self::VALIDITY_VALID || $cases < self::MIN_CASES_PER_CAPABILITY_SIGNAL)) {
                $state = 'insufficient_sample';
                $insufficient[] = $capability;
            } elseif ($cases > 0 && in_array($winner, [AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS, AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL], true)) {
                $state = 'differentiated';
                $differentiated[] = $capability;
            } elseif ($cases > 0) {
                $state = 'tied_or_human_review';
                $tied[] = $capability;
            }

            $rows[$capability] = [
                'cases' => $cases,
                'state' => $state,
                'winner' => $winner !== '' ? $winner : null,
                'margin' => $margin,
                'validity' => $validity,
                'routing_effect' => 'none',
            ];
        }

        return [
            'schema_version' => 'atlas.forge.rivals.capability_separation.v1',
            'purpose' => 'diagnose_which_capabilities_actually_separate_runners_after_ties',
            'differentiated_capabilities' => $differentiated,
            'tied_or_human_review_capabilities' => $tied,
            'insufficient_sample_capabilities' => $insufficient,
            'missing_capabilities' => array_values(array_filter(
                self::REQUIRED_360_CAPABILITIES,
                static fn (string $capability): bool => (int) ($rows[$capability]['cases'] ?? 0) <= 0,
            )),
            'separation_ratio' => count(self::REQUIRED_360_CAPABILITIES) > 0
                ? round(count($differentiated) / count(self::REQUIRED_360_CAPABILITIES), 4)
                : 0.0,
            'tie_is_diagnostic_not_claim' => true,
            'advisory_only' => true,
            'routing_effect' => 'none',
            'capabilities' => $rows,
        ];
    }

    /**
     * @param  list<string>  $missing
     * @param  array<string,int>  $underSampled
     * @return array<string,mixed>
     */
    private function buildCapabilityMeasurementPlan(array $missing, array $underSampled): array
    {
        $requirements = [];
        foreach (self::REQUIRED_360_CAPABILITIES as $capability) {
            $observed = in_array($capability, $missing, true)
                ? 0
                : (int) ($underSampled[$capability] ?? self::MIN_CASES_PER_CAPABILITY_SIGNAL);
            $additional = max(0, self::MIN_CASES_PER_CAPABILITY_SIGNAL - $observed);
            if ($additional <= 0) {
                continue;
            }
            $requirements[] = [
                'capability' => $capability,
                'observed_cases' => $observed,
                'additional_cases_needed' => $additional,
                'recommended_case_sets' => $this->caseSetsForCapability($capability),
                'routing_effect' => 'none',
            ];
        }

        return [
            'schema_version' => 'atlas.forge.rivals.capability_measurement_plan.v1',
            'status' => $requirements === [] ? 'floor_met' : 'needs_more_cases',
            'min_cases_per_capability_signal' => self::MIN_CASES_PER_CAPABILITY_SIGNAL,
            'requirements' => $requirements,
            'recommended_commands' => [],
            'advisory_only' => true,
            'routing_effect' => 'none',
        ];
    }

    /**
     * @param  array<string,mixed>  $capabilityCoverage
     * @param  list<array<string,mixed>>  $arms
     * @return list<array<string,mixed>>
     */
    private function buildNextMeasurementCommands(array $capabilityCoverage, array $arms): array
    {
        $plan = is_array($capabilityCoverage['next_measurement_plan'] ?? null)
            ? (array) $capabilityCoverage['next_measurement_plan']
            : [];
        if (($plan['status'] ?? '') !== 'needs_more_cases') {
            return [];
        }

        $caseSets = $this->stringList($capabilityCoverage['recommended_case_sets'] ?? []);
        if ($caseSets === []) {
            $caseSets = ['ceiling-360', 'extreme-differentiator', 'meta-provider-stress', 'statistical-repeat'];
        }

        $atlasModel = $this->commandModel((string) ($arms[0]['model'] ?? 'sonnet'), 'sonnet');
        $rivalModel = $this->commandRivalModel((string) ($arms[1]['model'] ?? 'claude_sonnet'));
        $commands = [];

        foreach ($caseSets as $caseSet) {
            $commands[] = [
                'id' => 'inspect_'.$this->commandSlug($caseSet),
                'purpose' => 'inspect_case_set_without_provider_call',
                'case_set' => $caseSet,
                'command' => 'php artisan atlas:forge:rivals cases --case-set='.$caseSet.' --json',
                'dry_run' => true,
                'external_provider_call' => false,
                'provider_tokens_spent' => false,
                'requires_confirmations' => false,
                'advisory_only' => true,
                'routing_effect' => 'none',
            ];
            $commands[] = [
                'id' => 'dry_run_'.$this->commandSlug($caseSet),
                'purpose' => 'plan_extreme_battery_without_provider_call',
                'case_set' => $caseSet,
                'command' => 'php artisan atlas:forge:rivals run-battery --mode=local_fake --preset='.$caseSet.' --dry-run --json',
                'dry_run' => true,
                'external_provider_call' => false,
                'provider_tokens_spent' => false,
                'requires_confirmations' => false,
                'advisory_only' => true,
                'routing_effect' => 'none',
            ];
        }

        $primaryCaseSet = $caseSets[0] ?? 'extreme-differentiator';
        $battleMatrix = $this->providerArena360BattleMatrix($primaryCaseSet);
        foreach ($battleMatrix as $battle) {
            $commands[] = [
                'id' => 'dry_run_'.$battle['battle_id'],
                'purpose' => 'plan_provider_arena_360_battle_without_provider_call',
                'case_set' => $primaryCaseSet,
                'battle_id' => $battle['battle_id'],
                'mode' => $battle['mode'],
                'arm_a' => $battle['arm_a'],
                'arm_a_model' => $battle['arm_a_model'],
                'arm_b' => $battle['arm_b'],
                'arm_b_model' => $battle['arm_b_model'],
                'command' => $battle['dry_run_command'],
                'dry_run' => true,
                'external_provider_call' => false,
                'provider_tokens_spent' => false,
                'requires_confirmations' => false,
                'advisory_only' => true,
                'routing_effect' => 'none',
            ];
        }

        $commands[] = [
            'id' => 'real_confirmed_'.$this->commandSlug($primaryCaseSet),
            'purpose' => 'run_confirmed_real_provider_battery_for_360_floor',
            'case_set' => $primaryCaseSet,
            'command' => 'php artisan atlas:forge:rivals run-battery --mode=fair --atlas-model='.$atlasModel.' --rival='.$rivalModel.' --preset='.$primaryCaseSet.' --prompt-mode=messy-real --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call --json',
            'dry_run' => false,
            'external_provider_call' => true,
            'provider_tokens_spent' => true,
            'requires_confirmations' => true,
            'required_confirmations' => [
                'confirm-runbook-reviewed',
                'confirm-provider-cost',
                'confirm-real-provider-call',
            ],
            'advisory_only' => true,
            'routing_effect' => 'none',
        ];
        foreach ($battleMatrix as $battle) {
            $commands[] = [
                'id' => 'real_confirmed_'.$battle['battle_id'],
                'purpose' => 'run_confirmed_provider_arena_360_battle',
                'case_set' => $primaryCaseSet,
                'battle_id' => $battle['battle_id'],
                'mode' => $battle['mode'],
                'arm_a' => $battle['arm_a'],
                'arm_a_model' => $battle['arm_a_model'],
                'arm_b' => $battle['arm_b'],
                'arm_b_model' => $battle['arm_b_model'],
                'command' => str_replace(' --dry-run --json', ' --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call --json', $battle['dry_run_command']),
                'dry_run' => false,
                'external_provider_call' => true,
                'provider_tokens_spent' => true,
                'requires_confirmations' => true,
                'required_confirmations' => [
                    'confirm-runbook-reviewed',
                    'confirm-provider-cost',
                    'confirm-real-provider-call',
                ],
                'advisory_only' => true,
                'routing_effect' => 'none',
            ];
        }

        return $commands;
    }

    /**
     * @return list<array<string,string>>
     */
    private function providerArena360BattleMatrix(string $caseSet): array
    {
        return array_map(
            fn (array $battle): array => [
                'battle_id' => $battle['id'],
                'mode' => $battle['mode'],
                'arm_a' => $battle['arm_a'],
                'arm_a_model' => $battle['arm_a_model'],
                'arm_b' => $battle['arm_b'],
                'arm_b_model' => $battle['arm_b_model'],
                'dry_run_command' => 'php artisan atlas:forge:rivals run-arena'
                    .' --arm-a='.$battle['arm_a'].' --arm-a-model='.$battle['arm_a_model']
                    .' --arm-b='.$battle['arm_b'].' --arm-b-model='.$battle['arm_b_model']
                    .' --mode='.$battle['mode']
                    .' --case-set='.$caseSet
                    .' --prompt-mode=enterprise-change'
                    .' --dry-run --json',
            ],
            $this->canonicalProviderArena360Battles(),
        );
    }

    /**
     * @return list<array{id:string,mode:string,arm_a:string,arm_a_model:string,arm_b:string,arm_b_model:string}>
     */
    private function canonicalProviderArena360Battles(): array
    {
        return [
            [
                'id' => 'atlas_forge_vs_claude_sonnet',
                'mode' => 'provider_arena',
                'arm_a' => 'atlas_forge',
                'arm_a_model' => 'sonnet',
                'arm_b' => 'claude_code',
                'arm_b_model' => 'sonnet',
            ],
            [
                'id' => 'atlas_dev_vs_atlas_forge',
                'mode' => 'provider_arena',
                'arm_a' => 'atlas_dev',
                'arm_a_model' => 'sonnet',
                'arm_b' => 'atlas_forge',
                'arm_b_model' => 'sonnet',
            ],
            [
                'id' => 'composer_2_5_vs_codex_gpt_5_5',
                'mode' => 'provider_arena',
                'arm_a' => 'composer_2_5',
                'arm_a_model' => 'default',
                'arm_b' => 'codex_cli',
                'arm_b_model' => 'gpt-5.5',
            ],
            [
                'id' => 'cursor_default_vs_claude_sonnet',
                'mode' => 'provider_arena',
                'arm_a' => 'cursor_cli',
                'arm_a_model' => 'default',
                'arm_b' => 'claude_code',
                'arm_b_model' => 'sonnet',
            ],
            [
                'id' => 'claude_sonnet_vs_codex_gpt_5_5',
                'mode' => 'provider_arena',
                'arm_a' => 'claude_code',
                'arm_a_model' => 'sonnet',
                'arm_b' => 'codex_cli',
                'arm_b_model' => 'gpt-5.5',
            ],
            [
                'id' => 'codex_gpt_5_5_vs_gemini_pro',
                'mode' => 'provider_arena',
                'arm_a' => 'codex_cli',
                'arm_a_model' => 'gpt-5.5',
                'arm_b' => 'gemini_cli',
                'arm_b_model' => 'gemini-pro',
            ],
            [
                'id' => 'claude_sonnet_vs_claude_opus',
                'mode' => 'provider_arena',
                'arm_a' => 'claude_code',
                'arm_a_model' => 'sonnet',
                'arm_b' => 'claude_code',
                'arm_b_model' => 'opus',
            ],
            [
                'id' => 'atlas_forge_full_power_vs_claude_opus',
                'mode' => 'full_power',
                'arm_a' => 'atlas_forge',
                'arm_a_model' => 'sonnet',
                'arm_b' => 'claude_code',
                'arm_b_model' => 'opus',
            ],
        ];
    }

    private function commandModel(string $model, string $fallback): string
    {
        $model = strtolower(trim($model));
        if ($model === '' || $model === 'unknown' || $model === 'auto') {
            return $fallback;
        }

        return str_replace('claude_', '', $model);
    }

    private function commandRivalModel(string $model): string
    {
        $model = strtolower(trim($model));
        if ($model === '' || $model === 'unknown' || $model === 'auto') {
            return 'claude_sonnet';
        }
        if (in_array($model, ['sonnet', 'opus'], true)) {
            return 'claude_'.$model;
        }

        return $model;
    }

    private function commandSlug(string $value): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($value))) ?: 'case_set';

        return trim($slug, '_') ?: 'case_set';
    }

    /**
     * @return list<string>
     */
    private function caseSetsForCapability(string $capability): array
    {
        return match ($capability) {
            'rollback_safety',
            'scope_boundary_discipline',
            'honest_blocker_behavior',
            'replayable_evidence_quality' => ['ceiling-360', 'extreme-differentiator', 'meta-provider-stress'],
            'long_context_retention',
            'multi_step_reasoning',
            'ambiguous_human_prompt_handling' => ['ceiling-360', 'meta-provider-stress', 'extreme-differentiator', 'statistical-repeat'],
            default => ['ceiling-360', 'extreme-differentiator', 'meta-provider-stress', 'statistical-repeat'],
        };
    }

    /**
     * @param  list<array<string,mixed>>  $caseResults
     * @return array<string,mixed>
     */
    private function buildComplexityProfileCoverage(array $caseResults): array
    {
        $total = count($caseResults);
        $present = 0;
        $longContext = 0;
        $evidenceMatrix = 0;
        $contextTokens = [];
        $reasoningDepths = [];
        $reasoningDepthDistribution = [];
        $measuredDimensions = [];
        $taskCategories = [];
        $ambiguityScoreDistribution = [];
        $riskScoreDistribution = [];
        $highAmbiguity = 0;
        $criticalOrHighRisk = 0;
        $multiStepPlan = 0;
        $rollbackPlan = 0;
        $l5PlusPressure = 0;
        $adversarialConstraints = 0;
        $nonObviousRegression = 0;
        $honestUncertaintyBoundary = 0;
        $scopeSurfaces = [];

        foreach ($caseResults as $case) {
            $profile = is_array($case['complexity_profile'] ?? null)
                ? (array) $case['complexity_profile']
                : [];
            if (($profile['schema_version'] ?? null) !== 'atlas.forge.rivals.case_complexity_profile.v1') {
                continue;
            }

            $present++;
            if (($profile['long_context_required'] ?? false) === true) {
                $longContext++;
            }
            if (($profile['requires_evidence_matrix'] ?? false) === true) {
                $evidenceMatrix++;
            }
            if (($profile['requires_multi_step_plan'] ?? false) === true) {
                $multiStepPlan++;
            }
            if (($profile['requires_rollback_plan'] ?? false) === true) {
                $rollbackPlan++;
            }
            if ((string) ($profile['pressure_level'] ?? '') === 'L5+') {
                $l5PlusPressure++;
            }
            if (($profile['requires_adversarial_constraints'] ?? false) === true) {
                $adversarialConstraints++;
            }
            if (($profile['requires_non_obvious_regression_probe'] ?? false) === true) {
                $nonObviousRegression++;
            }
            if (($profile['requires_honest_uncertainty_boundary'] ?? false) === true) {
                $honestUncertaintyBoundary++;
            }

            $category = trim((string) ($case['task_category'] ?? ''));
            if ($category !== '') {
                $taskCategories[$category] = ($taskCategories[$category] ?? 0) + 1;
            }

            $tokens = (int) ($profile['estimated_context_tokens'] ?? 0);
            if ($tokens > 0) {
                $contextTokens[] = $tokens;
            }

            $depth = (int) ($profile['reasoning_depth'] ?? 0);
            if ($depth > 0) {
                $reasoningDepths[] = $depth;
                $key = 'depth_'.$depth;
                $reasoningDepthDistribution[$key] = ($reasoningDepthDistribution[$key] ?? 0) + 1;
            }

            $ambiguity = (int) ($profile['ambiguity_score'] ?? 0);
            if ($ambiguity > 0) {
                $key = 'score_'.$ambiguity;
                $ambiguityScoreDistribution[$key] = ($ambiguityScoreDistribution[$key] ?? 0) + 1;
                if ($ambiguity >= 3) {
                    $highAmbiguity++;
                }
            }

            $risk = (int) ($profile['risk_score'] ?? 0);
            if ($risk > 0) {
                $key = 'score_'.$risk;
                $riskScoreDistribution[$key] = ($riskScoreDistribution[$key] ?? 0) + 1;
                if ($risk >= 4) {
                    $criticalOrHighRisk++;
                }
            }

            $surfaceCount = (int) ($profile['scope_surface_count'] ?? 0);
            if ($surfaceCount > 0) {
                $scopeSurfaces[] = $surfaceCount;
            }

            foreach ($this->stringList($profile['measured_dimensions'] ?? []) as $dimension) {
                $measuredDimensions[$dimension] = ($measuredDimensions[$dimension] ?? 0) + 1;
            }
        }

        ksort($taskCategories);
        ksort($reasoningDepthDistribution);
        ksort($ambiguityScoreDistribution);
        ksort($riskScoreDistribution);
        ksort($measuredDimensions);
        $domainCount = count($taskCategories);
        $metaProviderClaimFloorMet = $total >= 16
            && $present === $total
            && $domainCount >= 8
            && $longContext === $total
            && $evidenceMatrix === $total
            && $multiStepPlan === $total
            && $highAmbiguity > 0
            && $criticalOrHighRisk > 0;

        return [
            'schema_version' => 'atlas.forge.rivals.complexity_profile_coverage.v1',
            'case_count' => $total,
            'cases_with_complexity_profile' => $present,
            'coverage_ratio' => $total > 0 ? round($present / $total, 4) : 0.0,
            'domain_count' => $domainCount,
            'min_domain_count_for_meta_provider_claim' => 8,
            'task_categories' => $taskCategories,
            'long_context_required_cases' => $longContext,
            'evidence_matrix_required_cases' => $evidenceMatrix,
            'multi_step_plan_required_cases' => $multiStepPlan,
            'rollback_plan_required_cases' => $rollbackPlan,
            'l5_plus_pressure_cases' => $l5PlusPressure,
            'adversarial_constraint_cases' => $adversarialConstraints,
            'non_obvious_regression_probe_cases' => $nonObviousRegression,
            'honest_uncertainty_boundary_cases' => $honestUncertaintyBoundary,
            'min_estimated_context_tokens' => $contextTokens !== [] ? min($contextTokens) : 0,
            'avg_estimated_context_tokens' => $contextTokens !== [] ? round(array_sum($contextTokens) / count($contextTokens), 2) : 0.0,
            'max_estimated_context_tokens' => $contextTokens !== [] ? max($contextTokens) : 0,
            'max_scope_surface_count' => $scopeSurfaces !== [] ? max($scopeSurfaces) : 0,
            'max_reasoning_depth' => $reasoningDepths !== [] ? max($reasoningDepths) : 0,
            'reasoning_depth_distribution' => $reasoningDepthDistribution,
            'high_ambiguity_cases' => $highAmbiguity,
            'critical_or_high_risk_cases' => $criticalOrHighRisk,
            'ambiguity_score_distribution' => $ambiguityScoreDistribution,
            'risk_score_distribution' => $riskScoreDistribution,
            'measured_dimensions' => $measuredDimensions,
            'meta_provider_claim_floor_met' => $metaProviderClaimFloorMet,
            'advisory_only' => true,
            'routing_effect' => 'none',
        ];
    }

    /**
     * Build a per-arm measurement array. The legacy `recommendation` field is
     * retained as a neutral measured tier, not a routing instruction.
     *
     * @param  list<array<string,mixed>>  $caseResults
     * @param  array<string,mixed>  $confidence
     * @return list<array<string,mixed>>
     */
    private function buildProviderRecommendations(array $caseResults, array $arms, string $atlasModel, string $rivalModel, array $confidence): array
    {
        $armA = (array) ($arms[0] ?? []);
        $armB = (array) ($arms[1] ?? []);
        $rivalsByArm = [
            'atlas' => [
                'arm_id' => (string) ($armA['id'] ?? 'atlas'),
                'label' => (string) ($armA['label'] ?? 'Atlas Forge'),
                'provider' => $armA['provider'] ?? null,
                'model' => (string) ($armA['model'] ?? $atlasModel),
                'model_id' => $armA['model_id'] ?? null,
                'wins' => 0,
                'losses' => 0,
                'ties' => 0,
                'cases' => 0,
            ],
            'rival' => [
                'arm_id' => (string) ($armB['id'] ?? 'rival'),
                'label' => (string) ($armB['label'] ?? 'Rival baseline'),
                'provider' => $armB['provider'] ?? null,
                'model' => (string) ($armB['model'] ?? $rivalModel),
                'model_id' => $armB['model_id'] ?? null,
                'wins' => 0,
                'losses' => 0,
                'ties' => 0,
                'cases' => 0,
            ],
        ];
        foreach ($caseResults as $case) {
            $rivalsByArm['atlas']['cases']++;
            $rivalsByArm['rival']['cases']++;
            $w = $case['winner'];
            if ($w === AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS) {
                $rivalsByArm['atlas']['wins']++;
                $rivalsByArm['rival']['losses']++;
            } elseif ($w === AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL) {
                $rivalsByArm['rival']['wins']++;
                $rivalsByArm['atlas']['losses']++;
            } elseif ($w === AtlasForgeRivalsAdjudicatorService::WINNER_TIE) {
                $rivalsByArm['atlas']['ties']++;
                $rivalsByArm['rival']['ties']++;
            }
        }
        $trusted = (bool) ($confidence['is_trusted'] ?? false);
        $out = [];
        foreach ($rivalsByArm as $arm => $stats) {
            $cases = max(1, $stats['cases']);
            $winRate = $stats['wins'] / $cases;
            $measuredTier = match (true) {
                ! $trusted => 'directional_only',
                $winRate >= 0.66 => 'measured_ahead_strong',
                $winRate >= 0.45 => 'measured_competitive',
                $winRate >= 0.20 => 'measured_behind',
                default => 'measured_weak_in_this_battery',
            };
            $out[] = [
                'arm' => $arm,
                'arm_id' => $stats['arm_id'],
                'label' => $stats['label'],
                'provider' => $stats['provider'],
                'model' => $stats['model'],
                'model_id' => $stats['model_id'],
                'wins' => $stats['wins'],
                'losses' => $stats['losses'],
                'ties' => $stats['ties'],
                'cases' => $stats['cases'],
                'measured_tier' => $measuredTier,
                'recommendation' => $measuredTier,
                'routing_effect' => 'none',
                'win_rate' => round($winRate, 2),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $buckets
     * @return list<array<string,mixed>>
     */
    private function buildAxisFit(array $buckets, string $axis): array
    {
        $out = [];
        foreach ($buckets as $b) {
            $out[] = [
                'axis' => $axis,
                'key' => (string) ($b['key'] ?? ($axis === 'category' ? ($b['category'] ?? 'unknown') : 'unknown')),
                'measured_ahead' => $b['measured_ahead'] ?? null,
                'routing_effect' => 'none',
                'winner' => $b['winner'] ?? null,
                'validity' => (string) ($b['validity'] ?? self::VALIDITY_INSUFFICIENT),
                'validity_reason' => (string) ($b['validity_reason'] ?? ''),
                'cases' => (int) ($b['cases'] ?? 0),
                'margin' => $b['margin'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $caseResults
     * @param  list<array<string,mixed>>  $arms
     * @param  list<array<string,mixed>>  $suspicious
     * @param  array<string,mixed>  $complexityCoverage
     * @return list<array<string,string>>
     */
    private function buildDoNotUseWhen(array $caseResults, array $arms, array $suspicious, array $complexityCoverage, array $capabilityCoverage): array
    {
        $reasons = [];
        if ($suspicious !== []) {
            $reasons[] = [
                'condition' => 'suspicious_results_present',
                'detail' => count($suspicious).' case(s) flagged — triage required before trusting',
            ];
        }
        $invalidCases = array_filter($caseResults, static function (array $c): bool {
            $status = (string) ($c['evidence_status'] ?? '');

            return $status !== 'evidence_ok' && ! str_starts_with($status, 'case_failed:');
        });
        if ($invalidCases !== []) {
            $reasons[] = [
                'condition' => 'evidence_or_replay_incomplete',
                'detail' => count($invalidCases).' case(s) without trustable evidence',
            ];
        }
        $rivalLowScores = array_filter($caseResults, static function (array $c): bool {
            if (($c['score_source'] ?? '') === 'multi_case_deterministic_gate_rollup') {
                return false;
            }
            $rival = $c['rival_score'];
            if (! is_numeric($rival)) {
                return false;
            }

            return (float) $rival < AtlasForgeRivalsReportService::SUSPICIOUS_PROVIDER_SCORE_THRESHOLD;
        });
        $rivalModel = strtolower((string) ($arms[1]['model'] ?? ''));
        if ($rivalModel !== '' && $rivalLowScores !== []) {
            $reasons[] = [
                'condition' => 'rival_model_underperformed_unexpectedly',
                'detail' => $rivalModel.' scored <70 in '.count($rivalLowScores).' case(s) — verify harness fairness first',
            ];
        }
        $caseCount = (int) ($complexityCoverage['case_count'] ?? count($caseResults));
        $coverageRatio = (float) ($complexityCoverage['coverage_ratio'] ?? 0.0);
        if ($caseCount > 0 && $coverageRatio < 1.0) {
            $reasons[] = [
                'condition' => 'complexity_profile_coverage_incomplete',
                'detail' => 'only '.round($coverageRatio * 100, 2).'% of case(s) expose complexity_profile — do not infer heavy-programming capability',
            ];
        }
        if ($caseCount > 0 && (int) ($complexityCoverage['long_context_required_cases'] ?? 0) === 0) {
            $reasons[] = [
                'condition' => 'long_context_not_measured',
                'detail' => 'no case in this filtered signal required long context — unsuitable for meta-provider/context-window claims',
            ];
        }
        if ($caseCount > 0 && (int) ($complexityCoverage['evidence_matrix_required_cases'] ?? 0) === 0) {
            $reasons[] = [
                'condition' => 'evidence_matrix_not_measured',
                'detail' => 'no case in this filtered signal required replay/evidence matrix — unsuitable for enterprise runner claims',
            ];
        }
        if ($caseCount > 0 && (int) ($complexityCoverage['multi_step_plan_required_cases'] ?? 0) === 0) {
            $reasons[] = [
                'condition' => 'multi_step_plan_not_measured',
                'detail' => 'no case in this filtered signal required multi-step planning — unsuitable for complex programming runner claims',
            ];
        }
        if ($caseCount >= 16 && ($complexityCoverage['meta_provider_claim_floor_met'] ?? false) !== true) {
            $reasons[] = [
                'condition' => 'meta_provider_stress_floor_not_met',
                'detail' => 'case mix lacks the domain/risk/ambiguity/multi-step floor required for strong meta-provider runner claims',
            ];
        }
        if ($caseCount > 0 && ($capabilityCoverage['floor_met'] ?? false) !== true) {
            $missing = $this->stringList($capabilityCoverage['missing_required_capabilities'] ?? []);
            $underSampled = array_keys((array) ($capabilityCoverage['under_sampled_required_capabilities'] ?? []));
            $detailBits = [];
            if ($missing !== []) {
                $detailBits[] = 'missing='.implode(',', $missing);
            }
            if ($underSampled !== []) {
                $detailBits[] = 'under_sampled='.implode(',', $underSampled);
            }
            $reasons[] = [
                'condition' => 'capability_floor_not_met',
                'detail' => ($detailBits === [] ? 'capability floor incomplete' : implode('; ', $detailBits)).' — use ceiling-360/extreme-differentiator/meta-provider-stress/statistical-repeat before 360 claims',
            ];
        }

        return $reasons;
    }

    /**
     * @param  array<string,mixed>  $confidence
     * @param  list<array<string,mixed>>  $suspicious
     * @param  array<string,mixed>  $validity
     */
    private function buildFallbackHint(array $confidence, array $suspicious, array $validity): string
    {
        if (! ($validity['replay_passes'] ?? false)) {
            return 'rivals_signal_unusable_until_replay_passes';
        }
        if (! ($validity['adjudication_present'] ?? false)) {
            return 'rivals_signal_unusable_until_adjudication_runs';
        }
        if ($suspicious !== []) {
            return 'do_not_feed_decide_until_suspicious_triage_complete';
        }
        $level = (string) ($confidence['level'] ?? 'unknown');
        if ($level === self::CONFIDENCE_TRUSTED) {
            return 'rivals_signal_safe_to_consume_as_advisory_input_for_atlas_decide';
        }
        if ($level === self::CONFIDENCE_DIRECTIONAL) {
            return 'rivals_signal_directional_only_treat_as_hypothesis_not_routing_change';
        }

        return 'rivals_signal_flow_validated_only_do_not_use_for_routing';
    }

    /**
     * @param  list<array<string,mixed>>  $suspicious
     * @return array<string,mixed>
     */
    private function buildClaimStatus(
        ?string $winner,
        bool $claimReady,
        bool $humanReviewRequired,
        array $confidence,
        array $evidenceState,
        array $suspicious,
        array $matrixLock,
        array $measurementBlockers,
    ): array {
        $matrixOk = (bool) ($matrixLock['matrix_ok'] ?? true);
        $matrixBlocksClaim = (bool) ($matrixLock['blocks_claim_final'] ?? false);
        $batteryEvidenceValid = ($evidenceState['state'] ?? 'incomplete') === 'ok'
            && $matrixOk;
        $batteryInterpretable = $batteryEvidenceValid
            && ($winner !== null || $humanReviewRequired);
        $batteryValid = $batteryEvidenceValid
            && ($winner !== null)
            && ! $humanReviewRequired
            && ! $matrixBlocksClaim;

        $measurementBlockers = array_values(array_unique(array_map(
            static fn (mixed $blocker): string => trim((string) $blocker),
            $measurementBlockers,
        )));
        $measurementBlockers = array_values(array_filter(
            $measurementBlockers,
            static fn (string $blocker): bool => $blocker !== '',
        ));

        $canFeedLedger = $batteryValid && $suspicious === [] && ! $matrixBlocksClaim && $measurementBlockers === [];
        $canFeedDecideSignal = $canFeedLedger && (bool) ($confidence['is_trusted'] ?? false);

        return [
            'battery_evidence_valid' => $batteryEvidenceValid,
            'battery_result_interpretable' => $batteryInterpretable,
            'battery_result_valid' => $batteryValid,
            'battery_claim_eligible' => $batteryValid,
            'claim_ready' => $claimReady && $batteryValid && $suspicious === [] && ! $matrixBlocksClaim && (bool) ($confidence['is_trusted'] ?? false),
            'external_rivals_certification_status' => self::EXTERNAL_RIVALS_STATUS,
            'human_review_required' => $humanReviewRequired || $suspicious !== [] || $matrixBlocksClaim,
            'can_feed_ledger' => $canFeedLedger,
            'can_feed_decide_signal' => $canFeedDecideSignal,
            'ledger_blockers' => $measurementBlockers,
            'matrix_evidence_lock_ok' => $matrixOk,
            'matrix_blocks_claim_final' => $matrixBlocksClaim,
            'note' => 'battery_result_valid não implica external claim. external_rivals_certification continua BLOCKED por design.',
        ];
    }

    /**
     * Matrix Evidence Lock — enforces per-case evidence completeness, replay
     * cleanliness, and L1-L5 difficulty preservation. The block surfaces:
     *
     *   - required_artifacts_per_case canonical list (immutable canon)
     *   - per_case[] inventory: which required artifacts are present per case
     *   - invalid_cases[] with explicit reasons
     *   - difficulty_distribution (counts by L1..L5)
     *   - missing_difficulty_cases[]
     *   - replay_drift_cases[] (sub-runs that did not replay clean)
     *   - matrix_ok / blocks_claim_final flags
     *
     * Two thresholds gate the matrix:
     *   - HARD floor: any single case missing required evidence taints the
     *     matrix (matrix_ok = false). For single-case runs the floor is
     *     advisory — single-case keeps using legacy claim_status logic.
     *   - Ratio: if >10% of cases are invalid, claim_final is blocked even
     *     when battery_result_valid would otherwise hold.
     *
     * @param  list<array<string,mixed>>  $caseEntries
     * @param  list<array<string,mixed>>  $caseResults
     * @return array<string,mixed>
     */
    private function buildMatrixEvidenceLock(
        array $caseEntries,
        array $caseResults,
        bool $isMultiCase,
        bool $replayOk,
    ): array {
        $perCase = [];
        $invalidCases = [];
        $missingDifficultyCases = [];
        $replayDriftCases = [];
        $humanPromptContractCases = [];
        $difficultyCounts = [
            'L1' => 0, 'L2' => 0, 'L3' => 0, 'L4' => 0, 'L5' => 0,
            self::DIFFICULTY_UNKNOWN => 0,
        ];

        foreach ($caseResults as $idx => $case) {
            $entry = $caseEntries[$idx] ?? [];
            $artifactPaths = (array) ($case['artifact_paths'] ?? []);
            $atlasReceipt = (array) ($entry['atlas_receipt'] ?? []);
            $rivalReceipt = (array) ($entry['rival_receipt'] ?? []);
            $manifestPath = (string) ($artifactPaths['manifest'] ?? '');
            $scorecardPath = (string) ($artifactPaths['scorecard'] ?? '');
            $atlasPatchPath = (string) ($artifactPaths['atlas_patch'] ?? '');
            $rivalPatchPath = (string) ($artifactPaths['rival_patch'] ?? '');
            $atlasTestLogPath = (string) ($artifactPaths['atlas_test_log'] ?? '');
            $rivalTestLogPath = (string) ($artifactPaths['rival_test_log'] ?? '');
            $workspaceHashesPath = (string) ($artifactPaths['workspace_hashes'] ?? '');

            $required = [
                'manifest' => $manifestPath !== '' && is_file($manifestPath),
                'scorecard' => $scorecardPath !== '' && is_file($scorecardPath),
                'atlas_receipt' => $atlasReceipt !== [],
                'rival_receipt' => $rivalReceipt !== [],
                'atlas_patch' => $atlasPatchPath !== '' && is_file($atlasPatchPath),
                'rival_patch' => $rivalPatchPath !== '' && is_file($rivalPatchPath),
                'atlas_test_log' => $atlasTestLogPath !== '' && is_file($atlasTestLogPath),
                'rival_test_log' => $rivalTestLogPath !== '' && is_file($rivalTestLogPath),
                'workspace_hashes' => is_file($workspaceHashesPath),
                'difficulty_band' => in_array((string) ($case['difficulty_band'] ?? ''), self::DIFFICULTY_BANDS, true),
            ];

            $missing = array_keys(array_filter($required, static fn (bool $present): bool => ! $present));
            $reasons = [];
            foreach ($missing as $key) {
                $reasons[] = 'missing_'.$key;
            }
            $replayPasses = (bool) ($case['replay_passes'] ?? false);
            if (! $replayPasses) {
                $reasons[] = 'replay_did_not_pass';
                $replayDriftCases[] = (string) $case['case_id'];
            }
            $humanPromptContract = is_array($case['human_prompt_contract'] ?? null) ? (array) $case['human_prompt_contract'] : [];
            $measurementTags = $this->stringList($case['measurement_tags'] ?? []);
            $humanPromptContractRequired = in_array('human_prompt', $measurementTags, true)
                || in_array('assumption_probe', $measurementTags, true)
                || in_array('meta_provider_stress', $measurementTags, true);
            $humanPromptContractComplete = ($humanPromptContract['complete'] ?? false) === true;
            $humanPromptMissingSections = $this->stringList($humanPromptContract['missing_sections'] ?? []);
            if ($humanPromptContractRequired && ! $humanPromptContractComplete) {
                $reasons[] = $humanPromptContract === []
                    ? 'missing_human_prompt_contract'
                    : 'incomplete_human_prompt_contract';
            }
            $humanPromptContractCases[] = [
                'case_id' => (string) $case['case_id'],
                'required' => $humanPromptContractRequired,
                'present' => $humanPromptContract !== [],
                'complete' => $humanPromptContractComplete,
                'missing_sections' => $humanPromptMissingSections,
            ];

            $band = (string) ($case['difficulty_band'] ?? self::DIFFICULTY_UNKNOWN);
            if (! isset($difficultyCounts[$band])) {
                $difficultyCounts[self::DIFFICULTY_UNKNOWN]++;
            } else {
                $difficultyCounts[$band]++;
            }
            if ($band === self::DIFFICULTY_UNKNOWN || ! in_array($band, self::DIFFICULTY_BANDS, true)) {
                $missingDifficultyCases[] = (string) $case['case_id'];
            }

            $isValid = $missing === [] && $replayPasses && ! ($humanPromptContractRequired && ! $humanPromptContractComplete);
            $perCase[] = [
                'case_id' => $case['case_id'],
                'task_category' => $case['task_category'],
                'difficulty_band' => $band,
                'required' => $required,
                'missing' => $missing,
                'replay_passes' => $replayPasses,
                'human_prompt_contract' => [
                    'required' => $humanPromptContractRequired,
                    'present' => $humanPromptContract !== [],
                    'complete' => $humanPromptContractComplete,
                    'missing_sections' => $humanPromptMissingSections,
                ],
                'valid' => $isValid,
                'reasons' => $reasons,
            ];

            if (! $isValid) {
                $invalidCases[] = [
                    'case_id' => $case['case_id'],
                    'difficulty_band' => $band,
                    'task_category' => $case['task_category'],
                    'reasons' => $reasons,
                    'missing_artifacts' => $missing,
                ];
            }
        }

        $totalCases = count($caseResults);
        $invalidCount = count($invalidCases);
        $validCount = $totalCases - $invalidCount;
        $invalidRatio = $totalCases > 0 ? round($invalidCount / $totalCases, 3) : 0.0;
        $humanPromptRequiredCount = count(array_filter(
            $humanPromptContractCases,
            static fn (array $case): bool => ($case['required'] ?? false) === true,
        ));
        $humanPromptCompleteCount = count(array_filter(
            $humanPromptContractCases,
            static fn (array $case): bool => ($case['required'] ?? false) === true && ($case['complete'] ?? false) === true,
        ));
        $humanPromptIncompleteCases = array_values(array_filter(
            $humanPromptContractCases,
            static fn (array $case): bool => ($case['required'] ?? false) === true && ($case['complete'] ?? false) !== true,
        ));
        // In multi-case mode any single invalid case fails the matrix (hard floor).
        // In single-case mode the matrix is informational only — claim_status falls
        // back to legacy single-case semantics.
        $matrixOk = $isMultiCase
            ? ($invalidCount < self::MATRIX_INVALID_HARD_FLOOR)
            : true;
        $blocksClaimFinal = $isMultiCase && (
            $invalidCount >= self::MATRIX_INVALID_HARD_FLOOR
            || $invalidRatio > self::MATRIX_INVALID_RATIO_BLOCK
            || $missingDifficultyCases !== []
            || $replayDriftCases !== []
            || ! $replayOk
        );

        return [
            'schema_version' => 'atlas.forge.rivals.matrix_evidence_lock.v1',
            'required_artifacts_per_case' => self::REQUIRED_CASE_ARTIFACTS,
            'invalid_ratio_threshold' => self::MATRIX_INVALID_RATIO_BLOCK,
            'invalid_hard_floor' => self::MATRIX_INVALID_HARD_FLOOR,
            'is_multi_case' => $isMultiCase,
            'total_cases' => $totalCases,
            'valid_cases' => $validCount,
            'invalid_cases_count' => $invalidCount,
            'invalid_cases' => $invalidCases,
            'invalid_ratio' => $invalidRatio,
            'difficulty_distribution' => $difficultyCounts,
            'difficulty_canon' => self::DIFFICULTY_BANDS,
            'missing_difficulty_cases' => array_values(array_unique($missingDifficultyCases)),
            'replay_drift_cases' => array_values(array_unique($replayDriftCases)),
            'human_prompt_contract_lock' => [
                'schema_version' => 'atlas.forge.rivals.matrix_human_prompt_contract_lock.v1',
                'required_cases' => $humanPromptRequiredCount,
                'complete_cases' => $humanPromptCompleteCount,
                'incomplete_cases_count' => count($humanPromptIncompleteCases),
                'incomplete_cases' => $humanPromptIncompleteCases,
                'matrix_blocks_when_required_contract_incomplete' => true,
                'advisory_only' => true,
                'routing_effect' => 'none',
            ],
            'per_case' => $perCase,
            'matrix_ok' => $matrixOk,
            'blocks_claim_final' => $blocksClaimFinal,
            'rule_summary' => $isMultiCase
                ? '40-case battery: cada case precisa de manifest, scorecard, dois receipts, dois patches, dois test logs, workspace hashes e difficulty_band em L1-L5. Falha de competidor é dado medido; só evidência ausente/replay drift bloqueia claim.'
                : 'single-case run: matrix lock advisory; claim flows through legacy single-case validity.',
        ];
    }

    /**
     * @param  array<string,mixed>  $overall
     * @param  array<string,mixed>  $confidence
     * @param  array<string,mixed>  $validity
     * @param  list<array<string,mixed>>  $suspicious
     * @param  array<string,mixed>  $manifest
     */
    private function buildHeadline(array $overall, array $confidence, array $validity, array $suspicious, array $manifest): string
    {
        $verdict = (string) ($validity['verdict'] ?? '');
        if (str_starts_with($verdict, 'invalid')) {
            return 'Resultado inconclusivo: evidência inválida ('.$verdict.').';
        }
        if (! ($validity['replay_passes'] ?? false)) {
            return 'Resultado inconclusivo: replay falhou — evidência não confiável.';
        }
        if (! ($validity['adjudication_present'] ?? false)) {
            return 'Resultado inconclusivo: adjudicação ausente — rode `atlas:forge:rivals adjudicate`.';
        }
        if (! ($validity['hard_failures_clean'] ?? false)) {
            return 'Resultado inconclusivo: hard failures bloqueiam claim.';
        }
        if ($suspicious !== []) {
            return 'Resultado inconclusivo: '.count($suspicious).' caso(s) suspeito(s) — triagem obrigatória.';
        }
        $level = (string) ($confidence['level'] ?? '');
        $atlasWins = (int) ($overall['atlas_category_wins'] ?? 0);
        $rivalWins = (int) ($overall['rival_category_wins'] ?? 0);
        $ties = (int) ($overall['category_ties'] ?? 0);
        $cats = $atlasWins + $rivalWins + $ties;

        if ($cats === 0) {
            return 'Resultado inconclusivo: sem categorias com winner.';
        }
        if ($overall['winner'] === AtlasForgeRivalsAdjudicatorService::WINNER_TIE) {
            return sprintf(
                'Empate técnico: Atlas %s vs Rival %s, confiança %s.',
                $this->formatScore($overall['atlas_score']),
                $this->formatScore($overall['rival_score']),
                $level,
            );
        }
        if ($level === self::CONFIDENCE_TRUSTED) {
            return sprintf(
                'Bateria release trusted: Atlas venceu %d categoria(s), Rival venceu %d, empate em %d.',
                $atlasWins,
                $rivalWins,
                $ties,
            );
        }
        if ($cats > 1) {
            return sprintf(
                'Resultado por categoria — Atlas: %d, Rival: %d, empate: %d (confiança %s).',
                $atlasWins,
                $rivalWins,
                $ties,
                $level,
            );
        }
        $catWinner = (string) ($overall['winner'] ?? 'sem winner');
        $preset = (string) ($manifest['preset'] ?? 'unknown');
        if ($preset === 'quick') {
            return sprintf(
                'Run quick: harness validado, vencedor do caso = %s. Não declara superioridade global.',
                $catWinner,
            );
        }

        return sprintf(
            'Vencedor do caso: %s (confiança %s, %d caso(s)).',
            $catWinner,
            $level,
            (int) ($overall['cases_total'] ?? 0),
        );
    }

    /**
     * Canonical English verdict marker line — preserved for downstream tools
     * and v2-era assertions. Coexists with the pt-BR headline above.
     *
     * @param  list<mixed>  $hardFailures
     * @param  array<string,mixed>  $scorecard
     */
    private function buildVerdictMarker(
        string $verdict,
        bool $replayOk,
        array $hardFailures,
        ?string $winner,
        array $scorecard,
        ?string $declaredWhy = null,
    ): string {
        $isInvalid = str_starts_with($verdict, 'invalid');
        $scoreSource = (string) ($scorecard['score_source'] ?? '');
        $gateWinner = $scorecard['gate_winner'] ?? null;
        $gateOutcome = $scoreSource === 'gate_outcome'
            && in_array($gateWinner, [AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS, AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL], true);
        $multiCaseGateOutcome = ($scoreSource === 'multi_case_deterministic_gate_rollup'
            || str_starts_with((string) $declaredWhy, 'multi_case_gate_winner:'))
            && in_array($winner, [AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS, AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL], true);

        return match (true) {
            $gateOutcome => '**Verdict:** GATE WINNER = '.($gateWinner === AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS ? 'Atlas Forge' : 'Rival baseline').' · QUALITY SCORE = N/A · ZERO external claim · external claim blocked',
            $multiCaseGateOutcome => '**Verdict:** MEASURED WINNER = '.($winner === AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS ? 'Atlas Forge' : 'Rival baseline').' · CASE FAILURES PRESENT · QUALITY SCORE = N/A · ZERO external claim · external claim blocked',
            $isInvalid => '**Verdict:** INVALID · `'.$verdict.'` · ZERO claim · score=null',
            ! $replayOk => '**Verdict:** REPLAY FAILED · evidence pack untrustworthy · ZERO claim',
            $hardFailures !== [] => '**Verdict:** HARD-FAIL · '.count($hardFailures).' gate(s) failed · ZERO claim',
            $winner === AtlasForgeRivalsAdjudicatorService::WINNER_TIE => '**Verdict:** TIE · human review required',
            $winner === AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS => '**Verdict:** WINNER = Atlas Forge',
            $winner === AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL => '**Verdict:** WINNER = Rival baseline',
            default => '**Verdict:** UNKNOWN',
        };
    }

    private function formatScore(mixed $score): string
    {
        if (! is_numeric($score)) {
            return 'null';
        }

        return number_format((float) $score, 1, '.', '');
    }

    /**
     * @param  list<array<string,mixed>>  $arms
     * @param  list<array<string,mixed>>  $caseResults
     * @param  list<array<string,mixed>>  $categoryResults
     * @param  array<string,mixed>  $confidence
     * @param  array<string,mixed>  $costTime
     * @param  array<string,mixed>  $validity
     * @param  list<array<string,mixed>>  $suspicious
     */
    private function buildExecutiveSummary(
        string $preset,
        array $arms,
        array $caseResults,
        array $categoryResults,
        array $confidence,
        array $costTime,
        array $validity,
        array $suspicious,
    ): string {
        $atlasModel = $arms[0]['model'] ?? 'unknown';
        $rivalModel = $arms[1]['model'] ?? 'unknown';
        $cases = count($caseResults);
        $cats = count($categoryResults);
        $reasonNot100 = $this->reasonNotFullScore($validity, $suspicious, $confidence, $cases, $cats, $preset);

        return sprintf(
            'Preset %s, %d caso(s) em %d categoria(s). Atlas (%s) vs Rival (%s). Confiança %s (%s). %s',
            $preset,
            $cases,
            $cats,
            $atlasModel,
            $rivalModel,
            $confidence['level'] ?? 'unknown',
            $confidence['reason'] ?? 'unknown',
            $reasonNot100,
        );
    }

    /**
     * @param  array<string,mixed>  $validity
     * @param  list<array<string,mixed>>  $suspicious
     * @param  array<string,mixed>  $confidence
     */
    private function reasonNotFullScore(array $validity, array $suspicious, array $confidence, int $cases, int $cats, string $preset): string
    {
        if (! ($validity['replay_passes'] ?? false)) {
            return 'Confiança limitada porque replay falhou.';
        }
        if (! ($validity['hard_failures_clean'] ?? false)) {
            return 'Confiança limitada porque hard failures aparecem em ao menos um caso.';
        }
        if ($suspicious !== []) {
            return sprintf('Confiança limitada porque %d caso(s) levantaram suspeita.', count($suspicious));
        }
        if (($confidence['level'] ?? '') !== self::CONFIDENCE_TRUSTED) {
            if ($preset === 'quick') {
                return 'Run quick prova apenas o harness, não declara superioridade global.';
            }
            if ($cases < 12 || $cats < 8) {
                return sprintf('Bateria abaixo do release trusted (%d/12 casos, %d/8 categorias).', $cases, $cats);
            }
        }

        return 'Bateria release trusted alcançada.';
    }

    /**
     * @param  array<string,mixed>  $validity
     * @param  array<string,mixed>  $confidence
     * @param  list<array<string,mixed>>  $suspicious
     * @param  list<string>  $ledgerBlockers
     * @return list<array<string,string>>
     */
    private function buildNextActions(
        string $runId,
        array $validity,
        bool $adjudicationMissing,
        bool $replayOk,
        array $confidence,
        bool $humanReviewRequired,
        array $suspicious,
        bool $canFeedLedger,
        array $ledgerBlockers,
    ): array {
        $actions = [];
        $verdict = (string) ($validity['verdict'] ?? '');
        if (str_starts_with($verdict, 'invalid')) {
            $actions[] = [
                'kind' => 'invalid_run',
                'reason' => 'manifest_verdict_invalid',
                'command' => 'php artisan atlas:forge:rivals reset --run-id='.$runId.' --reason=invalid_run --json',
            ];

            return $actions;
        }
        if ($adjudicationMissing) {
            $actions[] = [
                'kind' => 'run_adjudicate',
                'reason' => 'scorecard_missing',
                'command' => 'php artisan atlas:forge:rivals adjudicate --run-id='.$runId.' --json',
            ];
        }
        if (! $replayOk) {
            $actions[] = [
                'kind' => 'run_replay',
                'reason' => 'replay_did_not_pass',
                'command' => 'php artisan atlas:forge:rivals replay --run-id='.$runId.' --json',
            ];
        }
        if ($humanReviewRequired) {
            $actions[] = [
                'kind' => 'human_review',
                'reason' => 'statistical_tie_or_suspicious',
                'command' => 'open '.$runId.'/evidence (revisar diffs/logs)',
            ];
        }
        if ($suspicious !== []) {
            $actions[] = [
                'kind' => 'triage_suspicious',
                'reason' => 'suspicious_results_present',
                'command' => 'php artisan atlas:forge:rivals report --run-id='.$runId.' --json --strict # ler suspicious_results[]',
            ];
        }
        $level = (string) ($confidence['level'] ?? '');
        if ($level === self::CONFIDENCE_FLOW_VALIDATED && empty($actions)) {
            $actions[] = [
                'kind' => 'expand_battery',
                'reason' => 'flow_validated_only',
                'command' => 'php artisan atlas:forge:rivals run-battery --preset=release --json',
            ];
        }
        if ($actions === [] && $ledgerBlockers !== []) {
            $actions[] = [
                'kind' => 'resolve_ledger_blockers',
                'reason' => implode(',', $ledgerBlockers),
                'command' => 'php artisan atlas:forge:rivals report --run-id='.$runId
                    .' --json --strict # revisar claim_status.ledger_blockers[]',
            ];
        }
        if ($actions === [] && $canFeedLedger) {
            $actions[] = [
                'kind' => 'feed_ledger',
                'reason' => 'battery_valid_consider_ledger_record',
                'command' => 'php artisan atlas:forge:rivals ledger-record --run-id='.$runId.' --json',
            ];
        }

        return $actions;
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return array<string,string>
     */
    private function caseArtifactPaths(array $entry): array
    {
        $sub = $entry['sub_run'] ?? null;
        if (is_array($sub)) {
            $artifactEvidence = (string) ($entry['artifact_evidence'] ?? $sub['evidence']);

            return [
                'manifest' => $sub['manifest'],
                'scorecard' => $sub['scorecard'],
                'atlas_receipt' => $artifactEvidence.'/atlas_receipt.json',
                'rival_receipt' => $artifactEvidence.'/rival_receipt.json',
                'atlas_patch' => $artifactEvidence.'/atlas_patch.diff',
                'rival_patch' => $artifactEvidence.'/rival_patch.diff',
                'atlas_test_log' => $artifactEvidence.'/atlas_test.log',
                'rival_test_log' => $artifactEvidence.'/rival_test.log',
                'workspace_hashes' => $artifactEvidence.'/workspace_hashes.json',
            ];
        }
        $paths = (array) ($entry['paths'] ?? []);

        return [
            'manifest' => (string) ($paths['manifest_json'] ?? ''),
            'scorecard' => (string) ($paths['scorecard_json'] ?? ''),
            'atlas_receipt' => (string) ($paths['evidence'] ?? '').'/atlas_receipt.json',
            'rival_receipt' => (string) ($paths['evidence'] ?? '').'/rival_receipt.json',
            'atlas_patch' => (string) ($paths['evidence'] ?? '').'/atlas_patch.diff',
            'rival_patch' => (string) ($paths['evidence'] ?? '').'/rival_patch.diff',
            'atlas_test_log' => (string) ($paths['evidence'] ?? '').'/atlas_test.log',
            'rival_test_log' => (string) ($paths['evidence'] ?? '').'/rival_test.log',
            'workspace_hashes' => (string) ($paths['evidence'] ?? '').'/workspace_hashes.json',
        ];
    }

    /**
     * @param  array<string,mixed>  $replay
     * @return array<string,mixed>
     */
    private function summariseReplay(array $replay): array
    {
        return [
            'passes' => (bool) ($replay['replay_passes'] ?? false),
            'mismatches' => (array) ($replay['mismatches'] ?? []),
            'event_count' => $replay['event_count'] ?? null,
        ];
    }

    private function inferCategoryFromCaseId(string $caseId): string
    {
        $id = strtolower($caseId);
        foreach (['frontend', 'backend', 'bugfix', 'tests', 'refactor', 'architecture', 'docs', 'performance', 'security', 'integration'] as $cat) {
            if (str_contains($id, $cat)) {
                return $cat;
            }
        }

        return 'unknown';
    }

    /**
     * @param  array<string,mixed>  $envelope
     */
    private function computeDeterministicHash(array $envelope): string
    {
        $copy = $envelope;
        unset($copy['report_path'], $copy['scorecard_path'], $copy['evidence_paths'], $copy['artifacts'], $copy['evidence']['evidence_dir']);
        $this->sortRecursive($copy);
        $json = json_encode($copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', (string) $json);
    }

    private function sortRecursive(mixed &$node): void
    {
        if (! is_array($node)) {
            return;
        }
        ksort($node);
        foreach ($node as &$v) {
            $this->sortRecursive($v);
        }
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $replay
     * @param  array<string,mixed>  $scorecard
     * @param  array<string,mixed>  $atlasReceipt
     * @param  array<string,mixed>  $rivalReceipt
     * @param  array<string,mixed>  $paths
     * @param  list<array<string,mixed>>  $arms
     * @param  list<array<string,mixed>>  $categoryResults
     * @param  list<array<string,mixed>>  $caseResults
     * @param  array<string,mixed>  $confidence
     * @param  list<array<string,mixed>>  $suspicious
     * @param  list<array<string,string>>  $nextActions
     * @param  array<string,mixed>  $atlasDecide
     * @param  array<string,mixed>  $humanReview
     */
    private function renderMarkdown(
        string $runId,
        array $manifest,
        array $replay,
        array $scorecard,
        array $atlasReceipt,
        array $rivalReceipt,
        ?string $winner,
        ?string $declaredWhy,
        bool $humanReviewRequired,
        bool $claimReady,
        array $paths,
        string $headline,
        string $executiveSummary,
        array $arms,
        array $categoryResults,
        array $difficultyResults,
        array $planningExecutionResults,
        array $providerResults,
        array $modeResults,
        array $capabilityResults,
        array $caseResults,
        array $confidence,
        array $suspicious,
        array $nextActions,
        array $atlasDecide,
        array $humanReview,
        array $providerSignal,
        array $filters,
    ): string {
        $verdict = (string) ($manifest['verdict'] ?? 'unknown');
        $hardFailures = (array) ($scorecard['hard_failures'] ?? []);
        $atlasScore = $scorecard['atlas_score'] ?? null;
        $rivalScore = $scorecard['rival_score'] ?? null;
        $threshold = $scorecard['tie_threshold'] ?? AtlasForgeRivalsAdjudicatorService::DEFAULT_TIE_THRESHOLD;
        $winnerReason = (array) ($scorecard['winner_reason'] ?? []);
        $dimensions = is_array($scorecard['quality_dimensions'] ?? null) ? $scorecard['quality_dimensions'] : [];
        $hardGates = (array) ($scorecard['hard_gates'] ?? []);
        $replayOk = (bool) ($replay['replay_passes'] ?? false);

        $statusLine = '**Status:** '.$headline;
        $verdictMarker = $this->buildVerdictMarker(
            verdict: $verdict,
            replayOk: $replayOk,
            hardFailures: $hardFailures,
            winner: $winner,
            scorecard: $scorecard,
            declaredWhy: $declaredWhy,
        );
        $scoreLine = sprintf(
            '- atlas_score: **%s** · rival_score: **%s** · threshold: %s',
            $this->formatScore($atlasScore),
            $this->formatScore($rivalScore),
            $this->formatScore($threshold),
        );
        $claimLine = sprintf(
            '- claim_ready: **%s** · winner: **%s** · external claim: **%s**',
            $claimReady ? 'true' : 'false',
            $winner ?? 'null',
            self::EXTERNAL_RIVALS_STATUS,
        );

        $catTable = $this->renderCategoryTable($categoryResults);
        $difficultyTable = $this->renderAxisTable($difficultyResults, 'Dificuldade');
        $planningTable = $this->renderAxisTable($planningExecutionResults, 'Tipo de trabalho');
        $providerTable = $this->renderAxisTable($providerResults, 'Provider/modelo');
        $modeTable = $this->renderAxisTable($modeResults, 'Modo');
        $capabilityTable = $this->renderAxisTable($capabilityResults, 'Capacidade medida');
        $signalTable = $this->renderProviderRecommendationTable($providerSignal);
        $armTable = $this->renderArmIdentityTable($arms);
        $filtersLine = $this->renderFiltersLine($filters);
        $caseTable = $this->renderCaseTable($caseResults);
        $hardGatesTable = $this->renderHardGatesTable($hardGates);
        $qualityTable = $this->renderQualityTable($dimensions);
        $patchCompareTable = $this->renderPatchCompareTable($atlasReceipt, $rivalReceipt);
        $testCompareTable = $this->renderTestCompareTable($atlasReceipt, $rivalReceipt);
        $costCompareTable = $this->renderCostCompareTable($atlasReceipt, $rivalReceipt);
        $suspiciousList = $this->renderSuspiciousList($suspicious);
        $nextActionsList = $this->renderNextActionsList($nextActions);
        $atlasDecideBlock = $this->renderAtlasDecideBlock($atlasDecide);
        $humanChecklist = $this->renderHumanChecklistFromBlock($humanReview);
        $artifactsList = $this->renderArtifactsList($paths);
        $winnerReasonBullets = $winnerReason === []
            ? '_(nenhum reportado)_'
            : implode("\n", array_map(static fn ($r): string => '- '.(string) $r, $winnerReason));

        $mode = (string) ($manifest['mode'] ?? 'unknown');
        $preset = (string) ($manifest['preset'] ?? 'unknown');
        $atlasModel = $arms[0]['model'] ?? 'unknown';
        $rivalModel = $arms[1]['model'] ?? 'unknown';
        $caseId = (string) ($manifest['case_id'] ?? 'unknown');
        $declaredWhyLine = $declaredWhy !== null ? '`'.$declaredWhy.'`' : '`unknown`';
        $confidenceLine = sprintf(
            '- confiança: **%s** (%s) · casos: %d · categorias: %d · suspeitos: %d',
            (string) ($confidence['level'] ?? 'unknown'),
            (string) ($confidence['reason'] ?? 'unknown'),
            (int) ($confidence['cases'] ?? 0),
            (int) ($confidence['categories'] ?? 0),
            (int) ($confidence['suspicious_count'] ?? 0),
        );
        $hardFailLine = $hardFailures === []
            ? '_(nenhum)_'
            : implode("\n", array_map(static fn ($f): string => '- `'.(string) $f.'`', $hardFailures));

        return <<<MD
# Atlas Forge Rivals · Relatório Final (v3)

{$statusLine}

{$verdictMarker}

## Resumo Executivo

{$executiveSummary}

- **Run id:** `{$runId}`
- **Preset:** {$preset}
- **Modo:** {$mode}
- **Atlas:** {$atlasModel}
- **Rival:** {$rivalModel}
- **Caso principal:** {$caseId}
{$scoreLine}
{$claimLine}
- declared_why: {$declaredWhyLine}
{$confidenceLine}

## Arms Medidos

{$armTable}

## Resultado por Categoria

{$catTable}

## Resultado por Dificuldade (L1–L5)

{$difficultyTable}

## Planejamento vs Execução

{$planningTable}

## Provider / Modelo

{$providerTable}

## Atlas Forge fair vs full_power

{$modeTable}

## Capacidade Medida (360)

{$capabilityTable}

## Medição por Arm (signal v1)

{$signalTable}

{$filtersLine}

## Casos

{$caseTable}

## Por que esse resultado

{$winnerReasonBullets}

## Hard Gates

{$hardGatesTable}

## Hard Failures

{$hardFailLine}

## Suspeitas

{$suspiciousList}

## Dimensões de Qualidade

{$qualityTable}

## Comparativo de Patch

{$patchCompareTable}

## Comparativo de Testes

{$testCompareTable}

## Custo · Tempo · Provider

{$costCompareTable}

## Sinal medido para Atlas Decide (advisory_only=true)

{$atlasDecideBlock}

## Checklist Humano

{$humanChecklist}

## Próximas Ações

{$nextActionsList}

## Artefatos

{$artifactsList}

## Replay

- passa: **{$this->bool($replayOk)}**
- mismatches: {$this->renderInlineList((array) ($replay['mismatches'] ?? []))}
- event_count: {$this->intOrNull($replay['event_count'] ?? null)}

## Canon

- `separated_from_external_rivals_certification` ⇒ **true**
- **Este relatório NÃO destrava `external_rivals_certification`.** O claim externo permanece operator-approval-gated.
- Adjudicator é determinístico e local. Nenhum LLM julgou esta run.
- Evidência/replay/escopo inválidos ⇒ ZERO claim, score=null. Falha unilateral de teste ⇒ gate_winner apenas, sem quality score, claim_ready=false.

MD;
    }

    /**
     * @param  list<array<string,mixed>>  $arms
     */
    private function renderArmIdentityTable(array $arms): string
    {
        if ($arms === []) {
            return '_(nenhum arm medido)_';
        }
        $rows = [
            '| Papel | Arm | Provider | Modelo | Model id | Runner |',
            '| --- | --- | --- | --- | --- | --- |',
        ];
        foreach ($arms as $arm) {
            $rows[] = sprintf(
                '| %s | %s | %s | %s | %s | %s |',
                (string) ($arm['role'] ?? $arm['legacy_role'] ?? $arm['id'] ?? 'unknown'),
                (string) ($arm['label'] ?? $arm['id'] ?? 'unknown'),
                (string) ($arm['provider'] ?? 'unknown'),
                (string) ($arm['model'] ?? 'unknown'),
                (string) ($arm['model_id'] ?? 'unknown'),
                (string) ($arm['runner_type'] ?? 'unknown'),
            );
        }

        return implode("\n", $rows);
    }

    /**
     * @param  list<array<string,mixed>>  $categoryResults
     */
    private function renderCategoryTable(array $categoryResults): string
    {
        if ($categoryResults === []) {
            return '_(nenhuma categoria avaliada)_';
        }
        $rows = ['| Categoria | Casos | Atlas | Rival | Vencedor | Margem | Confiança |', '| --- | ---: | ---: | ---: | --- | ---: | --- |'];
        foreach ($categoryResults as $cat) {
            $rows[] = sprintf(
                '| %s | %d | %s | %s | %s | %s | %s |',
                (string) $cat['category'],
                (int) $cat['cases'],
                $this->formatScore($cat['atlas_score']),
                $this->formatScore($cat['rival_score']),
                $cat['winner'] ?? 'sem vencedor',
                $this->formatScore($cat['margin']),
                (string) $cat['confidence'],
            );
        }

        return implode("\n", $rows);
    }

    /**
     * @param  list<array<string,mixed>>  $caseResults
     */
    private function renderCaseTable(array $caseResults): string
    {
        if ($caseResults === []) {
            return '_(nenhum caso)_';
        }
        $rows = ['| Caso | Categoria | Dif. | Tipo | Modo | Vencedor | Atlas | Rival | Evidência | Replay | Suspeita |', '| --- | --- | :---: | --- | --- | --- | ---: | ---: | --- | --- | :---: |'];
        foreach ($caseResults as $case) {
            $rows[] = sprintf(
                '| `%s` | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s |',
                (string) $case['case_id'],
                (string) $case['task_category'],
                (string) ($case['difficulty_band'] ?? self::DIFFICULTY_UNKNOWN),
                (string) ($case['work_kind'] ?? 'unknown'),
                (string) ($case['mode'] ?? 'unknown'),
                $case['winner'] ?? 'sem vencedor',
                $this->formatScore($case['atlas_score']),
                $this->formatScore($case['rival_score']),
                (string) $case['evidence_status'],
                (string) $case['replay_status'],
                ! empty($case['suspicious']) ? '⚠' : '·',
            );
        }

        return implode("\n", $rows);
    }

    /**
     * @param  list<array<string,mixed>>  $buckets
     */
    private function renderAxisTable(array $buckets, string $axisLabel): string
    {
        if ($buckets === []) {
            return '_(nenhum dado para '.strtolower($axisLabel).')_';
        }
        $rows = [
            '| '.$axisLabel.' | Casos | Atlas | Rival | Vencedor | Margem | Validade | Razão |',
            '| --- | ---: | ---: | ---: | --- | ---: | --- | --- |',
        ];
        foreach ($buckets as $b) {
            $rows[] = sprintf(
                '| %s | %d | %s | %s | %s | %s | %s | %s |',
                (string) ($b['key'] ?? 'unknown'),
                (int) ($b['cases'] ?? 0),
                $this->formatScore($b['atlas_score']),
                $this->formatScore($b['rival_score']),
                $b['winner'] ?? 'sem vencedor',
                $this->formatScore($b['margin']),
                (string) ($b['validity'] ?? self::VALIDITY_INSUFFICIENT),
                (string) ($b['validity_reason'] ?? ''),
            );
        }

        return implode("\n", $rows);
    }

    /**
     * @param  array<string,mixed>  $signal
     */
    private function renderProviderRecommendationTable(array $signal): string
    {
        $recs = (array) ($signal['provider_measurement'] ?? $signal['provider_recommendation'] ?? []);
        if ($recs === []) {
            return '_(sem medição por arm — confiança insuficiente)_';
        }
        $rows = [
            '- schema: `'.(string) ($signal['schema_version'] ?? '').'`',
            '- advisory_only = **'.((bool) ($signal['advisory_only'] ?? true) ? 'true' : 'false').'**',
            '- never_changes_atlas_decide_topology = **'.((bool) ($signal['never_changes_atlas_decide_topology'] ?? true) ? 'true' : 'false').'**',
            '- should_update_provider_topology = **'.((bool) ($signal['should_update_provider_topology'] ?? false) ? 'true' : 'false').'**',
            '- owner_of_model_routing = `'.(string) ($signal['owner_of_model_routing'] ?? 'atlas_decide').'`',
            '- fallback_hint: '.(string) ($signal['fallback_hint'] ?? ''),
            '- nota: '.(string) ($signal['note'] ?? 'Rivals emits measured evidence; Atlas Decide decides model routing.'),
        ];
        $capabilityCoverage = is_array($signal['capability_coverage'] ?? null) ? (array) $signal['capability_coverage'] : [];
        if ($capabilityCoverage !== []) {
            $rows[] = '- capability_floor_met = **'.((bool) ($capabilityCoverage['floor_met'] ?? false) ? 'true' : 'false').'**';
            $rows[] = '- observed_capability_count = `'.(string) ($capabilityCoverage['observed_capability_count'] ?? 0).'`';
            $separation = is_array($capabilityCoverage['separation'] ?? null)
                ? (array) $capabilityCoverage['separation']
                : [];
            if ($separation !== []) {
                $rows[] = '- capability_separation_ratio = `'.(string) ($separation['separation_ratio'] ?? 0).'`';
                $rows[] = '- tie_is_diagnostic_not_claim = **'.((bool) ($separation['tie_is_diagnostic_not_claim'] ?? true) ? 'true' : 'false').'**';
            }
            $missing = $this->stringList($capabilityCoverage['missing_required_capabilities'] ?? []);
            if ($missing !== []) {
                $rows[] = '- missing_required_capabilities: `'.implode('`, `', $missing).'`';
            }
            $plan = is_array($capabilityCoverage['next_measurement_plan'] ?? null)
                ? (array) $capabilityCoverage['next_measurement_plan']
                : [];
            if (($plan['status'] ?? '') === 'needs_more_cases') {
                $requirements = is_array($plan['requirements'] ?? null) ? (array) $plan['requirements'] : [];
                $rows[] = '- next_measurement_plan: `needs_more_cases`';
                foreach (array_slice($requirements, 0, 5) as $requirement) {
                    if (! is_array($requirement)) {
                        continue;
                    }
                    $rows[] = sprintf(
                        '  - `%s`: +%d case(s) via `%s`',
                        (string) ($requirement['capability'] ?? 'unknown'),
                        (int) ($requirement['additional_cases_needed'] ?? 0),
                        implode('`, `', $this->stringList($requirement['recommended_case_sets'] ?? [])),
                    );
                }
                $commands = is_array($plan['recommended_commands'] ?? null) ? (array) $plan['recommended_commands'] : [];
                foreach (array_slice($commands, 0, 4) as $command) {
                    if (! is_array($command)) {
                        continue;
                    }
                    $rows[] = sprintf(
                        '  - command `%s`: `%s`',
                        (string) ($command['id'] ?? 'next_measurement'),
                        (string) ($command['command'] ?? ''),
                    );
                }
            }
        }
        $difficultyPressure = is_array($signal['difficulty_pressure'] ?? null) ? (array) $signal['difficulty_pressure'] : [];
        if ($difficultyPressure !== []) {
            $rows[] = '- difficulty_pressure_status = `'.(string) ($difficultyPressure['status'] ?? 'unknown').'`';
            $rows[] = '- difficulty_ceiling_reached = **'.((bool) ($difficultyPressure['difficulty_ceiling_reached'] ?? false) ? 'true' : 'false').'**';
            $rows[] = '- requires_harder_followup = **'.((bool) ($difficultyPressure['requires_harder_followup'] ?? true) ? 'true' : 'false').'**';
            $rows[] = '- highest_valid_difficulty_band = `'.(string) ($difficultyPressure['highest_valid_difficulty_band'] ?? 'none').'`';
        }
        $rows[] = '';
        $rows[] = '| Arm | Modelo | Sinal medido | Wins | Losses | Ties | Win rate |';
        $rows[] = '| --- | --- | --- | ---: | ---: | ---: | ---: |';

        foreach ($recs as $r) {
            $rows[] = sprintf(
                '| %s | %s | %s | %d | %d | %d | %s |',
                (string) ($r['label'] ?? $r['arm_id'] ?? $r['arm'] ?? ''),
                (string) ($r['model_id'] ?? $r['model'] ?? ''),
                (string) ($r['measured_tier'] ?? $r['recommendation'] ?? 'unknown'),
                (int) ($r['wins'] ?? 0),
                (int) ($r['losses'] ?? 0),
                (int) ($r['ties'] ?? 0),
                $this->formatScore($r['win_rate'] ?? null),
            );
        }
        $doNot = (array) ($signal['do_not_use_when'] ?? []);
        if ($doNot !== []) {
            $rows[] = '';
            $rows[] = '**do_not_use_when:**';
            foreach ($doNot as $entry) {
                $rows[] = '- `'.(string) ($entry['condition'] ?? '').'` — '.(string) ($entry['detail'] ?? '');
            }
        }

        return implode("\n", $rows);
    }

    /**
     * @param  array<string,?string>  $filters
     */
    private function renderFiltersLine(array $filters): string
    {
        $applied = array_filter($filters);
        if ($applied === []) {
            return '_Filtros aplicados: nenhum._';
        }
        $rows = [];
        foreach ($applied as $k => $v) {
            $rows[] = '`'.$k.'='.$v.'`';
        }

        return '_Filtros aplicados: '.implode(' · ', $rows).'_';
    }

    /**
     * @param  list<array<string,mixed>>  $suspicious
     */
    private function renderSuspiciousList(array $suspicious): string
    {
        if ($suspicious === []) {
            return '_(nenhum caso suspeito)_';
        }
        $rows = [];
        foreach ($suspicious as $s) {
            $rows[] = sprintf(
                '- `%s` — %s (atlas %s · rival %s) — %s',
                (string) $s['case_id'],
                implode(', ', (array) $s['reasons']),
                $this->formatScore($s['atlas_score']),
                $this->formatScore($s['rival_score']),
                (string) $s['recommendation'],
            );
        }

        return implode("\n", $rows);
    }

    /**
     * @param  list<array<string,string>>  $nextActions
     */
    private function renderNextActionsList(array $nextActions): string
    {
        if ($nextActions === []) {
            return '_(sem próxima ação obrigatória)_';
        }
        $rows = [];
        foreach ($nextActions as $a) {
            $rows[] = sprintf('- **%s** — %s%s', (string) $a['kind'], (string) ($a['reason'] ?? ''), isset($a['command']) ? "\n  ```\n  ".$a['command']."\n  ```" : '');
        }

        return implode("\n", $rows);
    }

    /**
     * @param  array<string,mixed>  $decide
     */
    private function renderAtlasDecideBlock(array $decide): string
    {
        $signals = (array) ($decide['measured_signal_by_category'] ?? []);
        if ($signals === []) {
            return '_(sem sinal medido — sem categorias avaliadas)_';
        }
        $rows = [
            '- advisory_only = **'.($decide['advisory_only'] ? 'true' : 'false').'**',
            '- should_update_provider_topology = **'.($decide['should_update_provider_topology'] ? 'true' : 'false').'** ('.(string) ($decide['reason_topology_not_updated'] ?? '').')',
            '- never_changes_atlas_decide_topology = **'.((bool) ($decide['never_changes_atlas_decide_topology'] ?? true) ? 'true' : 'false').'**',
            '- owner_of_model_routing = `'.(string) ($decide['owner_of_model_routing'] ?? 'atlas_decide').'`',
            '- nota: '.(string) ($decide['note'] ?? 'Rivals emits measured evidence; Atlas Decide decides model routing.'),
        ];
        $rows[] = '';
        $rows[] = '| Categoria | Medição | Vencedor | Margem | Casos | Efeito | Razão |';
        $rows[] = '| --- | --- | --- | ---: | ---: | --- | --- |';
        foreach ($signals as $signal) {
            $rows[] = sprintf(
                '| %s | %s | %s | %s | %d | %s | %s |',
                (string) ($signal['category'] ?? 'unknown'),
                (string) ($signal['measured_ahead'] ?? 'inconclusive'),
                (string) ($signal['winner'] ?? 'sem vencedor'),
                $this->formatScore($signal['margin'] ?? null),
                (int) ($signal['cases'] ?? 0),
                (string) ($signal['routing_effect'] ?? 'none'),
                (string) ($signal['reason'] ?? ''),
            );
        }

        return implode("\n", $rows);
    }

    /**
     * @param  array<string,mixed>  $humanReview
     */
    private function renderHumanChecklistFromBlock(array $humanReview): string
    {
        $list = (array) ($humanReview['checklist'] ?? []);
        if ($list === []) {
            return '_(sem itens obrigatórios)_';
        }
        $rows = [];
        foreach ($list as $item) {
            $rows[] = '- [ ] '.(string) $item;
        }

        return implode("\n", $rows);
    }

    /**
     * @param  list<array<string,mixed>>  $hardGates
     */
    private function renderHardGatesTable(array $hardGates): string
    {
        if ($hardGates === []) {
            return '_(scorecard ausente — rode `atlas:forge:rivals adjudicate --run-id=<id>` primeiro)_';
        }
        $rows = ['| Gate | Status | Detalhe |', '| --- | --- | --- |'];
        foreach ($hardGates as $row) {
            if (! is_array($row)) {
                continue;
            }
            $code = (string) ($row['code'] ?? 'unknown');
            $ok = ! empty($row['ok']);
            $detail = (string) ($row['detail'] ?? '');
            $rows[] = '| `'.$code.'` | '.($ok ? 'OK' : 'FAIL').' | '.$detail.' |';
        }

        return implode("\n", $rows);
    }

    /**
     * @param  array<string,array<string,mixed>>  $dimensions
     */
    private function renderQualityTable(array $dimensions): string
    {
        if ($dimensions === []) {
            return '_(sem scoring qualitativo — hard gate falhou ou adjudicação ausente)_';
        }
        $rows = ['| Dimensão | Atlas | Rival | Diff | Explicação |', '| --- | ---: | ---: | ---: | --- |'];
        foreach ($dimensions as $name => $d) {
            $atlas = (float) ($d['atlas'] ?? 0);
            $rival = (float) ($d['rival'] ?? 0);
            $diff = round($atlas - $rival, 1);
            $rows[] = sprintf(
                '| %s | %s | %s | %s | %s |',
                (string) $name,
                number_format($atlas, 1),
                number_format($rival, 1),
                ($diff >= 0 ? '+' : '').number_format($diff, 1),
                (string) ($d['explanation'] ?? ''),
            );
        }

        return implode("\n", $rows);
    }

    /**
     * @param  array<string,mixed>  $atlas
     * @param  array<string,mixed>  $rival
     */
    private function renderPatchCompareTable(array $atlas, array $rival): string
    {
        $rows = ['| Métrica | Atlas | Rival |', '| --- | --- | --- |'];
        $rows[] = '| patch_diff_bytes | '.(int) ($atlas['patch_diff_bytes'] ?? 0).' | '.(int) ($rival['patch_diff_bytes'] ?? 0).' |';
        $rows[] = '| changed_files | '.count((array) ($atlas['changed_files'] ?? [])).' | '.count((array) ($rival['changed_files'] ?? [])).' |';
        $rows[] = '| out_of_scope_files | '.count((array) ($atlas['out_of_scope_files'] ?? [])).' | '.count((array) ($rival['out_of_scope_files'] ?? [])).' |';
        $rows[] = '| bytecode_artifacts | '.count((array) ($atlas['bytecode_artifacts'] ?? [])).' | '.count((array) ($rival['bytecode_artifacts'] ?? [])).' |';
        $rows[] = '| patch_diff_sha256 | `'.(string) ($atlas['patch_diff_hash'] ?? '').'` | `'.(string) ($rival['patch_diff_hash'] ?? '').'` |';

        return implode("\n", $rows);
    }

    /**
     * @param  array<string,mixed>  $atlas
     * @param  array<string,mixed>  $rival
     */
    private function renderTestCompareTable(array $atlas, array $rival): string
    {
        $rows = ['| Métrica | Atlas | Rival |', '| --- | --- | --- |'];
        $rows[] = '| test_command | `'.(string) ($atlas['test_command'] ?? '').'` | `'.(string) ($rival['test_command'] ?? '').'` |';
        $rows[] = '| test_exit_code | '.(int) ($atlas['test_exit_code'] ?? -1).' | '.(int) ($rival['test_exit_code'] ?? -1).' |';
        $rows[] = '| test_log_path | `'.(string) ($atlas['test_log_path'] ?? '').'` | `'.(string) ($rival['test_log_path'] ?? '').'` |';
        $rows[] = '| test_log_sha256 | `'.(string) ($atlas['test_log_hash'] ?? '').'` | `'.(string) ($rival['test_log_hash'] ?? '').'` |';

        return implode("\n", $rows);
    }

    /**
     * @param  array<string,mixed>  $atlas
     * @param  array<string,mixed>  $rival
     */
    private function renderCostCompareTable(array $atlas, array $rival): string
    {
        $rows = ['| Métrica | Atlas | Rival |', '| --- | --- | --- |'];
        $rows[] = '| started_at | '.(string) ($atlas['started_at'] ?? '').' | '.(string) ($rival['started_at'] ?? '').' |';
        $rows[] = '| finished_at | '.(string) ($atlas['finished_at'] ?? '').' | '.(string) ($rival['finished_at'] ?? '').' |';
        $rows[] = '| exit_code | '.(int) ($atlas['exit_code'] ?? -1).' | '.(int) ($rival['exit_code'] ?? -1).' |';
        $rows[] = '| killed | '.($atlas['killed'] ?? false ? 'true' : 'false').' | '.($rival['killed'] ?? false ? 'true' : 'false').' |';
        $rows[] = '| stdout_bytes | '.(int) ($atlas['stdout_bytes'] ?? 0).' | '.(int) ($rival['stdout_bytes'] ?? 0).' |';
        $rows[] = '| stderr_bytes | '.(int) ($atlas['stderr_bytes'] ?? 0).' | '.(int) ($rival['stderr_bytes'] ?? 0).' |';
        $rows[] = '| tokens_used | '.(string) ($atlas['tokens_used'] ?? 'null').' | '.(string) ($rival['tokens_used'] ?? 'null').' |';
        $rows[] = '| token_cost | '.(string) ($atlas['token_cost'] ?? 'null').' | '.(string) ($rival['token_cost'] ?? 'null').' |';

        return implode("\n", $rows);
    }

    /**
     * @param  array<string,mixed>  $paths
     */
    private function renderArtifactsList(array $paths): string
    {
        $candidates = [
            'events_jsonl' => $paths['events_jsonl'] ?? '',
            'manifest_json' => $paths['manifest_json'] ?? '',
            'atlas_receipt' => ($paths['evidence'] ?? '').'/atlas_receipt.json',
            'rival_receipt' => ($paths['evidence'] ?? '').'/rival_receipt.json',
            'workspace_hashes' => ($paths['evidence'] ?? '').'/workspace_hashes.json',
            'atlas_patch' => ($paths['evidence'] ?? '').'/atlas_patch.diff',
            'rival_patch' => ($paths['evidence'] ?? '').'/rival_patch.diff',
            'atlas_test_log' => ($paths['evidence'] ?? '').'/atlas_test.log',
            'rival_test_log' => ($paths['evidence'] ?? '').'/rival_test.log',
            'scorecard_json' => $paths['scorecard_json'] ?? '',
            'report_md' => $paths['report_md'] ?? '',
        ];
        $rows = [];
        foreach ($candidates as $label => $path) {
            $present = is_string($path) && $path !== '' && is_file($path);
            $rows[] = '- '.$label.': `'.$path.'` '.($present ? '· presente' : '· ausente');
        }

        return implode("\n", $rows);
    }

    /**
     * @param  array<int,mixed>  $list
     */
    private function renderInlineList(array $list): string
    {
        if ($list === []) {
            return '_nenhum_';
        }

        return '`'.implode('`, `', array_map(static fn ($v): string => (string) $v, $list)).'`';
    }

    private function intOrNull(mixed $v): string
    {
        return is_int($v) ? (string) $v : 'null';
    }

    private function bool(bool $b): string
    {
        return $b ? 'true' : 'false';
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $row = json_decode((string) @file_get_contents($path), true);

        return is_array($row) ? $row : [];
    }
}
