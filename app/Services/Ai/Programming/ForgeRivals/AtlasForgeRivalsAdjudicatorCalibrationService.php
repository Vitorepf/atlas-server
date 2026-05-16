<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

/**
 * Atlas Forge Rivals · Adjudicator Calibration Service (Truth Guard v1).
 *
 * Pure, deterministic, local service that owns the canonical calibration
 * tables consumed by the v2 adjudicator:
 *
 *   - Difficulty ladder (L1..L5) and their multipliers.
 *   - Per-category default weights.
 *   - Planning-specific subcategories.
 *   - Truth Guard thresholds.
 *
 * Source of truth: `atlas-forge-rivals-benchmark-strategy-v1.md` §Taxonomia
 * de dificuldade.
 *
 * **Difficulty changes weight but never masks a hard failure.** The
 * calibration helpers in this service are only consulted AFTER hard gates are
 * computed. Hard fail ⇒ `score=null`, `winner=null`, regardless of difficulty.
 *
 * IMPORTANT: This service NEVER calls a provider, NEVER spends tokens, NEVER
 * unlocks `external_rivals_certification`, NEVER promotes `claim_ready`.
 */
final class AtlasForgeRivalsAdjudicatorCalibrationService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.adjudicator_calibration.v1';

    public const DIFFICULTY_L1 = 'L1';

    public const DIFFICULTY_L2 = 'L2';

    public const DIFFICULTY_L3 = 'L3';

    public const DIFFICULTY_L4 = 'L4';

    public const DIFFICULTY_L5 = 'L5';

    /**
     * Canonical multipliers from `atlas-forge-rivals-benchmark-strategy-v1.md`.
     *
     * @var array<string,float>
     */
    public const DIFFICULTY_MULTIPLIERS = [
        self::DIFFICULTY_L1 => 1.00,
        self::DIFFICULTY_L2 => 1.20,
        self::DIFFICULTY_L3 => 1.50,
        self::DIFFICULTY_L4 => 2.00,
        self::DIFFICULTY_L5 => 2.50,
    ];

    /**
     * Default planning vs execution composition by difficulty. Planning weight
     * climbs with the ladder so that beating many L1 mechanical cases never
     * shows up as more important than beating fewer L4/L5 architectural cases.
     *
     * @var array<string,array{planning:float,execution:float}>
     */
    public const DIFFICULTY_PLAN_EXEC = [
        self::DIFFICULTY_L1 => ['planning' => 0.10, 'execution' => 0.90],
        self::DIFFICULTY_L2 => ['planning' => 0.20, 'execution' => 0.80],
        self::DIFFICULTY_L3 => ['planning' => 0.35, 'execution' => 0.65],
        self::DIFFICULTY_L4 => ['planning' => 0.55, 'execution' => 0.45],
        self::DIFFICULTY_L5 => ['planning' => 0.70, 'execution' => 0.30],
    ];

    /** @var list<string> Sub-categories where planning quality dominates. */
    public const PLANNING_SUBCATEGORIES = [
        'software_planning',
        'execution_planning',
        'system_design',
        'risk_analysis',
        'implementation_strategy',
    ];

    /**
     * Per-category default weights (sum 1.0 each). Used by the v2 adjudicator
     * when a case manifest does not declare its own `quality_gates.weights`.
     *
     * @var array<string,array<string,float>>
     */
    public const CATEGORY_WEIGHTS = [
        'frontend_ui' => [
            'correctness' => 0.20,
            'ux_quality' => 0.25,
            'scope_discipline' => 0.15,
            'test_coverage' => 0.15,
            'minimality' => 0.10,
            'maintainability' => 0.10,
            'evidence_quality' => 0.05,
        ],
        'backend_logic' => [
            'correctness' => 0.25,
            'test_coverage' => 0.20,
            'scope_discipline' => 0.15,
            'architecture_fit' => 0.15,
            'maintainability' => 0.10,
            'minimality' => 0.10,
            'evidence_quality' => 0.05,
        ],
        'realistic_bugfix' => [
            'correctness' => 0.30,
            'minimality' => 0.25,
            'test_coverage' => 0.20,
            'scope_discipline' => 0.10,
            'maintainability' => 0.10,
            'evidence_quality' => 0.05,
        ],
        'refactor' => [
            'maintainability' => 0.30,
            'architecture_fit' => 0.20,
            'scope_discipline' => 0.20,
            'minimality' => 0.15,
            'test_coverage' => 0.10,
            'evidence_quality' => 0.05,
        ],
        'test_design' => [
            'test_coverage' => 0.40,
            'correctness' => 0.20,
            'maintainability' => 0.15,
            'scope_discipline' => 0.15,
            'evidence_quality' => 0.10,
        ],
        'architecture' => [
            'architecture_fit' => 0.30,
            'correctness' => 0.20,
            'scope_discipline' => 0.15,
            'maintainability' => 0.15,
            'evidence_quality' => 0.10,
            'test_coverage' => 0.10,
        ],
        'integration' => [
            'correctness' => 0.25,
            'architecture_fit' => 0.20,
            'test_coverage' => 0.20,
            'scope_discipline' => 0.15,
            'evidence_quality' => 0.10,
            'maintainability' => 0.10,
        ],
        'performance_edge_case' => [
            'performance' => 0.35,
            'correctness' => 0.20,
            'test_coverage' => 0.15,
            'scope_discipline' => 0.15,
            'maintainability' => 0.10,
            'evidence_quality' => 0.05,
        ],
        'planning' => [
            'architecture_fit' => 0.30,
            'correctness' => 0.20,
            'evidence_quality' => 0.20,
            'scope_discipline' => 0.15,
            'maintainability' => 0.10,
            'test_coverage' => 0.05,
        ],
        'docs' => [
            'evidence_quality' => 0.30,
            'scope_discipline' => 0.20,
            'maintainability' => 0.20,
            'correctness' => 0.15,
            'minimality' => 0.10,
            'test_coverage' => 0.05,
        ],
        'security' => [
            'correctness' => 0.30,
            'scope_discipline' => 0.20,
            'test_coverage' => 0.15,
            'architecture_fit' => 0.15,
            'maintainability' => 0.10,
            'evidence_quality' => 0.10,
        ],
    ];

    /** @var list<string> Ambiguity levels supported by manifests. */
    public const AMBIGUITY_LEVELS = ['low', 'medium', 'high'];

    /** @var list<string> Risk levels supported by manifests. */
    public const RISK_LEVELS = ['low', 'medium', 'high', 'critical'];

    /**
     * Truth Guard thresholds. Used by the v2 adjudicator's suspicious triage
     * to refuse trusting absurd score patterns.
     */
    public const TRUTH_GUARD = [
        'blowout_atlas_score_floor' => 90.0,
        'blowout_rival_score_ceiling' => 10.0,
        // The "100×0" code fires on any of these three patterns:
        //   - atlas ≥ 99 AND rival ≤ 1 (literal 100 vs 0)
        //   - margin ≥ score_100_vs_0_margin (very wide gap)
        //   - margin ≥ extreme_outlier_margin AND max(atlas, rival) ≥
        //     extreme_outlier_score_floor (one arm near-perfect, the other
        //     dramatically behind — the canon "absurd scoreline").
        'score_100_vs_0_margin' => 95.0,
        'extreme_outlier_margin' => 60.0,
        'extreme_outlier_score_floor' => 95.0,
        'rival_strong_score_ceiling' => 70.0,
        'patch_almost_empty_bytes' => 64,
        'evidence_artifact_presence_floor' => 0.8,
        'suspicious_provider_timeout_score_ceiling' => 50.0,
    ];

    /** Maximum allowed total weight drift before normalization. */
    public const WEIGHT_SUM_TOLERANCE = 0.01;

    /**
     * Normalize an L-string or numeric difficulty score to the canonical L1..L5
     * and return its multiplier. Unknown / null input defaults to L3 (the
     * "Product/Integration" anchor — same as `realistic_bugfix` baseline).
     *
     * @return array{level:string,score:float,multiplier:float,reason:string}
     */
    public function resolveDifficulty(mixed $rawLevel = null, mixed $rawScore = null, ?string $reason = null): array
    {
        $level = $this->canonLevel($rawLevel);
        if ($level === null && is_numeric($rawScore)) {
            $level = $this->levelFromScore((float) $rawScore);
        }
        if ($level === null) {
            $level = self::DIFFICULTY_L3;
        }
        $multiplier = self::DIFFICULTY_MULTIPLIERS[$level];
        $score = is_numeric($rawScore) ? (float) $rawScore : $this->scoreFromLevel($level);

        return [
            'level' => $level,
            'score' => round($score, 2),
            'multiplier' => $multiplier,
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : 'difficulty_inferred_from_'.($rawLevel === null ? 'score_or_default' : 'manifest'),
        ];
    }

    /**
     * Normalize planning/execution weights. Falls back to the canonical
     * composition for the given difficulty level.
     *
     * @return array{planning:float,execution:float}
     */
    public function resolvePlanExecWeights(string $level, mixed $rawPlanning = null, mixed $rawExecution = null): array
    {
        $level = $this->canonLevel($level) ?? self::DIFFICULTY_L3;
        $defaults = self::DIFFICULTY_PLAN_EXEC[$level];

        $planning = is_numeric($rawPlanning) ? (float) $rawPlanning : null;
        $execution = is_numeric($rawExecution) ? (float) $rawExecution : null;

        if ($planning === null && $execution === null) {
            return $defaults;
        }
        if ($planning === null) {
            $planning = max(0.0, 1.0 - $execution);
        }
        if ($execution === null) {
            $execution = max(0.0, 1.0 - $planning);
        }
        $sum = $planning + $execution;
        if ($sum <= 0) {
            return $defaults;
        }
        // Normalize to sum 1.0 without changing semantics.
        return [
            'planning' => round($planning / $sum, 4),
            'execution' => round($execution / $sum, 4),
        ];
    }

    /**
     * Return the canonical category weights for the given task_category. Falls
     * back to a balanced default if the category is unknown.
     *
     * @return array<string,float>
     */
    public function categoryWeights(string $taskCategory): array
    {
        $key = strtolower(trim($taskCategory));

        return self::CATEGORY_WEIGHTS[$key] ?? AtlasForgeRivalsAdjudicatorV2Service::DEFAULT_WEIGHTS;
    }

    /**
     * Apply the difficulty multiplier to a quality score. NEVER applied when
     * `valid=false` (hard fail). The 100-cap keeps the result bounded so a
     * difficulty multiplier cannot fabricate "score > 100" claims.
     */
    public function applyDifficultyMultiplier(?float $qualityScore, string $level, bool $valid): ?float
    {
        if (! $valid || $qualityScore === null) {
            return null;
        }
        $canonLevel = $this->canonLevel($level) ?? self::DIFFICULTY_L3;
        $multiplier = self::DIFFICULTY_MULTIPLIERS[$canonLevel];

        return min(100.0, round($qualityScore * $multiplier, 4));
    }

    /**
     * Map a confidence-ladder string to a confidence_score in [0,1].
     */
    public function confidenceScore(string $confidence): float
    {
        return match ($confidence) {
            AtlasForgeRivalsAdjudicatorV2Service::CONFIDENCE_DECIDE_SIGNAL => 1.00,
            AtlasForgeRivalsAdjudicatorV2Service::CONFIDENCE_PROVIDER_RANKING => 0.85,
            AtlasForgeRivalsAdjudicatorV2Service::CONFIDENCE_TRUSTED_BATTERY => 0.70,
            AtlasForgeRivalsAdjudicatorV2Service::CONFIDENCE_DIRECTIONAL_SIGNAL => 0.45,
            AtlasForgeRivalsAdjudicatorV2Service::CONFIDENCE_FLOW_VALIDATED => 0.20,
            AtlasForgeRivalsAdjudicatorV2Service::CONFIDENCE_INSUFFICIENT => 0.00,
            default => 0.00,
        };
    }

    /**
     * Return the canonical table snapshot for docs/reporting/audit.
     *
     * @return array<string,mixed>
     */
    public function tableSnapshot(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'difficulty_multipliers' => self::DIFFICULTY_MULTIPLIERS,
            'difficulty_plan_exec' => self::DIFFICULTY_PLAN_EXEC,
            'planning_subcategories' => self::PLANNING_SUBCATEGORIES,
            'category_weights' => self::CATEGORY_WEIGHTS,
            'ambiguity_levels' => self::AMBIGUITY_LEVELS,
            'risk_levels' => self::RISK_LEVELS,
            'truth_guard' => self::TRUTH_GUARD,
        ];
    }

    private function canonLevel(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $upper = strtoupper(trim($raw));

        return match ($upper) {
            'L1', 'L2', 'L3', 'L4', 'L5' => $upper,
            default => null,
        };
    }

    private function levelFromScore(float $score): string
    {
        if ($score >= 4.5) {
            return self::DIFFICULTY_L5;
        }
        if ($score >= 3.5) {
            return self::DIFFICULTY_L4;
        }
        if ($score >= 2.5) {
            return self::DIFFICULTY_L3;
        }
        if ($score >= 1.5) {
            return self::DIFFICULTY_L2;
        }

        return self::DIFFICULTY_L1;
    }

    private function scoreFromLevel(string $level): float
    {
        return match ($level) {
            self::DIFFICULTY_L1 => 1.0,
            self::DIFFICULTY_L2 => 2.0,
            self::DIFFICULTY_L3 => 3.0,
            self::DIFFICULTY_L4 => 4.0,
            self::DIFFICULTY_L5 => 5.0,
            default => 3.0,
        };
    }
}
