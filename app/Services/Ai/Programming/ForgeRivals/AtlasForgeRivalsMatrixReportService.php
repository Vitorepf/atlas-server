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

    private const MIN_DIFFERENTIATED_CAPABILITIES_FOR_STRONG_SIGNAL = 3;

    private readonly AtlasForgeRivalsProviderArenaCorpusService $corpus;

    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $paths,
        private readonly AtlasForgeRivalsBatteryEvidenceService $battery,
        ?AtlasForgeRivalsProviderArenaCorpusService $corpus = null,
    ) {
        $this->corpus = $corpus ?? new AtlasForgeRivalsProviderArenaCorpusService;
    }

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
        $capabilityRanking = $this->buildCapabilityRanking($comparableScored);
        $differentiation = $this->buildDifferentiationDiagnosis($overall, $capabilityRanking);
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
            'capability_ranking' => $capabilityRanking,
            'differentiation' => $differentiation,
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
            'measured_capabilities' => $this->capabilityKeysFromCase($case),
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
     * Aggregate the 360 capability axes that a case exercised. A global tie can
     * still be useful when the matrix says exactly which capabilities tied and
     * which still need harder samples.
     *
     * @param  list<array<string,mixed>>  $cases
     * @return array<string,mixed>
     */
    private function buildCapabilityRanking(array $cases): array
    {
        $buckets = [];
        foreach ($cases as $case) {
            foreach ((array) ($case['measured_capabilities'] ?? []) as $capability) {
                $key = trim((string) $capability);
                if ($key === '') {
                    continue;
                }
                $buckets[$key] ??= [
                    'capability' => $key,
                    'cases' => 0,
                    'atlas_wins' => 0,
                    'rival_wins' => 0,
                    'ties' => 0,
                    'atlas_score_sum' => 0.0,
                    'rival_score_sum' => 0.0,
                    'case_ids' => [],
                ];
                $buckets[$key]['cases']++;
                $buckets[$key]['atlas_score_sum'] += (float) ($case['atlas_score'] ?? 0);
                $buckets[$key]['rival_score_sum'] += (float) ($case['rival_score'] ?? 0);
                $buckets[$key]['case_ids'][] = (string) ($case['case_id'] ?? '');

                $winner = $case['winner'] ?? null;
                if ($winner === self::WINNER_ATLAS) {
                    $buckets[$key]['atlas_wins']++;
                } elseif ($winner === self::WINNER_RIVAL) {
                    $buckets[$key]['rival_wins']++;
                } else {
                    $buckets[$key]['ties']++;
                }
            }
        }

        $rows = [];
        $required = AtlasForgeRivalsReportService::REQUIRED_360_CAPABILITIES;
        foreach ($buckets as $key => $bucket) {
            $casesCount = (int) $bucket['cases'];
            $leader = $this->bucketLeader([
                'atlas' => (int) $bucket['atlas_wins'],
                'rival' => (int) $bucket['rival_wins'],
                'tie' => (int) $bucket['ties'],
                'total' => $casesCount,
            ]);
            $validity = $casesCount >= AtlasForgeRivalsReportService::MIN_CASES_PER_CAPABILITY_SIGNAL
                ? 'valid'
                : 'insufficient';
            $rows[] = [
                'capability' => $key,
                'cases' => $casesCount,
                'atlas_wins' => (int) $bucket['atlas_wins'],
                'rival_wins' => (int) $bucket['rival_wins'],
                'ties' => (int) $bucket['ties'],
                'tie_rate' => round((int) $bucket['ties'] / max(1, $casesCount), 4),
                'leader' => $leader,
                'atlas_avg_score' => round((float) $bucket['atlas_score_sum'] / max(1, $casesCount), 2),
                'rival_avg_score' => round((float) $bucket['rival_score_sum'] / max(1, $casesCount), 2),
                'validity' => $validity,
                'validity_reason' => $validity === 'valid' ? 'sample_size_and_evidence_ok' : 'small_sample_less_than_three',
                'separation_state' => $this->capabilitySeparationState($leader, $validity),
                'routing_effect' => 'none',
                'case_ids' => array_values(array_unique(array_filter($bucket['case_ids']))),
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            return ((int) $b['cases'] <=> (int) $a['cases'])
                ?: strcmp((string) $a['capability'], (string) $b['capability']);
        });

        $observed = array_column($rows, 'cases', 'capability');
        $missing = array_values(array_filter(
            $required,
            static fn (string $capability): bool => ! array_key_exists($capability, $observed),
        ));
        $underSampled = [];
        foreach ($required as $capability) {
            $count = (int) ($observed[$capability] ?? 0);
            if ($count > 0 && $count < AtlasForgeRivalsReportService::MIN_CASES_PER_CAPABILITY_SIGNAL) {
                $underSampled[$capability] = $count;
            }
        }
        $nextMeasurementPlan = $this->buildCapabilityNextMeasurementPlan($rows, $missing, $underSampled);

        return [
            'schema_version' => 'atlas.forge.rivals.matrix_capability_ranking.v1',
            'status' => $rows === [] ? 'insufficient_evidence' : 'advisory',
            'rows' => $rows,
            'required_360_capabilities' => $required,
            'observed_capability_count' => count($rows),
            'min_cases_per_capability_signal' => AtlasForgeRivalsReportService::MIN_CASES_PER_CAPABILITY_SIGNAL,
            'missing_required_capabilities' => $missing,
            'under_sampled_required_capabilities' => $underSampled,
            'floor_met' => $missing === [] && $underSampled === [],
            'next_measurement_plan' => $nextMeasurementPlan,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'note' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
        ];
    }

    /**
     * @param  array<string,mixed>  $overall
     * @param  array<string,mixed>  $capabilityRanking
     * @return array<string,mixed>
     */
    private function buildDifferentiationDiagnosis(array $overall, array $capabilityRanking): array
    {
        $rows = is_array($capabilityRanking['rows'] ?? null) ? (array) $capabilityRanking['rows'] : [];
        if ($rows === []) {
            return [
                'schema_version' => 'atlas.forge.rivals.differentiation_diagnosis.v1',
                'status' => 'insufficient_evidence',
                'differentiated_capability_count' => 0,
                'tied_capability_count' => 0,
                'insufficient_capability_count' => 0,
                'separation_ratio' => 0.0,
                'tie_is_diagnostic_not_claim' => true,
                'next_action' => 'run_extreme_differentiator_cases_before_claiming_runner_strength',
                'advisory_only' => true,
                'should_update_provider_topology' => false,
                'never_changes_atlas_decide_topology' => true,
                'owner_of_model_routing' => 'atlas_decide',
                'routing_effect' => 'none',
                'note' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
            ];
        }

        $differentiated = [];
        $tied = [];
        $insufficient = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $capability = (string) ($row['capability'] ?? '');
            if ($capability === '') {
                continue;
            }
            $state = (string) ($row['separation_state'] ?? $this->capabilitySeparationState(
                (string) ($row['leader'] ?? ''),
                (string) ($row['validity'] ?? 'insufficient'),
            ));

            if ($state === 'differentiated') {
                $differentiated[] = $capability;
            } elseif ($state === 'tied') {
                $tied[] = $capability;
            } else {
                $insufficient[] = $capability;
            }
        }

        $required = AtlasForgeRivalsReportService::REQUIRED_360_CAPABILITIES;
        $requiredDifferentiated = array_values(array_intersect($required, $differentiated));
        $requiredTied = array_values(array_intersect($required, $tied));
        $requiredInsufficient = array_values(array_intersect(
            $required,
            array_merge(
                $insufficient,
                (array) ($capabilityRanking['missing_required_capabilities'] ?? []),
                array_keys((array) ($capabilityRanking['under_sampled_required_capabilities'] ?? [])),
            ),
        ));
        $ratio = count($required) > 0 ? round(count($requiredDifferentiated) / count($required), 4) : 0.0;
        $overallWinner = $overall['winner'] ?? null;
        $strongSignal = count($requiredDifferentiated) >= self::MIN_DIFFERENTIATED_CAPABILITIES_FOR_STRONG_SIGNAL
            && in_array($overallWinner, [self::WINNER_ATLAS, self::WINNER_RIVAL], true);

        return [
            'schema_version' => 'atlas.forge.rivals.differentiation_diagnosis.v1',
            'status' => $strongSignal ? 'differentiated' : 'low_differentiation',
            'min_differentiated_required_capabilities_for_strong_signal' => self::MIN_DIFFERENTIATED_CAPABILITIES_FOR_STRONG_SIGNAL,
            'differentiated_capability_count' => count($differentiated),
            'tied_capability_count' => count($tied),
            'insufficient_capability_count' => count($insufficient),
            'required_differentiated_capabilities' => $requiredDifferentiated,
            'required_tied_capabilities' => $requiredTied,
            'required_insufficient_capabilities' => $requiredInsufficient,
            'separation_ratio' => $ratio,
            'overall_winner' => $overallWinner,
            'tie_is_diagnostic_not_claim' => true,
            'next_action' => $strongSignal
                ? 'continue_repetition_for_confidence_and_cost_receipts'
                : 'run_extreme_differentiator_cases_targeting_required_tied_or_insufficient_capabilities',
            'recommended_case_sets' => [
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_CEILING_360,
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_EXTREME_DIFFERENTIATOR,
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_META_PROVIDER_STRESS,
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_STATISTICAL_REPEAT,
            ],
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'note' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
        ];
    }

    private function capabilitySeparationState(string $leader, string $validity): string
    {
        if ($validity !== 'valid') {
            return 'insufficient_sample';
        }
        if (in_array($leader, [self::WINNER_ATLAS, self::WINNER_RIVAL], true)) {
            return 'differentiated';
        }

        return 'tied';
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  list<string>  $missing
     * @param  array<string,int>  $underSampled
     * @return array<string,mixed>
     */
    private function buildCapabilityNextMeasurementPlan(array $rows, array $missing, array $underSampled): array
    {
        $observedCasesByCapability = [];
        foreach ($rows as $row) {
            $capability = (string) ($row['capability'] ?? '');
            if ($capability === '') {
                continue;
            }
            $observedCasesByCapability[$capability] = $this->stringList($row['case_ids'] ?? []);
        }

        $requirements = [];
        foreach (AtlasForgeRivalsReportService::REQUIRED_360_CAPABILITIES as $capability) {
            $current = 0;
            foreach ($rows as $row) {
                if (($row['capability'] ?? null) === $capability) {
                    $current = (int) ($row['cases'] ?? 0);
                    break;
                }
            }
            if (! in_array($capability, $missing, true) && ! array_key_exists($capability, $underSampled)) {
                continue;
            }

            $additional = max(1, AtlasForgeRivalsReportService::MIN_CASES_PER_CAPABILITY_SIGNAL - $current);
            $candidates = $this->candidateCasesForCapability(
                capability: $capability,
                excludeCaseIds: $observedCasesByCapability[$capability] ?? [],
                limit: max(3, $additional),
            );

            $requirements[] = [
                'capability' => $capability,
                'current_cases' => $current,
                'required_additional_cases' => $additional,
                'recommended_case_sets' => $this->caseSetsForCapability($capability),
                'candidate_cases' => $candidates,
                'recommended_dry_run_commands' => array_values(array_map(
                    fn (array $case): string => $this->recommendedDryRunCommand($case),
                    $candidates,
                )),
                'battle_matrix' => $this->battleMatrixForCapability($capability, $candidates),
            ];
        }

        return [
            'schema_version' => 'atlas.forge.rivals.capability_next_measurement_plan.v1',
            'status' => $requirements === [] ? 'complete' : 'needs_more_measurement',
            'min_cases_per_capability_signal' => AtlasForgeRivalsReportService::MIN_CASES_PER_CAPABILITY_SIGNAL,
            'requirements' => $requirements,
            'provider_call' => false,
            'tokens_spent' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'note' => 'Dry-run commands only; real provider calls still require explicit confirmations and disk guard.',
        ];
    }

    /**
     * @param  list<string>  $excludeCaseIds
     * @return list<array<string,mixed>>
     */
    private function candidateCasesForCapability(string $capability, array $excludeCaseIds, int $limit): array
    {
        $pool = [];
        foreach ($this->caseSetsForCapability($capability) as $caseSet) {
            try {
                $pool = array_merge($pool, $this->corpus->casesForCaseSet($caseSet));
            } catch (\Throwable) {
                continue;
            }
        }

        $candidates = [];
        foreach ($pool as $case) {
            if (! is_array($case)) {
                continue;
            }
            $caseId = (string) ($case['case_id'] ?? '');
            if ($caseId === '' || in_array($caseId, $excludeCaseIds, true)) {
                continue;
            }
            if (! in_array($capability, $this->capabilityKeysFromCase($case), true)) {
                continue;
            }

            $candidates[$caseId] = [
                'case_id' => $caseId,
                'case_set' => $case['industrial_case_set'] ?? $case['case_set'] ?? AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_EXTREME_DIFFERENTIATOR,
                'task_category' => $case['task_category'] ?? null,
                'difficulty_level' => $case['difficulty_level'] ?? null,
                'ambiguity_level' => $case['ambiguity_level'] ?? null,
                'risk_level' => $case['risk_level'] ?? null,
                'measured_capabilities' => $this->capabilityKeysFromCase($case),
            ];
        }

        $candidates = array_values($candidates);
        usort($candidates, static function (array $a, array $b): int {
            $levelScore = ['L5' => 5, 'L4' => 4, 'L3' => 3, 'L2' => 2, 'L1' => 1];
            $riskScore = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
            $ambiguityScore = ['high' => 3, 'medium' => 2, 'low' => 1];

            return (($levelScore[(string) ($b['difficulty_level'] ?? '')] ?? 0) <=> ($levelScore[(string) ($a['difficulty_level'] ?? '')] ?? 0))
                ?: (($riskScore[(string) ($b['risk_level'] ?? '')] ?? 0) <=> ($riskScore[(string) ($a['risk_level'] ?? '')] ?? 0))
                ?: (($ambiguityScore[(string) ($b['ambiguity_level'] ?? '')] ?? 0) <=> ($ambiguityScore[(string) ($a['ambiguity_level'] ?? '')] ?? 0))
                ?: strcmp((string) ($a['case_id'] ?? ''), (string) ($b['case_id'] ?? ''));
        });

        return array_slice($candidates, 0, $limit);
    }

    /**
     * @param  list<array<string,mixed>>  $candidateCases
     * @return list<array<string,mixed>>
     */
    private function battleMatrixForCapability(string $capability, array $candidateCases): array
    {
        if ($candidateCases === []) {
            return [];
        }

        $first = $candidateCases[0];
        $taskCategory = (string) ($first['task_category'] ?? 'bugfix');
        $caseId = (string) ($first['case_id'] ?? '');
        if ($caseId === '') {
            return [];
        }

        return array_values(array_map(
            fn (array $battle): array => [
                'battle_id' => $battle['id'],
                'capability' => $capability,
                'case_id' => $caseId,
                'mode' => $battle['mode'],
                'arm_a' => $battle['arm_a'],
                'arm_a_model' => $battle['arm_a_model'],
                'arm_b' => $battle['arm_b'],
                'arm_b_model' => $battle['arm_b_model'],
                'dry_run_command' => $this->arenaDryRunCommand(
                    armA: $battle['arm_a'],
                    armAModel: $battle['arm_a_model'],
                    armB: $battle['arm_b'],
                    armBModel: $battle['arm_b_model'],
                    mode: $battle['mode'],
                    taskCategory: $taskCategory,
                    caseId: $caseId,
                ),
                'real_run_requires_confirmations' => ['runbook_reviewed', 'provider_cost', 'real_provider_call'],
                'provider_call' => false,
                'tokens_spent' => false,
                'routing_effect' => 'none',
            ],
            $this->canonicalCapabilityBattles(),
        ));
    }

    /**
     * @return list<array{id:string,mode:string,arm_a:string,arm_a_model:string,arm_b:string,arm_b_model:string}>
     */
    private function canonicalCapabilityBattles(): array
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

    /**
     * @param  array<string,mixed>  $case
     */
    private function recommendedDryRunCommand(array $case): string
    {
        return $this->arenaDryRunCommand(
            armA: 'atlas_forge',
            armAModel: 'sonnet',
            armB: 'claude_code',
            armBModel: 'sonnet',
            mode: 'provider_arena',
            taskCategory: (string) ($case['task_category'] ?? 'bugfix'),
            caseId: (string) ($case['case_id'] ?? ''),
        );
    }

    private function arenaDryRunCommand(
        string $armA,
        string $armAModel,
        string $armB,
        string $armBModel,
        string $mode,
        string $taskCategory,
        string $caseId,
    ): string {
        return 'php artisan atlas:forge:rivals run-arena'
            .' --arm-a='.$armA.' --arm-a-model='.$armAModel
            .' --arm-b='.$armB.' --arm-b-model='.$armBModel
            .' --mode='.$mode
            .' --task-category='.$taskCategory
            .' --case='.$caseId
            .' --prompt-mode=enterprise-change'
            .' --dry-run --json';
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
            'replayable_evidence_quality' => [
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_CEILING_360,
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_EXTREME_DIFFERENTIATOR,
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_META_PROVIDER_STRESS,
            ],
            'long_context_retention',
            'multi_step_reasoning',
            'ambiguous_human_prompt_handling' => [
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_CEILING_360,
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_META_PROVIDER_STRESS,
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_EXTREME_DIFFERENTIATOR,
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_STATISTICAL_REPEAT,
            ],
            default => [
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_CEILING_360,
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_EXTREME_DIFFERENTIATOR,
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_META_PROVIDER_STRESS,
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_STATISTICAL_REPEAT,
            ],
        };
    }

    /**
     * @param  array<string,mixed>  $case
     * @return list<string>
     */
    private function capabilityKeysFromCase(array $case): array
    {
        $profile = is_array($case['context_profile'] ?? null) ? (array) $case['context_profile'] : [];
        $extreme = is_array($case['extreme_differentiator'] ?? null) ? (array) $case['extreme_differentiator'] : [];
        $humanPrompt = is_array($case['human_prompt_probe'] ?? null) ? (array) $case['human_prompt_probe'] : [];

        $keys = array_merge(
            $this->stringList($case['measured_capabilities'] ?? []),
            $this->stringList($case['measurement_tags'] ?? []),
            $this->stringList($profile['measured_dimensions'] ?? []),
            $this->stringList($extreme['capability_axes'] ?? []),
            $this->stringList($extreme['measures'] ?? []),
        );

        if (($profile['requires_rollback_plan'] ?? false) === true) {
            $keys[] = 'rollback_safety';
        }
        if (($profile['requires_multi_step_plan'] ?? false) === true) {
            $keys[] = 'multi_step_reasoning';
        }
        if (($profile['requires_evidence_matrix'] ?? false) === true) {
            $keys[] = 'replayable_evidence_quality';
        }
        if (($profile['long_context_required'] ?? false) === true) {
            $keys[] = 'long_context_retention';
        }
        if (($humanPrompt['ambiguity_level'] ?? null) === 'high' || ($case['ambiguity_level'] ?? null) === 'high') {
            $keys[] = 'ambiguous_human_prompt_handling';
        }

        return $this->normaliseCapabilityKeys($keys);
    }

    /**
     * @param  list<string>  $capabilities
     * @return list<string>
     */
    private function normaliseCapabilityKeys(array $capabilities): array
    {
        $out = [];
        foreach ($capabilities as $capability) {
            $key = trim((string) $capability);
            if ($key === '') {
                continue;
            }
            $out[] = match ($key) {
                'multi_step_execution' => 'multi_step_reasoning',
                'evidence_replay_completeness' => 'replayable_evidence_quality',
                'ambiguity_resolution',
                'ambiguity_handling',
                'assumption_quality' => 'ambiguous_human_prompt_handling',
                'scope_boundary_probe' => 'scope_boundary_discipline',
                'contract_safety' => 'scope_boundary_discipline',
                default => $key,
            };
        }

        return array_values(array_unique($out));
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

        $capabilityRanking = (array) ($matrix['capability_ranking'] ?? []);
        $capabilityRows = (array) ($capabilityRanking['rows'] ?? []);
        $lines[] = '## Ranking por capacidade (360)';
        $lines[] = '';
        $lines[] = '- **Status:** `'.($capabilityRanking['status'] ?? 'insufficient_evidence').'`';
        $lines[] = '- **Capability floor met:** `'.((bool) ($capabilityRanking['floor_met'] ?? false) ? 'true' : 'false').'`';
        $lines[] = '- **Observed capabilities:** `'.((int) ($capabilityRanking['observed_capability_count'] ?? 0)).'`';
        if ($capabilityRows === []) {
            $lines[] = '_Nenhuma capacidade medida nos cases comparable+scored._';
        } else {
            $lines[] = '| Capacidade | Cases | Atlas | Rival | Empate | Leader | Validade |';
            $lines[] = '|---|---:|---:|---:|---:|---|---|';
            foreach ($capabilityRows as $row) {
                $lines[] = '| `'.$row['capability'].'` | '.$row['cases'].' | '.$row['atlas_wins'].' | '.$row['rival_wins'].' | '.$row['ties'].' | **'.$this->humanWinner($row['leader']).'** | `'.$row['validity'].'` |';
            }
        }
        $missingCapabilities = (array) ($capabilityRanking['missing_required_capabilities'] ?? []);
        $underSampledCapabilities = array_keys((array) ($capabilityRanking['under_sampled_required_capabilities'] ?? []));
        $lines[] = '- **Missing required:** '.$this->joinOrDash(array_map('strval', $missingCapabilities));
        $lines[] = '- **Under-sampled required:** '.$this->joinOrDash(array_map('strval', $underSampledCapabilities));
        $lines[] = '- Rivals emits measured evidence; Atlas Decide decides model routing.';
        $lines[] = '';

        $differentiation = (array) ($matrix['differentiation'] ?? []);
        $lines[] = '### Diagnóstico de diferenciação';
        $lines[] = '';
        $lines[] = '- **Status:** `'.($differentiation['status'] ?? 'insufficient_evidence').'`';
        $lines[] = '- **Required differentiated:** '.$this->joinOrDash(array_map('strval', (array) ($differentiation['required_differentiated_capabilities'] ?? [])));
        $lines[] = '- **Required tied:** '.$this->joinOrDash(array_map('strval', (array) ($differentiation['required_tied_capabilities'] ?? [])));
        $lines[] = '- **Required insufficient:** '.$this->joinOrDash(array_map('strval', (array) ($differentiation['required_insufficient_capabilities'] ?? [])));
        $lines[] = '- **Separation ratio:** `'.($differentiation['separation_ratio'] ?? 0.0).'`';
        $lines[] = '- **Next action:** `'.($differentiation['next_action'] ?? 'run_extreme_differentiator_cases_before_claiming_runner_strength').'`';
        $lines[] = '- **Tie is diagnostic:** `'.((bool) ($differentiation['tie_is_diagnostic_not_claim'] ?? true) ? 'true' : 'false').'`';
        $lines[] = '';

        $nextPlan = (array) ($capabilityRanking['next_measurement_plan'] ?? []);
        $requirements = (array) ($nextPlan['requirements'] ?? []);
        if ($requirements !== []) {
            $lines[] = '### Próximas medições recomendadas';
            $lines[] = '';
            $lines[] = '| Capacidade | Atual | Faltam | Casos sugeridos |';
            $lines[] = '|---|---:|---:|---|';
            foreach ($requirements as $requirement) {
                $candidateCases = array_values(array_map(
                    static fn (array $case): string => (string) ($case['case_id'] ?? ''),
                    array_slice((array) ($requirement['candidate_cases'] ?? []), 0, 3),
                ));
                $lines[] = '| `'.$requirement['capability'].'` | '.$requirement['current_cases'].' | '.$requirement['required_additional_cases'].' | '.$this->joinOrDash($candidateCases).' |';
            }
            $lines[] = '';
            $firstBattleMatrix = (array) ($requirements[0]['battle_matrix'] ?? []);
            if ($firstBattleMatrix !== []) {
                $lines[] = '### Matriz de batalhas sugerida';
                $lines[] = '';
                $lines[] = '| Battle | Caso | Comando dry-run |';
                $lines[] = '|---|---|---|';
                foreach ($firstBattleMatrix as $battle) {
                    $lines[] = '| `'.$battle['battle_id'].'` | `'.$battle['case_id'].'` | `'.$battle['dry_run_command'].'` |';
                }
                $lines[] = '';
            }
        }

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
            'capability_ranking' => [
                'schema_version' => 'atlas.forge.rivals.matrix_capability_ranking.v1',
                'status' => 'insufficient_evidence',
                'rows' => [],
                'required_360_capabilities' => AtlasForgeRivalsReportService::REQUIRED_360_CAPABILITIES,
                'observed_capability_count' => 0,
                'min_cases_per_capability_signal' => AtlasForgeRivalsReportService::MIN_CASES_PER_CAPABILITY_SIGNAL,
                'missing_required_capabilities' => AtlasForgeRivalsReportService::REQUIRED_360_CAPABILITIES,
                'under_sampled_required_capabilities' => [],
                'floor_met' => false,
                'advisory_only' => true,
                'should_update_provider_topology' => false,
                'never_changes_atlas_decide_topology' => true,
                'owner_of_model_routing' => 'atlas_decide',
                'routing_effect' => 'none',
                'note' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
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
