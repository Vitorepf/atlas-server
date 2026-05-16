<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;

/**
 * Atlas Forge Rivals · Matrix Report (multi-case v1).
 *
 * Human-facing aggregate report for a Rivals battery (multi-case across N
 * run_ids). Reads the battery evidence pack + each run's adjudicator
 * scorecard and emits:
 *
 *   - overall winner (Atlas / Rival / tie / insufficient_evidence),
 *   - per-category ranking (counts of wins, win rate per arm),
 *   - per-difficulty L1-L5 ranking (counts + weighted score),
 *   - category × difficulty heatmap (`L1..L5 × {category,...}` win counts),
 *   - planning_score vs execution_score per arm (derived from
 *     adjudicator quality dimensions; planning =
 *     {objective_alignment, scope_discipline, evidence_quality};
 *     execution = {patch_focus, implementation_complexity, test_quality,
 *     maintainability, risk_surface, cost_time_efficiency}),
 *   - invalid_cases vs suspicious_cases (each with an explicit reason),
 *   - "atlas_better_in" / "rival_better_in" per (category, L1-L5) cell,
 *   - Atlas Decide recommendation per category (advisory),
 *   - Markdown body + JSON envelope.
 *
 * Honesty contract:
 *   - No provider call. Read-only.
 *   - When the battery has no comparable+scored case, the report returns
 *     `status=insufficient_evidence` and refuses to declare a winner or a
 *     recommendation. Same for any battery whose every run lacks a scorecard.
 *   - `claim_ready=false` always. The matrix report describes the audit
 *     trail; it never promotes a claim.
 *   - `external_rivals_certification_status='blocked'` always.
 *
 * Schema: `atlas.forge.rivals.matrix_report.v1`.
 */
final class AtlasForgeRivalsMatrixReportService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.matrix_report.v1';

    public const MATRIX_REPORT_JSON_FILE = 'matrix_report.json';

    public const MATRIX_REPORT_MD_FILE = 'matrix_report.md';

    /** @var list<string> */
    public const PLANNING_DIMENSIONS = ['objective_alignment', 'scope_discipline', 'evidence_quality'];

    /** @var list<string> */
    public const EXECUTION_DIMENSIONS = [
        'patch_focus',
        'implementation_complexity',
        'test_quality',
        'maintainability',
        'risk_surface',
        'cost_time_efficiency',
    ];

    public const WINNER_ATLAS = 'atlas';

    public const WINNER_RIVAL = 'rival';

    public const WINNER_TIE = 'tie';

    public const WINNER_NONE = null;

    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $paths,
        private readonly AtlasForgeRivalsBatteryEvidenceService $battery,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function render(array $input): array
    {
        $aggregate = $this->battery->aggregate($input);
        $pack = is_array($aggregate['battery_evidence_pack'] ?? null) ? $aggregate['battery_evidence_pack'] : [];
        if (! is_array($pack) || $pack === []) {
            return $this->insufficientEnvelope($aggregate['blockers'] ?? ['battery_evidence_pack_empty'], $input);
        }

        $runs = is_array($pack['runs'] ?? null) ? $pack['runs'] : [];
        $cases = is_array($pack['cases'] ?? null) ? $pack['cases'] : [];

        $caseRows = [];
        $scorecardByRun = [];
        foreach ($runs as $run) {
            if (! is_array($run) || ($run['present'] ?? false) !== true) {
                continue;
            }
            $runId = (string) ($run['run_id'] ?? '');
            $paths = $this->paths->paths($runId);
            $scorecardByRun[$runId] = $this->readJson($paths['scorecard_json']);
        }

        foreach ($cases as $case) {
            if (! is_array($case)) {
                continue;
            }
            $runId = (string) ($case['run_id'] ?? '');
            $scorecard = $this->resolveCaseScorecard($case, $scorecardByRun[$runId] ?? []);
            $caseRows[] = $this->buildCaseRow($case, $scorecard);
        }

        $comparableScored = array_values(array_filter(
            $caseRows,
            static fn (array $r): bool => $r['comparable_scored'] === true,
        ));
        $invalidCases = array_values(array_filter(
            $caseRows,
            static fn (array $r): bool => $r['is_invalid'] === true,
        ));
        $suspiciousCases = array_values(array_filter(
            $caseRows,
            static fn (array $r): bool => $r['is_suspicious'] === true && $r['is_invalid'] !== true,
        ));

        $insufficient = $comparableScored === [];
        $overall = $insufficient
            ? $this->insufficientOverall(count($caseRows), count($invalidCases), count($suspiciousCases))
            : $this->buildOverallWinner($comparableScored);

        $categoryRanking = $this->buildCategoryRanking($comparableScored);
        $difficultyRanking = $this->buildDifficultyRanking($comparableScored);
        $heatmap = $this->buildHeatmap($comparableScored);
        $planningExecution = $this->buildPlanningExecutionSplit($comparableScored);
        $betterMap = $this->buildBetterMap($categoryRanking, $difficultyRanking);
        $atlasDecide = $insufficient
            ? $this->insufficientAtlasDecide()
            : $this->buildAtlasDecideRecommendation($categoryRanking, $comparableScored);

        $matrix = [
            'schema_version' => self::SCHEMA_VERSION,
            'battery_id' => $pack['battery_id'] ?? null,
            'generated_at' => now()->toJSON(),
            'run_ids' => $pack['run_ids'] ?? [],
            'case_count' => count($caseRows),
            'comparable_scored_count' => count($comparableScored),
            'invalid_count' => count($invalidCases),
            'suspicious_count' => count($suspiciousCases),
            'status' => $insufficient ? 'insufficient_evidence' : 'ok',
            'overall' => $overall,
            'category_ranking' => $categoryRanking,
            'difficulty_ranking' => $difficultyRanking,
            'heatmap' => $heatmap,
            'planning_vs_execution' => $planningExecution,
            'atlas_better_in' => $betterMap['atlas_better_in'],
            'rival_better_in' => $betterMap['rival_better_in'],
            'invalid_cases' => array_map(
                fn (array $r): array => $this->projectCaseForBucket($r, 'invalid'),
                $invalidCases,
            ),
            'suspicious_cases' => array_map(
                fn (array $r): array => $this->projectCaseForBucket($r, 'suspicious'),
                $suspiciousCases,
            ),
            'atlas_decide_recommendation' => $atlasDecide,
            'difficulty_summary' => $pack['difficulty_summary'] ?? null,
            'category_summary' => $pack['category_summary'] ?? null,
            'mode_distribution' => $pack['mode_distribution'] ?? null,
            'cases' => $caseRows,
            'claim_ready' => false,
            'external_provider_call' => false,
            'external_rivals_certification_status' => 'blocked',
            'separated_from_external_rivals_certification' => true,
            'note' => $insufficient
                ? 'Bateria sem cases comparable+scored — sem winner por desenho.'
                : 'Relatório descritivo: claim final continua sob adjudicação humana.',
        ];

        $outputDir = $this->resolveOutputDir($pack);
        if ($outputDir !== null) {
            @mkdir($outputDir, 0o755, true);
            $jsonPath = $outputDir.'/'.self::MATRIX_REPORT_JSON_FILE;
            $mdPath = $outputDir.'/'.self::MATRIX_REPORT_MD_FILE;
            file_put_contents($jsonPath, $this->jsonEncode($matrix));
            file_put_contents($mdPath, $this->renderMarkdown($matrix));
            $matrix['matrix_report_json_path'] = $jsonPath;
            $matrix['matrix_report_md_path'] = $mdPath;
            $matrix['matrix_report_json_sha256'] = hash_file('sha256', $jsonPath) ?: null;
            $matrix['matrix_report_md_sha256'] = hash_file('sha256', $mdPath) ?: null;
        }

        return [
            'status' => $matrix['status'] === 'ok' ? 'ok' : $matrix['status'],
            'matrix_report' => $matrix,
            'next_command' => $matrix['status'] === 'ok'
                ? 'human_review_recommended; cabin: '.($outputDir ?? 'no_disk_path')
                : 'rode `atlas:forge:rivals run-real` em mais cases antes de pedir matrix-report',
            'external_provider_call' => false,
            'separated_from_external_rivals_certification' => true,
        ];
    }

    /**
     * Resolve which scorecard belongs to a case. Preference order:
     *
     *   1. Per-case `scorecard.json` in the case subdir (multi-case canon).
     *   2. The run-level scorecard's `cases_breakdown[case_id]` map.
     *   3. The run-level scorecard itself (single-case legacy run).
     *
     * Empty array means no scorecard found for this case.
     *
     * @param  array<string,mixed>  $case
     * @param  array<string,mixed>  $runScorecard
     * @return array<string,mixed>
     */
    private function resolveCaseScorecard(array $case, array $runScorecard): array
    {
        $evidencePath = (string) ($case['evidence_path'] ?? '');
        if ($evidencePath !== '') {
            $perCasePath = $evidencePath.'/scorecard.json';
            $perCase = $this->readJson($perCasePath);
            if ($perCase !== []) {
                return $perCase;
            }
        }

        $caseId = (string) ($case['case_id'] ?? '');
        $breakdown = $runScorecard['cases_breakdown'] ?? null;
        if (is_array($breakdown) && isset($breakdown[$caseId]) && is_array($breakdown[$caseId])) {
            // breakdown rows are flat (atlas_score/rival_score/hard_failures);
            // promote them to a scorecard shape the rest of the pipeline reads.
            $row = $breakdown[$caseId];

            return [
                'atlas_score' => $row['atlas_score'] ?? null,
                'rival_score' => $row['rival_score'] ?? null,
                'hard_failures' => $row['hard_failures'] ?? [],
                'tie_threshold' => $runScorecard['tie_threshold'] ?? 5.0,
                'score_source' => $row['score_source'] ?? 'quality_dimensions',
                'quality_dimensions' => $row['quality_dimensions'] ?? $runScorecard['quality_dimensions'] ?? null,
                'winner' => $row['winner'] ?? null,
            ];
        }

        // Single-case fallback: the run-level scorecard IS the per-case one.
        return $runScorecard;
    }

    /**
     * Build the per-case row from the battery case digest + per-case scorecard.
     *
     * @param  array<string,mixed>  $case
     * @param  array<string,mixed>  $scorecard
     * @return array<string,mixed>
     */
    private function buildCaseRow(array $case, array $scorecard): array
    {
        $verdict = (string) ($case['verdict'] ?? 'unknown');
        $isInvalid = str_starts_with($verdict, 'invalid');
        $hardFailures = (array) ($scorecard['hard_failures'] ?? []);
        $atlasScore = $scorecard['atlas_score'] ?? null;
        $rivalScore = $scorecard['rival_score'] ?? null;
        $scoreSource = (string) ($scorecard['score_source'] ?? '');
        $scoredCleanly = $verdict === 'comparable'
            && $hardFailures === []
            && is_numeric($atlasScore)
            && is_numeric($rivalScore)
            && $scoreSource === 'quality_dimensions';

        $winner = null;
        if ($scoredCleanly) {
            $delta = (float) $atlasScore - (float) $rivalScore;
            $threshold = (float) ($scorecard['tie_threshold'] ?? 5.0);
            if (abs($delta) < $threshold) {
                $winner = self::WINNER_TIE;
            } else {
                $winner = $delta > 0 ? self::WINNER_ATLAS : self::WINNER_RIVAL;
            }
        }

        $isSuspicious = ! $isInvalid && (
            $hardFailures !== [] || (
                $scoreSource === 'gate_outcome' && $scorecard !== []
            )
        );

        $planningExecution = $this->splitPlanningExecutionFromScorecard($scorecard);
        $reason = match (true) {
            $isInvalid => 'invalid_verdict:'.$verdict,
            $scorecard === [] => 'no_scorecard',
            $hardFailures !== [] => 'hard_failures:'.implode(',', array_map('strval', $hardFailures)),
            $scoreSource === 'gate_outcome' => 'gate_outcome_only_no_quality_score',
            ! $scoredCleanly => 'not_comparable_or_score_missing',
            default => null,
        };

        return [
            'run_id' => (string) ($case['run_id'] ?? ''),
            'case_id' => (string) ($case['case_id'] ?? ''),
            'task_category' => $case['task_category'] ?? null,
            'difficulty_level' => $case['difficulty_level'] ?? null,
            'difficulty_level_origin' => $case['difficulty_level_origin'] ?? 'missing',
            'difficulty_weight' => $case['difficulty_weight'] ?? null,
            'verdict' => $verdict,
            'is_invalid' => $isInvalid,
            'is_suspicious' => $isSuspicious,
            'comparable_scored' => $scoredCleanly,
            'winner' => $winner,
            'atlas_score' => $atlasScore,
            'rival_score' => $rivalScore,
            'score_source' => $scoreSource !== '' ? $scoreSource : null,
            'hard_failures' => $hardFailures,
            'reason' => $reason,
            'planning' => $planningExecution['planning'],
            'execution' => $planningExecution['execution'],
        ];
    }

    /**
     * Average the planning and execution quality dimensions for each arm.
     * Returns null arms when the scorecard does not carry quality_dimensions
     * (i.e. invalid run / gate_outcome run). The caller treats null as
     * "no signal" and does not include the case in planning/execution
     * aggregates.
     *
     * @param  array<string,mixed>  $scorecard
     * @return array{
     *   planning: array{atlas:?float,rival:?float,dimensions:list<string>},
     *   execution: array{atlas:?float,rival:?float,dimensions:list<string>}
     * }
     */
    private function splitPlanningExecutionFromScorecard(array $scorecard): array
    {
        $dims = is_array($scorecard['quality_dimensions'] ?? null) ? $scorecard['quality_dimensions'] : null;
        $planning = ['atlas' => null, 'rival' => null, 'dimensions' => self::PLANNING_DIMENSIONS];
        $execution = ['atlas' => null, 'rival' => null, 'dimensions' => self::EXECUTION_DIMENSIONS];
        if ($dims === null) {
            return ['planning' => $planning, 'execution' => $execution];
        }

        $planning = $this->averageDimensions($dims, self::PLANNING_DIMENSIONS, self::PLANNING_DIMENSIONS);
        $execution = $this->averageDimensions($dims, self::EXECUTION_DIMENSIONS, self::EXECUTION_DIMENSIONS);

        return ['planning' => $planning, 'execution' => $execution];
    }

    /**
     * Average a subset of quality dimensions for each arm. Returns nulls when
     * none of the listed dimensions are present in the scorecard, so the
     * caller never sees a fake zero.
     *
     * @param  array<string,mixed>  $dims
     * @param  list<string>  $keys
     * @param  list<string>  $contractKeys  Names emitted in the response (for traceability).
     * @return array{atlas:?float,rival:?float,dimensions:list<string>}
     */
    private function averageDimensions(array $dims, array $keys, array $contractKeys): array
    {
        $atlasSum = 0.0;
        $rivalSum = 0.0;
        $count = 0;
        foreach ($keys as $key) {
            $entry = $dims[$key] ?? null;
            if (! is_array($entry)) {
                continue;
            }
            $atlasSum += (float) ($entry['atlas'] ?? 0);
            $rivalSum += (float) ($entry['rival'] ?? 0);
            $count++;
        }
        if ($count === 0) {
            return ['atlas' => null, 'rival' => null, 'dimensions' => $contractKeys];
        }

        return [
            'atlas' => round($atlasSum / $count, 2),
            'rival' => round($rivalSum / $count, 2),
            'dimensions' => $contractKeys,
        ];
    }

    /**
     * Compute the overall winner over comparable+scored cases with weighted
     * voting. Each case contributes its `difficulty_weight` to whichever arm
     * won; ties split half-half. The arm with the higher weighted total wins.
     * If the spread is below 1.0 weight unit, the result is `tie`.
     *
     * @param  list<array<string,mixed>>  $cases
     * @return array<string,mixed>
     */
    private function buildOverallWinner(array $cases): array
    {
        $atlasWeighted = 0.0;
        $rivalWeighted = 0.0;
        $tieCount = 0;
        $atlasWins = 0;
        $rivalWins = 0;
        $atlasScoreSum = 0.0;
        $rivalScoreSum = 0.0;
        foreach ($cases as $case) {
            $weight = (float) ($case['difficulty_weight'] ?? 1.0);
            $winner = $case['winner'] ?? null;
            $atlasScoreSum += (float) ($case['atlas_score'] ?? 0);
            $rivalScoreSum += (float) ($case['rival_score'] ?? 0);
            if ($winner === self::WINNER_ATLAS) {
                $atlasWeighted += $weight;
                $atlasWins++;
            } elseif ($winner === self::WINNER_RIVAL) {
                $rivalWeighted += $weight;
                $rivalWins++;
            } else {
                $atlasWeighted += $weight / 2;
                $rivalWeighted += $weight / 2;
                $tieCount++;
            }
        }
        $diff = $atlasWeighted - $rivalWeighted;
        $winner = abs($diff) < 1.0
            ? self::WINNER_TIE
            : ($diff > 0 ? self::WINNER_ATLAS : self::WINNER_RIVAL);
        $caseCount = count($cases) ?: 1;

        return [
            'winner' => $winner,
            'atlas_wins' => $atlasWins,
            'rival_wins' => $rivalWins,
            'tie_count' => $tieCount,
            'atlas_weighted' => round($atlasWeighted, 2),
            'rival_weighted' => round($rivalWeighted, 2),
            'spread' => round($diff, 2),
            'atlas_avg_score' => round($atlasScoreSum / $caseCount, 2),
            'rival_avg_score' => round($rivalScoreSum / $caseCount, 2),
            'comparable_scored_count' => count($cases),
            'note' => 'Weighted by L1-L5 difficulty weight; spread &lt;1.0 ⇒ tie.',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $cases
     * @return list<array<string,mixed>>
     */
    private function buildCategoryRanking(array $cases): array
    {
        $buckets = [];
        foreach ($cases as $case) {
            $category = (string) ($case['task_category'] ?? '');
            if ($category === '') {
                continue;
            }
            if (! isset($buckets[$category])) {
                $buckets[$category] = ['atlas' => 0, 'rival' => 0, 'tie' => 0, 'total' => 0];
            }
            $buckets[$category]['total']++;
            $winner = $case['winner'] ?? null;
            if ($winner === self::WINNER_ATLAS) {
                $buckets[$category]['atlas']++;
            } elseif ($winner === self::WINNER_RIVAL) {
                $buckets[$category]['rival']++;
            } else {
                $buckets[$category]['tie']++;
            }
        }
        $rows = [];
        foreach ($buckets as $category => $counts) {
            $total = $counts['total'] ?: 1;
            $rows[] = [
                'category' => $category,
                'cases' => $counts['total'],
                'atlas_wins' => $counts['atlas'],
                'rival_wins' => $counts['rival'],
                'ties' => $counts['tie'],
                'atlas_win_rate' => round($counts['atlas'] / $total, 3),
                'rival_win_rate' => round($counts['rival'] / $total, 3),
                'leader' => $this->bucketLeader($counts),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['cases'] <=> $a['cases']);

        return $rows;
    }

    /**
     * @param  list<array<string,mixed>>  $cases
     * @return list<array<string,mixed>>
     */
    private function buildDifficultyRanking(array $cases): array
    {
        $buckets = [];
        foreach (AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVELS as $level) {
            $buckets[$level] = [
                'atlas' => 0,
                'rival' => 0,
                'tie' => 0,
                'total' => 0,
                'weight' => AtlasForgeRivalsProviderArenaCorpusService::difficultyLevelWeight($level),
            ];
        }
        foreach ($cases as $case) {
            $level = (string) ($case['difficulty_level'] ?? '');
            if (! isset($buckets[$level])) {
                continue;
            }
            $buckets[$level]['total']++;
            $winner = $case['winner'] ?? null;
            if ($winner === self::WINNER_ATLAS) {
                $buckets[$level]['atlas']++;
            } elseif ($winner === self::WINNER_RIVAL) {
                $buckets[$level]['rival']++;
            } else {
                $buckets[$level]['tie']++;
            }
        }
        $rows = [];
        foreach ($buckets as $level => $counts) {
            $total = $counts['total'];
            $denominator = $total ?: 1;
            $rows[] = [
                'level' => $level,
                'weight' => $counts['weight'],
                'cases' => $total,
                'atlas_wins' => $counts['atlas'],
                'rival_wins' => $counts['rival'],
                'ties' => $counts['tie'],
                'atlas_win_rate' => $total === 0 ? null : round($counts['atlas'] / $denominator, 3),
                'rival_win_rate' => $total === 0 ? null : round($counts['rival'] / $denominator, 3),
                'leader' => $total === 0 ? null : $this->bucketLeader($counts),
            ];
        }

        return $rows;
    }

    /**
     * Heatmap (category × L1-L5): each cell carries arm wins + leader.
     *
     * @param  list<array<string,mixed>>  $cases
     * @return array<string,mixed>
     */
    private function buildHeatmap(array $cases): array
    {
        $categories = [];
        foreach ($cases as $case) {
            $cat = (string) ($case['task_category'] ?? '');
            if ($cat !== '' && ! in_array($cat, $categories, true)) {
                $categories[] = $cat;
            }
        }
        sort($categories, SORT_STRING);

        $cells = [];
        foreach ($categories as $cat) {
            $cells[$cat] = [];
            foreach (AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVELS as $level) {
                $cells[$cat][$level] = ['atlas' => 0, 'rival' => 0, 'tie' => 0, 'total' => 0, 'leader' => null];
            }
        }
        foreach ($cases as $case) {
            $cat = (string) ($case['task_category'] ?? '');
            $level = (string) ($case['difficulty_level'] ?? '');
            if (! isset($cells[$cat][$level])) {
                continue;
            }
            $cells[$cat][$level]['total']++;
            $winner = $case['winner'] ?? null;
            if ($winner === self::WINNER_ATLAS) {
                $cells[$cat][$level]['atlas']++;
            } elseif ($winner === self::WINNER_RIVAL) {
                $cells[$cat][$level]['rival']++;
            } else {
                $cells[$cat][$level]['tie']++;
            }
        }
        foreach ($cells as $cat => $levels) {
            foreach ($levels as $level => $counts) {
                $cells[$cat][$level]['leader'] = $counts['total'] === 0 ? null : $this->bucketLeader($counts);
            }
        }

        return [
            'categories' => $categories,
            'levels' => AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVELS,
            'cells' => $cells,
        ];
    }

    /**
     * Aggregate planning_score and execution_score across all comparable+scored
     * cases. Returns null arms when no case contributed (i.e. no quality
     * dimensions on any scorecard).
     *
     * @param  list<array<string,mixed>>  $cases
     * @return array<string,mixed>
     */
    private function buildPlanningExecutionSplit(array $cases): array
    {
        $planningAtlas = 0.0;
        $planningRival = 0.0;
        $planningCount = 0;
        $executionAtlas = 0.0;
        $executionRival = 0.0;
        $executionCount = 0;
        foreach ($cases as $case) {
            $planning = $case['planning'] ?? null;
            if (is_array($planning) && is_numeric($planning['atlas']) && is_numeric($planning['rival'])) {
                $planningAtlas += (float) $planning['atlas'];
                $planningRival += (float) $planning['rival'];
                $planningCount++;
            }
            $execution = $case['execution'] ?? null;
            if (is_array($execution) && is_numeric($execution['atlas']) && is_numeric($execution['rival'])) {
                $executionAtlas += (float) $execution['atlas'];
                $executionRival += (float) $execution['rival'];
                $executionCount++;
            }
        }

        return [
            'planning' => $planningCount === 0 ? [
                'atlas' => null,
                'rival' => null,
                'leader' => null,
                'sample_size' => 0,
                'dimensions' => self::PLANNING_DIMENSIONS,
            ] : [
                'atlas' => round($planningAtlas / $planningCount, 2),
                'rival' => round($planningRival / $planningCount, 2),
                'leader' => $this->pairLeader($planningAtlas, $planningRival),
                'sample_size' => $planningCount,
                'dimensions' => self::PLANNING_DIMENSIONS,
            ],
            'execution' => $executionCount === 0 ? [
                'atlas' => null,
                'rival' => null,
                'leader' => null,
                'sample_size' => 0,
                'dimensions' => self::EXECUTION_DIMENSIONS,
            ] : [
                'atlas' => round($executionAtlas / $executionCount, 2),
                'rival' => round($executionRival / $executionCount, 2),
                'leader' => $this->pairLeader($executionAtlas, $executionRival),
                'sample_size' => $executionCount,
                'dimensions' => self::EXECUTION_DIMENSIONS,
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $categoryRanking
     * @param  list<array<string,mixed>>  $difficultyRanking
     * @return array{atlas_better_in:array<string,mixed>,rival_better_in:array<string,mixed>}
     */
    private function buildBetterMap(array $categoryRanking, array $difficultyRanking): array
    {
        $atlasCategories = array_values(array_filter(
            $categoryRanking,
            static fn (array $r): bool => ($r['leader'] ?? null) === self::WINNER_ATLAS,
        ));
        $rivalCategories = array_values(array_filter(
            $categoryRanking,
            static fn (array $r): bool => ($r['leader'] ?? null) === self::WINNER_RIVAL,
        ));
        $atlasLevels = array_values(array_filter(
            $difficultyRanking,
            static fn (array $r): bool => ($r['leader'] ?? null) === self::WINNER_ATLAS,
        ));
        $rivalLevels = array_values(array_filter(
            $difficultyRanking,
            static fn (array $r): bool => ($r['leader'] ?? null) === self::WINNER_RIVAL,
        ));

        return [
            'atlas_better_in' => [
                'categories' => array_values(array_map(
                    static fn (array $r): string => (string) $r['category'],
                    $atlasCategories,
                )),
                'difficulty_levels' => array_values(array_map(
                    static fn (array $r): string => (string) $r['level'],
                    $atlasLevels,
                )),
            ],
            'rival_better_in' => [
                'categories' => array_values(array_map(
                    static fn (array $r): string => (string) $r['category'],
                    $rivalCategories,
                )),
                'difficulty_levels' => array_values(array_map(
                    static fn (array $r): string => (string) $r['level'],
                    $rivalLevels,
                )),
            ],
        ];
    }

    /**
     * Provider performance signal per category. Advisory only: Rivals emits
     * measured evidence; Atlas Decide remains the only routing authority.
     * Categories with insufficient samples (< 2 comparable cases) surface
     * `insufficient_sample`.
     *
     * @param  list<array<string,mixed>>  $categoryRanking
     * @param  list<array<string,mixed>>  $comparable
     * @return array<string,mixed>
     */
    private function buildAtlasDecideRecommendation(array $categoryRanking, array $comparable): array
    {
        $entries = [];
        foreach ($categoryRanking as $row) {
            $category = (string) $row['category'];
            $total = (int) $row['cases'];
            $atlasWins = (int) $row['atlas_wins'];
            $rivalWins = (int) $row['rival_wins'];
            $recommendation = match (true) {
                $total < 2 => 'insufficient_sample',
                $atlasWins === $rivalWins => 'human_review_inconclusive',
                $atlasWins > $rivalWins => 'atlas_forge_measured_ahead',
                default => 'rival_measured_ahead',
            };
            $entries[] = [
                'category' => $category,
                'signal' => $recommendation,
                'recommendation' => $recommendation,
                'atlas_win_rate' => $row['atlas_win_rate'],
                'rival_win_rate' => $row['rival_win_rate'],
                'sample_size' => $total,
            ];
        }
        usort($entries, static fn (array $a, array $b): int => $b['sample_size'] <=> $a['sample_size']);

        return [
            'status' => $entries === [] ? 'insufficient_evidence' : 'advisory',
            'note' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'entries' => $entries,
            'global_recommendation' => $this->globalAtlasDecideRecommendation($entries, $comparable),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @param  list<array<string,mixed>>  $comparable
     */
    private function globalAtlasDecideRecommendation(array $entries, array $comparable): string
    {
        if ($comparable === []) {
            return 'insufficient_evidence';
        }
        $atlasCount = 0;
        $rivalCount = 0;
        foreach ($entries as $entry) {
            $signal = (string) ($entry['signal'] ?? $entry['recommendation'] ?? '');
            if ($signal === 'atlas_forge_measured_ahead') {
                $atlasCount++;
            } elseif ($signal === 'rival_measured_ahead') {
                $rivalCount++;
            }
        }
        if ($atlasCount === 0 && $rivalCount === 0) {
            return 'human_review_required';
        }
        if ($atlasCount > $rivalCount) {
            return 'atlas_forge_measured_ahead_over_more_categories';
        }
        if ($rivalCount > $atlasCount) {
            return 'rival_measured_ahead_over_more_categories';
        }

        return 'split_measured_evidence_by_category';
    }

    /**
     * @return array<string,mixed>
     */
    private function insufficientAtlasDecide(): array
    {
        return [
            'status' => 'insufficient_evidence',
            'note' => 'Sem cases comparable+scored — sinal consultivo omitido por desenho.',
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'entries' => [],
            'global_recommendation' => 'insufficient_evidence',
        ];
    }

    /**
     * @param  array{atlas:int,rival:int,tie:int,total:int}  $counts
     */
    private function bucketLeader(array $counts): string
    {
        if ($counts['atlas'] > $counts['rival']) {
            return self::WINNER_ATLAS;
        }
        if ($counts['rival'] > $counts['atlas']) {
            return self::WINNER_RIVAL;
        }

        return self::WINNER_TIE;
    }

    private function pairLeader(float $atlas, float $rival): string
    {
        $diff = $atlas - $rival;
        if (abs($diff) < 0.5) {
            return self::WINNER_TIE;
        }

        return $diff > 0 ? self::WINNER_ATLAS : self::WINNER_RIVAL;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function projectCaseForBucket(array $row, string $bucket): array
    {
        return [
            'run_id' => $row['run_id'],
            'case_id' => $row['case_id'],
            'task_category' => $row['task_category'],
            'difficulty_level' => $row['difficulty_level'],
            'verdict' => $row['verdict'],
            'reason' => $row['reason'],
            'hard_failures' => $row['hard_failures'],
            'bucket' => $bucket,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function insufficientOverall(int $caseCount, int $invalidCount, int $suspiciousCount): array
    {
        return [
            'winner' => self::WINNER_NONE,
            'atlas_wins' => 0,
            'rival_wins' => 0,
            'tie_count' => 0,
            'atlas_weighted' => 0.0,
            'rival_weighted' => 0.0,
            'spread' => 0.0,
            'atlas_avg_score' => null,
            'rival_avg_score' => null,
            'comparable_scored_count' => 0,
            'case_count' => $caseCount,
            'invalid_count' => $invalidCount,
            'suspicious_count' => $suspiciousCount,
            'note' => 'insufficient_evidence: nenhuma case comparable+scored disponível.',
        ];
    }

    /**
     * Markdown rendering. Designed to read top-to-bottom: TL;DR (winner +
     * confidence + sample size) → per-category ranking → per-difficulty
     * ranking → heatmap → planning vs execution → invalid/suspicious →
     * Atlas Decide recommendation. Tables are tight (≤7 cols) so they fit
     * a terminal width.
     *
     * @param  array<string,mixed>  $matrix
     */
    private function renderMarkdown(array $matrix): string
    {
        $lines = [];
        $batteryId = (string) ($matrix['battery_id'] ?? 'unknown');
        $caseCount = (int) ($matrix['case_count'] ?? 0);
        $comparable = (int) ($matrix['comparable_scored_count'] ?? 0);
        $invalid = (int) ($matrix['invalid_count'] ?? 0);
        $suspicious = (int) ($matrix['suspicious_count'] ?? 0);
        $status = (string) ($matrix['status'] ?? '');

        $lines[] = '# Atlas Forge Rivals · Matrix Report';
        $lines[] = '';
        $lines[] = '**Battery:** `'.$batteryId.'`';
        $lines[] = '**Status:** `'.$status.'`';
        $lines[] = '**Cases:** '.$caseCount.' total · '.$comparable.' comparable+scored · '.$invalid.' invalid · '.$suspicious.' suspicious';
        $lines[] = '';

        if ($status === 'insufficient_evidence') {
            $lines[] = '> **insufficient_evidence** — esta bateria não contém cases comparable+scored. Nenhum winner, ranking ou recomendação é emitido por desenho.';
            $lines[] = '';
            if ($invalid + $suspicious > 0) {
                $lines[] = 'Veja seções **Invalid** e **Suspicious** abaixo para o motivo case-a-case.';
                $lines[] = '';
            }
        }

        $overall = (array) ($matrix['overall'] ?? []);
        $lines[] = '## TL;DR — Winner geral';
        $lines[] = '';
        if ($status === 'insufficient_evidence') {
            $lines[] = '- **Winner:** _insufficient_evidence_';
        } else {
            $lines[] = '- **Winner:** **'.$this->humanWinner($overall['winner'] ?? null).'**';
            $lines[] = '- **Atlas wins / Rival wins / Ties:** '.((int) ($overall['atlas_wins'] ?? 0)).' / '.((int) ($overall['rival_wins'] ?? 0)).' / '.((int) ($overall['tie_count'] ?? 0));
            $lines[] = '- **Weighted total (Atlas vs Rival):** '.((float) ($overall['atlas_weighted'] ?? 0.0)).' vs '.((float) ($overall['rival_weighted'] ?? 0.0)).' (spread '.((float) ($overall['spread'] ?? 0.0)).')';
            $lines[] = '- **Average score (Atlas vs Rival):** '.($overall['atlas_avg_score'] ?? '—').' vs '.($overall['rival_avg_score'] ?? '—');
        }
        $lines[] = '';

        $lines[] = '## Ranking por categoria';
        $lines[] = '';
        $categoryRanking = (array) ($matrix['category_ranking'] ?? []);
        if ($categoryRanking === []) {
            $lines[] = '_Nenhuma case comparable+scored com categoria._';
        } else {
            $lines[] = '| Categoria | Cases | Atlas | Rival | Empate | Leader |';
            $lines[] = '|---|---:|---:|---:|---:|---|';
            foreach ($categoryRanking as $row) {
                $lines[] = '| `'.$row['category'].'` | '.$row['cases'].' | '.$row['atlas_wins'].' | '.$row['rival_wins'].' | '.$row['ties'].' | **'.$this->humanWinner($row['leader']).'** |';
            }
        }
        $lines[] = '';

        $lines[] = '## Ranking por dificuldade (L1-L5)';
        $lines[] = '';
        $difficultyRanking = (array) ($matrix['difficulty_ranking'] ?? []);
        $lines[] = '| Nível | Peso | Cases | Atlas | Rival | Empate | Leader |';
        $lines[] = '|---|---:|---:|---:|---:|---:|---|';
        foreach ($difficultyRanking as $row) {
            $leader = $row['leader'] === null ? '—' : $this->humanWinner($row['leader']);
            $lines[] = '| **'.$row['level'].'** | '.$row['weight'].' | '.$row['cases'].' | '.$row['atlas_wins'].' | '.$row['rival_wins'].' | '.$row['ties'].' | '.$leader.' |';
        }
        $lines[] = '';

        $lines[] = '## Heatmap (categoria × dificuldade)';
        $lines[] = '';
        $heatmap = (array) ($matrix['heatmap'] ?? []);
        $categories = (array) ($heatmap['categories'] ?? []);
        $levels = (array) ($heatmap['levels'] ?? AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVELS);
        if ($categories === []) {
            $lines[] = '_Heatmap vazio — sem categoria comparable+scored._';
        } else {
            $header = '| Categoria \ Nível |';
            $sep = '|---|';
            foreach ($levels as $level) {
                $header .= ' '.$level.' |';
                $sep .= '---|';
            }
            $lines[] = $header;
            $lines[] = $sep;
            foreach ($categories as $cat) {
                $row = '| `'.$cat.'` |';
                foreach ($levels as $level) {
                    $cell = $heatmap['cells'][$cat][$level] ?? null;
                    if (! is_array($cell) || $cell['total'] === 0) {
                        $row .= ' · |';

                        continue;
                    }
                    $row .= ' '.$this->heatmapBadge($cell).' |';
                }
                $lines[] = $row;
            }
        }
        $lines[] = '';
        $lines[] = '_Legenda: `A` Atlas, `R` Rival, `T` Tie. `Ax/Rx/Tx` mostra contagem por arm._';
        $lines[] = '';

        $pve = (array) ($matrix['planning_vs_execution'] ?? []);
        $planning = (array) ($pve['planning'] ?? []);
        $execution = (array) ($pve['execution'] ?? []);
        $lines[] = '## planning_score vs execution_score';
        $lines[] = '';
        $lines[] = '| Eixo | Atlas | Rival | Leader | Amostra | Dimensões |';
        $lines[] = '|---|---:|---:|---|---:|---|';
        $lines[] = '| planning | '.($planning['atlas'] ?? '—').' | '.($planning['rival'] ?? '—').' | **'.$this->humanWinner($planning['leader'] ?? null).'** | '.((int) ($planning['sample_size'] ?? 0)).' | '.implode(', ', (array) ($planning['dimensions'] ?? [])).' |';
        $lines[] = '| execution | '.($execution['atlas'] ?? '—').' | '.($execution['rival'] ?? '—').' | **'.$this->humanWinner($execution['leader'] ?? null).'** | '.((int) ($execution['sample_size'] ?? 0)).' | '.implode(', ', (array) ($execution['dimensions'] ?? [])).' |';
        $lines[] = '';

        $lines[] = '## Onde Atlas é melhor';
        $lines[] = '';
        $atlasBetter = (array) ($matrix['atlas_better_in'] ?? []);
        $lines[] = '- **Categorias:** '.($this->joinOrDash((array) ($atlasBetter['categories'] ?? [])));
        $lines[] = '- **Dificuldades:** '.($this->joinOrDash((array) ($atlasBetter['difficulty_levels'] ?? [])));
        $lines[] = '';

        $lines[] = '## Onde Claude/Codex (Rival) é melhor';
        $lines[] = '';
        $rivalBetter = (array) ($matrix['rival_better_in'] ?? []);
        $lines[] = '- **Categorias:** '.($this->joinOrDash((array) ($rivalBetter['categories'] ?? [])));
        $lines[] = '- **Dificuldades:** '.($this->joinOrDash((array) ($rivalBetter['difficulty_levels'] ?? [])));
        $lines[] = '';

        $lines[] = '## Invalid cases';
        $lines[] = '';
        $invalidCases = (array) ($matrix['invalid_cases'] ?? []);
        if ($invalidCases === []) {
            $lines[] = '_Nenhum case inválido._';
        } else {
            $lines[] = '| Run | Case | Categoria | L | Verdict | Motivo |';
            $lines[] = '|---|---|---|---|---|---|';
            foreach ($invalidCases as $case) {
                $lines[] = '| `'.$case['run_id'].'` | `'.$case['case_id'].'` | '.($case['task_category'] ?? '—').' | '.($case['difficulty_level'] ?? '—').' | '.$case['verdict'].' | '.($case['reason'] ?? '—').' |';
            }
        }
        $lines[] = '';

        $lines[] = '## Suspicious cases';
        $lines[] = '';
        $suspiciousCases = (array) ($matrix['suspicious_cases'] ?? []);
        if ($suspiciousCases === []) {
            $lines[] = '_Nenhum case suspeito._';
        } else {
            $lines[] = '| Run | Case | Categoria | L | Verdict | Motivo |';
            $lines[] = '|---|---|---|---|---|---|';
            foreach ($suspiciousCases as $case) {
                $lines[] = '| `'.$case['run_id'].'` | `'.$case['case_id'].'` | '.($case['task_category'] ?? '—').' | '.($case['difficulty_level'] ?? '—').' | '.$case['verdict'].' | '.($case['reason'] ?? '—').' |';
            }
        }
        $lines[] = '';

        $atlasDecide = (array) ($matrix['atlas_decide_recommendation'] ?? []);
        $lines[] = '## Sinal medido para Atlas Decide';
        $lines[] = '';
        $lines[] = '- **Global:** `'.($atlasDecide['global_recommendation'] ?? 'insufficient_evidence').'`';
        $lines[] = '- **Status:** `'.($atlasDecide['status'] ?? 'insufficient_evidence').'`';
        $lines[] = '- **Advisory only:** `'.($atlasDecide['advisory_only'] ?? true ? 'true' : 'false').'` · **Topology update:** `false`';
        $lines[] = '- Rivals emits measured evidence; Atlas Decide decides model routing.';
        $lines[] = '';
        $entries = (array) ($atlasDecide['entries'] ?? []);
        if ($entries === []) {
            $lines[] = '_Sem sinal por categoria — amostragem insuficiente._';
        } else {
            $lines[] = '| Categoria | Sinal | Atlas win-rate | Rival win-rate | Amostra |';
            $lines[] = '|---|---|---:|---:|---:|';
            foreach ($entries as $entry) {
                $signal = (string) ($entry['signal'] ?? $entry['recommendation'] ?? 'insufficient_sample');
                $lines[] = '| `'.$entry['category'].'` | `'.$signal.'` | '.$entry['atlas_win_rate'].' | '.$entry['rival_win_rate'].' | '.$entry['sample_size'].' |';
            }
        }
        $lines[] = '';

        $lines[] = '---';
        $lines[] = '';
        $lines[] = '> **claim_ready:** `false` · **external_rivals_certification:** `blocked` · **provider_call:** `false`';
        $lines[] = '> Schema: `'.self::SCHEMA_VERSION.'`. Esta superfície descreve a evidência; não promove claim externo.';

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array{atlas:int,rival:int,tie:int,total:int,leader:?string}  $cell
     */
    private function heatmapBadge(array $cell): string
    {
        $leader = $cell['leader'] ?? null;
        $tag = match ($leader) {
            self::WINNER_ATLAS => 'A',
            self::WINNER_RIVAL => 'R',
            default => 'T',
        };

        return $tag.' '.$cell['atlas'].'/'.$cell['rival'].'/'.$cell['tie'];
    }

    private function humanWinner(?string $winner): string
    {
        return match ($winner) {
            self::WINNER_ATLAS => 'Atlas Forge',
            self::WINNER_RIVAL => 'Rival',
            self::WINNER_TIE => 'Empate',
            null => 'insufficient_evidence',
            default => $winner,
        };
    }

    /**
     * @param  list<string>  $values
     */
    private function joinOrDash(array $values): string
    {
        $values = array_values(array_filter($values, static fn ($v): bool => $v !== '' && $v !== null));
        if ($values === []) {
            return '—';
        }

        return implode(', ', array_map(static fn (string $v): string => '`'.$v.'`', $values));
    }

    /**
     * Resolve where the matrix report should be written. Default: the first
     * present run's evidence directory.
     *
     * @param  array<string,mixed>  $pack
     */
    private function resolveOutputDir(array $pack): ?string
    {
        $runs = is_array($pack['runs'] ?? null) ? $pack['runs'] : [];
        foreach ($runs as $run) {
            if (! is_array($run) || ($run['present'] ?? false) !== true) {
                continue;
            }
            $packPath = (string) ($run['pack_path'] ?? '');
            if ($packPath === '') {
                continue;
            }

            return dirname($packPath);
        }

        return null;
    }

    /**
     * @param  list<string>  $blockers
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function insufficientEnvelope(array $blockers, array $input): array
    {
        $matrix = [
            'schema_version' => self::SCHEMA_VERSION,
            'battery_id' => null,
            'generated_at' => now()->toJSON(),
            'run_ids' => (array) (
                is_string($input['run_ids'] ?? null) ? explode(',', (string) $input['run_ids']) : ($input['run_ids'] ?? [])
            ),
            'case_count' => 0,
            'comparable_scored_count' => 0,
            'invalid_count' => 0,
            'suspicious_count' => 0,
            'status' => 'insufficient_evidence',
            'overall' => $this->insufficientOverall(0, 0, 0),
            'category_ranking' => [],
            'difficulty_ranking' => [],
            'heatmap' => ['categories' => [], 'levels' => AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVELS, 'cells' => []],
            'planning_vs_execution' => [
                'planning' => ['atlas' => null, 'rival' => null, 'leader' => null, 'sample_size' => 0, 'dimensions' => self::PLANNING_DIMENSIONS],
                'execution' => ['atlas' => null, 'rival' => null, 'leader' => null, 'sample_size' => 0, 'dimensions' => self::EXECUTION_DIMENSIONS],
            ],
            'atlas_better_in' => ['categories' => [], 'difficulty_levels' => []],
            'rival_better_in' => ['categories' => [], 'difficulty_levels' => []],
            'invalid_cases' => [],
            'suspicious_cases' => [],
            'atlas_decide_recommendation' => $this->insufficientAtlasDecide(),
            'claim_ready' => false,
            'external_provider_call' => false,
            'external_rivals_certification_status' => 'blocked',
            'separated_from_external_rivals_certification' => true,
            'blockers' => $blockers,
            'note' => 'insufficient_evidence: battery agregada não retornou pack utilizável.',
        ];

        return [
            'status' => 'insufficient_evidence',
            'matrix_report' => $matrix,
            'blockers' => $blockers,
            'next_command' => 'rode `atlas:forge:rivals run-real` em cases reais antes do matrix-report',
            'external_provider_call' => false,
            'separated_from_external_rivals_certification' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $blob = (string) @file_get_contents($path);
        $row = json_decode($blob, true);

        return is_array($row) ? $row : [];
    }

    private function jsonEncode(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
