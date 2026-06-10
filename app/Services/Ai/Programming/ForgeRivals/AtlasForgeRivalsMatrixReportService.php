<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use App\Services\Ai\Support\JsonFileStore;

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
 *   - planning_score vs execution_score per arm (derived from strong
 *     evidence dimensions; planning = {objective_alignment,
 *     scope_discipline, evidence_quality}; execution =
 *     {test_quality, ceiling_360_contract}; diagnostic-only =
 *     {patch_focus, implementation_complexity, maintainability,
 *     risk_surface}; telemetry-only = {cost_time_efficiency}),
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

    public const CEILING_360_CONTRACT_MATRIX_SCHEMA_VERSION = 'atlas.forge.rivals.matrix_ceiling_360_contract.v1';

    /** @var list<string> */
    public const PLANNING_DIMENSIONS = ['objective_alignment', 'scope_discipline', 'evidence_quality'];

    /** @var list<string> */
    public const EXECUTION_DIMENSIONS = [
        'test_quality',
        'ceiling_360_contract',
    ];

    /** @var list<string> */
    public const DIAGNOSTIC_ONLY_DIMENSIONS = [
        'patch_focus',
        'implementation_complexity',
        'maintainability',
        'risk_surface',
    ];

    /** @var list<string> */
    public const TELEMETRY_ONLY_DIMENSIONS = ['cost_time_efficiency'];

    /** @var list<string> */
    private const ATLAS_DEV_RECOMMENDED_TASK_CATEGORIES = [
        'planning',
        'frontend',
        'backend',
        'bugfix',
        'tests',
        'refactor',
        'architecture',
        'docs',
        'performance',
        'security',
    ];

    public const WINNER_ATLAS = 'atlas';

    public const WINNER_RIVAL = 'rival';

    public const WINNER_TIE = 'tie';

    public const WINNER_NONE = null;

    private const MIN_DIFFERENTIATED_CAPABILITIES_FOR_STRONG_SIGNAL = 3;

    private const HIGH_TIE_RATE_THRESHOLD = 0.6;

    private const MAX_TECHNICAL_TIE_RATE_PER_DIFFICULTY_LEVEL = 0.55;

    private const TIE_ESCALATION_CANCEL_AFTER_TIES = 10;

    /** @var array<string,list<string>> */
    private const CEILING_360_MARKER_CAPABILITY_MAP = [
        'facts_assumptions_decisions_split' => [
            'ambiguous_human_prompt_handling',
            'scope_boundary_discipline',
            'honest_blocker_behavior',
        ],
        'tradeoff_matrix' => [
            'multi_step_reasoning',
            'capability_separation_signal',
        ],
        'rollback_plan' => [
            'rollback_safety',
            'scope_boundary_discipline',
        ],
        'negative_test_or_replay_probe' => [
            'non_obvious_regression_detection',
            'replayable_evidence_quality',
        ],
        'uncertainty_boundary' => [
            'uncertainty_boundary_quality',
            'honest_blocker_behavior',
        ],
        'production_invariant_reasoning' => [
            'production_invariant_reasoning',
            'adversarial_constraint_handling',
        ],
        'capability_specific_evidence' => [
            'capability_separation_signal',
            'replayable_evidence_quality',
        ],
        'contradiction_resolution' => [
            'contradiction_resolution_quality',
            'adversarial_constraint_handling',
            'multi_step_reasoning',
        ],
        'hidden_oracle_hypotheses' => [
            'hidden_oracle_reasoning',
            'honest_blocker_behavior',
            'uncertainty_boundary_quality',
        ],
        'failure_mode_matrix' => [
            'failure_mode_analysis',
            'non_obvious_regression_detection',
            'production_invariant_reasoning',
        ],
        'stop_block_criteria' => [
            'stop_block_criteria_quality',
            'honest_blocker_behavior',
            'scope_boundary_discipline',
        ],
        'telemetry_delta' => [
            'telemetry_delta_quality',
            'production_invariant_reasoning',
            'replayable_evidence_quality',
        ],
        'counterfactual_check' => [
            'counterfactual_reasoning',
            'adversarial_constraint_handling',
            'non_obvious_regression_detection',
        ],
        'blast_radius_quantification' => [
            'blast_radius_quantification',
            'production_invariant_reasoning',
            'scope_boundary_discipline',
        ],
        'confidence_calibration' => [
            'confidence_calibration',
            'uncertainty_boundary_quality',
            'honest_blocker_behavior',
        ],
    ];

    private readonly AtlasForgeRivalsProviderArenaCorpusService $corpus;

    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $paths,
        private readonly AtlasForgeRivalsBatteryEvidenceService $battery,
        ?AtlasForgeRivalsProviderArenaCorpusService $corpus = null,
        private readonly ?AtlasForgeRivalsProviderEvidenceDiskGuardService $evidenceDiskGuard = null,
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
        $manifestByRun = [];
        foreach ($runs as $run) {
            if (! is_array($run) || ($run['present'] ?? false) !== true) {
                continue;
            }
            $runId = (string) ($run['run_id'] ?? '');
            $paths = $this->paths->paths($runId);
            $scorecardByRun[$runId] = $this->readJson($paths['scorecard_json']);
            $manifestByRun[$runId] = $this->readJson($paths['manifest_json']);
        }

        foreach ($cases as $case) {
            if (! is_array($case)) {
                continue;
            }
            $runId = (string) ($case['run_id'] ?? '');
            $scorecard = $this->resolveCaseScorecard($case, $scorecardByRun[$runId] ?? []);
            $caseRows[] = $this->buildCaseRow($case, $scorecard, $manifestByRun[$runId] ?? []);
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
        $ceiling360ContractMatrix = $this->buildCeiling360ContractMatrix($comparableScored);
        $battleCoverage = $this->buildBattleCoverage($comparableScored);
        $tiePressure = $this->buildTiePressureDiagnosis($comparableScored, $differentiation);
        $ceiling360CompletionGap = $this->buildCeiling360CompletionGap(
            capabilityRanking: $capabilityRanking,
            ceiling360ContractMatrix: $ceiling360ContractMatrix,
            battleCoverage: $battleCoverage,
            tiePressure: $tiePressure,
            comparableCaseCount: count($comparableScored),
        );
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
            'score_decision_policy' => [
                'winner_uses_cost_time_efficiency' => false,
                'winner_uses_patch_shape_heuristics' => false,
                'telemetry_only_dimensions' => self::TELEMETRY_ONLY_DIMENSIONS,
                'diagnostic_only_dimensions' => self::DIAGNOSTIC_ONLY_DIMENSIONS,
                'winner_decision_excluded_dimensions' => [
                    ...self::TELEMETRY_ONLY_DIMENSIONS,
                    ...self::DIAGNOSTIC_ONLY_DIMENSIONS,
                ],
                'strong_quality_decision_policy' => 'winner_uses_only_strong_evidence_dimensions',
                'note' => 'Cost/token/time/efficiency and weak patch-shape heuristics are measured for operations analysis but excluded from round winners.',
            ],
            'capability_ranking' => $capabilityRanking,
            'differentiation' => $differentiation,
            'ceiling_360_contract_matrix' => $ceiling360ContractMatrix,
            'battle_coverage' => $battleCoverage,
            'tie_pressure_diagnosis' => $tiePressure,
            'ceiling_360_completion_gap' => $ceiling360CompletionGap,
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
            $writeStatus = $this->projectEvidenceDiskStatus(
                ($this->evidenceDiskGuard ?? new AtlasForgeRivalsProviderEvidenceDiskGuardService)
                    ->check($outputDir.'/'.self::MATRIX_REPORT_JSON_FILE)
            );
            if (($writeStatus['status'] ?? null) === 'ok') {
                @mkdir($outputDir, 0o755, true);
                $jsonPath = $outputDir.'/'.self::MATRIX_REPORT_JSON_FILE;
                $mdPath = $outputDir.'/'.self::MATRIX_REPORT_MD_FILE;
                file_put_contents($jsonPath, $this->jsonEncode($matrix));
                file_put_contents($mdPath, $this->renderMarkdown($matrix));
                $matrix['matrix_report_json_path'] = $jsonPath;
                $matrix['matrix_report_md_path'] = $mdPath;
                $matrix['matrix_report_json_sha256'] = hash_file('sha256', $jsonPath) ?: null;
                $matrix['matrix_report_md_sha256'] = hash_file('sha256', $mdPath) ?: null;
                $matrix['matrix_report_write_status'] = $writeStatus;
            } else {
                $matrix['matrix_report_json_path'] = null;
                $matrix['matrix_report_md_path'] = null;
                $matrix['matrix_report_json_sha256'] = null;
                $matrix['matrix_report_md_sha256'] = null;
                $matrix['matrix_report_write_status'] = $writeStatus;
                $matrix['matrix_report_write_blockers'] = $this->stringList($writeStatus['blockers'] ?? []);
            }
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
    private function buildCaseRow(array $case, array $scorecard, array $manifest = []): array
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
        $battle = $this->battleIdentityFromManifest($manifest, (string) ($case['task_category'] ?? ''));
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
            'diagnostic_only' => $planningExecution['diagnostic_only'],
            'telemetry' => $planningExecution['telemetry'],
            'measured_capabilities' => $this->capabilityKeysFromCase($case),
            'ceiling_360_contract' => $this->ceiling360ContractFromScorecard($scorecard),
            'battle' => $battle,
        ];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    private function battleIdentityFromManifest(array $manifest, string $caseTaskCategory = ''): array
    {
        $contracts = is_array($manifest['arena_contracts'] ?? null) ? (array) $manifest['arena_contracts'] : [];
        $armA = is_array($contracts['arm_a'] ?? null) ? (array) $contracts['arm_a'] : [];
        $armB = is_array($contracts['arm_b'] ?? null) ? (array) $contracts['arm_b'] : [];

        $armAId = (string) ($armA['arm_id'] ?? $manifest['arm_a'] ?? 'atlas_forge');
        $armBId = (string) ($armB['arm_id'] ?? $manifest['arm_b'] ?? 'claude_code');
        $armAModel = (string) ($armA['model_alias'] ?? $armA['requested_model'] ?? $manifest['arm_a_model'] ?? $manifest['atlas_model'] ?? 'sonnet');
        $armBModel = (string) ($armB['model_alias'] ?? $armB['requested_model'] ?? $manifest['arm_b_model'] ?? $manifest['rival_model'] ?? 'sonnet');
        $mode = (string) ($manifest['mode'] ?? 'provider_arena');

        $taskCategory = $caseTaskCategory !== '' ? $caseTaskCategory : (string) ($manifest['task_category'] ?? '');
        $battleId = $this->battleIdFor($armAId, $armAModel, $armBId, $armBModel, $mode, $taskCategory);

        return [
            'battle_id' => $battleId,
            'mode' => $mode,
            'arm_a' => $armAId,
            'arm_a_model' => $armAModel,
            'arm_a_provider' => $armA['provider'] ?? null,
            'arm_a_resolved_model_id' => $armA['resolved_model_id'] ?? null,
            'arm_b' => $armBId,
            'arm_b_model' => $armBModel,
            'arm_b_provider' => $armB['provider'] ?? null,
            'arm_b_resolved_model_id' => $armB['resolved_model_id'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $scorecard
     * @return array<string,mixed>|null
     */
    private function ceiling360ContractFromScorecard(array $scorecard): ?array
    {
        $dims = is_array($scorecard['quality_dimensions'] ?? null) ? $scorecard['quality_dimensions'] : [];
        $dimension = is_array($dims['ceiling_360_contract'] ?? null) ? $dims['ceiling_360_contract'] : null;
        if ($dimension === null || ($dimension['markers']['required'] ?? false) !== true) {
            return null;
        }

        return $dimension;
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
     *   execution: array{atlas:?float,rival:?float,dimensions:list<string>},
     *   diagnostic_only: array{atlas:?float,rival:?float,dimensions:list<string>,excluded_from_winner:bool},
     *   telemetry: array{atlas:?float,rival:?float,dimensions:list<string>,excluded_from_winner:bool}
     * }
     */
    private function splitPlanningExecutionFromScorecard(array $scorecard): array
    {
        $dims = is_array($scorecard['quality_dimensions'] ?? null) ? $scorecard['quality_dimensions'] : null;
        $planning = ['atlas' => null, 'rival' => null, 'dimensions' => self::PLANNING_DIMENSIONS];
        $execution = ['atlas' => null, 'rival' => null, 'dimensions' => self::EXECUTION_DIMENSIONS];
        $diagnostic = ['atlas' => null, 'rival' => null, 'dimensions' => self::DIAGNOSTIC_ONLY_DIMENSIONS, 'excluded_from_winner' => true];
        $telemetry = ['atlas' => null, 'rival' => null, 'dimensions' => self::TELEMETRY_ONLY_DIMENSIONS, 'excluded_from_winner' => true];
        if ($dims === null) {
            return ['planning' => $planning, 'execution' => $execution, 'diagnostic_only' => $diagnostic, 'telemetry' => $telemetry];
        }

        $planning = $this->averageDimensions($dims, self::PLANNING_DIMENSIONS, self::PLANNING_DIMENSIONS);
        $execution = $this->averageDimensions($dims, self::EXECUTION_DIMENSIONS, self::EXECUTION_DIMENSIONS);
        $diagnostic = [
            ...$this->averageDimensions($dims, self::DIAGNOSTIC_ONLY_DIMENSIONS, self::DIAGNOSTIC_ONLY_DIMENSIONS),
            'excluded_from_winner' => true,
        ];
        $telemetry = [
            ...$this->averageDimensions($dims, self::TELEMETRY_ONLY_DIMENSIONS, self::TELEMETRY_ONLY_DIMENSIONS),
            'excluded_from_winner' => true,
        ];

        return ['planning' => $planning, 'execution' => $execution, 'diagnostic_only' => $diagnostic, 'telemetry' => $telemetry];
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
        $diagnosticAtlas = 0.0;
        $diagnosticRival = 0.0;
        $diagnosticCount = 0;
        $telemetryAtlas = 0.0;
        $telemetryRival = 0.0;
        $telemetryCount = 0;
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
            $diagnostic = $case['diagnostic_only'] ?? null;
            if (is_array($diagnostic) && is_numeric($diagnostic['atlas']) && is_numeric($diagnostic['rival'])) {
                $diagnosticAtlas += (float) $diagnostic['atlas'];
                $diagnosticRival += (float) $diagnostic['rival'];
                $diagnosticCount++;
            }
            $telemetry = $case['telemetry'] ?? null;
            if (is_array($telemetry) && is_numeric($telemetry['atlas']) && is_numeric($telemetry['rival'])) {
                $telemetryAtlas += (float) $telemetry['atlas'];
                $telemetryRival += (float) $telemetry['rival'];
                $telemetryCount++;
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
            'diagnostic_only' => $diagnosticCount === 0 ? [
                'atlas' => null,
                'rival' => null,
                'leader' => null,
                'sample_size' => 0,
                'dimensions' => self::DIAGNOSTIC_ONLY_DIMENSIONS,
                'excluded_from_winner' => true,
            ] : [
                'atlas' => round($diagnosticAtlas / $diagnosticCount, 2),
                'rival' => round($diagnosticRival / $diagnosticCount, 2),
                'leader' => $this->pairLeader($diagnosticAtlas, $diagnosticRival),
                'sample_size' => $diagnosticCount,
                'dimensions' => self::DIAGNOSTIC_ONLY_DIMENSIONS,
                'excluded_from_winner' => true,
            ],
            'telemetry_only' => $telemetryCount === 0 ? [
                'atlas' => null,
                'rival' => null,
                'leader' => null,
                'sample_size' => 0,
                'dimensions' => self::TELEMETRY_ONLY_DIMENSIONS,
                'excluded_from_winner' => true,
            ] : [
                'atlas' => round($telemetryAtlas / $telemetryCount, 2),
                'rival' => round($telemetryRival / $telemetryCount, 2),
                'leader' => $this->pairLeader($telemetryAtlas, $telemetryRival),
                'sample_size' => $telemetryCount,
                'dimensions' => self::TELEMETRY_ONLY_DIMENSIONS,
                'excluded_from_winner' => true,
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
            $tieRate = round((int) $bucket['ties'] / max(1, $casesCount), 4);
            $rows[] = [
                'capability' => $key,
                'cases' => $casesCount,
                'atlas_wins' => (int) $bucket['atlas_wins'],
                'rival_wins' => (int) $bucket['rival_wins'],
                'ties' => (int) $bucket['ties'],
                'tie_rate' => $tieRate,
                'leader' => $leader,
                'atlas_avg_score' => round((float) $bucket['atlas_score_sum'] / max(1, $casesCount), 2),
                'rival_avg_score' => round((float) $bucket['rival_score_sum'] / max(1, $casesCount), 2),
                'validity' => $validity,
                'validity_reason' => $validity === 'valid' ? 'sample_size_and_evidence_ok' : 'small_sample_less_than_three',
                'separation_state' => $this->capabilitySeparationState($leader, $validity, $tieRate),
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
            } elseif (in_array($state, ['tied', 'tied_high_tie_rate'], true)) {
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

    /**
     * Aggregate the semantic L5+ ceiling contract across matrix cases. This is
     * intentionally separate from the global winner: two runners can tie on
     * weighted score while one has stronger 360 contract evidence, or both can
     * miss the contract floor.
     *
     * @param  list<array<string,mixed>>  $cases
     * @return array<string,mixed>
     */
    private function buildCeiling360ContractMatrix(array $cases): array
    {
        $rows = [];
        $missing = 0;
        $differentiated = 0;
        $atlasAhead = 0;
        $rivalAhead = 0;
        $ties = 0;
        $floorScore = 100.0;
        $belowFloor = 0;
        $sharedMissingMarkers = [];
        $anyMissingMarkers = [];

        foreach ($cases as $case) {
            $dimension = is_array($case['ceiling_360_contract'] ?? null) ? $case['ceiling_360_contract'] : null;
            if ($dimension === null) {
                $missing++;

                continue;
            }

            $atlasMarkers = is_array($dimension['markers']['atlas'] ?? null) ? (array) $dimension['markers']['atlas'] : [];
            $rivalMarkers = is_array($dimension['markers']['rival'] ?? null) ? (array) $dimension['markers']['rival'] : [];
            $atlasScore = is_numeric($dimension['atlas'] ?? null) ? (float) $dimension['atlas'] : null;
            $rivalScore = is_numeric($dimension['rival'] ?? null) ? (float) $dimension['rival'] : null;
            $markerDelta = [];

            foreach (array_unique(array_merge(array_keys($atlasMarkers), array_keys($rivalMarkers))) as $marker) {
                $marker = (string) $marker;
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
                    $leader = self::WINNER_ATLAS;
                    $atlasAhead++;
                    $differentiated++;
                } elseif ($rivalScore > $atlasScore) {
                    $leader = self::WINNER_RIVAL;
                    $rivalAhead++;
                    $differentiated++;
                } else {
                    $leader = self::WINNER_TIE;
                    $ties++;
                }
            }

            $rows[] = [
                'run_id' => (string) ($case['run_id'] ?? ''),
                'case_id' => (string) ($case['case_id'] ?? 'unknown-case'),
                'task_category' => $case['task_category'] ?? null,
                'difficulty_level' => $case['difficulty_level'] ?? null,
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

        $observed = count($rows);
        $observedCaseIds = array_values(array_filter(array_map(
            static fn (array $row): string => (string) ($row['case_id'] ?? ''),
            $rows,
        )));
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
            'schema_version' => self::CEILING_360_CONTRACT_MATRIX_SCHEMA_VERSION,
            'purpose' => 'aggregate_l5_plus_contract_differences_across_matrix_cases',
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
            'next_measurement_plan' => $this->buildCeiling360NextMeasurementPlan(
                anyMissingMarkers: $anyMissingMarkers,
                sharedMissingMarkers: $sharedMissingMarkers,
                observedCaseIds: $observedCaseIds,
            ),
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'note' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
            'cases' => $rows,
        ];
    }

    /**
     * 360 model comprehension requires battle diversity. Capability coverage
     * alone can be satisfied by repeated same-pair L5 cases, so this signal
     * tracks which canonical runner/model battles have real comparable
     * evidence.
     *
     * @param  list<array<string,mixed>>  $cases
     * @return array<string,mixed>
     */
    private function buildBattleCoverage(array $cases): array
    {
        $canonical = $this->canonicalCapabilityBattles();
        $canonicalById = [];
        foreach ($canonical as $battle) {
            $canonicalById[$battle['id']] = $battle;
        }

        $observed = [];
        foreach ($cases as $case) {
            $battle = is_array($case['battle'] ?? null) ? (array) $case['battle'] : [];
            $battleId = (string) ($battle['battle_id'] ?? '');
            if ($battleId === '') {
                continue;
            }
            $observed[$battleId] ??= [
                'battle_id' => $battleId,
                'canonical' => array_key_exists($battleId, $canonicalById),
                'mode' => $battle['mode'] ?? null,
                'arm_a' => $battle['arm_a'] ?? null,
                'arm_a_model' => $battle['arm_a_model'] ?? null,
                'arm_a_provider' => $battle['arm_a_provider'] ?? null,
                'arm_a_resolved_model_id' => $battle['arm_a_resolved_model_id'] ?? null,
                'arm_b' => $battle['arm_b'] ?? null,
                'arm_b_model' => $battle['arm_b_model'] ?? null,
                'arm_b_provider' => $battle['arm_b_provider'] ?? null,
                'arm_b_resolved_model_id' => $battle['arm_b_resolved_model_id'] ?? null,
                'cases' => 0,
                'case_ids' => [],
            ];
            $observed[$battleId]['cases']++;
            $observed[$battleId]['case_ids'][] = (string) ($case['case_id'] ?? '');
        }

        $missing = [];
        foreach ($canonicalById as $battleId => $battle) {
            if (isset($observed[$battleId])) {
                continue;
            }
            $dryRunCommand = $this->arenaDryRunCommand(
                armA: $battle['arm_a'],
                armAModel: $battle['arm_a_model'],
                armB: $battle['arm_b'],
                armBModel: $battle['arm_b_model'],
                mode: $battle['mode'],
                taskCategory: $battle['task_category'],
                caseId: $battle['case_id'],
            );
            $missing[] = array_merge($battle, [
                'dry_run_command' => $dryRunCommand,
                'real_run_command_when_ready' => $this->arenaRealRunCommand($dryRunCommand),
                'provider_call' => false,
                'tokens_spent' => false,
                'routing_effect' => 'none',
            ]);
        }

        $observedRows = array_values($observed);
        usort($observedRows, static fn (array $a, array $b): int => strcmp((string) $a['battle_id'], (string) $b['battle_id']));

        $floorMet = $missing === [] && $observedRows !== [];
        $observedCanonicalBattleCount = count(array_filter($observedRows, static fn (array $row): bool => (bool) ($row['canonical'] ?? false)));
        $evidenceDisk = ($this->evidenceDiskGuard ?? new AtlasForgeRivalsProviderEvidenceDiskGuardService)
            ->check($this->paths->rootDirectory().'/battle-coverage-next-probe');
        $evidenceDiskStatus = $this->projectEvidenceDiskStatus($evidenceDisk);
        $realRunReady = $missing !== [] && ($evidenceDiskStatus['status'] ?? null) === 'ok';
        $nextMissingBattle = $missing[0] ?? null;

        return [
            'schema_version' => 'atlas.forge.rivals.matrix_battle_coverage.v1',
            'status' => $floorMet ? 'battle_floor_met' : 'needs_more_battle_diversity',
            'canonical_battle_count' => count($canonical),
            'observed_battle_count' => $observedCanonicalBattleCount,
            'observed_non_canonical_battle_count' => count(array_filter($observedRows, static fn (array $row): bool => ! (bool) ($row['canonical'] ?? false))),
            'missing_battle_count' => count($missing),
            'coverage_ratio' => count($canonical) > 0 ? round($observedCanonicalBattleCount / count($canonical), 4) : 0.0,
            'floor_met' => $floorMet,
            'observed_battles' => $observedRows,
            'missing_canonical_battles' => $missing,
            'next_measurement_plan' => [
                'schema_version' => 'atlas.forge.rivals.battle_coverage_next_measurement_plan.v1',
                'status' => $missing === [] ? 'complete' : 'needs_missing_canonical_battles',
                'requirements' => $missing,
                'next_missing_battle' => $nextMissingBattle,
                'required_real_runs_remaining' => count($missing),
                'dry_run_commands' => array_values(array_map(
                    static fn (array $battle): string => (string) ($battle['dry_run_command'] ?? ''),
                    $missing,
                )),
                'real_run_commands_when_ready' => array_values(array_map(
                    static fn (array $battle): string => (string) ($battle['real_run_command_when_ready'] ?? ''),
                    $missing,
                )),
                'dry_run_ready' => true,
                'real_run_ready' => $realRunReady,
                'real_run_blockers' => $realRunReady ? [] : $this->stringList($evidenceDiskStatus['blockers'] ?? []),
                'evidence_disk_status' => $evidenceDiskStatus,
                'required_confirmations_for_real_run' => ['runbook_reviewed', 'provider_cost', 'real_provider_call'],
                'provider_call' => false,
                'tokens_spent' => false,
                'advisory_only' => true,
                'should_update_provider_topology' => false,
                'never_changes_atlas_decide_topology' => true,
                'owner_of_model_routing' => 'atlas_decide',
                'routing_effect' => 'none',
            ],
            'tie_is_diagnostic_not_claim' => true,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'note' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $cases
     * @param  array<string,mixed>  $differentiation
     * @return array<string,mixed>
     */
    private function buildTiePressureDiagnosis(array $cases, array $differentiation): array
    {
        $total = count($cases);
        $ties = 0;
        $l5Cases = 0;
        $l5Ties = 0;
        $marginSum = 0.0;
        $maxMargin = 0.0;
        $tiedCaseIds = [];
        $levelTieBuckets = [];
        foreach (AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVELS as $level) {
            $levelTieBuckets[$level] = ['level' => $level, 'cases' => 0, 'ties' => 0];
        }

        foreach ($cases as $case) {
            $winner = (string) ($case['winner'] ?? '');
            $margin = abs((float) ($case['margin'] ?? ((float) ($case['atlas_score'] ?? 0) - (float) ($case['rival_score'] ?? 0))));
            $marginSum += $margin;
            $maxMargin = max($maxMargin, $margin);

            $isTie = ! in_array($winner, [self::WINNER_ATLAS, self::WINNER_RIVAL], true);
            if ($isTie) {
                $ties++;
                $tiedCaseIds[] = (string) ($case['case_id'] ?? 'unknown-case');
            }

            if ((string) ($case['difficulty_level'] ?? '') === 'L5') {
                $l5Cases++;
                if ($isTie) {
                    $l5Ties++;
                }
            }

            $level = (string) ($case['difficulty_level'] ?? '');
            if (isset($levelTieBuckets[$level])) {
                $levelTieBuckets[$level]['cases']++;
                if ($isTie) {
                    $levelTieBuckets[$level]['ties']++;
                }
            }
        }

        $tieRate = $total > 0 ? round($ties / $total, 4) : 0.0;
        $l5TieRate = $l5Cases > 0 ? round($l5Ties / $l5Cases, 4) : 0.0;
        $avgMargin = $total > 0 ? round($marginSum / $total, 2) : 0.0;
        $targetCapabilities = array_values(array_unique(array_merge(
            $this->stringList($differentiation['required_tied_capabilities'] ?? []),
            $this->stringList($differentiation['required_insufficient_capabilities'] ?? []),
        )));
        if ($targetCapabilities === []) {
            $targetCapabilities = AtlasForgeRivalsReportService::REQUIRED_360_CAPABILITIES;
        }

        $followupCommands = [];
        $candidateCases = [];
        foreach (array_slice($targetCapabilities, 0, 3) as $capability) {
            foreach ($this->candidateCasesForCapability($capability, $tiedCaseIds, 1) as $candidate) {
                $candidateCases[(string) ($candidate['case_id'] ?? '')] = $candidate;
                $followupCommands[] = $this->recommendedDryRunCommand($candidate);
            }
        }
        $followupCommands = array_values(array_unique(array_filter($followupCommands)));

        $perLevelTieEscalation = $this->buildPerLevelTieEscalation($levelTieBuckets);
        $levelsExceedingTieBudget = $this->stringList($perLevelTieEscalation['levels_exceeding_tie_budget'] ?? []);
        $shouldCancelCurrentBattery = $ties >= self::TIE_ESCALATION_CANCEL_AFTER_TIES || $levelsExceedingTieBudget !== [];
        $requiresHarderFollowup = $shouldCancelCurrentBattery || (
            $total > 0
            && ($tieRate >= self::HIGH_TIE_RATE_THRESHOLD || ($l5Cases > 0 && $l5TieRate >= self::HIGH_TIE_RATE_THRESHOLD))
        );
        $status = match (true) {
            $total === 0 => 'no_comparable_cases',
            $levelsExceedingTieBudget !== [] => 'per_level_tie_escalation_cancel_and_increase_complexity',
            $shouldCancelCurrentBattery => 'tie_escalation_cancel_and_increase_baseline_complexity',
            $requiresHarderFollowup && $l5Cases > 0 => 'l5_tie_pressure_unresolved',
            $requiresHarderFollowup => 'high_tie_rate_needs_extreme_pressure',
            default => 'differentiating_enough_for_current_sample',
        };

        return [
            'schema_version' => 'atlas.forge.rivals.tie_pressure_diagnosis.v1',
            'purpose' => 'detect_easy_or_underpressured_batteries_where_global_ties_hide_runner_differences',
            'status' => $status,
            'case_count' => $total,
            'tie_count' => $ties,
            'tie_rate' => $tieRate,
            'l5_case_count' => $l5Cases,
            'l5_tie_count' => $l5Ties,
            'l5_tie_rate' => $l5TieRate,
            'average_abs_margin' => $avgMargin,
            'max_abs_margin' => round($maxMargin, 2),
            'high_tie_rate_threshold' => self::HIGH_TIE_RATE_THRESHOLD,
            'per_level_tie_escalation' => $perLevelTieEscalation,
            'tie_escalation_policy' => [
                'schema_version' => 'atlas.forge.rivals.tie_escalation_policy.v1',
                'cancel_after_tie_count' => self::TIE_ESCALATION_CANCEL_AFTER_TIES,
                'max_technical_tie_rate_per_difficulty_level' => self::MAX_TECHNICAL_TIE_RATE_PER_DIFFICULTY_LEVEL,
                'should_cancel_current_battery' => $shouldCancelCurrentBattery,
                'required_action' => $shouldCancelCurrentBattery
                    ? 'cancel_current_battery_and_raise_complexity_for_over_tied_levels'
                    : 'continue_until_cancel_threshold_or_sufficient_differentiation',
                'next_baseline_complexity' => $shouldCancelCurrentBattery
                    ? 'multi_capability_composite_l5_plus_plus'
                    : 'current_or_targeted_extreme_followup',
                'levels_to_reinforce' => $levelsExceedingTieBudget,
                'must_metric_more_capabilities' => $shouldCancelCurrentBattery,
                'cost_efficiency_role' => 'telemetry_only_excluded_from_winner',
            ],
            'requires_harder_followup' => $requiresHarderFollowup,
            'target_capabilities' => $targetCapabilities,
            'candidate_cases' => array_values(array_filter($candidateCases)),
            'recommended_case_sets' => [
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_CEILING_360,
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_EXTREME_DIFFERENTIATOR,
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_META_PROVIDER_STRESS,
                AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_STATISTICAL_REPEAT,
            ],
            'recommended_dry_run_commands' => array_slice($followupCommands, 0, 6),
            'tie_is_diagnostic_not_claim' => true,
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
     * @param  array<string,array{level:string,cases:int,ties:int}>  $levelTieBuckets
     * @return array<string,mixed>
     */
    private function buildPerLevelTieEscalation(array $levelTieBuckets): array
    {
        $rows = [];
        $levelsExceeding = [];
        foreach (AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVELS as $level) {
            $bucket = $levelTieBuckets[$level] ?? ['level' => $level, 'cases' => 0, 'ties' => 0];
            $cases = (int) ($bucket['cases'] ?? 0);
            $ties = (int) ($bucket['ties'] ?? 0);
            $tieRate = $cases > 0 ? round($ties / $cases, 4) : null;
            $exceeds = $tieRate !== null && $tieRate >= self::MAX_TECHNICAL_TIE_RATE_PER_DIFFICULTY_LEVEL;
            if ($exceeds) {
                $levelsExceeding[] = $level;
            }
            $rows[] = [
                'level' => $level,
                'case_count' => $cases,
                'technical_tie_count' => $ties,
                'technical_tie_rate' => $tieRate,
                'max_allowed_technical_tie_rate' => self::MAX_TECHNICAL_TIE_RATE_PER_DIFFICULTY_LEVEL,
                'exceeds_tie_budget' => $exceeds,
                'required_action' => $exceeds
                    ? 'stop_this_level_and_increase_complexity_functions_and_capability_measurement'
                    : ($cases === 0 ? 'collect_level_sample' : 'continue_measuring'),
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
     * @param  array<string,mixed>  $capabilityRanking
     * @param  array<string,mixed>  $ceiling360ContractMatrix
     * @param  array<string,mixed>  $battleCoverage
     * @param  array<string,mixed>  $tiePressure
     * @return array<string,mixed>
     */
    private function buildCeiling360CompletionGap(
        array $capabilityRanking,
        array $ceiling360ContractMatrix,
        array $battleCoverage,
        array $tiePressure,
        int $comparableCaseCount,
    ): array {
        $battlePlan = (array) ($battleCoverage['next_measurement_plan'] ?? []);
        $evidenceDiskStatus = (array) ($battlePlan['evidence_disk_status'] ?? []);
        $capabilityFloorMet = (bool) ($capabilityRanking['floor_met'] ?? false);
        $contractFloorMet = in_array((string) ($ceiling360ContractMatrix['status'] ?? ''), [
            'differentiated',
            'tied_at_contract_floor',
        ], true);
        $battleFloorMet = (bool) ($battleCoverage['floor_met'] ?? false);
        $evidenceDiskReady = ($evidenceDiskStatus['status'] ?? null) === 'ok';
        $realRunCommands = (array) ($battlePlan['real_run_commands_when_ready'] ?? []);

        $blockers = [];
        if ($comparableCaseCount <= 0) {
            $blockers[] = 'no_comparable_scored_cases';
        }
        if (! $capabilityFloorMet) {
            $blockers[] = 'capability_floor_not_met';
        }
        if (! $contractFloorMet) {
            $blockers[] = 'ceiling_360_contract_floor_not_met';
        }
        if (! $battleFloorMet) {
            $blockers[] = 'canonical_battle_floor_not_met';
        }
        if ((bool) ($tiePressure['requires_harder_followup'] ?? false)) {
            $blockers[] = 'tie_pressure_requires_harder_followup';
        }
        if (! $evidenceDiskReady) {
            $blockers = array_merge(
                $blockers,
                $this->stringList($evidenceDiskStatus['blockers'] ?? ['provider_evidence_disk_space_insufficient']),
            );
        }

        $status = match (true) {
            $blockers === [] => 'ceiling_360_practical_limit_measured',
            ! $evidenceDiskReady => 'blocked_by_evidence_disk',
            (bool) ($tiePressure['requires_harder_followup'] ?? false) => 'needs_extreme_tie_breaker_pressure',
            ! $battleFloorMet => 'needs_canonical_battle_runs',
            ! $contractFloorMet => 'needs_contract_floor_pressure',
            ! $capabilityFloorMet => 'needs_capability_floor_pressure',
            default => 'needs_more_360_evidence',
        };

        return [
            'schema_version' => 'atlas.forge.rivals.ceiling_360_completion_gap.v1',
            'status' => $status,
            'ready_for_360_claim' => $blockers === [],
            'comparable_case_count' => $comparableCaseCount,
            'capability_floor_met' => $capabilityFloorMet,
            'contract_floor_met' => $contractFloorMet,
            'battle_floor_met' => $battleFloorMet,
            'tie_pressure_resolved' => ! (bool) ($tiePressure['requires_harder_followup'] ?? false),
            'evidence_disk_ready' => $evidenceDiskReady,
            'observed_battle_count' => (int) ($battleCoverage['observed_battle_count'] ?? 0),
            'canonical_battle_count' => (int) ($battleCoverage['canonical_battle_count'] ?? 0),
            'battle_coverage_ratio' => (float) ($battleCoverage['coverage_ratio'] ?? 0.0),
            'required_real_runs_remaining' => (int) ($battlePlan['required_real_runs_remaining'] ?? 0),
            'next_missing_battle' => $battlePlan['next_missing_battle'] ?? null,
            'next_real_run_command_when_ready' => $realRunCommands[0] ?? null,
            'blockers' => array_values(array_unique($blockers)),
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
     * @param  array<string,int>  $anyMissingMarkers
     * @param  array<string,int>  $sharedMissingMarkers
     * @param  list<string>  $observedCaseIds
     * @return array<string,mixed>
     */
    private function buildCeiling360NextMeasurementPlan(
        array $anyMissingMarkers,
        array $sharedMissingMarkers,
        array $observedCaseIds,
    ): array {
        $evidenceDisk = ($this->evidenceDiskGuard ?? new AtlasForgeRivalsProviderEvidenceDiskGuardService)
            ->check($this->paths->rootDirectory().'/matrix-next-probe');
        $evidenceDiskStatus = $this->projectEvidenceDiskStatus($evidenceDisk);
        $realRunReady = ($evidenceDiskStatus['status'] ?? null) === 'ok';
        $requirements = [];
        foreach ($anyMissingMarkers as $marker => $count) {
            $marker = (string) $marker;
            $capabilities = self::CEILING_360_MARKER_CAPABILITY_MAP[$marker] ?? [];
            $candidates = $this->candidateCasesForCeilingMarker(
                marker: $marker,
                capabilities: $capabilities,
                excludeCaseIds: $observedCaseIds,
                limit: 3,
            );

            $requirements[] = [
                'marker' => $marker,
                'missing_count' => (int) $count,
                'shared_missing_count' => (int) ($sharedMissingMarkers[$marker] ?? 0),
                'target_capabilities' => $capabilities,
                'recommended_case_sets' => [
                    AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_CEILING_360,
                    AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_EXTREME_DIFFERENTIATOR,
                    AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_META_PROVIDER_STRESS,
                ],
                'candidate_cases' => $candidates,
                'recommended_dry_run_commands' => array_values(array_map(
                    fn (array $case): string => $this->recommendedDryRunCommand($case),
                    $candidates,
                )),
                'battle_matrix' => $this->battleMatrixForCeilingMarker($marker, $capabilities, $candidates),
            ];
        }

        usort($requirements, static function (array $a, array $b): int {
            return ((int) ($b['shared_missing_count'] ?? 0) <=> (int) ($a['shared_missing_count'] ?? 0))
                ?: ((int) ($b['missing_count'] ?? 0) <=> (int) ($a['missing_count'] ?? 0))
                ?: strcmp((string) ($a['marker'] ?? ''), (string) ($b['marker'] ?? ''));
        });
        $compositePlan = $this->buildCeiling360CompositeNextMeasurementPlan(
            requirements: $requirements,
            observedCaseIds: $observedCaseIds,
        );

        return [
            'schema_version' => 'atlas.forge.rivals.ceiling_360_marker_next_measurement_plan.v1',
            'status' => $requirements === [] ? 'complete' : 'needs_more_marker_pressure',
            'planning_mode' => 'multi_capability_composite_pressure',
            'single_marker_plan_is_advisory' => true,
            'requirements' => $requirements,
            'composite_next_measurement_plan' => $compositePlan,
            'dry_run_ready' => true,
            'real_run_ready' => $requirements !== [] && $realRunReady,
            'real_run_blockers' => $realRunReady ? [] : $this->stringList($evidenceDiskStatus['blockers'] ?? []),
            'evidence_disk_status' => $evidenceDiskStatus,
            'required_confirmations_for_real_run' => ['runbook_reviewed', 'provider_cost', 'real_provider_call'],
            'provider_call' => false,
            'tokens_spent' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'note' => 'Dry-run marker pressure only; real provider calls still require confirmations and disk guard.',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $requirements
     * @param  list<string>  $observedCaseIds
     * @return array<string,mixed>
     */
    private function buildCeiling360CompositeNextMeasurementPlan(array $requirements, array $observedCaseIds): array
    {
        $targetMarkers = [];
        $targetCapabilities = [];
        foreach ($requirements as $requirement) {
            $marker = (string) ($requirement['marker'] ?? '');
            if ($marker !== '') {
                $targetMarkers[] = $marker;
            }
            $targetCapabilities = array_merge(
                $targetCapabilities,
                $this->stringList($requirement['target_capabilities'] ?? []),
            );
        }
        $targetMarkers = array_values(array_unique($targetMarkers));
        $targetCapabilities = array_values(array_unique($targetCapabilities));

        $candidates = $this->candidateCasesForCompositeCeilingPressure(
            targetMarkers: $targetMarkers,
            targetCapabilities: $targetCapabilities,
            excludeCaseIds: $observedCaseIds,
            limit: 5,
        );

        return [
            'schema_version' => 'atlas.forge.rivals.ceiling_360_composite_next_measurement_plan.v1',
            'status' => $candidates === [] ? 'complete' : 'needs_multi_capability_pressure',
            'purpose' => 'avoid single-marker batteries by selecting cases that pressure several L5++ capabilities at once',
            'target_markers' => $targetMarkers,
            'target_capabilities' => $targetCapabilities,
            'candidate_cases' => $candidates,
            'recommended_dry_run_commands' => [
                'atlas_dev_vs_claude_sonnet' => array_values(array_map(
                    fn (array $case): string => $this->arenaDryRunCommandForCase($case, 'atlas_dev', 'sonnet', 'claude_code', 'sonnet'),
                    $candidates,
                )),
                'atlas_dev_escalated_architecture_vs_claude_sonnet' => array_values(array_map(
                    fn (array $case): string => $this->arenaDryRunCommandForCase($case, 'atlas_dev', 'sonnet', 'claude_code', 'sonnet'),
                    $candidates,
                )),
                'atlas_dev_architecture_pressure_vs_claude_sonnet' => array_values(array_map(
                    fn (array $case): string => $this->arenaDryRunCommandForCase($case, 'atlas_dev', 'sonnet', 'claude_code', 'sonnet'),
                    $candidates,
                )),
            ],
            'recommended_real_run_commands_when_ready' => [
                'atlas_dev_vs_claude_sonnet' => array_values(array_map(
                    fn (array $case): string => $this->arenaRealRunCommand(
                        $this->arenaDryRunCommandForCase($case, 'atlas_dev', 'sonnet', 'claude_code', 'sonnet'),
                    ),
                    $candidates,
                )),
                'atlas_dev_escalated_architecture_vs_claude_sonnet' => array_values(array_map(
                    fn (array $case): string => $this->arenaRealRunCommand(
                        $this->arenaDryRunCommandForCase($case, 'atlas_dev', 'sonnet', 'claude_code', 'sonnet'),
                    ),
                    $candidates,
                )),
                'atlas_dev_architecture_pressure_vs_claude_sonnet' => array_values(array_map(
                    fn (array $case): string => $this->arenaRealRunCommand(
                        $this->arenaDryRunCommandForCase($case, 'atlas_dev', 'sonnet', 'claude_code', 'sonnet'),
                    ),
                    $candidates,
                )),
            ],
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
     * @param  list<string>  $targetMarkers
     * @param  list<string>  $targetCapabilities
     * @param  list<string>  $excludeCaseIds
     * @return list<array<string,mixed>>
     */
    private function candidateCasesForCompositeCeilingPressure(
        array $targetMarkers,
        array $targetCapabilities,
        array $excludeCaseIds,
        int $limit,
    ): array {
        $pool = [];
        foreach ([
            AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_CEILING_360,
            AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_EXTREME_DIFFERENTIATOR,
            AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_META_PROVIDER_STRESS,
            AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_STATISTICAL_REPEAT,
        ] as $caseSet) {
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

            $caseCapabilities = $this->capabilityKeysFromCase($case);
            $matchedCapabilities = array_values(array_intersect($targetCapabilities, $caseCapabilities));
            if ($targetCapabilities !== [] && $matchedCapabilities === []) {
                continue;
            }

            $pressure = is_array($case['ceiling_pressure_profile'] ?? null) ? (array) $case['ceiling_pressure_profile'] : [];
            $requiredSections = $this->stringList($pressure['required_sections'] ?? []);
            $matchedMarkers = array_values(array_intersect($targetMarkers, $requiredSections));
            $compositeScore = count($matchedCapabilities) + count($matchedMarkers);

            $candidates[$caseId] = [
                'case_id' => $caseId,
                'case_set' => $case['industrial_case_set'] ?? $case['case_set'] ?? AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_CEILING_360,
                'task_category' => $case['task_category'] ?? null,
                'difficulty_level' => $case['difficulty_level'] ?? null,
                'ambiguity_level' => $case['ambiguity_level'] ?? null,
                'risk_level' => $case['risk_level'] ?? null,
                'composite_score' => $compositeScore,
                'target_markers' => $matchedMarkers,
                'target_capabilities' => $matchedCapabilities,
                'measured_capabilities' => $caseCapabilities,
            ];
        }

        $candidates = array_values($candidates);
        usort($candidates, static function (array $a, array $b): int {
            $levelScore = ['L5' => 5, 'L4' => 4, 'L3' => 3, 'L2' => 2, 'L1' => 1];
            $riskScore = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
            $ambiguityScore = ['high' => 3, 'medium' => 2, 'low' => 1];

            return ((int) ($b['composite_score'] ?? 0) <=> (int) ($a['composite_score'] ?? 0))
                ?: (($levelScore[(string) ($b['difficulty_level'] ?? '')] ?? 0) <=> ($levelScore[(string) ($a['difficulty_level'] ?? '')] ?? 0))
                ?: (($riskScore[(string) ($b['risk_level'] ?? '')] ?? 0) <=> ($riskScore[(string) ($a['risk_level'] ?? '')] ?? 0))
                ?: (($ambiguityScore[(string) ($b['ambiguity_level'] ?? '')] ?? 0) <=> ($ambiguityScore[(string) ($a['ambiguity_level'] ?? '')] ?? 0))
                ?: strcmp((string) ($a['case_id'] ?? ''), (string) ($b['case_id'] ?? ''));
        });

        return array_slice($candidates, 0, $limit);
    }

    /**
     * @param  list<string>  $capabilities
     * @param  list<string>  $excludeCaseIds
     * @return list<array<string,mixed>>
     */
    private function candidateCasesForCeilingMarker(string $marker, array $capabilities, array $excludeCaseIds, int $limit): array
    {
        $pool = [];
        foreach ([
            AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_CEILING_360,
            AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_EXTREME_DIFFERENTIATOR,
            AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_META_PROVIDER_STRESS,
        ] as $caseSet) {
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
            $caseCapabilities = $this->capabilityKeysFromCase($case);
            if ($capabilities !== [] && array_intersect($capabilities, $caseCapabilities) === []) {
                continue;
            }

            $pressure = is_array($case['ceiling_pressure_profile'] ?? null) ? (array) $case['ceiling_pressure_profile'] : [];
            $requiredSections = $this->stringList($pressure['required_sections'] ?? []);
            $invalidIfMissing = $this->stringList($pressure['invalid_if_missing'] ?? []);
            $candidates[$caseId] = [
                'case_id' => $caseId,
                'case_set' => $case['industrial_case_set'] ?? $case['case_set'] ?? AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_CEILING_360,
                'task_category' => $case['task_category'] ?? null,
                'difficulty_level' => $case['difficulty_level'] ?? null,
                'ambiguity_level' => $case['ambiguity_level'] ?? null,
                'risk_level' => $case['risk_level'] ?? null,
                'marker' => $marker,
                'required_sections' => $requiredSections,
                'invalid_if_missing' => $invalidIfMissing,
                'measured_capabilities' => $caseCapabilities,
            ];
        }

        $candidates = array_values($candidates);
        usort($candidates, static function (array $a, array $b): int {
            $levelScore = ['L5' => 5, 'L4' => 4, 'L3' => 3, 'L2' => 2, 'L1' => 1];
            $riskScore = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
            $ambiguityScore = ['high' => 3, 'medium' => 2, 'low' => 1];
            $aAtlasDevSupported = in_array((string) ($a['task_category'] ?? ''), self::ATLAS_DEV_RECOMMENDED_TASK_CATEGORIES, true) ? 1 : 0;
            $bAtlasDevSupported = in_array((string) ($b['task_category'] ?? ''), self::ATLAS_DEV_RECOMMENDED_TASK_CATEGORIES, true) ? 1 : 0;

            return (($levelScore[(string) ($b['difficulty_level'] ?? '')] ?? 0) <=> ($levelScore[(string) ($a['difficulty_level'] ?? '')] ?? 0))
                ?: (($riskScore[(string) ($b['risk_level'] ?? '')] ?? 0) <=> ($riskScore[(string) ($a['risk_level'] ?? '')] ?? 0))
                ?: ($bAtlasDevSupported <=> $aAtlasDevSupported)
                ?: (($ambiguityScore[(string) ($b['ambiguity_level'] ?? '')] ?? 0) <=> ($ambiguityScore[(string) ($a['ambiguity_level'] ?? '')] ?? 0))
                ?: strcmp((string) ($a['case_id'] ?? ''), (string) ($b['case_id'] ?? ''));
        });

        return array_slice($candidates, 0, $limit);
    }

    /**
     * @param  list<string>  $capabilities
     * @param  list<array<string,mixed>>  $candidateCases
     * @return list<array<string,mixed>>
     */
    private function battleMatrixForCeilingMarker(string $marker, array $capabilities, array $candidateCases): array
    {
        $battles = $this->battleMatrixForCapability($capabilities[0] ?? 'capability_separation_signal', $candidateCases);

        return array_values(array_map(
            static fn (array $battle): array => array_merge($battle, [
                'marker' => $marker,
                'target_capabilities' => $capabilities,
            ]),
            $battles,
        ));
    }

    private function capabilitySeparationState(string $leader, string $validity, ?float $tieRate = null): string
    {
        if ($validity !== 'valid') {
            return 'insufficient_sample';
        }
        if ($tieRate !== null && $tieRate >= self::HIGH_TIE_RATE_THRESHOLD) {
            return 'tied_high_tie_rate';
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
            $taskCategory = (string) ($case['task_category'] ?? '');
            if (! in_array($taskCategory, self::ATLAS_DEV_RECOMMENDED_TASK_CATEGORIES, true)) {
                continue;
            }

            $candidates[$caseId] = [
                'case_id' => $caseId,
                'case_set' => $case['industrial_case_set'] ?? $case['case_set'] ?? AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_EXTREME_DIFFERENTIATOR,
                'task_category' => $taskCategory,
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
     * @return list<array{id:string,mode:string,arm_a:string,arm_a_model:string,arm_b:string,arm_b_model:string,task_category:string,case_id:string}>
     */
    private function canonicalCapabilityBattles(): array
    {
        return [
            [
                'id' => 'atlas_dev_architecture_escalated_vs_claude_sonnet',
                'mode' => 'provider_arena',
                'arm_a' => 'atlas_dev',
                'arm_a_model' => 'sonnet',
                'arm_b' => 'claude_code',
                'arm_b_model' => 'sonnet',
                'task_category' => 'architecture',
                'case_id' => 'ceiling-360-003-industrial-015-incomplete_requirements',
            ],
            [
                'id' => 'atlas_dev_vs_claude_sonnet',
                'mode' => 'provider_arena',
                'arm_a' => 'atlas_dev',
                'arm_a_model' => 'sonnet',
                'arm_b' => 'claude_code',
                'arm_b_model' => 'sonnet',
                'task_category' => 'architecture',
                'case_id' => 'ceiling-360-003-industrial-015-incomplete_requirements',
            ],
            [
                'id' => 'atlas_dev_architecture_pressure_vs_claude_sonnet',
                'mode' => 'provider_arena',
                'arm_a' => 'atlas_dev',
                'arm_a_model' => 'sonnet',
                'arm_b' => 'claude_code',
                'arm_b_model' => 'sonnet',
                'task_category' => 'refactor',
                'case_id' => 'ceiling-360-001-industrial-005-incident_rollback',
            ],
            [
                'id' => 'composer_2_5_vs_codex_gpt_5_5',
                'mode' => 'provider_arena',
                'arm_a' => 'composer_2_5',
                'arm_a_model' => 'default',
                'arm_b' => 'codex_cli',
                'arm_b_model' => 'gpt-5.5',
                'task_category' => 'refactor',
                'case_id' => 'ceiling-360-001-industrial-005-incident_rollback',
            ],
            [
                'id' => 'cursor_default_vs_claude_sonnet',
                'mode' => 'provider_arena',
                'arm_a' => 'cursor_cli',
                'arm_a_model' => 'default',
                'arm_b' => 'claude_code',
                'arm_b_model' => 'sonnet',
                'task_category' => 'refactor',
                'case_id' => 'ceiling-360-001-industrial-005-incident_rollback',
            ],
            [
                'id' => 'claude_sonnet_vs_codex_gpt_5_5',
                'mode' => 'provider_arena',
                'arm_a' => 'claude_code',
                'arm_a_model' => 'sonnet',
                'arm_b' => 'codex_cli',
                'arm_b_model' => 'gpt-5.5',
                'task_category' => 'security',
                'case_id' => 'ceiling-360-007-industrial-035-security',
            ],
            [
                'id' => 'codex_gpt_5_5_vs_gemini_pro',
                'mode' => 'provider_arena',
                'arm_a' => 'codex_cli',
                'arm_a_model' => 'gpt-5.5',
                'arm_b' => 'gemini_cli',
                'arm_b_model' => 'gemini-pro',
                'task_category' => 'performance',
                'case_id' => 'ceiling-360-005-industrial-025-performance',
            ],
            [
                'id' => 'claude_sonnet_vs_claude_opus',
                'mode' => 'provider_arena',
                'arm_a' => 'claude_code',
                'arm_a_model' => 'sonnet',
                'arm_b' => 'claude_code',
                'arm_b_model' => 'opus',
                'task_category' => 'architecture',
                'case_id' => 'ceiling-360-003-industrial-015-incomplete_requirements',
            ],
            [
                'id' => 'atlas_forge_full_power_vs_claude_opus',
                'mode' => 'full_power',
                'arm_a' => 'atlas_forge',
                'arm_a_model' => 'sonnet',
                'arm_b' => 'claude_code',
                'arm_b_model' => 'opus',
                'task_category' => 'architecture',
                'case_id' => 'ceiling-360-003-industrial-015-incomplete_requirements',
            ],
        ];
    }

    private function battleIdFor(string $armA, string $armAModel, string $armB, string $armBModel, string $mode, string $taskCategory = ''): string
    {
        foreach ($this->canonicalCapabilityBattles() as $battle) {
            if (
                $battle['arm_a'] === $armA
                && $battle['arm_a_model'] === $armAModel
                && $battle['arm_b'] === $armB
                && $battle['arm_b_model'] === $armBModel
                && $battle['mode'] === $mode
                && ($taskCategory === '' || $battle['task_category'] === $taskCategory)
            ) {
                return $battle['id'];
            }
        }

        return implode('_vs_', [
            $this->normaliseBattleToken($armA.'_'.$armAModel),
            $this->normaliseBattleToken($armB.'_'.$armBModel),
            $this->normaliseBattleToken($mode),
        ]);
    }

    private function normaliseBattleToken(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?: $value;

        return trim($value, '_');
    }

    /**
     * @param  array<string,mixed>  $case
     */
    private function recommendedDryRunCommand(array $case): string
    {
        return $this->arenaDryRunCommandForCase($case, 'atlas_dev', 'sonnet', 'claude_code', 'sonnet');
    }

    /**
     * @param  array<string,mixed>  $case
     */
    private function arenaDryRunCommandForCase(
        array $case,
        string $armA,
        string $armAModel,
        string $armB,
        string $armBModel,
    ): string {
        return $this->arenaDryRunCommand(
            armA: $armA,
            armAModel: $armAModel,
            armB: $armB,
            armBModel: $armBModel,
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

    private function arenaRealRunCommand(string $dryRunCommand): string
    {
        return str_replace(
            ' --dry-run --json',
            ' --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call --json',
            $dryRunCommand,
        );
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
     * @param  array<string,mixed>  $evidenceDisk
     * @return array<string,mixed>
     */
    private function projectEvidenceDiskStatus(array $evidenceDisk): array
    {
        return [
            'status' => $evidenceDisk['status'] ?? 'unknown',
            'path' => $evidenceDisk['path'] ?? null,
            'required_free_bytes' => $evidenceDisk['required_free_bytes'] ?? null,
            'free_bytes' => $evidenceDisk['free_bytes'] ?? null,
            'blockers' => $this->stringList($evidenceDisk['blockers'] ?? []),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
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
        $diagnosticOnly = (array) ($pve['diagnostic_only'] ?? []);
        $telemetryOnly = (array) ($pve['telemetry_only'] ?? []);
        $lines[] = '## planning_score vs execution_score';
        $lines[] = '';
        $lines[] = '| Eixo | Atlas | Rival | Leader | Amostra | Dimensões | Policy |';
        $lines[] = '|---|---:|---:|---|---:|---|---|';
        $lines[] = '| planning | '.($planning['atlas'] ?? '—').' | '.($planning['rival'] ?? '—').' | **'.$this->humanWinner($planning['leader'] ?? null).'** | '.((int) ($planning['sample_size'] ?? 0)).' | '.implode(', ', (array) ($planning['dimensions'] ?? [])).' | winner signal |';
        $lines[] = '| execution | '.($execution['atlas'] ?? '—').' | '.($execution['rival'] ?? '—').' | **'.$this->humanWinner($execution['leader'] ?? null).'** | '.((int) ($execution['sample_size'] ?? 0)).' | '.implode(', ', (array) ($execution['dimensions'] ?? [])).' | winner signal |';
        $lines[] = '| diagnostic_only | '.($diagnosticOnly['atlas'] ?? '—').' | '.($diagnosticOnly['rival'] ?? '—').' | **'.$this->humanWinner($diagnosticOnly['leader'] ?? null).'** | '.((int) ($diagnosticOnly['sample_size'] ?? 0)).' | '.implode(', ', (array) ($diagnosticOnly['dimensions'] ?? [])).' | excluded_from_winner |';
        $lines[] = '| telemetry_only | '.($telemetryOnly['atlas'] ?? '—').' | '.($telemetryOnly['rival'] ?? '—').' | **'.$this->humanWinner($telemetryOnly['leader'] ?? null).'** | '.((int) ($telemetryOnly['sample_size'] ?? 0)).' | '.implode(', ', (array) ($telemetryOnly['dimensions'] ?? [])).' | excluded_from_winner |';
        $lines[] = '- **Cost/token/time/efficiency and patch-shape heuristics:** measured as telemetry/diagnostics, excluded from round winners.';
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

        $tiePressure = (array) ($matrix['tie_pressure_diagnosis'] ?? []);
        $lines[] = '### Pressao de empate';
        $lines[] = '';
        $lines[] = '- **Status:** `'.($tiePressure['status'] ?? 'no_comparable_cases').'`';
        $lines[] = '- **Tie rate / L5 tie rate:** `'.($tiePressure['tie_rate'] ?? 0).'` / `'.($tiePressure['l5_tie_rate'] ?? 0).'`';
        $lines[] = '- **Requires harder followup:** `'.((bool) ($tiePressure['requires_harder_followup'] ?? false) ? 'true' : 'false').'`';
        $perLevelTie = (array) ($tiePressure['per_level_tie_escalation'] ?? []);
        $lines[] = '- **Max technical tie rate per L1-L5:** `'.($perLevelTie['max_allowed_technical_tie_rate'] ?? self::MAX_TECHNICAL_TIE_RATE_PER_DIFFICULTY_LEVEL).'`';
        $lines[] = '- **Levels over tie budget:** '.$this->joinOrDash(array_map('strval', (array) ($perLevelTie['levels_exceeding_tie_budget'] ?? [])));
        $tieEscalation = (array) ($tiePressure['tie_escalation_policy'] ?? []);
        $lines[] = '- **Cancel after ties / cancel now:** `'.((int) ($tieEscalation['cancel_after_tie_count'] ?? self::TIE_ESCALATION_CANCEL_AFTER_TIES)).'` / `'.((bool) ($tieEscalation['should_cancel_current_battery'] ?? false) ? 'true' : 'false').'`';
        $lines[] = '- **Cost efficiency role:** `'.($tieEscalation['cost_efficiency_role'] ?? 'telemetry_only_excluded_from_winner').'`';
        $lines[] = '- **Target capabilities:** '.$this->joinOrDash(array_map('strval', (array) ($tiePressure['target_capabilities'] ?? [])));
        $lines[] = '- Rivals emits measured evidence; Atlas Decide decides model routing.';
        $lines[] = '';

        $ceilingMatrix = (array) ($matrix['ceiling_360_contract_matrix'] ?? []);
        $lines[] = '### Matriz ceiling_360_contract';
        $lines[] = '';
        $lines[] = '- **Status:** `'.($ceilingMatrix['status'] ?? 'not_observed').'`';
        $lines[] = '- **Observed / missing contract cases:** `'.((int) ($ceilingMatrix['observed_cases'] ?? 0)).'` / `'.((int) ($ceilingMatrix['missing_contract_cases'] ?? 0)).'`';
        $lines[] = '- **Differentiated / Atlas ahead / Rival ahead / Ties:** `'.((int) ($ceilingMatrix['differentiated_cases'] ?? 0)).'` / `'.((int) ($ceilingMatrix['atlas_ahead_cases'] ?? 0)).'` / `'.((int) ($ceilingMatrix['rival_ahead_cases'] ?? 0)).'` / `'.((int) ($ceilingMatrix['tie_cases'] ?? 0)).'`';
        $lines[] = '- **Below contract floor:** `'.((int) ($ceilingMatrix['below_contract_floor_cases'] ?? 0)).'` · **Separation ratio:** `'.($ceilingMatrix['separation_ratio'] ?? 0.0).'`';
        $lines[] = '- **Shared missing markers:** '.$this->joinOrDash(array_keys((array) ($ceilingMatrix['shared_missing_markers'] ?? [])));
        $lines[] = '- **Any missing markers:** '.$this->joinOrDash(array_keys((array) ($ceilingMatrix['any_missing_markers'] ?? [])));
        $markerPlan = (array) ($ceilingMatrix['next_measurement_plan'] ?? []);
        $markerRequirements = (array) ($markerPlan['requirements'] ?? []);
        $lines[] = '- **Marker next measurement:** `'.($markerPlan['status'] ?? 'complete').'`';
        $lines[] = '- **Real run ready:** `'.((bool) ($markerPlan['real_run_ready'] ?? false) ? 'true' : 'false').'` · **Evidence disk:** `'.(data_get($markerPlan, 'evidence_disk_status.status') ?? 'unknown').'`';
        $ceilingCases = (array) ($ceilingMatrix['cases'] ?? []);
        if ($ceilingCases !== []) {
            $lines[] = '';
            $lines[] = '| Case | L | Atlas | Rival | Leader | Floor | Marker deltas |';
            $lines[] = '|---|---|---:|---:|---|---|---|';
            foreach ($ceilingCases as $case) {
                $deltaMarkers = array_keys((array) ($case['marker_delta'] ?? []));
                $lines[] = '| `'.$case['case_id'].'` | '.($case['difficulty_level'] ?? '—').' | '.($case['atlas_score'] ?? '—').' | '.($case['rival_score'] ?? '—').' | **'.$this->humanWinner($case['leader'] ?? null).'** | `'.((bool) ($case['floor_met'] ?? false) ? 'true' : 'false').'` | '.$this->joinOrDash($deltaMarkers).' |';
            }
        }
        if ($markerRequirements !== []) {
            $lines[] = '';
            $lines[] = '| Marker | Missing | Shared | Capabilities | Suggested cases |';
            $lines[] = '|---|---:|---:|---|---|';
            foreach ($markerRequirements as $requirement) {
                $candidateCases = array_values(array_map(
                    static fn (array $case): string => (string) ($case['case_id'] ?? ''),
                    array_slice((array) ($requirement['candidate_cases'] ?? []), 0, 3),
                ));
                $lines[] = '| `'.$requirement['marker'].'` | '.$requirement['missing_count'].' | '.$requirement['shared_missing_count'].' | '.$this->joinOrDash((array) ($requirement['target_capabilities'] ?? [])).' | '.$this->joinOrDash($candidateCases).' |';
            }
        }
        $lines[] = '- Rivals emits measured evidence; Atlas Decide decides model routing.';
        $lines[] = '';

        $battleCoverage = (array) ($matrix['battle_coverage'] ?? []);
        $lines[] = '### Cobertura de batalhas 360';
        $lines[] = '';
        $lines[] = '- **Status:** `'.($battleCoverage['status'] ?? 'needs_more_battle_diversity').'`';
        $lines[] = '- **Observed canonical battles:** `'.((int) ($battleCoverage['observed_battle_count'] ?? 0)).'` / `'.((int) ($battleCoverage['canonical_battle_count'] ?? 0)).'`';
        $lines[] = '- **Missing canonical battles:** `'.((int) ($battleCoverage['missing_battle_count'] ?? 0)).'`';
        $lines[] = '- **Battle floor met:** `'.((bool) ($battleCoverage['floor_met'] ?? false) ? 'true' : 'false').'`';
        $battlePlan = (array) ($battleCoverage['next_measurement_plan'] ?? []);
        $lines[] = '- **Real run ready:** `'.((bool) ($battlePlan['real_run_ready'] ?? false) ? 'true' : 'false').'` · **Evidence disk:** `'.(data_get($battlePlan, 'evidence_disk_status.status') ?? 'unknown').'`';
        $observedBattles = (array) ($battleCoverage['observed_battles'] ?? []);
        if ($observedBattles !== []) {
            $lines[] = '';
            $lines[] = '| Battle | Cases | Case ids |';
            $lines[] = '|---|---:|---|';
            foreach ($observedBattles as $battle) {
                $lines[] = '| `'.$battle['battle_id'].'` | '.$battle['cases'].' | '.$this->joinOrDash((array) ($battle['case_ids'] ?? [])).' |';
            }
        }
        $missingBattles = (array) ($battleCoverage['missing_canonical_battles'] ?? []);
        if ($missingBattles !== []) {
            $lines[] = '';
            $lines[] = '| Missing battle | Dry-run |';
            $lines[] = '|---|---|';
            foreach (array_slice($missingBattles, 0, 6) as $battle) {
                $lines[] = '| `'.$battle['id'].'` | `'.$battle['dry_run_command'].'` |';
            }
        }
        $lines[] = '- Rivals emits measured evidence; Atlas Decide decides model routing.';
        $lines[] = '';

        $completionGap = (array) ($matrix['ceiling_360_completion_gap'] ?? []);
        $lines[] = '### Gap para 360 prático';
        $lines[] = '';
        $lines[] = '- **Status:** `'.($completionGap['status'] ?? 'needs_more_360_evidence').'`';
        $lines[] = '- **Ready for 360 claim:** `'.((bool) ($completionGap['ready_for_360_claim'] ?? false) ? 'true' : 'false').'`';
        $lines[] = '- **Capability / contract / battle / disk:** `'.((bool) ($completionGap['capability_floor_met'] ?? false) ? 'true' : 'false').'` / `'.((bool) ($completionGap['contract_floor_met'] ?? false) ? 'true' : 'false').'` / `'.((bool) ($completionGap['battle_floor_met'] ?? false) ? 'true' : 'false').'` / `'.((bool) ($completionGap['evidence_disk_ready'] ?? false) ? 'true' : 'false').'`';
        $lines[] = '- **Battle coverage:** `'.($completionGap['observed_battle_count'] ?? 0).'` / `'.($completionGap['canonical_battle_count'] ?? 0).'` · **Remaining real runs:** `'.($completionGap['required_real_runs_remaining'] ?? 0).'`';
        $nextBattle = (array) ($completionGap['next_missing_battle'] ?? []);
        if ($nextBattle !== []) {
            $lines[] = '- **Next missing battle:** `'.($nextBattle['id'] ?? 'unknown').'`';
        }
        $lines[] = '- **Blockers:** '.$this->joinOrDash(array_map('strval', (array) ($completionGap['blockers'] ?? [])));
        $lines[] = '- Rivals emits measured evidence; Atlas Decide decides model routing.';
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
                'diagnostic_only' => ['atlas' => null, 'rival' => null, 'leader' => null, 'sample_size' => 0, 'dimensions' => self::DIAGNOSTIC_ONLY_DIMENSIONS, 'excluded_from_winner' => true],
                'telemetry_only' => ['atlas' => null, 'rival' => null, 'leader' => null, 'sample_size' => 0, 'dimensions' => self::TELEMETRY_ONLY_DIMENSIONS, 'excluded_from_winner' => true],
            ],
            'score_decision_policy' => [
                'winner_uses_cost_time_efficiency' => false,
                'winner_uses_patch_shape_heuristics' => false,
                'telemetry_only_dimensions' => self::TELEMETRY_ONLY_DIMENSIONS,
                'diagnostic_only_dimensions' => self::DIAGNOSTIC_ONLY_DIMENSIONS,
                'winner_decision_excluded_dimensions' => [
                    ...self::TELEMETRY_ONLY_DIMENSIONS,
                    ...self::DIAGNOSTIC_ONLY_DIMENSIONS,
                ],
                'strong_quality_decision_policy' => 'winner_uses_only_strong_evidence_dimensions',
                'note' => 'Cost/token/time/efficiency and weak patch-shape heuristics are measured for operations analysis but excluded from round winners.',
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
            'ceiling_360_contract_matrix' => $this->buildCeiling360ContractMatrix([]),
            'battle_coverage' => $this->buildBattleCoverage([]),
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
        return JsonFileStore::readArray($path) ?? [];
    }

    private function jsonEncode(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
