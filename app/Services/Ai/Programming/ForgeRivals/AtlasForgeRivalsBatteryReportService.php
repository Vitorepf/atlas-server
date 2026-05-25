<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Atlas Forge Rivals · Battery Report v2.
 *
 * v1 produced an informational pass/fail rollup keyed on the L1-L5 difficulty
 * ladder. v2 keeps every v1 field (so the v1 contract tests stay green) and
 * adds the human-first multi-case ranking surface the operator asked for:
 *
 *   - global_score / winner / winner_reason / claim_ready
 *   - confidence ladder (inconclusive | flow_validated | category_signal |
 *     trusted_battery) with explicit reason codes
 *   - cases_total / cases_valid / cases_invalid counters
 *   - categories[]  (atlas_avg, rival_avg, delta, winner, confidence per
 *     category — answers "Atlas ganha em frontend? Claude ganha em
 *     planejamento?")
 *   - difficulty_bands[] (L1..L5 averages plus delta_grows_with_difficulty
 *     scaling signal — answers "quem escala melhor")
 *   - per_case_results[] (case_id, category, difficulty, atlas_score,
 *     rival_score, winner, hard_gates, evidence_status, replay_status,
 *     contamination_reason)
 *   - hard_failures[]
 *   - contaminated_game (true ⇒ any per-case hard gate or contamination)
 *   - why_score_counts / why_score_does_not_count diagnostic lists
 *   - result_valid_for_ranking flag — false the moment justice is broken
 *
 * Hard contract (v2 keeps every v1 invariant):
 *   - claim_ready=false unless every case reached `completed` AND there are
 *     no per-case hard failures AND no contamination AND confidence is at
 *     least `category_signal`. A single failed/invalid/skipped/pending case
 *     keeps the battery non-claimable, regardless of the weighted score.
 *   - external_rivals_certification remains `blocked_requires_operator_approval`
 *     in every envelope. v2 never unlocks it.
 *   - The service never invokes providers, never edits worktrees, never
 *     touches the central adjudicator hard gates.
 *   - Synthetic scores are never produced: when per-case scorecards are
 *     missing the case is surfaced as `evidence_status=missing_scorecard`
 *     with null scores and counted as invalid_for_ranking.
 *
 * Schema: atlas.forge.rivals.battery_report.v2
 *
 * Multi-case layout discovered at `runs/<run_id>/cases/<safe_case_dir>/
 * evidence/{scorecard.json,manifest.json,atlas_receipt.json,
 * rival_receipt.json}`. The same layout the v3 ReportService uses for the
 * single-run multi-case path.
 */
final class AtlasForgeRivalsBatteryReportService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.battery_report.v3';

    public const PREVIOUS_SCHEMA_VERSION_V2 = 'atlas.forge.rivals.battery_report.v2';

    public const PREVIOUS_SCHEMA_VERSION = 'atlas.forge.rivals.battery_report.v1';

    /** Confidence ladder, lowest → highest. */
    public const CONFIDENCE_INCONCLUSIVE = 'inconclusive';

    public const CONFIDENCE_FLOW_VALIDATED = 'flow_validated';

    public const CONFIDENCE_CATEGORY_SIGNAL = 'category_signal';

    public const CONFIDENCE_TRUSTED = 'trusted_battery';

    /** @var list<string> */
    public const CONFIDENCE_LADDER = [
        self::CONFIDENCE_INCONCLUSIVE,
        self::CONFIDENCE_FLOW_VALIDATED,
        self::CONFIDENCE_CATEGORY_SIGNAL,
        self::CONFIDENCE_TRUSTED,
    ];

    /** Tie threshold reused from the adjudicator (informational here). */
    public const TIE_THRESHOLD = 5.0;

    public const MAX_TECHNICAL_TIE_RATE_PER_DIFFICULTY_LEVEL = 0.55;

    public const MAX_TECHNICAL_TIES_BEFORE_REINFORCEMENT = 10;

    /** Trusted battery floors. */
    public const TRUSTED_MIN_CASES = 12;

    public const TRUSTED_MIN_CATEGORIES = 8;

    public const CATEGORY_SIGNAL_MIN_CASES = 12;

    public const CATEGORY_SIGNAL_MIN_CATEGORIES = 6;

    /**
     * Release-trusted floors. release_trusted is the v1 Scoring Sanity,
     * Fairness & Confidence top tier: stricter than `trusted_battery` because
     * it additionally requires every mandatory category and every difficulty
     * level to be exercised with a valid case.
     */
    public const RELEASE_TRUSTED_MIN_CASES = 12;

    /** @var list<string> Mandatory canonical categories for release_trusted. */
    public const RELEASE_TRUSTED_MANDATORY_CATEGORIES = [
        'planning',
        'frontend_ui',
        'backend_logic',
        'realistic_bugfix',
        'refactor',
        'test_design',
        'architecture',
        'integration_performance',
    ];

    /** @var list<string> Mandatory difficulty levels for release_trusted. */
    public const RELEASE_TRUSTED_MANDATORY_DIFFICULTIES = ['L1', 'L2', 'L3', 'L4', 'L5'];

    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $paths,
        private readonly AtlasForgeRivalsBatteryStateService $battery,
    ) {}

    /**
     * Render the battery report. When the battery does not exist for the
     * supplied run_id, returns status=blocked. Otherwise writes
     * `runs/<run_id>/evidence/battery_report.md` and returns the JSON
     * envelope.
     *
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
                'next_command' => 'php artisan atlas:forge:rivals battery-report --run-id=<id> --json',
            ];
        }
        $battery = $this->battery->load($runId);
        if ($battery === null) {
            return [
                'status' => 'blocked',
                'blockers' => ['battery_not_found:'.$runId],
                'run_id' => $runId,
                'next_command' => 'php artisan atlas:forge:rivals run-battery --preset=release --mode=fair --atlas-model=sonnet --rival=claude_sonnet --json',
            ];
        }

        $cases = (array) ($battery['cases'] ?? []);

        $categoryAggregate = $this->aggregateBy($cases, 'task_category');
        $difficultyAggregate = $this->aggregateBy($cases, 'difficulty_level');

        $scoreBreakdown = $this->weightedScoreBreakdown($cases);
        $planningScore = $this->weightedScoreFor($cases, 'planning_weight');
        $executionScore = $this->weightedScoreFor($cases, 'execution_weight');
        $difficultyScoreTotal = $this->weightedScoreFor($cases, 'difficulty_score');

        // v2 multi-case ranking surface.
        $perCaseResults = $this->buildPerCaseResults($runId, $cases);
        $categories = $this->aggregateCategoriesV2($perCaseResults);
        $difficultyBands = $this->aggregateDifficultyBandsV2($perCaseResults);
        $hardFailures = $this->collectHardFailures($perCaseResults);
        $contamination = $this->detectContamination($perCaseResults);
        $counters = $this->countCases($perCaseResults);
        $stateClaimReady = $this->isClaimReady($cases, (string) ($battery['mode'] ?? ''));
        $globalAverages = $this->computeGlobalAverages($perCaseResults);
        $separationAnalysis = $this->computeSeparationAnalysis(
            perCase: $perCaseResults,
            categories: $categories,
            difficultyBands: $difficultyBands,
            globalAverages: $globalAverages,
        );
        $extremeMeasurementPlan = $this->buildExtremeMeasurementPlan(
            perCase: $perCaseResults,
            categories: $categories,
            difficultyBands: $difficultyBands,
            separationAnalysis: $separationAnalysis,
            globalAverages: $globalAverages,
        );
        $confidence = $this->resolveConfidence(
            counters: $counters,
            categories: $categories,
            hardFailures: $hardFailures,
            contamination: $contamination,
            mode: (string) ($battery['mode'] ?? ''),
        );
        $confidence = $this->enrichConfidenceWithReleaseTrusted(
            confidence: $confidence,
            perCaseResults: $perCaseResults,
            counters: $counters,
            hardFailures: $hardFailures,
            contamination: $contamination,
            battery: $battery,
        );
        $winnerDecision = $this->resolveGlobalWinner(
            averages: $globalAverages,
            confidence: $confidence,
            hardFailures: $hardFailures,
            contamination: $contamination,
        );
        $resultValidForRanking = $hardFailures === []
            && ! $contamination['contaminated_game']
            && $counters['cases_valid'] > 0
            && in_array($confidence['level'], [self::CONFIDENCE_CATEGORY_SIGNAL, self::CONFIDENCE_TRUSTED], true);
        $claimReady = $stateClaimReady && $resultValidForRanking
            && $winnerDecision['winner'] !== null
            && $winnerDecision['winner'] !== AtlasForgeRivalsAdjudicatorService::WINNER_TIE;

        $whyScoreCounts = $this->buildWhyScoreCounts(
            confidence: $confidence,
            counters: $counters,
            categories: $categories,
            difficultyBands: $difficultyBands,
            hardFailures: $hardFailures,
            contamination: $contamination,
            resultValid: $resultValidForRanking,
        );
        $whyScoreDoesNotCount = $this->buildWhyScoreDoesNotCount(
            confidence: $confidence,
            counters: $counters,
            categories: $categories,
            hardFailures: $hardFailures,
            contamination: $contamination,
            resultValid: $resultValidForRanking,
        );

        $markdown = $this->renderMarkdown(
            battery: $battery,
            categoryAggregate: $categoryAggregate,
            difficultyAggregate: $difficultyAggregate,
            scoreBreakdown: $scoreBreakdown,
            stateClaimReady: $stateClaimReady,
            claimReady: $claimReady,
            planningScore: $planningScore,
            executionScore: $executionScore,
            difficultyScoreTotal: $difficultyScoreTotal,
            categoriesV2: $categories,
            difficultyBandsV2: $difficultyBands,
            perCaseResults: $perCaseResults,
            counters: $counters,
            hardFailures: $hardFailures,
            contamination: $contamination,
            confidence: $confidence,
            winnerDecision: $winnerDecision,
            globalAverages: $globalAverages,
            separationAnalysis: $separationAnalysis,
            extremeMeasurementPlan: $extremeMeasurementPlan,
            resultValidForRanking: $resultValidForRanking,
            whyScoreCounts: $whyScoreCounts,
            whyScoreDoesNotCount: $whyScoreDoesNotCount,
        );

        $paths = $this->paths->paths($runId);
        $evidenceDir = $paths['evidence'];
        if (! is_dir($evidenceDir)) {
            @mkdir($evidenceDir, 0o755, true);
        }
        $reportPath = $evidenceDir.'/battery_report.md';
        @file_put_contents($reportPath, $markdown);

        $envelope = [
            'status' => 'ok',
            'schema_version' => self::SCHEMA_VERSION,
            'schema_version_v2' => self::PREVIOUS_SCHEMA_VERSION_V2,
            'schema_version_v1' => self::PREVIOUS_SCHEMA_VERSION,
            'run_id' => $runId,
            'generated_at' => $this->nowIso(),
            'battery_status' => $battery['battery_status'] ?? null,
            'preset' => $battery['preset'] ?? null,
            'case_set' => $battery['case_set'] ?? null,
            'mode' => $battery['mode'] ?? null,
            'atlas_model' => $battery['atlas_model'] ?? null,
            'rival_model' => $battery['rival_model'] ?? null,
            'case_count' => (int) ($battery['case_count'] ?? count($cases)),
            'aggregate_verdict' => $battery['aggregate_verdict'] ?? null,
            // v1 surface, preserved byte-for-byte.
            'category_aggregate' => $categoryAggregate,
            'difficulty_aggregate' => $difficultyAggregate,
            'weighted_score' => $scoreBreakdown,
            'planning_score' => $planningScore,
            'execution_score' => $executionScore,
            'difficulty_score' => $difficultyScoreTotal,
            'claim_ready' => $claimReady,
            'human_review_required' => ! $claimReady,
            'external_provider_call' => (bool) ($battery['external_provider_call'] ?? false),
            'provider_tokens_spent' => (bool) ($battery['provider_tokens_spent'] ?? false),
            'separated_from_external_rivals_certification' => true,
            'report_path' => $reportPath,
            // v2 ranking surface.
            'global_score' => $globalAverages['delta'],
            'global_atlas_avg' => $globalAverages['atlas_avg'],
            'global_rival_avg' => $globalAverages['rival_avg'],
            'winner' => $winnerDecision['winner'],
            'winner_reason' => $winnerDecision['reasons'],
            'confidence' => $confidence,
            'separation_analysis' => $separationAnalysis,
            'per_level_tie_escalation' => $separationAnalysis['per_level_tie_escalation'] ?? null,
            'extreme_measurement_plan' => $extremeMeasurementPlan,
            'cases_total' => $counters['cases_total'],
            'cases_valid' => $counters['cases_valid'],
            'cases_invalid' => $counters['cases_invalid'],
            'categories' => $categories,
            'difficulty_bands' => $difficultyBands,
            'per_case_results' => $perCaseResults,
            // v3 canonical multi-case ranking field names. Aliases of the v2
            // fields above so downstream tooling can adopt either contract.
            // Adding aliases (not removing v2) keeps every v2 contract test
            // green while letting the operator-facing surface use the names
            // declared in the lockdown spec.
            'total_cases' => $counters['cases_total'],
            'case_results' => $perCaseResults,
            'category_results' => $categories,
            'difficulty_results' => $difficultyBands,
            'aggregate_score' => $globalAverages['delta'],
            'aggregate_atlas_avg' => $globalAverages['atlas_avg'],
            'aggregate_rival_avg' => $globalAverages['rival_avg'],
            'confidence_level' => $confidence['level'] ?? self::CONFIDENCE_INCONCLUSIVE,
            // v1 Scoring Sanity, Fairness & Confidence ladder surface. The
            // legacy `confidence_level` above stays unchanged; consumers that
            // want the 5-level ladder (invalid/low/medium/high/release_trusted)
            // read `confidence_level_v1`.
            'confidence_level_v1' => $confidence['level_v1']
                ?? AtlasForgeRivalsAdjudicatorService::CONFIDENCE_LEVEL_INVALID,
            'release_trusted' => (bool) ($confidence['release_trusted'] ?? false),
            'release_category_coverage' => $confidence['category_coverage'] ?? [
                'required' => self::RELEASE_TRUSTED_MANDATORY_CATEGORIES,
                'present' => [],
                'missing' => self::RELEASE_TRUSTED_MANDATORY_CATEGORIES,
                'complete' => false,
            ],
            'release_difficulty_coverage' => $confidence['difficulty_coverage'] ?? [
                'required' => self::RELEASE_TRUSTED_MANDATORY_DIFFICULTIES,
                'present' => [],
                'missing' => self::RELEASE_TRUSTED_MANDATORY_DIFFICULTIES,
                'complete' => false,
            ],
            'release_sanity_gates' => $confidence['sanity_gates'] ?? [],
            'is_multi_case' => $counters['cases_total'] > 1,
            'is_single_case' => $counters['cases_total'] === 1,
            'hard_failures' => $hardFailures,
            'contaminated_game' => $contamination['contaminated_game'],
            'contamination_reasons' => $contamination['reasons'],
            'result_valid_for_ranking' => $resultValidForRanking,
            'why_score_counts' => $whyScoreCounts,
            'why_score_does_not_count' => $whyScoreDoesNotCount,
            'safety' => [
                'external_rivals_certification_status' => 'blocked_requires_operator_approval',
                'unlocks_external_rivals_certification' => false,
                'synthetic_score_admitted' => false,
            ],
            'unlocks_external_rivals_certification' => false,
            'next_command' => $this->nextCommandFor($runId, $cases, $resultValidForRanking, $contamination, $hardFailures),
            'note' => 'Battery report v2 é diagnóstico. Nunca destrava external_rivals_certification e nunca emite score sintético. Veja confidence + why_score_does_not_count para auditoria.',
        ];

        return $envelope;
    }

    // ---------------------------------------------------------------- v1 ---

    /**
     * Aggregate per-state counters keyed by a case attribute (task_category
     * or difficulty_level). Returns ordered entries with completed/failed/
     * invalid/skipped/pending counters plus a weighted_score per group.
     *
     * @param  list<array<string,mixed>>  $cases
     * @return list<array<string,mixed>>
     */
    private function aggregateBy(array $cases, string $key): array
    {
        $buckets = [];
        foreach ($cases as $row) {
            if (! is_array($row)) {
                continue;
            }
            $bucketKey = trim((string) ($row[$key] ?? ''));
            if ($bucketKey === '') {
                $bucketKey = 'unspecified';
            }
            $bucket = $buckets[$bucketKey] ?? [
                'key' => $bucketKey,
                'case_count' => 0,
                'completed' => 0,
                'failed' => 0,
                'invalid' => 0,
                'skipped' => 0,
                'pending' => 0,
                'running' => 0,
                'completed_weight' => 0.0,
                'total_weight' => 0.0,
                'case_ids' => [],
            ];
            $state = (string) ($row['state'] ?? AtlasForgeRivalsBatteryStateService::CASE_STATE_PENDING);
            $weight = (float) ($row['difficulty_weight']
                ?? AtlasForgeRivalsProviderArenaCorpusService::difficultyLevelWeight(
                    (string) ($row['difficulty_level'] ?? '')
                ));
            $bucket['case_count']++;
            $bucket['total_weight'] += $weight;
            $bucket['case_ids'][] = (string) ($row['case_id'] ?? '');
            $stateKey = match ($state) {
                AtlasForgeRivalsBatteryStateService::CASE_STATE_COMPLETED => 'completed',
                AtlasForgeRivalsBatteryStateService::CASE_STATE_FAILED => 'failed',
                AtlasForgeRivalsBatteryStateService::CASE_STATE_INVALID => 'invalid',
                AtlasForgeRivalsBatteryStateService::CASE_STATE_SKIPPED => 'skipped',
                AtlasForgeRivalsBatteryStateService::CASE_STATE_RUNNING => 'running',
                default => 'pending',
            };
            $bucket[$stateKey]++;
            if ($stateKey === 'completed') {
                $bucket['completed_weight'] += $weight;
            }
            $buckets[$bucketKey] = $bucket;
        }

        $out = [];
        foreach ($buckets as $bucket) {
            $totalWeight = $bucket['total_weight'];
            $bucket['weighted_score_percent'] = $totalWeight > 0
                ? round(($bucket['completed_weight'] / $totalWeight) * 100, 2)
                : 0.0;
            $bucket['case_ids'] = array_values(array_filter($bucket['case_ids'], static fn (string $i): bool => $i !== ''));
            $out[] = $bucket;
        }

        if ($key === 'difficulty_level') {
            $order = AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVELS;
            usort($out, static function (array $a, array $b) use ($order): int {
                $ai = array_search($a['key'], $order, true);
                $bi = array_search($b['key'], $order, true);
                $ai = $ai === false ? PHP_INT_MAX : (int) $ai;
                $bi = $bi === false ? PHP_INT_MAX : (int) $bi;
                if ($ai !== $bi) {
                    return $ai <=> $bi;
                }

                return strcmp((string) $a['key'], (string) $b['key']);
            });
        } else {
            usort($out, static fn (array $a, array $b): int => strcmp((string) $a['key'], (string) $b['key']));
        }

        return $out;
    }

    /**
     * Compute the weighted-score breakdown for the whole battery. Score is
     * sum(completed_weights) / sum(all_weights), capped at 100. Returns
     * the breakdown so the report can render counters per L1-L5 level.
     *
     * @param  list<array<string,mixed>>  $cases
     * @return array<string,mixed>
     */
    private function weightedScoreBreakdown(array $cases): array
    {
        $totalWeight = 0.0;
        $completedWeight = 0.0;
        $byLevel = [];
        foreach (AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVELS as $level) {
            $byLevel[$level] = [
                'level' => $level,
                'weight' => AtlasForgeRivalsProviderArenaCorpusService::difficultyLevelWeight($level),
                'case_count' => 0,
                'completed' => 0,
                'completed_weight' => 0.0,
                'total_weight' => 0.0,
            ];
        }
        foreach ($cases as $row) {
            if (! is_array($row)) {
                continue;
            }
            $level = (string) ($row['difficulty_level'] ?? '');
            if ($level === '' || ! isset($byLevel[$level])) {
                $level = AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVEL_L3;
            }
            $weight = (float) ($row['difficulty_weight']
                ?? AtlasForgeRivalsProviderArenaCorpusService::difficultyLevelWeight($level));
            $byLevel[$level]['case_count']++;
            $byLevel[$level]['total_weight'] += $weight;
            $totalWeight += $weight;
            if (($row['state'] ?? '') === AtlasForgeRivalsBatteryStateService::CASE_STATE_COMPLETED) {
                $byLevel[$level]['completed']++;
                $byLevel[$level]['completed_weight'] += $weight;
                $completedWeight += $weight;
            }
        }

        return [
            'total_weight' => $totalWeight,
            'completed_weight' => $completedWeight,
            'score_percent' => $totalWeight > 0 ? round(($completedWeight / $totalWeight) * 100, 2) : 0.0,
            'by_level' => array_values($byLevel),
        ];
    }

    /**
     * Generic weighted-score computation for an arbitrary case attribute
     * (planning_weight, execution_weight, difficulty_score). Falls back to
     * `difficulty_weight` (always set by the adapter) when the requested
     * attribute is absent so the score block is never empty for a
     * matrix-corpus case-set.
     *
     * @param  list<array<string,mixed>>  $cases
     * @return array<string,mixed>
     */
    private function weightedScoreFor(array $cases, string $weightKey): array
    {
        $totalWeight = 0.0;
        $completedWeight = 0.0;
        $cap = 0;
        foreach ($cases as $row) {
            if (! is_array($row)) {
                continue;
            }
            $weight = is_numeric($row[$weightKey] ?? null)
                ? (float) $row[$weightKey]
                : (float) ($row['difficulty_weight'] ?? AtlasForgeRivalsProviderArenaCorpusService::difficultyLevelWeight(
                    (string) ($row['difficulty_level'] ?? '')
                ));
            $totalWeight += $weight;
            $cap++;
            if (($row['state'] ?? '') === AtlasForgeRivalsBatteryStateService::CASE_STATE_COMPLETED) {
                $completedWeight += $weight;
            }
        }

        return [
            'weight_key' => $weightKey,
            'case_count' => $cap,
            'total_weight' => round($totalWeight, 4),
            'completed_weight' => round($completedWeight, 4),
            'score_percent' => $totalWeight > 0 ? round(($completedWeight / $totalWeight) * 100, 2) : 0.0,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $cases
     */
    private function isClaimReady(array $cases, string $mode): bool
    {
        if ($mode === AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE) {
            return false;
        }
        if ($cases === []) {
            return false;
        }
        foreach ($cases as $row) {
            if (! is_array($row)) {
                continue;
            }
            $state = (string) ($row['state'] ?? '');
            if ($state !== AtlasForgeRivalsBatteryStateService::CASE_STATE_COMPLETED) {
                return false;
            }
        }

        return true;
    }

    // ---------------------------------------------------------------- v2 ---

    /**
     * Build the per-case enriched result list. Reads per-case scorecard.json
     * (canonical layout) and manifest.json under `runs/<id>/cases/<safe>/
     * evidence/` and falls back to per-case receipts when those are absent.
     *
     * @param  list<array<string,mixed>>  $cases
     * @return list<array<string,mixed>>
     */
    private function buildPerCaseResults(string $runId, array $cases): array
    {
        $paths = $this->paths->paths($runId);
        $base = (string) ($paths['base'] ?? '');
        $out = [];
        foreach ($cases as $row) {
            if (! is_array($row)) {
                continue;
            }
            $caseId = (string) ($row['case_id'] ?? '');
            $safeDir = AtlasForgeRivalsBatteryStateService::safeCaseDir($caseId);
            $caseBase = $base.'/cases/'.$safeDir;
            $evidenceDir = $caseBase.'/evidence';
            $scorecardPath = $evidenceDir.'/scorecard.json';
            $manifestPath = $evidenceDir.'/manifest.json';
            $reportPath = $evidenceDir.'/report.md';
            $replayPath = $evidenceDir.'/replay_manifest.json';
            $atlasReceiptPath = $evidenceDir.'/atlas_receipt.json';
            $rivalReceiptPath = $evidenceDir.'/rival_receipt.json';
            $workspaceHashesPath = $evidenceDir.'/workspace_hashes.json';
            $scorecard = $this->readJsonIfPresent($scorecardPath);
            $manifest = $this->readJsonIfPresent($manifestPath);
            $workspaceHashes = $this->readJsonIfPresent($workspaceHashesPath);
            $atlasReceipt = $this->readJsonIfPresent($atlasReceiptPath);
            $rivalReceipt = $this->readJsonIfPresent($rivalReceiptPath);

            $atlasScore = $this->readScoreFromScorecard($scorecard, 'atlas_score');
            $rivalScore = $this->readScoreFromScorecard($scorecard, 'rival_score');
            $winnerRaw = $scorecard['winner'] ?? null;
            $winner = $this->normalizeWinner($winnerRaw);
            $hardGates = is_array($scorecard['hard_gates'] ?? null) ? $scorecard['hard_gates'] : [];
            $hardFailureCodes = array_values(array_map(
                static fn ($g): string => (string) ($g['code'] ?? ''),
                array_filter($hardGates, static fn ($g): bool => is_array($g) && ($g['ok'] ?? true) === false),
            ));
            $hardFailureCodes = array_values(array_filter($hardFailureCodes, static fn (string $c): bool => $c !== ''));
            $gatesPassed = count(array_filter($hardGates, static fn ($g): bool => is_array($g) && ($g['ok'] ?? false) === true));
            $gatesFailed = count($hardFailureCodes);
            $scoreSource = (string) ($scorecard['score_source'] ?? '');

            $evidenceStatus = $this->resolveEvidenceStatus($row, $scorecard, $manifest, $evidenceDir);
            $replayStatus = $this->resolveReplayStatus($scorecard, $caseBase);
            $contaminationReason = $this->resolveCaseContamination(
                row: $row,
                scorecard: $scorecard,
                workspaceHashes: $workspaceHashes,
                atlasReceipt: $atlasReceipt,
                rivalReceipt: $rivalReceipt,
            );

            $validForRanking = $atlasScore !== null
                && $rivalScore !== null
                && $hardFailureCodes === []
                && $evidenceStatus === 'present'
                && $replayStatus === 'pass'
                && $contaminationReason === null;

            $out[] = [
                'case_id' => $caseId,
                'case_index' => (int) ($row['case_index'] ?? 0),
                'category' => $this->canonicalCategory($row),
                'task_category_legacy' => $row['task_category'] ?? null,
                'difficulty' => $row['difficulty'] ?? null,
                'difficulty_level' => (string) ($row['difficulty_level'] ?? ''),
                'difficulty_weight' => isset($row['difficulty_weight']) ? (float) $row['difficulty_weight'] : null,
                'state' => (string) ($row['state'] ?? 'unknown'),
                'verdict' => $row['verdict'] ?? null,
                'attempts' => (int) ($row['attempts'] ?? 0),
                'atlas_score' => $atlasScore,
                'rival_score' => $rivalScore,
                'score_source' => $scoreSource !== '' ? $scoreSource : null,
                'winner' => $winner,
                'hard_gates' => [
                    'passed_count' => $gatesPassed,
                    'failed_count' => $gatesFailed,
                    'failed_codes' => $hardFailureCodes,
                ],
                'evidence_status' => $evidenceStatus,
                'replay_status' => $replayStatus,
                'contamination_reason' => $contaminationReason,
                'valid_for_ranking' => $validForRanking,
                // v3 — per-case audit-friendly paths so the operator and
                // downstream tooling can audit each case independently
                // without re-deriving the run path layout. Paths are
                // declared as absolute strings; `*_present` flags indicate
                // whether the file is actually on disk at render time.
                'paths' => [
                    'case_dir' => $caseBase,
                    'evidence_dir' => $evidenceDir,
                    'scorecard_path' => $scorecardPath,
                    'scorecard_present' => is_file($scorecardPath),
                    'manifest_path' => $manifestPath,
                    'manifest_present' => is_file($manifestPath),
                    'report_path' => $reportPath,
                    'report_present' => is_file($reportPath),
                    'replay_manifest_path' => $replayPath,
                    'replay_manifest_present' => is_file($replayPath),
                    'atlas_receipt_path' => $atlasReceiptPath,
                    'atlas_receipt_present' => is_file($atlasReceiptPath),
                    'rival_receipt_path' => $rivalReceiptPath,
                    'rival_receipt_present' => is_file($rivalReceiptPath),
                    'workspace_hashes_path' => $workspaceHashesPath,
                    'workspace_hashes_present' => is_file($workspaceHashesPath),
                ],
            ];
        }

        return $out;
    }

    /**
     * Aggregate per-category atlas vs rival from the per-case rows.
     *
     * @param  list<array<string,mixed>>  $perCase
     * @return list<array<string,mixed>>
     */
    private function aggregateCategoriesV2(array $perCase): array
    {
        $buckets = [];
        foreach ($perCase as $entry) {
            $category = (string) $entry['category'];
            if ($category === '') {
                $category = 'unspecified';
            }
            $b = $buckets[$category] ?? [
                'category_id' => $category,
                'cases_total' => 0,
                'valid_cases' => 0,
                'atlas_sum' => 0.0,
                'rival_sum' => 0.0,
                'atlas_wins' => 0,
                'rival_wins' => 0,
                'ties' => 0,
                'invalid' => 0,
                'hard_fail' => 0,
            ];
            $b['cases_total']++;
            if ($entry['valid_for_ranking']) {
                $b['valid_cases']++;
                $b['atlas_sum'] += (float) $entry['atlas_score'];
                $b['rival_sum'] += (float) $entry['rival_score'];
                switch ($entry['winner']) {
                    case AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS:
                        $b['atlas_wins']++;
                        break;
                    case AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL:
                        $b['rival_wins']++;
                        break;
                    case AtlasForgeRivalsAdjudicatorService::WINNER_TIE:
                        $b['ties']++;
                        break;
                }
            } else {
                $b['invalid']++;
                if ($entry['hard_gates']['failed_count'] > 0) {
                    $b['hard_fail']++;
                }
            }
            $buckets[$category] = $b;
        }

        $out = [];
        foreach ($buckets as $b) {
            $atlasAvg = $b['valid_cases'] > 0 ? round($b['atlas_sum'] / $b['valid_cases'], 2) : null;
            $rivalAvg = $b['valid_cases'] > 0 ? round($b['rival_sum'] / $b['valid_cases'], 2) : null;
            $delta = ($atlasAvg !== null && $rivalAvg !== null) ? round($atlasAvg - $rivalAvg, 2) : null;
            $winner = $this->winnerFromDelta($delta);
            $confidence = $this->categoryConfidence($b['valid_cases'], $b['cases_total']);
            $out[] = [
                'category_id' => $b['category_id'],
                'cases_total' => $b['cases_total'],
                'valid_cases' => $b['valid_cases'],
                'atlas_avg' => $atlasAvg,
                'rival_avg' => $rivalAvg,
                'delta' => $delta,
                'winner' => $winner,
                'confidence' => $confidence,
                'atlas_wins' => $b['atlas_wins'],
                'rival_wins' => $b['rival_wins'],
                'ties' => $b['ties'],
                'invalid_cases' => $b['invalid'],
                'hard_failed_cases' => $b['hard_fail'],
            ];
        }

        usort($out, static fn (array $a, array $b): int => strcmp((string) $a['category_id'], (string) $b['category_id']));

        return $out;
    }

    /**
     * Aggregate L1..L5 atlas vs rival from the per-case rows. Surfaces
     * `delta_grows_with_difficulty` so the operator can answer "quem
     * escala melhor em dificuldade L1-L5".
     *
     * @param  list<array<string,mixed>>  $perCase
     * @return list<array<string,mixed>>
     */
    private function aggregateDifficultyBandsV2(array $perCase): array
    {
        $levels = AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVELS;
        $byLevel = [];
        foreach ($levels as $level) {
            $byLevel[$level] = [
                'level' => $level,
                'weight' => AtlasForgeRivalsProviderArenaCorpusService::difficultyLevelWeight($level),
                'cases_total' => 0,
                'valid_cases' => 0,
                'atlas_sum' => 0.0,
                'rival_sum' => 0.0,
                'atlas_wins' => 0,
                'rival_wins' => 0,
                'ties' => 0,
                'invalid' => 0,
            ];
        }
        foreach ($perCase as $entry) {
            $level = strtoupper((string) $entry['difficulty_level']);
            if ($level === '' || ! isset($byLevel[$level])) {
                $level = AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVEL_L3;
            }
            $byLevel[$level]['cases_total']++;
            if ($entry['valid_for_ranking']) {
                $byLevel[$level]['valid_cases']++;
                $byLevel[$level]['atlas_sum'] += (float) $entry['atlas_score'];
                $byLevel[$level]['rival_sum'] += (float) $entry['rival_score'];
                switch ($entry['winner']) {
                    case AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS:
                        $byLevel[$level]['atlas_wins']++;
                        break;
                    case AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL:
                        $byLevel[$level]['rival_wins']++;
                        break;
                    case AtlasForgeRivalsAdjudicatorService::WINNER_TIE:
                        $byLevel[$level]['ties']++;
                        break;
                }
            } else {
                $byLevel[$level]['invalid']++;
            }
        }

        $out = [];
        $deltas = [];
        foreach ($byLevel as $b) {
            $atlasAvg = $b['valid_cases'] > 0 ? round($b['atlas_sum'] / $b['valid_cases'], 2) : null;
            $rivalAvg = $b['valid_cases'] > 0 ? round($b['rival_sum'] / $b['valid_cases'], 2) : null;
            $delta = ($atlasAvg !== null && $rivalAvg !== null) ? round($atlasAvg - $rivalAvg, 2) : null;
            $winner = $this->winnerFromDelta($delta);
            $out[] = [
                'level' => $b['level'],
                'weight' => $b['weight'],
                'cases_total' => $b['cases_total'],
                'valid_cases' => $b['valid_cases'],
                'atlas_avg' => $atlasAvg,
                'rival_avg' => $rivalAvg,
                'delta' => $delta,
                'winner' => $winner,
                'atlas_wins' => $b['atlas_wins'],
                'rival_wins' => $b['rival_wins'],
                'ties' => $b['ties'],
                'invalid_cases' => $b['invalid'],
            ];
            if ($delta !== null) {
                $deltas[$b['level']] = $delta;
            }
        }

        $scaling = $this->deltaScaling($deltas);
        foreach ($out as &$row) {
            $row['delta_grows_with_difficulty'] = $scaling['atlas_grows'];
            $row['rival_grows_with_difficulty'] = $scaling['rival_grows'];
        }
        unset($row);

        return $out;
    }

    /**
     * Detect if the delta(level)→hardest is monotonically increasing for
     * either arm. Returns booleans for both sides — diagnostic only.
     *
     * @param  array<string,float>  $deltas  level => delta(atlas-rival)
     * @return array{atlas_grows:bool,rival_grows:bool}
     */
    private function deltaScaling(array $deltas): array
    {
        $levels = AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVELS;
        $ordered = [];
        foreach ($levels as $lv) {
            if (array_key_exists($lv, $deltas)) {
                $ordered[] = $deltas[$lv];
            }
        }
        if (count($ordered) < 2) {
            return ['atlas_grows' => false, 'rival_grows' => false];
        }
        $atlasGrows = true;
        $rivalGrows = true;
        for ($i = 1; $i < count($ordered); $i++) {
            if ($ordered[$i] <= $ordered[$i - 1]) {
                $atlasGrows = false;
            }
            if ($ordered[$i] >= $ordered[$i - 1]) {
                $rivalGrows = false;
            }
        }

        return ['atlas_grows' => $atlasGrows, 'rival_grows' => $rivalGrows];
    }

    /**
     * @param  list<array<string,mixed>>  $perCase
     * @return array{cases_total:int,cases_valid:int,cases_invalid:int}
     */
    private function countCases(array $perCase): array
    {
        $valid = 0;
        $invalid = 0;
        foreach ($perCase as $row) {
            if ($row['valid_for_ranking']) {
                $valid++;
            } else {
                $invalid++;
            }
        }

        return [
            'cases_total' => count($perCase),
            'cases_valid' => $valid,
            'cases_invalid' => $invalid,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $perCase
     * @return array{atlas_avg:?float,rival_avg:?float,delta:?float}
     */
    private function computeGlobalAverages(array $perCase): array
    {
        $atlasSum = 0.0;
        $rivalSum = 0.0;
        $n = 0;
        foreach ($perCase as $row) {
            if (! $row['valid_for_ranking']) {
                continue;
            }
            $atlasSum += (float) $row['atlas_score'];
            $rivalSum += (float) $row['rival_score'];
            $n++;
        }
        if ($n === 0) {
            return ['atlas_avg' => null, 'rival_avg' => null, 'delta' => null];
        }
        $atlasAvg = round($atlasSum / $n, 2);
        $rivalAvg = round($rivalSum / $n, 2);

        return [
            'atlas_avg' => $atlasAvg,
            'rival_avg' => $rivalAvg,
            'delta' => round($atlasAvg - $rivalAvg, 2),
        ];
    }

    /**
     * Diagnose whether a battery can actually separate runners. A tie can be
     * a true result, but a high tie rate plus tiny deltas usually means the
     * benchmark is too easy, the scoring is too coarse, or both arms are being
     * evaluated on tasks that do not expose their specialities.
     *
     * @param  list<array<string,mixed>>  $perCase
     * @param  list<array<string,mixed>>  $categories
     * @param  list<array<string,mixed>>  $difficultyBands
     * @param  array{atlas_avg:?float,rival_avg:?float,delta:?float}  $globalAverages
     * @return array<string,mixed>
     */
    private function computeSeparationAnalysis(
        array $perCase,
        array $categories,
        array $difficultyBands,
        array $globalAverages,
    ): array {
        $valid = array_values(array_filter(
            $perCase,
            static fn (array $row): bool => (bool) ($row['valid_for_ranking'] ?? false),
        ));
        $validCount = count($valid);
        $tieCount = 0;
        $deltas = [];
        foreach ($valid as $row) {
            $winner = (string) ($row['winner'] ?? '');
            if ($winner === AtlasForgeRivalsAdjudicatorService::WINNER_TIE) {
                $tieCount++;
            }
            if (($row['atlas_score'] ?? null) !== null && ($row['rival_score'] ?? null) !== null) {
                $deltas[] = abs(round((float) $row['atlas_score'] - (float) $row['rival_score'], 2));
            }
        }

        sort($deltas);
        $averageAbsDelta = $deltas === [] ? null : round(array_sum($deltas) / count($deltas), 2);
        $maxAbsDelta = $deltas === [] ? null : round(max($deltas), 2);
        $medianAbsDelta = null;
        if ($deltas !== []) {
            $middle = intdiv(count($deltas), 2);
            $medianAbsDelta = count($deltas) % 2 === 1
                ? $deltas[$middle]
                : round(($deltas[$middle - 1] + $deltas[$middle]) / 2, 2);
        }

        $tieRate = $validCount > 0 ? round($tieCount / $validCount, 4) : null;
        $lowSeparationThreshold = self::TIE_THRESHOLD;
        $suspiciousTieRateThreshold = 0.60;
        $perLevelTieEscalation = $this->computePerLevelTieEscalation($difficultyBands);
        $reasons = [];
        if ($validCount === 0) {
            $reasons[] = 'no_valid_cases';
        }
        if ($tieRate !== null && $tieRate >= $suspiciousTieRateThreshold) {
            $reasons[] = 'high_tie_rate:'.number_format($tieRate, 2, '.', '');
        }
        if ($tieCount >= self::MAX_TECHNICAL_TIES_BEFORE_REINFORCEMENT) {
            $reasons[] = 'technical_tie_count_reached_reinforcement_threshold:'.$tieCount;
        }
        if ($averageAbsDelta !== null && $averageAbsDelta < $lowSeparationThreshold) {
            $reasons[] = 'average_abs_delta_below_tie_threshold:'.$averageAbsDelta;
        }
        if ($maxAbsDelta !== null && $maxAbsDelta < ($lowSeparationThreshold * 2)) {
            $reasons[] = 'no_large_delta_cases:max_abs_delta='.$maxAbsDelta;
        }

        $categoryWinners = array_values(array_unique(array_filter(array_map(
            static fn (array $row): string => (string) ($row['winner'] ?? ''),
            $categories,
        ), static fn (string $winner): bool => $winner !== '')));
        if (count($categoryWinners) <= 1 && $validCount >= 8) {
            $reasons[] = 'category_winners_do_not_vary';
        }

        $difficultyDeltas = array_values(array_filter(array_map(
            static fn (array $row): mixed => $row['delta'] ?? null,
            $difficultyBands,
        ), static fn (mixed $delta): bool => $delta !== null));
        if (count($difficultyDeltas) >= 2 && max($difficultyDeltas) === min($difficultyDeltas)) {
            $reasons[] = 'difficulty_deltas_flat';
        }
        foreach ((array) ($perLevelTieEscalation['levels_exceeding_tie_budget'] ?? []) as $level) {
            $reasons[] = 'difficulty_level_tie_rate_above_55_percent:'.$level;
        }

        return [
            'schema_version' => 'atlas.forge.rivals.separation_analysis.v1',
            'valid_cases' => $validCount,
            'tie_count' => $tieCount,
            'tie_rate' => $tieRate,
            'average_abs_delta' => $averageAbsDelta,
            'median_abs_delta' => $medianAbsDelta,
            'max_abs_delta' => $maxAbsDelta,
            'global_delta' => $globalAverages['delta'],
            'low_separation_threshold' => $lowSeparationThreshold,
            'suspicious_tie_rate_threshold' => $suspiciousTieRateThreshold,
            'tie_reinforcement_threshold_count' => self::MAX_TECHNICAL_TIES_BEFORE_REINFORCEMENT,
            'tie_reinforcement_required' => $tieCount >= self::MAX_TECHNICAL_TIES_BEFORE_REINFORCEMENT,
            'tie_reinforcement_action' => $tieCount >= self::MAX_TECHNICAL_TIES_BEFORE_REINFORCEMENT
                ? 'cancel_current_battery_and_increase_baseline_complexity_and_capability_measurement'
                : 'continue_sampling',
            'per_level_tie_escalation' => $perLevelTieEscalation,
            'low_discrimination' => $reasons !== [],
            'reasons' => array_values(array_unique($reasons)),
            'recommended_case_sets' => [
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_CEILING_360,
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_EXTREME_DIFFERENTIATOR,
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_META_PROVIDER_STRESS,
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_STATISTICAL_REPEAT,
            ],
            'advisory_only' => true,
            'routing_effect' => 'none',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $difficultyBands
     * @return array<string,mixed>
     */
    private function computePerLevelTieEscalation(array $difficultyBands): array
    {
        $byLevel = [];
        foreach ($difficultyBands as $band) {
            if (! is_array($band)) {
                continue;
            }
            $level = strtoupper(trim((string) ($band['level'] ?? '')));
            if ($level !== '') {
                $byLevel[$level] = $band;
            }
        }

        $rows = [];
        $levelsExceeding = [];
        foreach (AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVELS as $level) {
            $band = $byLevel[$level] ?? [];
            $validCases = (int) ($band['valid_cases'] ?? 0);
            $ties = (int) ($band['ties'] ?? 0);
            $tieRate = $validCases > 0 ? round($ties / $validCases, 4) : null;
            $exceeds = $tieRate !== null && $tieRate >= self::MAX_TECHNICAL_TIE_RATE_PER_DIFFICULTY_LEVEL;
            if ($exceeds) {
                $levelsExceeding[] = $level;
            }
            $rows[] = [
                'level' => $level,
                'valid_cases' => $validCases,
                'technical_tie_count' => $ties,
                'technical_tie_rate' => $tieRate,
                'max_allowed_technical_tie_rate' => self::MAX_TECHNICAL_TIE_RATE_PER_DIFFICULTY_LEVEL,
                'exceeds_tie_budget' => $exceeds,
                'required_action' => $exceeds
                    ? 'stop_this_level_and_increase_complexity_functions_and_capability_measurement'
                    : ($validCases === 0 ? 'collect_level_sample' : 'continue_measuring'),
            ];
        }

        return [
            'schema_version' => 'atlas.forge.rivals.per_level_tie_escalation.v1',
            'purpose' => 'keep L1-L5 difficulty adaptive as models improve by stopping levels with too many technical ties',
            'max_allowed_technical_tie_rate' => self::MAX_TECHNICAL_TIE_RATE_PER_DIFFICULTY_LEVEL,
            'levels_exceeding_tie_budget' => $levelsExceeding,
            'should_cancel_current_battery' => $levelsExceeding !== [],
            'required_action' => $levelsExceeding === []
                ? 'continue_current_level_mix'
                : 'cancel_current_battery_and_reinforce_over_tied_levels',
            'rows' => $rows,
            'provider_call' => false,
            'tokens_spent' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'note' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
        ];
    }

    /**
     * Build the next-runner difficulty plan used when a 40-case battery ties
     * or otherwise fails to separate strong runners. This is deliberately a
     * read model: it emits commands and slices to measure next, never a claim.
     *
     * @param  list<array<string,mixed>>  $perCase
     * @param  list<array<string,mixed>>  $categories
     * @param  list<array<string,mixed>>  $difficultyBands
     * @param  array<string,mixed>  $separationAnalysis
     * @param  array{atlas_avg:?float,rival_avg:?float,delta:?float}  $globalAverages
     * @return array<string,mixed>
     */
    private function buildExtremeMeasurementPlan(
        array $perCase,
        array $categories,
        array $difficultyBands,
        array $separationAnalysis,
        array $globalAverages,
    ): array {
        $lowDiscrimination = (bool) ($separationAnalysis['low_discrimination'] ?? false);
        $perLevelTieEscalation = is_array($separationAnalysis['per_level_tie_escalation'] ?? null)
            ? (array) $separationAnalysis['per_level_tie_escalation']
            : [];
        $levelsExceedingTieBudget = $this->stringList($perLevelTieEscalation['levels_exceeding_tie_budget'] ?? []);
        $tieRate = $separationAnalysis['tie_rate'] ?? null;
        $globalDelta = $globalAverages['delta'];
        $nearGlobalTie = $globalDelta === null || abs((float) $globalDelta) < self::TIE_THRESHOLD;
        $l5 = $this->findDifficultyBand($difficultyBands, 'L5');
        $l5Tied = $l5 !== null
            && (int) ($l5['valid_cases'] ?? 0) > 0
            && ($l5['winner'] ?? null) === AtlasForgeRivalsAdjudicatorService::WINNER_TIE;
        $needsFollowup = $lowDiscrimination || $nearGlobalTie || $l5Tied || $levelsExceedingTieBudget !== [];

        $categoryRows = [];
        foreach ($categories as $category) {
            $categoryId = (string) ($category['category_id'] ?? $category['category'] ?? 'unknown');
            $categoryRows[] = [
                'category' => $categoryId,
                'current_valid_cases' => (int) ($category['valid_cases'] ?? 0),
                'current_winner' => $category['winner'] ?? null,
                'current_delta' => $category['delta'] ?? null,
                'next_case_set' => $this->nextCaseSetForCategory($categoryId),
                'target_capabilities' => $this->capabilitiesForCategory($categoryId),
                'minimum_repetitions' => 3,
                'routing_effect' => 'none',
            ];
        }

        $capabilityRows = [];
        foreach ($this->extremeCapabilityMap() as $capability => $caseSets) {
            $observed = $this->observedCapabilityCases($perCase, $capability);
            $capabilityRows[] = [
                'capability' => $capability,
                'observed_cases' => $observed,
                'minimum_cases_for_signal' => 3,
                'additional_cases_needed' => max(0, 3 - $observed),
                'recommended_case_sets' => $caseSets,
                'routing_effect' => 'none',
            ];
        }

        return [
            'schema_version' => 'atlas.forge.rivals.extreme_measurement_plan.v1',
            'status' => $needsFollowup ? 'needs_extreme_followup' : 'separation_observed_continue_sampling',
            'purpose' => 'separate_real_runner_strengths_after_easy_battery_ties',
            'tie_is_diagnostic_not_claim' => true,
            'requires_harder_followup' => $needsFollowup,
            'reasons' => array_values(array_unique(array_merge(
                (array) ($separationAnalysis['reasons'] ?? []),
                $nearGlobalTie ? ['global_delta_inside_tie_threshold'] : [],
                $l5Tied ? ['l5_tie_requires_harder_cases'] : [],
                array_map(
                    static fn (string $level): string => 'reinforce_difficulty_level_above_55_percent_tie_rate:'.$level,
                    $levelsExceedingTieBudget,
                ),
            ))),
            'current_signal' => [
                'valid_cases' => (int) ($separationAnalysis['valid_cases'] ?? 0),
                'tie_rate' => $tieRate,
                'average_abs_delta' => $separationAnalysis['average_abs_delta'] ?? null,
                'max_abs_delta' => $separationAnalysis['max_abs_delta'] ?? null,
                'global_delta' => $globalDelta,
                'l5_winner' => $l5['winner'] ?? null,
                'routing_effect' => 'none',
            ],
            'per_level_tie_escalation' => $perLevelTieEscalation,
            'category_slices' => $categoryRows,
            'capability_slices' => $capabilityRows,
            'required_matchups' => $this->extremeRequiredMatchups(),
            'recommended_commands' => $this->extremeRecommendedCommands(),
            'real_runs_require_explicit_confirmation' => true,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'advisory_only' => true,
            'never_changes_atlas_decide_topology' => true,
            'should_update_provider_topology' => false,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'note' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $difficultyBands
     * @return array<string,mixed>|null
     */
    private function findDifficultyBand(array $difficultyBands, string $level): ?array
    {
        foreach ($difficultyBands as $band) {
            if ((string) ($band['level'] ?? '') === $level) {
                return $band;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function capabilitiesForCategory(string $category): array
    {
        return match ($category) {
            'planning', 'architecture' => ['long_context_retention', 'multi_step_reasoning', 'ambiguous_human_prompt_handling'],
            'realistic_bugfix' => ['honest_blocker_behavior', 'scope_boundary_discipline', 'replayable_evidence_quality'],
            'refactor' => ['long_context_retention', 'scope_boundary_discipline', 'rollback_safety'],
            'test_design' => ['replayable_evidence_quality', 'honest_blocker_behavior'],
            'integration_performance' => ['multi_step_reasoning', 'rollback_safety', 'replayable_evidence_quality'],
            'backend_logic' => ['multi_step_reasoning', 'scope_boundary_discipline'],
            'frontend_ui' => ['ambiguous_human_prompt_handling', 'scope_boundary_discipline'],
            default => ['multi_step_reasoning', 'replayable_evidence_quality'],
        };
    }

    private function nextCaseSetForCategory(string $category): string
    {
        return match ($category) {
            'planning', 'architecture' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_META_PROVIDER_STRESS,
            'realistic_bugfix' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_AMBIGUOUS_BUGS,
            'refactor' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_MULTI_DAY_REFACTORS,
            'test_design' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_STATISTICAL_REPEAT,
            'integration_performance' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_INCIDENT_RESPONSE,
            default => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_EXTREME_DIFFERENTIATOR,
        };
    }

    /**
     * @return array<string,list<string>>
     */
    private function extremeCapabilityMap(): array
    {
        return [
            'long_context_retention' => [
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_MULTI_DAY_REFACTORS,
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_META_PROVIDER_STRESS,
            ],
            'multi_step_reasoning' => [
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_EXTREME_DIFFERENTIATOR,
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_PRODUCT_SECURITY_MIGRATIONS,
            ],
            'rollback_safety' => [
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_INCIDENT_RESPONSE,
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_PRODUCT_SECURITY_MIGRATIONS,
            ],
            'scope_boundary_discipline' => [
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_EXTREME_DIFFERENTIATOR,
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_MULTI_DAY_REFACTORS,
            ],
            'replayable_evidence_quality' => [
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_STATISTICAL_REPEAT,
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_META_PROVIDER_STRESS,
            ],
            'honest_blocker_behavior' => [
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_AMBIGUOUS_BUGS,
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_META_PROVIDER_STRESS,
            ],
            'ambiguous_human_prompt_handling' => [
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_AMBIGUOUS_BUGS,
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_EXTREME_DIFFERENTIATOR,
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $perCase
     */
    private function observedCapabilityCases(array $perCase, string $capability): int
    {
        $count = 0;
        foreach ($perCase as $case) {
            $tags = array_merge(
                (array) ($case['measurement_tags'] ?? []),
                (array) data_get($case, 'complexity_profile.measured_dimensions', []),
            );
            if (in_array($capability, $tags, true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function extremeRequiredMatchups(): array
    {
        return [
            ['id' => 'atlas_dev_vs_claude_sonnet', 'mode' => 'fair', 'arm_a' => 'atlas_dev', 'arm_a_model' => 'sonnet', 'arm_b' => 'claude_code', 'arm_b_model' => 'sonnet'],
            ['id' => 'claude_sonnet_vs_codex_gpt_5_5', 'mode' => 'provider_arena', 'arm_a' => 'claude_code', 'arm_a_model' => 'sonnet', 'arm_b' => 'codex_cli', 'arm_b_model' => 'gpt-5.5'],
            ['id' => 'composer_2_5_vs_codex_gpt_5_5', 'mode' => 'provider_arena', 'arm_a' => 'composer_2_5', 'arm_a_model' => 'default', 'arm_b' => 'codex_cli', 'arm_b_model' => 'gpt-5.5'],
            ['id' => 'cursor_default_vs_claude_sonnet', 'mode' => 'provider_arena', 'arm_a' => 'cursor_cli', 'arm_a_model' => 'default', 'arm_b' => 'claude_code', 'arm_b_model' => 'sonnet'],
            ['id' => 'atlas_dev_architecture_pressure_vs_claude_sonnet', 'mode' => 'provider_arena', 'arm_a' => 'atlas_dev', 'arm_a_model' => 'sonnet', 'arm_b' => 'claude_code', 'arm_b_model' => 'sonnet'],
            ['id' => 'codex_vs_gemini', 'mode' => 'provider_arena', 'arm_a' => 'codex_cli', 'arm_a_model' => 'gpt-5.5', 'arm_b' => 'gemini_cli', 'arm_b_model' => 'gemini-pro'],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function extremeRecommendedCommands(): array
    {
        $commands = [];
        foreach ($this->extremeRequiredMatchups() as $matchup) {
            $commands[] = [
                'id' => 'dry_run_'.$matchup['id'],
                'purpose' => 'plan_extreme_matchup_without_provider_call',
                'command' => 'php artisan atlas:forge:rivals run-arena'
                    .' --arm-a='.$matchup['arm_a']
                    .' --arm-a-model='.$matchup['arm_a_model']
                    .' --arm-b='.$matchup['arm_b']
                    .' --arm-b-model='.$matchup['arm_b_model']
                    .' --mode='.$matchup['mode']
                    .' --task-category=bugfix --dry-run --json',
                'dry_run' => true,
                'external_provider_call' => false,
                'provider_tokens_spent' => false,
                'requires_confirmations' => false,
                'advisory_only' => true,
                'routing_effect' => 'none',
            ];
        }

        foreach ([
            AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_EXTREME_DIFFERENTIATOR,
            AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_META_PROVIDER_STRESS,
            AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_STATISTICAL_REPEAT,
        ] as $caseSet) {
            $commands[] = [
                'id' => 'inspect_'.$caseSet,
                'purpose' => 'inspect_harder_case_set_without_provider_call',
                'case_set' => $caseSet,
                'command' => 'php artisan atlas:forge:rivals cases --case-set='.$caseSet.' --json',
                'dry_run' => true,
                'external_provider_call' => false,
                'provider_tokens_spent' => false,
                'requires_confirmations' => false,
                'advisory_only' => true,
                'routing_effect' => 'none',
            ];
        }

        return $commands;
    }

    /**
     * @param  list<array<string,mixed>>  $perCase
     * @return list<array<string,mixed>>
     */
    private function collectHardFailures(array $perCase): array
    {
        $out = [];
        foreach ($perCase as $row) {
            foreach ((array) ($row['hard_gates']['failed_codes'] ?? []) as $code) {
                $out[] = [
                    'case_id' => $row['case_id'],
                    'category' => $row['category'],
                    'difficulty_level' => $row['difficulty_level'],
                    'code' => (string) $code,
                ];
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $perCase
     * @return array{contaminated_game:bool,reasons:list<string>,cases:list<array<string,string>>}
     */
    private function detectContamination(array $perCase): array
    {
        $reasons = [];
        $cases = [];
        foreach ($perCase as $row) {
            $reason = $row['contamination_reason'];
            if ($reason !== null) {
                $cases[] = ['case_id' => (string) $row['case_id'], 'reason' => (string) $reason];
                $reasons[] = (string) $reason;
            }
        }
        $uniqueReasons = array_values(array_unique($reasons));

        return [
            'contaminated_game' => $cases !== [],
            'reasons' => $uniqueReasons,
            'cases' => $cases,
        ];
    }

    /**
     * Confidence ladder resolution. Order: contamination/hard-fail floors
     * first, then case + category counts. Floors never lie even when
     * counts would otherwise unlock `trusted_battery`.
     *
     * @param  array{cases_total:int,cases_valid:int,cases_invalid:int}  $counters
     * @param  list<array<string,mixed>>  $categories
     * @param  list<array<string,mixed>>  $hardFailures
     * @param  array{contaminated_game:bool,reasons:list<string>,cases:list<array<string,string>>}  $contamination
     * @return array{level:string,reason:string,reasons:list<string>,is_trusted:bool}
     */
    private function resolveConfidence(
        array $counters,
        array $categories,
        array $hardFailures,
        array $contamination,
        string $mode,
    ): array {
        $reasons = [];
        if ($mode === AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE) {
            $reasons[] = 'mode:local_fake';
        }
        if ($counters['cases_total'] === 0) {
            return [
                'level' => self::CONFIDENCE_INCONCLUSIVE,
                'reason' => 'no_cases',
                'reasons' => array_values(array_unique(array_merge($reasons, ['no_cases']))),
                'is_trusted' => false,
            ];
        }
        if ($contamination['contaminated_game']) {
            return [
                'level' => self::CONFIDENCE_INCONCLUSIVE,
                'reason' => 'contaminated_game',
                'reasons' => array_values(array_unique(array_merge($reasons, ['contaminated_game'], $contamination['reasons']))),
                'is_trusted' => false,
            ];
        }
        if ($hardFailures !== []) {
            $codes = array_values(array_unique(array_map(
                static fn (array $f): string => (string) $f['code'],
                $hardFailures,
            )));
            $reasons = array_values(array_unique(array_merge($reasons, array_map(
                static fn (string $c): string => 'hard_fail:'.$c,
                $codes,
            ))));

            return [
                'level' => self::CONFIDENCE_FLOW_VALIDATED,
                'reason' => 'hard_failures_present',
                'reasons' => $reasons,
                'is_trusted' => false,
            ];
        }
        if ($counters['cases_valid'] === 0) {
            return [
                'level' => self::CONFIDENCE_INCONCLUSIVE,
                'reason' => 'no_valid_cases',
                'reasons' => array_values(array_unique(array_merge($reasons, ['no_valid_cases']))),
                'is_trusted' => false,
            ];
        }

        $categoriesWithValid = count(array_filter(
            $categories,
            static fn (array $c): bool => (int) ($c['valid_cases'] ?? 0) > 0,
        ));

        if ($counters['cases_valid'] >= self::TRUSTED_MIN_CASES
            && $categoriesWithValid >= self::TRUSTED_MIN_CATEGORIES
        ) {
            return [
                'level' => self::CONFIDENCE_TRUSTED,
                'reason' => 'cases>='.self::TRUSTED_MIN_CASES.' & categories>='.self::TRUSTED_MIN_CATEGORIES,
                'reasons' => array_values(array_unique(array_merge($reasons, [
                    'cases_valid:'.$counters['cases_valid'],
                    'categories_with_valid:'.$categoriesWithValid,
                ]))),
                'is_trusted' => true,
            ];
        }
        if ($counters['cases_valid'] >= self::CATEGORY_SIGNAL_MIN_CASES
            && $categoriesWithValid >= self::CATEGORY_SIGNAL_MIN_CATEGORIES
        ) {
            return [
                'level' => self::CONFIDENCE_CATEGORY_SIGNAL,
                'reason' => 'cases>='.self::CATEGORY_SIGNAL_MIN_CASES.' & categories>='.self::CATEGORY_SIGNAL_MIN_CATEGORIES,
                'reasons' => array_values(array_unique(array_merge($reasons, [
                    'cases_valid:'.$counters['cases_valid'],
                    'categories_with_valid:'.$categoriesWithValid,
                ]))),
                'is_trusted' => false,
            ];
        }

        return [
            'level' => self::CONFIDENCE_FLOW_VALIDATED,
            'reason' => 'below_category_signal_floor',
            'reasons' => array_values(array_unique(array_merge($reasons, [
                'cases_valid:'.$counters['cases_valid'],
                'categories_with_valid:'.$categoriesWithValid,
                'below_floor:cases<'.self::CATEGORY_SIGNAL_MIN_CASES.'_or_categories<'.self::CATEGORY_SIGNAL_MIN_CATEGORIES,
            ]))),
            'is_trusted' => false,
        ];
    }

    /**
     * Enrich the legacy confidence block with the v1 Scoring Sanity, Fairness
     * & Confidence layer: 5-level `confidence_level_v1` ladder, the
     * `release_trusted` boolean and the category/difficulty coverage maps that
     * explain the verdict. All existing fields (`level`, `reason`, `reasons`,
     * `is_trusted`) are preserved verbatim for backwards compatibility.
     *
     * release_trusted = the strictest possible signal:
     *   - already at `trusted_battery` (>= 12 valid cases, >= 8 categories),
     *   - every one of the 8 mandatory canonical categories represented by a
     *     valid case,
     *   - every difficulty level L1..L5 represented by a valid case,
     *   - no contamination, no hard failures, replay verified across the
     *     battery, evidence complete across the battery,
     *   - no dirty workspace before or after the run,
     *   - no human intervention indevida (human_assisted=true is fatal),
     *   - no provider error (kill/timeout/empty-output) across any case.
     *
     * @param  array{level:string,reason:string,reasons:list<string>,is_trusted:bool}  $confidence
     * @param  list<array<string,mixed>>  $perCaseResults
     * @param  array{cases_total:int,cases_valid:int,cases_invalid:int}  $counters
     * @param  list<array<string,mixed>>  $hardFailures
     * @param  array{contaminated_game:bool,reasons:list<string>,cases:list<array<string,string>>}  $contamination
     * @param  array<string,mixed>  $battery
     * @return array<string,mixed>
     */
    private function enrichConfidenceWithReleaseTrusted(
        array $confidence,
        array $perCaseResults,
        array $counters,
        array $hardFailures,
        array $contamination,
        array $battery,
    ): array {
        $categoryCoverage = $this->computeReleaseCategoryCoverage($perCaseResults);
        $difficultyCoverage = $this->computeReleaseDifficultyCoverage($perCaseResults);
        $sanity = $this->computeBatterySanity($perCaseResults, $hardFailures, $contamination, $battery, $counters);

        $isTrusted = (bool) ($confidence['is_trusted'] ?? false);
        $releaseTrustedReasons = [];

        if (! $isTrusted) {
            $releaseTrustedReasons[] = 'confidence_below_trusted_battery';
        }
        if (! $categoryCoverage['complete']) {
            $releaseTrustedReasons[] = 'missing_categories:'.implode(',', $categoryCoverage['missing']);
        }
        if (! $difficultyCoverage['complete']) {
            $releaseTrustedReasons[] = 'missing_difficulty_levels:'.implode(',', $difficultyCoverage['missing']);
        }
        if (! $sanity['replay_verified_all']) {
            $releaseTrustedReasons[] = 'replay_not_verified_for_all_valid_cases';
        }
        if (! $sanity['evidence_complete_all']) {
            $releaseTrustedReasons[] = 'evidence_incomplete_in_some_case';
        }
        if (! $sanity['workspace_clean_all']) {
            $releaseTrustedReasons[] = 'workspace_dirty_in_some_case';
        }
        if (! $sanity['human_intervention_clean']) {
            $releaseTrustedReasons[] = 'human_intervention_indevida_detected';
        }
        if (! $sanity['provider_run_clean']) {
            $releaseTrustedReasons[] = 'provider_error_detected_in_some_case';
        }
        if (! $sanity['mode_real_provider']) {
            $releaseTrustedReasons[] = 'mode_does_not_use_a_real_provider:'.(string) ($battery['mode'] ?? 'unknown');
        }
        if ($counters['cases_valid'] < self::RELEASE_TRUSTED_MIN_CASES) {
            $releaseTrustedReasons[] = 'cases_valid_below_release_floor:'.$counters['cases_valid'];
        }

        $releaseTrusted = $releaseTrustedReasons === [];

        $confidenceLevelV1 = $this->deriveBatteryConfidenceLevelV1(
            confidence: $confidence,
            releaseTrusted: $releaseTrusted,
        );

        $confidence['level_v1'] = $confidenceLevelV1['level'];
        $confidence['level_v1_reason'] = $confidenceLevelV1['reason'];
        $confidence['release_trusted'] = $releaseTrusted;
        $confidence['release_trusted_reasons'] = $releaseTrustedReasons;
        $confidence['category_coverage'] = $categoryCoverage;
        $confidence['difficulty_coverage'] = $difficultyCoverage;
        $confidence['sanity_gates'] = $sanity;
        $confidence['ladder_v1'] = AtlasForgeRivalsAdjudicatorService::CONFIDENCE_LEVELS;

        return $confidence;
    }

    /**
     * Build the release category coverage map. Returns the canonical 8
     * mandatory categories with a present/missing flag each, plus the
     * overall `complete` boolean.
     *
     * @param  list<array<string,mixed>>  $perCaseResults
     * @return array{
     *     required:list<string>,
     *     present:list<string>,
     *     missing:list<string>,
     *     complete:bool
     * }
     */
    private function computeReleaseCategoryCoverage(array $perCaseResults): array
    {
        $required = self::RELEASE_TRUSTED_MANDATORY_CATEGORIES;
        $present = [];
        foreach ($perCaseResults as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (! (bool) ($row['valid_for_ranking'] ?? false)) {
                continue;
            }
            $cat = strtolower(trim((string) ($row['category'] ?? '')));
            if ($cat === '' || $cat === 'unspecified') {
                continue;
            }
            if (in_array($cat, $required, true)) {
                $present[$cat] = true;
            }
        }
        $missing = array_values(array_diff($required, array_keys($present)));

        return [
            'required' => $required,
            'present' => array_values(array_keys($present)),
            'missing' => $missing,
            'complete' => $missing === [],
        ];
    }

    /**
     * Build the release difficulty coverage map for L1..L5.
     *
     * @param  list<array<string,mixed>>  $perCaseResults
     * @return array{
     *     required:list<string>,
     *     present:list<string>,
     *     missing:list<string>,
     *     complete:bool
     * }
     */
    private function computeReleaseDifficultyCoverage(array $perCaseResults): array
    {
        $required = self::RELEASE_TRUSTED_MANDATORY_DIFFICULTIES;
        $present = [];
        foreach ($perCaseResults as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (! (bool) ($row['valid_for_ranking'] ?? false)) {
                continue;
            }
            $level = strtoupper(trim((string) ($row['difficulty_level'] ?? '')));
            if ($level === '') {
                continue;
            }
            if (in_array($level, $required, true)) {
                $present[$level] = true;
            }
        }
        $missing = array_values(array_diff($required, array_keys($present)));

        return [
            'required' => $required,
            'present' => array_values(array_keys($present)),
            'missing' => $missing,
            'complete' => $missing === [],
        ];
    }

    /**
     * Battery-wide sanity gates that determine `release_trusted`. Each gate
     * is a boolean the operator can audit independently.
     *
     * @param  list<array<string,mixed>>  $perCaseResults
     * @param  list<array<string,mixed>>  $hardFailures
     * @param  array{contaminated_game:bool,reasons:list<string>,cases:list<array<string,string>>}  $contamination
     * @param  array<string,mixed>  $battery
     * @param  array{cases_total:int,cases_valid:int,cases_invalid:int}  $counters
     * @return array<string,bool>
     */
    private function computeBatterySanity(
        array $perCaseResults,
        array $hardFailures,
        array $contamination,
        array $battery,
        array $counters,
    ): array {
        $replayVerifiedAll = true;
        $evidenceCompleteAll = true;
        $workspaceCleanAll = true;
        $providerRunClean = true;
        $humanInterventionClean = ! (bool) ($battery['human_assisted'] ?? false);

        foreach ($perCaseResults as $row) {
            if (! is_array($row)) {
                continue;
            }
            $replay = (string) ($row['replay_status'] ?? '');
            if ($replay !== 'pass') {
                $replayVerifiedAll = false;
            }
            $evidence = (string) ($row['evidence_status'] ?? '');
            if ($evidence !== 'present') {
                $evidenceCompleteAll = false;
            }
            $hardFailedCodes = (array) ($row['hard_gates']['failed_codes'] ?? []);
            foreach ($hardFailedCodes as $code) {
                $code = (string) $code;
                if ($code === 'dirty_after_run_false' || $code === 'workspace_dirty_after' || $code === 'workspace_dirty_before') {
                    $workspaceCleanAll = false;
                }
                if (str_starts_with($code, 'timeout_without_result')
                    || str_starts_with($code, 'stalled_runner_no_heartbeat')
                    || $code === 'provider_returned_non_zero_with_empty_stdout'
                    || $code === 'output_empty_driver_error'
                ) {
                    $providerRunClean = false;
                }
            }
            if (($row['contamination_reason'] ?? null) !== null) {
                $workspaceCleanAll = false;
            }
        }

        if ($contamination['contaminated_game']) {
            $workspaceCleanAll = false;
        }
        if ($hardFailures !== []) {
            // Any hard failure surfaces as either provider/workspace impurity
            // already, but be defensive: a release-trusted claim cannot stand
            // on top of a battery with any pending hard failures.
            foreach ($hardFailures as $hf) {
                $code = (string) ($hf['code'] ?? '');
                if ($code === 'output_empty_driver_error'
                    || str_contains($code, 'timeout_without_result')
                    || str_contains($code, 'stalled_runner')
                ) {
                    $providerRunClean = false;
                }
            }
        }

        $mode = strtolower((string) ($battery['mode'] ?? ''));
        $modeRealProvider = in_array($mode, ['fair', 'full_power'], true);

        return [
            'replay_verified_all' => $replayVerifiedAll,
            'evidence_complete_all' => $evidenceCompleteAll,
            'workspace_clean_all' => $workspaceCleanAll,
            'human_intervention_clean' => $humanInterventionClean,
            'provider_run_clean' => $providerRunClean,
            'mode_real_provider' => $modeRealProvider,
            'has_any_valid_case' => $counters['cases_valid'] > 0,
            'no_contamination' => ! $contamination['contaminated_game'],
            'no_hard_failures' => $hardFailures === [],
        ];
    }

    /**
     * Map the legacy 4-level battery confidence + release_trusted boolean
     * onto the canonical 5-level `confidence_level_v1` ladder.
     *
     * @param  array<string,mixed>  $confidence
     * @return array{level:string,reason:string}
     */
    private function deriveBatteryConfidenceLevelV1(array $confidence, bool $releaseTrusted): array
    {
        $legacy = (string) ($confidence['level'] ?? self::CONFIDENCE_INCONCLUSIVE);
        if ($releaseTrusted) {
            return [
                'level' => AtlasForgeRivalsAdjudicatorService::CONFIDENCE_LEVEL_RELEASE_TRUSTED,
                'reason' => 'release_trusted_gates_all_green',
            ];
        }

        return match ($legacy) {
            self::CONFIDENCE_TRUSTED => [
                'level' => AtlasForgeRivalsAdjudicatorService::CONFIDENCE_LEVEL_HIGH,
                'reason' => 'trusted_battery_but_missing_release_coverage_or_sanity',
            ],
            self::CONFIDENCE_CATEGORY_SIGNAL => [
                'level' => AtlasForgeRivalsAdjudicatorService::CONFIDENCE_LEVEL_MEDIUM,
                'reason' => 'category_signal',
            ],
            self::CONFIDENCE_FLOW_VALIDATED => [
                'level' => AtlasForgeRivalsAdjudicatorService::CONFIDENCE_LEVEL_LOW,
                'reason' => 'flow_validated_only',
            ],
            default => [
                'level' => AtlasForgeRivalsAdjudicatorService::CONFIDENCE_LEVEL_INVALID,
                'reason' => 'inconclusive',
            ],
        };
    }

    /**
     * Resolve the global winner. Returns null winner when any of the
     * justice requirements is broken so callers cannot accidentally
     * publish a synthetic ranking.
     *
     * @param  array{atlas_avg:?float,rival_avg:?float,delta:?float}  $averages
     * @param  array{level:string,reason:string,reasons:list<string>,is_trusted:bool}  $confidence
     * @param  list<array<string,mixed>>  $hardFailures
     * @param  array{contaminated_game:bool,reasons:list<string>,cases:list<array<string,string>>}  $contamination
     * @return array{winner:?string,reasons:list<string>}
     */
    private function resolveGlobalWinner(
        array $averages,
        array $confidence,
        array $hardFailures,
        array $contamination,
    ): array {
        $reasons = [];
        if ($hardFailures !== []) {
            $reasons[] = 'winner_blocked:hard_failures_present';

            return ['winner' => null, 'reasons' => $reasons];
        }
        if ($contamination['contaminated_game']) {
            $reasons[] = 'winner_blocked:contaminated_game';

            return ['winner' => null, 'reasons' => $reasons];
        }
        if ($averages['delta'] === null) {
            $reasons[] = 'winner_blocked:no_valid_score';

            return ['winner' => null, 'reasons' => $reasons];
        }
        if ($confidence['level'] === self::CONFIDENCE_INCONCLUSIVE) {
            $reasons[] = 'winner_blocked:confidence_inconclusive';

            return ['winner' => null, 'reasons' => $reasons];
        }
        $absDelta = abs((float) $averages['delta']);
        if ($absDelta < self::TIE_THRESHOLD) {
            $reasons[] = sprintf('|delta|=%.2f<%.1f', $absDelta, self::TIE_THRESHOLD);
            $reasons[] = 'human_review_required_tie';

            return ['winner' => AtlasForgeRivalsAdjudicatorService::WINNER_TIE, 'reasons' => $reasons];
        }
        $winner = ((float) $averages['delta']) > 0
            ? AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS
            : AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL;
        $reasons[] = sprintf('|delta|=%.2f>=%.1f', $absDelta, self::TIE_THRESHOLD);
        $reasons[] = 'atlas_avg='.$averages['atlas_avg'];
        $reasons[] = 'rival_avg='.$averages['rival_avg'];
        if ($confidence['level'] === self::CONFIDENCE_FLOW_VALIDATED) {
            $reasons[] = 'advisory_only:confidence_below_category_signal';
        }

        return ['winner' => $winner, 'reasons' => $reasons];
    }

    /**
     * @param  array{cases_total:int,cases_valid:int,cases_invalid:int}  $counters
     * @param  list<array<string,mixed>>  $categories
     * @param  list<array<string,mixed>>  $difficultyBands
     * @param  list<array<string,mixed>>  $hardFailures
     * @param  array{contaminated_game:bool,reasons:list<string>,cases:list<array<string,string>>}  $contamination
     * @param  array{level:string,reason:string,reasons:list<string>,is_trusted:bool}  $confidence
     * @return list<string>
     */
    private function buildWhyScoreCounts(
        array $confidence,
        array $counters,
        array $categories,
        array $difficultyBands,
        array $hardFailures,
        array $contamination,
        bool $resultValid,
    ): array {
        if (! $resultValid) {
            return [];
        }
        $out = [];
        $out[] = 'cases_valid_for_ranking:'.$counters['cases_valid'];
        $out[] = 'categories_with_valid:'.count(array_filter(
            $categories,
            static fn (array $c): bool => (int) ($c['valid_cases'] ?? 0) > 0,
        ));
        $out[] = 'difficulty_levels_with_valid:'.count(array_filter(
            $difficultyBands,
            static fn (array $b): bool => (int) ($b['valid_cases'] ?? 0) > 0,
        ));
        $out[] = 'no_hard_failures';
        $out[] = 'no_contamination';
        $out[] = 'confidence:'.$confidence['level'];

        return $out;
    }

    /**
     * @param  array{cases_total:int,cases_valid:int,cases_invalid:int}  $counters
     * @param  list<array<string,mixed>>  $categories
     * @param  list<array<string,mixed>>  $hardFailures
     * @param  array{contaminated_game:bool,reasons:list<string>,cases:list<array<string,string>>}  $contamination
     * @param  array{level:string,reason:string,reasons:list<string>,is_trusted:bool}  $confidence
     * @return list<string>
     */
    private function buildWhyScoreDoesNotCount(
        array $confidence,
        array $counters,
        array $categories,
        array $hardFailures,
        array $contamination,
        bool $resultValid,
    ): array {
        if ($resultValid) {
            return [];
        }
        $out = [];
        if ($counters['cases_total'] === 0) {
            $out[] = 'no_cases_in_battery';

            return $out;
        }
        if ($counters['cases_valid'] === 0) {
            $out[] = 'no_valid_cases_for_ranking';
        }
        if ($hardFailures !== []) {
            $out[] = 'hard_failures_present:'.count($hardFailures);
        }
        if ($contamination['contaminated_game']) {
            $out[] = 'contaminated_game';
            foreach ($contamination['reasons'] as $r) {
                $out[] = 'contamination:'.$r;
            }
        }
        $categoriesWithValid = count(array_filter(
            $categories,
            static fn (array $c): bool => (int) ($c['valid_cases'] ?? 0) > 0,
        ));
        if ($counters['cases_valid'] > 0 && $counters['cases_valid'] < self::CATEGORY_SIGNAL_MIN_CASES) {
            $out[] = 'cases_valid<'.self::CATEGORY_SIGNAL_MIN_CASES.':'.$counters['cases_valid'];
        }
        if ($counters['cases_valid'] > 0 && $categoriesWithValid < self::CATEGORY_SIGNAL_MIN_CATEGORIES) {
            $out[] = 'categories_with_valid<'.self::CATEGORY_SIGNAL_MIN_CATEGORIES.':'.$categoriesWithValid;
        }
        if ($confidence['level'] === self::CONFIDENCE_INCONCLUSIVE) {
            $out[] = 'confidence_inconclusive';
        } elseif ($confidence['level'] === self::CONFIDENCE_FLOW_VALIDATED) {
            $out[] = 'confidence_flow_validated_only';
        }
        $out[] = 'result_invalid_for_ranking';

        return array_values(array_unique($out));
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  array<string,mixed>|null  $scorecard
     * @param  array<string,mixed>|null  $manifest
     */
    private function resolveEvidenceStatus(array $row, $scorecard, $manifest, string $evidenceDir): string
    {
        $state = (string) ($row['state'] ?? '');
        if ($state === AtlasForgeRivalsBatteryStateService::CASE_STATE_PENDING) {
            return 'pending';
        }
        if ($state === AtlasForgeRivalsBatteryStateService::CASE_STATE_SKIPPED) {
            return 'skipped';
        }
        if (! is_array($scorecard) || $scorecard === []) {
            return 'missing_scorecard';
        }
        if (! is_array($manifest) || $manifest === []) {
            return 'missing_manifest';
        }
        if (! is_file($evidenceDir.'/atlas_receipt.json') || ! is_file($evidenceDir.'/rival_receipt.json')) {
            return 'missing_receipts';
        }

        return 'present';
    }

    /**
     * Replay status: trust the per-case scorecard first; fall back to
     * `replay.json` if a sub-case verifier dropped one.
     *
     * @param  array<string,mixed>|null  $scorecard
     */
    private function resolveReplayStatus($scorecard, string $caseBase): string
    {
        if (is_array($scorecard) && array_key_exists('replay_passes', $scorecard)) {
            return $scorecard['replay_passes'] === true ? 'pass' : 'fail';
        }
        $replayPath = $caseBase.'/evidence/replay.json';
        if (is_file($replayPath)) {
            $replay = json_decode((string) file_get_contents($replayPath), true);
            if (is_array($replay) && array_key_exists('replay_passes', $replay)) {
                return $replay['replay_passes'] === true ? 'pass' : 'fail';
            }
        }

        return 'unknown';
    }

    /**
     * Per-case contamination heuristics: dirty workspace, out-of-scope
     * files, bytecode artifacts, killed runs, synthetic_score_admitted,
     * external_rivals_unlock_attempted, touched_forbidden_files.
     *
     * @param  array<string,mixed>  $row
     * @param  array<string,mixed>|null  $scorecard
     * @param  array<string,mixed>|null  $workspaceHashes
     * @param  array<string,mixed>|null  $atlasReceipt
     * @param  array<string,mixed>|null  $rivalReceipt
     */
    private function resolveCaseContamination(
        array $row,
        $scorecard,
        $workspaceHashes,
        $atlasReceipt,
        $rivalReceipt,
    ): ?string {
        if (is_array($workspaceHashes)
            && ($workspaceHashes['dirty_after_run'] ?? false) === true
        ) {
            return 'dirty_workspace_after_run';
        }
        foreach ([$atlasReceipt, $rivalReceipt] as $receipt) {
            if (! is_array($receipt)) {
                continue;
            }
            if (! empty($receipt['out_of_scope_files'] ?? [])) {
                return 'out_of_scope_files';
            }
            if (! empty($receipt['bytecode_artifacts'] ?? [])) {
                return 'bytecode_artifacts';
            }
            if (($receipt['killed'] ?? false) === true) {
                return 'arm_killed';
            }
        }
        if (is_array($scorecard)) {
            foreach ((array) ($scorecard['hard_failures'] ?? []) as $code) {
                if (in_array((string) $code, [
                    'synthetic_score_admitted',
                    'touched_forbidden_files',
                    'external_rivals_unlock_attempted',
                ], true)) {
                    return (string) $code;
                }
            }
        }
        $blockers = (array) ($row['last_blockers'] ?? []);
        foreach ($blockers as $b) {
            if (in_array((string) $b, [
                'synthetic_score_admitted',
                'touched_forbidden_files',
                'external_rivals_unlock_attempted',
            ], true)) {
                return (string) $b;
            }
        }

        return null;
    }

    private function winnerFromDelta(?float $delta): ?string
    {
        if ($delta === null) {
            return null;
        }
        $abs = abs($delta);
        if ($abs < self::TIE_THRESHOLD) {
            return AtlasForgeRivalsAdjudicatorService::WINNER_TIE;
        }

        return $delta > 0
            ? AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS
            : AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL;
    }

    private function categoryConfidence(int $valid, int $total): string
    {
        if ($valid === 0) {
            return self::CONFIDENCE_INCONCLUSIVE;
        }
        if ($valid < 2) {
            return self::CONFIDENCE_FLOW_VALIDATED;
        }
        if ($valid >= 4) {
            return self::CONFIDENCE_TRUSTED;
        }

        return self::CONFIDENCE_CATEGORY_SIGNAL;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function canonicalCategory(array $row): string
    {
        $candidate = (string) ($row['category'] ?? '');
        if ($candidate !== '') {
            return $candidate;
        }
        $legacy = strtolower(trim((string) ($row['task_category'] ?? '')));
        if ($legacy === '') {
            return 'unspecified';
        }

        return AtlasForgeRivalsProviderArenaCorpusService::LEGACY_CATEGORY_ALIAS[$legacy]
            ?? $legacy;
    }

    private function normalizeWinner(mixed $winner): ?string
    {
        if ($winner === null) {
            return null;
        }
        $winner = (string) $winner;
        if (in_array($winner, [
            AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS,
            AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL,
            AtlasForgeRivalsAdjudicatorService::WINNER_TIE,
        ], true)) {
            return $winner;
        }

        return null;
    }

    /**
     * @param  array<string,mixed>|null  $scorecard
     */
    private function readScoreFromScorecard($scorecard, string $key): ?float
    {
        if (! is_array($scorecard)) {
            return null;
        }
        $v = $scorecard[$key] ?? null;
        if ($v === null || $v === '') {
            return null;
        }
        if (! is_numeric($v)) {
            return null;
        }

        return (float) $v;
    }

    private function readJsonIfPresent(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        $raw = (string) @file_get_contents($path);
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  list<array<string,mixed>>  $cases
     * @param  array{contaminated_game:bool,reasons:list<string>,cases:list<array<string,string>>}  $contamination
     * @param  list<array<string,mixed>>  $hardFailures
     */
    private function nextCommandFor(
        string $runId,
        array $cases,
        bool $resultValid,
        array $contamination,
        array $hardFailures,
    ): string {
        $hasPending = false;
        foreach ($cases as $row) {
            if (! is_array($row)) {
                continue;
            }
            $state = (string) ($row['state'] ?? '');
            if ($state === AtlasForgeRivalsBatteryStateService::CASE_STATE_PENDING
                || $state === AtlasForgeRivalsBatteryStateService::CASE_STATE_RUNNING
            ) {
                $hasPending = true;
                break;
            }
        }
        if ($hasPending) {
            return 'php artisan atlas:forge:rivals resume --run-id='.$runId.' --json';
        }
        if ($hardFailures !== []) {
            return 'php artisan atlas:forge:rivals battery-verify-evidence --run-id='.$runId.' --json';
        }
        if ($contamination['contaminated_game']) {
            return 'php artisan atlas:forge:rivals battery-verify-evidence --run-id='.$runId.' --json';
        }
        if (! $resultValid) {
            return 'php artisan atlas:forge:rivals run-battery --resume --run-id='.$runId.' --json';
        }

        return 'php artisan atlas:forge:rivals matrix-report --run-id='.$runId.' --json';
    }

    /**
     * @param  array<string,mixed>  $battery
     * @param  list<array<string,mixed>>  $categoryAggregate
     * @param  list<array<string,mixed>>  $difficultyAggregate
     * @param  array<string,mixed>  $scoreBreakdown
     * @param  list<array<string,mixed>>  $categoriesV2
     * @param  list<array<string,mixed>>  $difficultyBandsV2
     * @param  list<array<string,mixed>>  $perCaseResults
     * @param  array{cases_total:int,cases_valid:int,cases_invalid:int}  $counters
     * @param  list<array<string,mixed>>  $hardFailures
     * @param  array{contaminated_game:bool,reasons:list<string>,cases:list<array<string,string>>}  $contamination
     * @param  array{level:string,reason:string,reasons:list<string>,is_trusted:bool}  $confidence
     * @param  array{winner:?string,reasons:list<string>}  $winnerDecision
     * @param  array{atlas_avg:?float,rival_avg:?float,delta:?float}  $globalAverages
     * @param  array<string,mixed>  $separationAnalysis
     * @param  array<string,mixed>  $extremeMeasurementPlan
     * @param  list<string>  $whyScoreCounts
     * @param  list<string>  $whyScoreDoesNotCount
     */
    private function renderMarkdown(
        array $battery,
        array $categoryAggregate,
        array $difficultyAggregate,
        array $scoreBreakdown,
        bool $stateClaimReady,
        bool $claimReady,
        array $planningScore,
        array $executionScore,
        array $difficultyScoreTotal,
        array $categoriesV2,
        array $difficultyBandsV2,
        array $perCaseResults,
        array $counters,
        array $hardFailures,
        array $contamination,
        array $confidence,
        array $winnerDecision,
        array $globalAverages,
        array $separationAnalysis,
        array $extremeMeasurementPlan,
        bool $resultValidForRanking,
        array $whyScoreCounts,
        array $whyScoreDoesNotCount,
    ): string {
        $cases = (array) ($battery['cases'] ?? []);
        $caseCount = (int) ($battery['case_count'] ?? count($cases));
        $preset = (string) ($battery['preset'] ?? 'unknown');
        $caseSet = (string) ($battery['case_set'] ?? '');
        $mode = (string) ($battery['mode'] ?? '');
        $atlasModel = (string) ($battery['atlas_model'] ?? '');
        $rivalModel = (string) ($battery['rival_model'] ?? '');
        $batteryStatus = (string) ($battery['battery_status'] ?? '');
        $aggregateVerdict = (string) ($battery['aggregate_verdict'] ?? '');
        $startedAt = (string) ($battery['started_at'] ?? '');
        $finishedAt = (string) ($battery['finished_at'] ?? '');
        $score = (float) ($scoreBreakdown['score_percent'] ?? 0);
        $resumeCount = (int) ($battery['resume_count'] ?? 0);

        $lines = [];
        $lines[] = '# Atlas Forge Rivals · Battery Report v2';
        $lines[] = '';
        $lines[] = '> Schema: '.self::SCHEMA_VERSION.' · run_id: `'.$battery['run_id'].'`';
        $lines[] = '> Gerado em '.$this->nowIso();
        $lines[] = '';

        // Resumo Executivo (human-first, em PT-BR).
        $lines[] = '## Resumo Executivo';
        $lines[] = '';
        $lines[] = $this->renderExecutiveSummary(
            confidence: $confidence,
            winnerDecision: $winnerDecision,
            globalAverages: $globalAverages,
            counters: $counters,
            categoriesV2: $categoriesV2,
            difficultyBandsV2: $difficultyBandsV2,
            hardFailures: $hardFailures,
            contamination: $contamination,
            resultValidForRanking: $resultValidForRanking,
            mode: $mode,
        );
        $lines[] = '';

        if (! $resultValidForRanking) {
            $lines[] = '> **Resultado inválido para ranking.** Veja `why_score_does_not_count` abaixo. external_rivals_certification permanece **blocked**.';
            $lines[] = '';
        }

        // Tabela Global.
        $lines[] = '## Tabela Global';
        $lines[] = '';
        $lines[] = '| Campo | Valor |';
        $lines[] = '| --- | --- |';
        $lines[] = '| preset | `'.$preset.'` |';
        $lines[] = '| case_set | `'.($caseSet !== '' ? $caseSet : '—').'` |';
        $lines[] = '| mode | `'.$mode.'` |';
        $lines[] = '| atlas_model | `'.$atlasModel.'` |';
        $lines[] = '| rival_model | `'.$rivalModel.'` |';
        $lines[] = '| cases_total | '.$counters['cases_total'].' |';
        $lines[] = '| cases_valid (para ranking) | '.$counters['cases_valid'].' |';
        $lines[] = '| cases_invalid | '.$counters['cases_invalid'].' |';
        $lines[] = '| battery_status | `'.$batteryStatus.'` |';
        $lines[] = '| aggregate_verdict | `'.$aggregateVerdict.'` |';
        $lines[] = '| started_at | '.$startedAt.' |';
        $lines[] = '| finished_at | '.($finishedAt !== '' ? $finishedAt : 'em curso').' |';
        $lines[] = '| resume_count | '.$resumeCount.' |';
        $lines[] = '| atlas_avg | '.$this->fmtScore($globalAverages['atlas_avg']).' |';
        $lines[] = '| rival_avg | '.$this->fmtScore($globalAverages['rival_avg']).' |';
        $lines[] = '| delta (atlas − rival) | '.$this->fmtScore($globalAverages['delta']).' |';
        $lines[] = '| winner | `'.($winnerDecision['winner'] ?? '—').'` |';
        $lines[] = '| confidence | `'.$confidence['level'].'` ('.$confidence['reason'].') |';
        $lines[] = '| result_valid_for_ranking | '.($resultValidForRanking ? 'true' : '**false**').' |';
        $lines[] = '| contaminated_game | '.($contamination['contaminated_game'] ? '**true**' : 'false').' |';
        $lines[] = '| hard_failures | '.count($hardFailures).' |';
        $lines[] = '| low_discrimination | '.(($separationAnalysis['low_discrimination'] ?? false) ? '**true**' : 'false').' |';
        $lines[] = '| weighted_score (difficulty ladder) | '.number_format($score, 2).'% |';
        if (! empty($planningScore)) {
            $lines[] = '| planning_score (matrix planning_weight) | '.number_format((float) ($planningScore['score_percent'] ?? 0), 2).'% |';
        }
        if (! empty($executionScore)) {
            $lines[] = '| execution_score (matrix execution_weight) | '.number_format((float) ($executionScore['score_percent'] ?? 0), 2).'% |';
        }
        if (! empty($difficultyScoreTotal)) {
            $lines[] = '| difficulty_score (matrix difficulty_score) | '.number_format((float) ($difficultyScoreTotal['score_percent'] ?? 0), 2).'% |';
        }
        $lines[] = '| claim_ready | '.($claimReady ? '**true**' : '**false**').' |';
        $lines[] = '| external_provider_call | '.(($battery['external_provider_call'] ?? false) ? 'true' : 'false').' |';
        $lines[] = '| separated_from_external_rivals_certification | **always true** |';
        $lines[] = '';

        // Separação / Empate.
        $lines[] = '## Separação dos Runners';
        $lines[] = '';
        $lines[] = '| Métrica | Valor |';
        $lines[] = '| --- | --- |';
        $lines[] = '| valid_cases | '.(int) ($separationAnalysis['valid_cases'] ?? 0).' |';
        $lines[] = '| tie_count | '.(int) ($separationAnalysis['tie_count'] ?? 0).' |';
        $lines[] = '| tie_rate | '.($separationAnalysis['tie_rate'] === null ? '—' : number_format((float) $separationAnalysis['tie_rate'], 2)).' |';
        $lines[] = '| average_abs_delta | '.$this->fmtScore($separationAnalysis['average_abs_delta'] ?? null).' |';
        $lines[] = '| median_abs_delta | '.$this->fmtScore($separationAnalysis['median_abs_delta'] ?? null).' |';
        $lines[] = '| max_abs_delta | '.$this->fmtScore($separationAnalysis['max_abs_delta'] ?? null).' |';
        $lines[] = '| low_discrimination | '.(($separationAnalysis['low_discrimination'] ?? false) ? '**true**' : 'false').' |';
        $perLevelTie = (array) ($separationAnalysis['per_level_tie_escalation'] ?? []);
        $lines[] = '| max_tie_rate_por_L1_L5 | '.number_format((float) ($perLevelTie['max_allowed_technical_tie_rate'] ?? self::MAX_TECHNICAL_TIE_RATE_PER_DIFFICULTY_LEVEL), 2).' |';
        $lines[] = '| niveis_acima_do_teto_de_empate | `'.implode('`, `', $this->stringList($perLevelTie['levels_exceeding_tie_budget'] ?? [])).'` |';
        if (($separationAnalysis['reasons'] ?? []) !== []) {
            $lines[] = '';
            $lines[] = 'Sinais de baixa separação:';
            foreach ((array) ($separationAnalysis['reasons'] ?? []) as $reason) {
                $lines[] = '- `'.$reason.'`';
            }
            $lines[] = '';
            $lines[] = 'Próximos presets recomendados: `'.implode('`, `', (array) ($separationAnalysis['recommended_case_sets'] ?? [])).'`.';
        } else {
            $lines[] = '';
            $lines[] = '_A bateria apresentou separação suficiente para análise por categoria/dificuldade._';
        }
        $lines[] = '';

        $lines[] = '### Política adaptativa por dificuldade';
        $lines[] = '';
        $lines[] = '| Nível | Casos válidos | Empates técnicos | Tie rate | Ação |';
        $lines[] = '| --- | --- | --- | --- | --- |';
        foreach ((array) ($perLevelTie['rows'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $tieRate = $row['technical_tie_rate'] ?? null;
            $lines[] = '| '.$row['level']
                .' | '.((int) ($row['valid_cases'] ?? 0))
                .' | '.((int) ($row['technical_tie_count'] ?? 0))
                .' | '.($tieRate === null ? '—' : number_format((float) $tieRate, 2))
                .' | `'.($row['required_action'] ?? 'continue_measuring').'` |';
        }
        $lines[] = '';

        // Plano extremo / 360.
        $lines[] = '## Plano Extremo 360';
        $lines[] = '';
        $lines[] = '| Campo | Valor |';
        $lines[] = '| --- | --- |';
        $lines[] = '| status | `'.(string) ($extremeMeasurementPlan['status'] ?? 'unknown').'` |';
        $lines[] = '| requires_harder_followup | '.((bool) ($extremeMeasurementPlan['requires_harder_followup'] ?? true) ? '**true**' : 'false').' |';
        $lines[] = '| tie_is_diagnostic_not_claim | **'.((bool) ($extremeMeasurementPlan['tie_is_diagnostic_not_claim'] ?? true) ? 'true' : 'false').'** |';
        $lines[] = '| required_matchups | '.count((array) ($extremeMeasurementPlan['required_matchups'] ?? [])).' |';
        $lines[] = '| recommended_commands | '.count((array) ($extremeMeasurementPlan['recommended_commands'] ?? [])).' |';
        $lines[] = '| advisory_only | **true** |';
        $lines[] = '';
        if ((array) ($extremeMeasurementPlan['reasons'] ?? []) !== []) {
            $lines[] = 'Razões para subir dificuldade:';
            foreach ((array) ($extremeMeasurementPlan['reasons'] ?? []) as $reason) {
                $lines[] = '- `'.(string) $reason.'`';
            }
            $lines[] = '';
        }
        $lines[] = 'Confrontos que precisam existir para 360 prático:';
        foreach ((array) ($extremeMeasurementPlan['required_matchups'] ?? []) as $matchup) {
            if (! is_array($matchup)) {
                continue;
            }
            $lines[] = '- `'.(string) ($matchup['id'] ?? 'matchup').'`: `'
                .(string) ($matchup['arm_a'] ?? 'arm_a').' '
                .(string) ($matchup['arm_a_model'] ?? 'default').'` vs `'
                .(string) ($matchup['arm_b'] ?? 'arm_b').' '
                .(string) ($matchup['arm_b_model'] ?? 'default').'` em `'
                .(string) ($matchup['mode'] ?? 'provider_arena').'`';
        }
        $lines[] = '';

        // Resultado por Categoria (v2).
        $lines[] = '## Resultado por Categoria';
        $lines[] = '';
        if ($categoriesV2 === []) {
            $lines[] = '_Nenhuma categoria registrada._';
        } else {
            $lines[] = '| Categoria | Total | Válidos | atlas_avg | rival_avg | Δ | Vencedor | Confidence |';
            $lines[] = '| --- | --- | --- | --- | --- | --- | --- | --- |';
            foreach ($categoriesV2 as $cat) {
                $lines[] = '| `'.$cat['category_id'].'`'
                    .' | '.$cat['cases_total']
                    .' | '.$cat['valid_cases']
                    .' | '.$this->fmtScore($cat['atlas_avg'])
                    .' | '.$this->fmtScore($cat['rival_avg'])
                    .' | '.$this->fmtScore($cat['delta'])
                    .' | `'.($cat['winner'] ?? '—').'`'
                    .' | `'.$cat['confidence'].'` |';
            }
        }
        $lines[] = '';

        // Resultado por Dificuldade L1-L5 (v2).
        $lines[] = '## Resultado por Dificuldade L1-L5';
        $lines[] = '';
        $lines[] = '| Nível | Peso | Total | Válidos | atlas_avg | rival_avg | Δ | Vencedor |';
        $lines[] = '| --- | --- | --- | --- | --- | --- | --- | --- |';
        foreach ($difficultyBandsV2 as $band) {
            $lines[] = '| '.$band['level']
                .' | '.number_format((float) $band['weight'], 2)
                .' | '.$band['cases_total']
                .' | '.$band['valid_cases']
                .' | '.$this->fmtScore($band['atlas_avg'])
                .' | '.$this->fmtScore($band['rival_avg'])
                .' | '.$this->fmtScore($band['delta'])
                .' | `'.($band['winner'] ?? '—').'` |';
        }
        $scaling = $this->deltaScaling(array_column(
            array_filter($difficultyBandsV2, static fn (array $b): bool => $b['delta'] !== null),
            'delta',
            'level',
        ));
        $lines[] = '';
        $lines[] = '> Escalada com dificuldade — atlas_grows: `'.($scaling['atlas_grows'] ? 'true' : 'false')
            .'` · rival_grows: `'.($scaling['rival_grows'] ? 'true' : 'false').'`';
        $lines[] = '';

        // Casos Inválidos / Contaminados.
        $lines[] = '## Casos Inválidos / Contaminados';
        $lines[] = '';
        $invalidRows = array_values(array_filter($perCaseResults, static fn (array $r): bool => ! $r['valid_for_ranking']));
        if ($invalidRows === []) {
            $lines[] = '_Nenhum caso inválido — todos os '.$counters['cases_total'].' casos passaram os gates de justiça._';
        } else {
            $lines[] = '| case_id | categoria | nível | estado | evidence_status | replay | hard_failed | contamination |';
            $lines[] = '| --- | --- | --- | --- | --- | --- | --- | --- |';
            foreach ($invalidRows as $r) {
                $lines[] = '| `'.$r['case_id'].'`'
                    .' | `'.$r['category'].'`'
                    .' | '.$r['difficulty_level']
                    .' | `'.$r['state'].'`'
                    .' | `'.$r['evidence_status'].'`'
                    .' | `'.$r['replay_status'].'`'
                    .' | '.$r['hard_gates']['failed_count']
                    .' | `'.($r['contamination_reason'] ?? '—').'` |';
            }
        }
        $lines[] = '';

        // Casos Detalhados.
        $lines[] = '## Casos Detalhados';
        $lines[] = '';
        $lines[] = '| # | case_id | categoria | L | atlas | rival | Δ | vencedor | hard_gates ✓/✗ | evidence | replay |';
        $lines[] = '| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |';
        foreach ($perCaseResults as $i => $r) {
            $delta = ($r['atlas_score'] !== null && $r['rival_score'] !== null)
                ? round((float) $r['atlas_score'] - (float) $r['rival_score'], 2)
                : null;
            $lines[] = '| '.($i + 1)
                .' | `'.$r['case_id'].'`'
                .' | `'.$r['category'].'`'
                .' | '.$r['difficulty_level']
                .' | '.$this->fmtScore($r['atlas_score'])
                .' | '.$this->fmtScore($r['rival_score'])
                .' | '.$this->fmtScore($delta)
                .' | `'.($r['winner'] ?? '—').'`'
                .' | '.$r['hard_gates']['passed_count'].'/'.$r['hard_gates']['failed_count']
                .' | `'.$r['evidence_status'].'`'
                .' | `'.$r['replay_status'].'` |';
        }
        $lines[] = '';

        // Hard failures.
        $lines[] = '## Hard Failures';
        $lines[] = '';
        if ($hardFailures === []) {
            $lines[] = '_Nenhum hard gate técnico falhou._';
        } else {
            $lines[] = '| case_id | categoria | nível | código |';
            $lines[] = '| --- | --- | --- | --- |';
            foreach ($hardFailures as $f) {
                $lines[] = '| `'.$f['case_id'].'`'
                    .' | `'.$f['category'].'`'
                    .' | '.$f['difficulty_level']
                    .' | `'.$f['code'].'` |';
            }
        }
        $lines[] = '';

        // Why score (counts/does not count).
        if ($whyScoreCounts !== []) {
            $lines[] = '## Por que o score conta';
            $lines[] = '';
            foreach ($whyScoreCounts as $r) {
                $lines[] = '- '.$r;
            }
            $lines[] = '';
        }
        if ($whyScoreDoesNotCount !== []) {
            $lines[] = '## Por que o score NÃO conta';
            $lines[] = '';
            foreach ($whyScoreDoesNotCount as $r) {
                $lines[] = '- '.$r;
            }
            $lines[] = '';
        }

        // Confidence detail.
        $lines[] = '## Confidence';
        $lines[] = '';
        $lines[] = '- **Nível:** `'.$confidence['level'].'`';
        $lines[] = '- **Motivo curto:** '.$confidence['reason'];
        if ($confidence['reasons'] !== []) {
            $lines[] = '- **Sinais:**';
            foreach ($confidence['reasons'] as $r) {
                $lines[] = '  - '.$r;
            }
        }
        $lines[] = '';

        // Próxima ação.
        $lines[] = '## Próximas Ações';
        $lines[] = '';
        $lines[] = '- Comando sugerido: `'.$this->nextCommandFor((string) $battery['run_id'], $cases, $resultValidForRanking, $contamination, $hardFailures).'`';
        if ($winnerDecision['reasons'] !== []) {
            $lines[] = '- Decisão do winner:';
            foreach ($winnerDecision['reasons'] as $r) {
                $lines[] = '  - '.$r;
            }
        }
        $lines[] = '';

        // Cláusula de segurança.
        $lines[] = '## Cláusula de Segurança';
        $lines[] = '';
        $lines[] = '- `external_rivals_certification_status`: **blocked_requires_operator_approval**';
        $lines[] = '- `unlocks_external_rivals_certification`: **false**';
        $lines[] = '- `synthetic_score_admitted`: **false** — o report nunca emite score sintético.';
        $lines[] = '- O winner deste report é **diagnóstico**. Ele não destrava nenhum gate externo nem promove `claim_ready=true` automaticamente.';
        if ($claimReady) {
            $lines[] = '- `claim_ready=true` — todos os casos `completed`, confidence ≥ category_signal, sem hard failures, sem contaminação. Ainda assim external_rivals fica blocked.';
        } else {
            $lines[] = '- `claim_ready=false` — não há ranking publicável a partir deste report.';
        }
        $lines[] = '';

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array{level:string,reason:string,reasons:list<string>,is_trusted:bool}  $confidence
     * @param  array{winner:?string,reasons:list<string>}  $winnerDecision
     * @param  array{atlas_avg:?float,rival_avg:?float,delta:?float}  $globalAverages
     * @param  array{cases_total:int,cases_valid:int,cases_invalid:int}  $counters
     * @param  list<array<string,mixed>>  $categoriesV2
     * @param  list<array<string,mixed>>  $difficultyBandsV2
     * @param  list<array<string,mixed>>  $hardFailures
     * @param  array{contaminated_game:bool,reasons:list<string>,cases:list<array<string,string>>}  $contamination
     */
    private function renderExecutiveSummary(
        array $confidence,
        array $winnerDecision,
        array $globalAverages,
        array $counters,
        array $categoriesV2,
        array $difficultyBandsV2,
        array $hardFailures,
        array $contamination,
        bool $resultValidForRanking,
        string $mode,
    ): string {
        $segments = [];
        $segments[] = 'Bateria com **'.$counters['cases_total'].'** casos · **'.$counters['cases_valid'].' válidos** para ranking.';

        if (! $resultValidForRanking) {
            if ($contamination['contaminated_game']) {
                $segments[] = '**Resultado inválido**: a partida foi contaminada ('.implode(', ', $contamination['reasons']).').';
            } elseif ($hardFailures !== []) {
                $segments[] = '**Resultado inválido**: '.count($hardFailures).' hard gate(s) técnico(s) falharam.';
            } elseif ($counters['cases_valid'] === 0) {
                $segments[] = '**Resultado inválido**: nenhum caso atingiu evidência completa para ranqueamento.';
            } else {
                $segments[] = '**Resultado inválido**: confidence='.$confidence['level'].' está abaixo do piso category_signal.';
            }
        } else {
            $winner = $winnerDecision['winner'];
            if ($winner === AtlasForgeRivalsAdjudicatorService::WINNER_TIE) {
                $segments[] = '**Empate estatístico** (|Δ| < '.self::TIE_THRESHOLD.'): revisão humana obrigatória.';
            } elseif ($winner === AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS) {
                $segments[] = '**Atlas vence** com Δ='.$this->fmtScore($globalAverages['delta']).' (atlas '.$this->fmtScore($globalAverages['atlas_avg']).' vs rival '.$this->fmtScore($globalAverages['rival_avg']).').';
            } elseif ($winner === AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL) {
                $segments[] = '**Rival vence** com Δ='.$this->fmtScore($globalAverages['delta']).' (atlas '.$this->fmtScore($globalAverages['atlas_avg']).' vs rival '.$this->fmtScore($globalAverages['rival_avg']).').';
            } else {
                $segments[] = 'Sem winner declarado.';
            }
        }

        $segments[] = 'Confidence `'.$confidence['level'].'`.';

        // Per-category headline.
        $atlasCats = [];
        $rivalCats = [];
        foreach ($categoriesV2 as $cat) {
            if ($cat['winner'] === AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS) {
                $atlasCats[] = $cat['category_id'];
            } elseif ($cat['winner'] === AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL) {
                $rivalCats[] = $cat['category_id'];
            }
        }
        if ($atlasCats !== []) {
            $segments[] = 'Atlas ganha em: '.implode(', ', $atlasCats).'.';
        }
        if ($rivalCats !== []) {
            $segments[] = 'Rival ganha em: '.implode(', ', $rivalCats).'.';
        }

        if ($mode === AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE) {
            $segments[] = 'Mode `local_fake` — sem provider real, `claim_ready` permanece false.';
        }

        return implode(' ', $segments);
    }

    private function fmtScore(?float $score): string
    {
        if ($score === null) {
            return '—';
        }

        return number_format($score, 2);
    }

    private function nowIso(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($item): string => trim((string) $item), $value),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
