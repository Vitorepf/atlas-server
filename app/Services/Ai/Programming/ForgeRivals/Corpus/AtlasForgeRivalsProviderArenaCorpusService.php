<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals\Corpus;

use App\Services\Ai\Programming\ForgeRivals\Schema\AtlasForgeRivalsSchemaContractService;
use InvalidArgumentException;

/**
 * Atlas Forge Rivals · Provider Arena Corpus Release/Industrial v1.
 *
 * Canonical, file-based corpus of twelve programming cases that the Provider
 * Arena uses to compare arms (Atlas Forge vs Claude Code vs Codex CLI vs
 * Gemini CLI, plus fair vs full_power, sonnet vs opus) across eight task
 * categories. The corpus is declarative and never invokes a provider — its
 * job is to declare *what* to measure, not to run anything.
 *
 * Eight canonical categories (every release case has exactly one primary):
 *   - backend_logic
 *   - frontend_ui
 *   - realistic_bugfix
 *   - refactor
 *   - test_design
 *   - architecture
 *   - integration
 *   - performance_edge_case
 *
 * Twenty-two canonical fields per case manifest (validated by validateManifest):
 *   case_id, title, category, secondary_categories, difficulty,
 *   objective, business_rule, acceptance_criteria,
 *   allowed_files_scope, forbidden_files_scope,
 *   fixture_seed_path, quick_test_command, full_test_command,
 *   expected_changed_files, quality_weights {dimensions, weights},
 *   invalid_if, timeout_policy, evidence_requirements, replay_requirements,
 *   fairness_notes, human_review_notes, claim_level.
 *
 * Back-compat aliases (auto-populated by adaptCase; never declared by hand):
 *   task_category (legacy 9-bucket), role_focus, setup_fixture {seed_dir,
 *   base_files}, quality_gates (== quality_weights), expected_signal,
 *   expected_evidence (== evidence_requirements). These exist so that the
 *   existing RunRealService.adaptCorpusCase, the RunBattery pipeline, the
 *   arm contract validator and downstream snapshot consumers keep working
 *   byte-for-byte without a coordinated refactor.
 *
 * Case sets (deterministic resolution):
 *   - quick:        3 short cases (one bugfix + one frontend + one perf)
 *   - release:      every case (the 12-case Release v1)
 *   - frontend:     case_id prefix `frontend-` (3 cases)
 *   - backend:      case_id prefix `backend-` (4 cases)
 *   - bugfix:       primary or secondary category == realistic_bugfix (2 cases)
 *   - architecture: primary in {architecture, refactor}
 *   - industrial-50 / industrial-100 / industrial-200: generated industrial benchmark suite slices.
 *   - ambiguous-bugs / multi-day-refactors / incident-response /
 *     product-security-migrations: domain-specific industrial batteries.
 *   - statistical-repeat: repeated industrial subset for variance analysis.
 *   - extreme-differentiator: high ambiguity/risk/depth cases that should
 *     separate runner strengths instead of producing comfortable ties.
 *   - ceiling-360: maximum-pressure L5 360 cases for runner ceiling mapping.
 *
 * Safety rules (never relaxed):
 *   - No case dispatches a provider in its declared commands.
 *   - No case touches Voice or Cartografia paths.
 *   - claim_level is always 'case_result_only'; no global_claim ever.
 *   - synthetic_score / external_rivals unlock are listed in invalid_if.
 *   - Every case is fully replayable from its fixture_seed_path.
 *
 * Schema: atlas.forge.rivals.provider_arena_corpus.v1 (release_v1)
 */
final class AtlasForgeRivalsProviderArenaCorpusService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.provider_arena_corpus.v1';

    public const RELEASE_VERSION = 'release_v1';

    public const CASE_SET_QUICK = 'quick';

    public const CASE_SET_RELEASE = 'release';

    public const CASE_SET_FRONTEND = 'frontend';

    public const CASE_SET_BACKEND = 'backend';

    public const CASE_SET_BUGFIX = 'bugfix';

    public const CASE_SET_ARCHITECTURE = 'architecture';

    public const CASE_SET_INDUSTRIAL_50 = 'industrial-50';

    public const CASE_SET_INDUSTRIAL_100 = 'industrial-100';

    public const CASE_SET_INDUSTRIAL_200 = 'industrial-200';

    public const CASE_SET_AMBIGUOUS_BUGS = 'ambiguous-bugs';

    public const CASE_SET_MULTI_DAY_REFACTORS = 'multi-day-refactors';

    public const CASE_SET_INCIDENT_RESPONSE = 'incident-response';

    public const CASE_SET_PRODUCT_SECURITY_MIGRATIONS = 'product-security-migrations';

    public const CASE_SET_STATISTICAL_REPEAT = 'statistical-repeat';

    public const CASE_SET_META_PROVIDER_STRESS = 'meta-provider-stress';

    public const CASE_SET_EXTREME_DIFFERENTIATOR = 'extreme-differentiator';

    public const CASE_SET_CEILING_360 = 'ceiling-360';

    /** @var list<string> */
    public const CASE_SETS = [
        self::CASE_SET_QUICK,
        self::CASE_SET_RELEASE,
        self::CASE_SET_FRONTEND,
        self::CASE_SET_BACKEND,
        self::CASE_SET_BUGFIX,
        self::CASE_SET_ARCHITECTURE,
        self::CASE_SET_INDUSTRIAL_50,
        self::CASE_SET_INDUSTRIAL_100,
        self::CASE_SET_INDUSTRIAL_200,
        self::CASE_SET_AMBIGUOUS_BUGS,
        self::CASE_SET_MULTI_DAY_REFACTORS,
        self::CASE_SET_INCIDENT_RESPONSE,
        self::CASE_SET_PRODUCT_SECURITY_MIGRATIONS,
        self::CASE_SET_STATISTICAL_REPEAT,
        self::CASE_SET_META_PROVIDER_STRESS,
        self::CASE_SET_EXTREME_DIFFERENTIATOR,
        self::CASE_SET_CEILING_360,
    ];

    /** @var list<string> */
    public const INDUSTRIAL_CASE_SETS = [
        self::CASE_SET_INDUSTRIAL_50,
        self::CASE_SET_INDUSTRIAL_100,
        self::CASE_SET_INDUSTRIAL_200,
        self::CASE_SET_AMBIGUOUS_BUGS,
        self::CASE_SET_MULTI_DAY_REFACTORS,
        self::CASE_SET_INCIDENT_RESPONSE,
        self::CASE_SET_PRODUCT_SECURITY_MIGRATIONS,
        self::CASE_SET_STATISTICAL_REPEAT,
        self::CASE_SET_META_PROVIDER_STRESS,
        self::CASE_SET_EXTREME_DIFFERENTIATOR,
        self::CASE_SET_CEILING_360,
    ];

    /** @var array<string,int> */
    public const INDUSTRIAL_CASE_SET_MIN_VALID_CASES = [
        self::CASE_SET_INDUSTRIAL_50 => 50,
        self::CASE_SET_INDUSTRIAL_100 => 100,
        self::CASE_SET_INDUSTRIAL_200 => 200,
        self::CASE_SET_AMBIGUOUS_BUGS => 50,
        self::CASE_SET_MULTI_DAY_REFACTORS => 50,
        self::CASE_SET_INCIDENT_RESPONSE => 50,
        self::CASE_SET_PRODUCT_SECURITY_MIGRATIONS => 50,
        self::CASE_SET_STATISTICAL_REPEAT => 50,
        self::CASE_SET_META_PROVIDER_STRESS => 50,
        self::CASE_SET_EXTREME_DIFFERENTIATOR => 80,
        self::CASE_SET_CEILING_360 => 120,
    ];

    /** @var list<string> */
    public const INDUSTRIAL_DOMAINS = [
        'ambiguous_bug',
        'incomplete_requirements',
        'large_refactor',
        'multi_day_task',
        'incident_rollback',
        'migration',
        'documentation',
        'test_design',
        'security',
        'product',
        'integration',
        'performance',
        'flakiness_repeat',
    ];

    /**
     * @var list<string> Canonical primary categories (the eight measured).
     *
     * Release Matrix v1 canon (8x5 = 40 cases): `planning` substitui o slot
     * antes inferido; `integration_performance` consolida `integration` +
     * `performance_edge_case` em um único eixo medido — cases reais
     * sempre cruzam contrato externo (integration) com custo observável
     * (performance).
     */
    public const TASK_CATEGORIES = [
        'planning',
        'frontend_ui',
        'backend_logic',
        'realistic_bugfix',
        'refactor',
        'test_design',
        'architecture',
        'integration_performance',
    ];

    /**
     * @var array<string,string>
     *
     * Aliases legados das categorias antigas. Tests/scripts que enviam
     * `integration` ou `performance_edge_case` continuam funcionando — o
     * planner/validator translita para o canon novo antes de comparar.
     */
    public const LEGACY_CATEGORY_ALIAS = [
        'docs' => 'planning',
        'planning_clarity' => 'planning',
        'frontend' => 'frontend_ui',
        'backend' => 'backend_logic',
        'bugfix' => 'realistic_bugfix',
        'tests' => 'test_design',
        'integration' => 'integration_performance',
        'performance_edge_case' => 'integration_performance',
        'performance' => 'integration_performance',
    ];

    /**
     * @var list<string>
     *
     * Twenty-nine canonical fields every Release v1 case declares
     * (22 originais + 7 do difficulty block L1..L5). O SchemaContractService
     * é a source of truth — o CorpusService só ecoa para preservar
     * back-compat com chamadas históricas que consultam `REQUIRED_FIELDS`
     * diretamente (testes e cert).
     */
    public const REQUIRED_FIELDS = [
        'case_id',
        'title',
        'category',
        'secondary_categories',
        'difficulty',
        'difficulty_level',
        'difficulty_score',
        'difficulty_reason',
        'planning_weight',
        'execution_weight',
        'ambiguity_level',
        'risk_level',
        'objective',
        'business_rule',
        'acceptance_criteria',
        'allowed_files_scope',
        'forbidden_files_scope',
        'fixture_seed_path',
        'quick_test_command',
        'full_test_command',
        'expected_changed_files',
        'quality_weights',
        'invalid_if',
        'timeout_policy',
        'evidence_requirements',
        'replay_requirements',
        'fairness_notes',
        'human_review_notes',
        'claim_level',
    ];

    public const CLAIM_LEVEL_CASE_RESULT_ONLY = 'case_result_only';

    public const DIFFICULTY_EASY = 'easy';

    public const DIFFICULTY_MEDIUM = 'medium';

    public const DIFFICULTY_HARD = 'hard';

    /** @var list<string> */
    public const DIFFICULTIES = [self::DIFFICULTY_EASY, self::DIFFICULTY_MEDIUM, self::DIFFICULTY_HARD];

    /**
     * Canonical L1-L5 difficulty ladder used by battery state, scoring, replay
     * manifest and report aggregation. The corpus declares three buckets
     * (easy/medium/hard); the ladder gives downstream services five rungs so
     * future medium-light (L2) and hard-extra (L4) bands can be introduced
     * without breaking schemas. Mapping:
     *
     *   easy   -> L1 (single-file, <20 line patch, well-scoped)
     *   medium -> L3 (multi-file, semantic correctness, integration scope)
     *   hard   -> L5 (architectural impact, cross-module reasoning)
     *
     * L2 and L4 are reserved for future corpus expansion and adjudicator
     * fine-grained banding; today no case uses them, but battery.json and the
     * report aggregator accept them.
     */
    public const DIFFICULTY_LEVEL_L1 = 'L1';

    public const DIFFICULTY_LEVEL_L2 = 'L2';

    public const DIFFICULTY_LEVEL_L3 = 'L3';

    public const DIFFICULTY_LEVEL_L4 = 'L4';

    public const DIFFICULTY_LEVEL_L5 = 'L5';

    /** @var list<string> Ladder order from easiest (L1) to hardest (L5). */
    public const DIFFICULTY_LEVELS = [
        self::DIFFICULTY_LEVEL_L1,
        self::DIFFICULTY_LEVEL_L2,
        self::DIFFICULTY_LEVEL_L3,
        self::DIFFICULTY_LEVEL_L4,
        self::DIFFICULTY_LEVEL_L5,
    ];

    /**
     * Mapping from coarse difficulty bucket to the canonical L1-L5 ladder.
     * Authoritative: this is the only place that translates between the two.
     *
     * @var array<string,string>
     */
    public const DIFFICULTY_TO_LEVEL = [
        self::DIFFICULTY_EASY => self::DIFFICULTY_LEVEL_L1,
        self::DIFFICULTY_MEDIUM => self::DIFFICULTY_LEVEL_L3,
        self::DIFFICULTY_HARD => self::DIFFICULTY_LEVEL_L5,
    ];

    /**
     * Scoring weight per difficulty level. Harder cases carry more weight in
     * the report aggregate so a release battery that only passes L1 cases
     * does not look as strong as one that passes L5 cases. Weights are
     * advisory — the adjudicator's hard gates still fail closed.
     *
     * @var array<string,float>
     */
    public const DIFFICULTY_LEVEL_SCORE_WEIGHTS = [
        self::DIFFICULTY_LEVEL_L1 => 1.0,
        self::DIFFICULTY_LEVEL_L2 => 1.5,
        self::DIFFICULTY_LEVEL_L3 => 2.0,
        self::DIFFICULTY_LEVEL_L4 => 2.5,
        self::DIFFICULTY_LEVEL_L5 => 3.0,
    ];

    /**
     * Canonical L1..L5 difficulty score map. The score values match the
     * adjudicator/report contract so the same numbers flow from case →
     * scorecard → evidence → replay → report without translation drift.
     * Distinct from `DIFFICULTY_LEVEL_SCORE_WEIGHTS` which carries the
     * advisory aggregation weight; here we expose the canonical "intrinsic
     * score" per level used by report aggregates and SchemaContract fallback.
     *
     * @var array<string,float>
     */
    public const DIFFICULTY_LEVEL_SCORE = [
        self::DIFFICULTY_LEVEL_L1 => 1.0,
        self::DIFFICULTY_LEVEL_L2 => 2.0,
        self::DIFFICULTY_LEVEL_L3 => 3.0,
        self::DIFFICULTY_LEVEL_L4 => 4.0,
        self::DIFFICULTY_LEVEL_L5 => 5.0,
    ];

    /**
     * Translate a corpus difficulty bucket (easy/medium/hard) into the
     * canonical L1-L5 ladder. Falls back to L3 (medium) when the input is
     * unknown — keeps battery.json and report.md schema-stable rather than
     * letting an unmapped value pollute downstream contracts.
     */
    public static function difficultyToLevel(string $difficulty): string
    {
        $key = strtolower(trim($difficulty));

        return self::DIFFICULTY_TO_LEVEL[$key] ?? self::DIFFICULTY_LEVEL_L3;
    }

    /**
     * Score weight for a given L1-L5 level (or coarse difficulty bucket via
     * difficultyToLevel()). Falls back to L3 weight if the level is unknown.
     */
    public static function difficultyLevelWeight(string $level): float
    {
        $upper = strtoupper(trim($level));

        return self::DIFFICULTY_LEVEL_SCORE_WEIGHTS[$upper]
            ?? self::DIFFICULTY_LEVEL_SCORE_WEIGHTS[self::DIFFICULTY_LEVEL_L3];
    }

    /** @var list<string> Quick preset = 3 short representative cases (bugfix + frontend + perf). */
    public const QUICK_CASE_IDS = [
        'backend-pagination-off-by-one',
        'frontend-form-validation-accessibility',
        'performance-n-plus-one-query',
    ];

    /**
     * Mandatory hard gates that every case must include in `invalid_if`.
     * Keeps the contract honest: no synthetic scoring, no unauthorized scope,
     * no quiet provider escape hatch, no external_rivals unlock.
     *
     * @var list<string>
     */
    public const REQUIRED_INVALID_IF_HARD_GATES = [
        'synthetic_score_admitted',
        'touched_forbidden_files',
        'external_rivals_unlock_attempted',
    ];

    /**
     * Canonical mapping from new primary category to the legacy
     * task_category bucket used by ArmRegistryService / RunRealService.
     * Kept here so the corpus is the only source of truth for the alias.
     *
     * @var array<string,string>
     */
    public const LEGACY_TASK_CATEGORY_MAP = [
        'planning' => 'docs',
        'backend_logic' => 'backend',
        'frontend_ui' => 'frontend',
        'realistic_bugfix' => 'bugfix',
        'refactor' => 'refactor',
        'test_design' => 'tests',
        'architecture' => 'architecture',
        'integration_performance' => 'backend',
    ];

    /**
     * Canonical default role_focus per primary category. role_focus is a
     * legacy field downstream services still read; we derive it instead of
     * making every manifest re-declare it.
     *
     * @var array<string,string>
     */
    public const DEFAULT_ROLE_FOCUS_MAP = [
        'planning' => 'planning_clarity',
        'backend_logic' => 'api_correctness',
        'frontend_ui' => 'ui_correctness',
        'realistic_bugfix' => 'minimal_diff',
        'refactor' => 'cohesion',
        'test_design' => 'coverage_quality',
        'architecture' => 'boundary_integrity',
        'integration_performance' => 'integration_safety',
    ];

    private const INDUSTRIAL_CASE_COUNT = 200;

    /**
     * @return list<array<string,mixed>>
     */
    public function cases(): array
    {
        return array_values(array_map(
            fn (array $c): array => $this->adaptCase($c),
            $this->corpus(),
        ));
    }

    /**
     * @return list<string>
     */
    public function caseIds(): array
    {
        return array_values(array_map(
            static fn (array $case): string => (string) $case['case_id'],
            $this->corpus(),
        ));
    }

    /**
     * @return array<string,mixed>
     */
    public function case(string $caseId): array
    {
        $caseId = trim($caseId);
        foreach (array_merge($this->corpus(), $this->allGeneratedIndustrialCases()) as $case) {
            if ((string) $case['case_id'] === $caseId) {
                return $this->adaptCase($case);
            }
        }

        throw new InvalidArgumentException("unknown_case_id:{$caseId}");
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function casesForCaseSet(string $caseSet): array
    {
        $key = strtolower(trim($caseSet));
        if (! in_array($key, self::CASE_SETS, true)) {
            throw new InvalidArgumentException("unknown_case_set:{$caseSet}");
        }

        $all = $this->cases();

        return match ($key) {
            self::CASE_SET_RELEASE => $all,
            self::CASE_SET_INDUSTRIAL_50 => array_slice($this->industrialCases(), 0, 50),
            self::CASE_SET_INDUSTRIAL_100 => array_slice($this->industrialCases(), 0, 100),
            self::CASE_SET_INDUSTRIAL_200 => $this->industrialCases(),
            self::CASE_SET_AMBIGUOUS_BUGS => $this->industrialCasesForDomain(self::CASE_SET_AMBIGUOUS_BUGS, 'ambiguous_bug', 50),
            self::CASE_SET_MULTI_DAY_REFACTORS => $this->industrialCasesForDomain(self::CASE_SET_MULTI_DAY_REFACTORS, 'multi_day_task', 50),
            self::CASE_SET_INCIDENT_RESPONSE => $this->industrialCasesForDomain(self::CASE_SET_INCIDENT_RESPONSE, 'incident_rollback', 50),
            self::CASE_SET_PRODUCT_SECURITY_MIGRATIONS => $this->industrialCasesForDomains(self::CASE_SET_PRODUCT_SECURITY_MIGRATIONS, ['product', 'security', 'migration'], 50),
            self::CASE_SET_STATISTICAL_REPEAT => $this->statisticalRepeatCases(),
            self::CASE_SET_META_PROVIDER_STRESS => $this->metaProviderStressCases(),
            self::CASE_SET_EXTREME_DIFFERENTIATOR => $this->extremeDifferentiatorCases(),
            self::CASE_SET_CEILING_360 => $this->ceiling360Cases(),
            self::CASE_SET_QUICK => array_values(array_filter(
                $all,
                static fn (array $c): bool => in_array((string) $c['case_id'], self::QUICK_CASE_IDS, true),
            )),
            self::CASE_SET_FRONTEND => array_values(array_filter(
                $all,
                static fn (array $c): bool => (string) ($c['category'] ?? '') === 'frontend_ui',
            )),
            self::CASE_SET_BACKEND => array_values(array_filter(
                $all,
                static fn (array $c): bool => in_array(
                    (string) ($c['category'] ?? ''),
                    ['backend_logic', 'integration_performance'],
                    true,
                ),
            )),
            self::CASE_SET_BUGFIX => array_values(array_filter(
                $all,
                static function (array $c): bool {
                    if ((string) ($c['category'] ?? '') === 'realistic_bugfix') {
                        return true;
                    }
                    foreach ((array) ($c['secondary_categories'] ?? []) as $s) {
                        if ((string) $s === 'realistic_bugfix') {
                            return true;
                        }
                    }

                    return false;
                },
            )),
            self::CASE_SET_ARCHITECTURE => array_values(array_filter(
                $all,
                static fn (array $c): bool => in_array(
                    (string) ($c['category'] ?? ''),
                    ['architecture', 'refactor'],
                    true,
                ),
            )),
        };
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function casesForTaskCategory(string $taskCategory): array
    {
        $key = strtolower(trim($taskCategory));
        // Honor legacy aliases (integration|performance|performance_edge_case
        // → integration_performance) so callers em scripts antigos não quebram.
        $key = self::LEGACY_CATEGORY_ALIAS[$key] ?? $key;
        if (! in_array($key, self::TASK_CATEGORIES, true)) {
            throw new InvalidArgumentException("unknown_task_category:{$taskCategory}");
        }

        return array_values(array_filter(
            $this->cases(),
            static fn (array $c): bool => (string) ($c['category'] ?? '') === $key,
        ));
    }

    /**
     * Validate one case manifest against the twenty-two field schema.
     *
     * @param  array<string,mixed>  $case
     * @return list<string> empty list ⇒ valid
     */
    public function validateManifest(array $case): array
    {
        $invalid = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $case)) {
                $invalid[] = "missing_field:{$field}";
            }
        }

        if (isset($case['category']) && ! in_array($case['category'], self::TASK_CATEGORIES, true)) {
            $invalid[] = 'category_not_in_canon:'.(string) $case['category'];
        }

        if (isset($case['difficulty']) && ! in_array($case['difficulty'], self::DIFFICULTIES, true)) {
            $invalid[] = 'difficulty_not_in_canon:'.(string) $case['difficulty'];
        }

        // Difficulty canon L1..L5 — fail-closed via shared contract.
        $contract = new AtlasForgeRivalsSchemaContractService;
        foreach ($contract->validateDifficultyBlock($case, 'corpus_case', flat: true) as $diffViolation) {
            // Strip the prefix so validateManifest violations remain in legacy shape.
            $invalid[] = str_replace('corpus_case.', '', $diffViolation);
        }

        if (isset($case['claim_level']) && (string) $case['claim_level'] !== self::CLAIM_LEVEL_CASE_RESULT_ONLY) {
            $invalid[] = 'claim_level_must_be_case_result_only:'.(string) $case['claim_level'];
        }

        $listFields = [
            'secondary_categories',
            'acceptance_criteria',
            'allowed_files_scope',
            'forbidden_files_scope',
            'expected_changed_files',
            'evidence_requirements',
            'replay_requirements',
            'invalid_if',
        ];
        foreach ($listFields as $listField) {
            if (! isset($case[$listField])) {
                continue;
            }
            if (! is_array($case[$listField])) {
                $invalid[] = "field_must_be_array:{$listField}";

                continue;
            }
            // secondary_categories may legitimately be empty; everything else must not.
            if ($listField !== 'secondary_categories' && $case[$listField] === []) {
                $invalid[] = "field_must_be_non_empty:{$listField}";
            }
        }

        $stringFields = [
            'case_id',
            'title',
            'category',
            'difficulty',
            'objective',
            'business_rule',
            'fixture_seed_path',
            'quick_test_command',
            'full_test_command',
            'fairness_notes',
            'human_review_notes',
            'claim_level',
        ];
        foreach ($stringFields as $stringField) {
            if (! isset($case[$stringField])) {
                continue;
            }
            if (! is_string($case[$stringField]) || trim((string) $case[$stringField]) === '') {
                $invalid[] = "field_must_be_non_empty_string:{$stringField}";
            }
        }

        if (isset($case['secondary_categories']) && is_array($case['secondary_categories'])) {
            foreach ($case['secondary_categories'] as $sec) {
                if (! is_string($sec) || ! in_array($sec, self::TASK_CATEGORIES, true)) {
                    $invalid[] = 'secondary_category_not_in_canon:'.(string) $sec;
                }
            }
        }

        if (isset($case['quality_weights'])) {
            $weights = $case['quality_weights'];
            if (! is_array($weights) || ! isset($weights['dimensions'], $weights['weights'])) {
                $invalid[] = 'quality_weights_malformed';
            } elseif (! is_array($weights['dimensions']) || ! is_array($weights['weights']) || $weights['dimensions'] === []) {
                $invalid[] = 'quality_weights_dimensions_missing';
            } else {
                $sum = 0.0;
                foreach ($weights['weights'] as $w) {
                    $sum += (float) $w;
                }
                if (abs($sum - 1.0) > 0.01) {
                    $invalid[] = 'quality_weights_do_not_sum_to_one';
                }
                foreach (array_keys($weights['weights']) as $dim) {
                    if (! in_array($dim, $weights['dimensions'], true)) {
                        $invalid[] = 'quality_weights_dimension_not_declared:'.(string) $dim;
                    }
                }
            }
        }

        if (isset($case['timeout_policy'])) {
            $tp = $case['timeout_policy'];
            $required = ['wall_clock_seconds_max', 'per_stage_seconds_max', 'hard_kill_after_seconds'];
            if (! is_array($tp)) {
                $invalid[] = 'timeout_policy_must_be_array';
            } else {
                foreach ($required as $key) {
                    if (! isset($tp[$key]) || ! is_int($tp[$key]) || $tp[$key] <= 0) {
                        $invalid[] = "timeout_policy_missing_or_invalid:{$key}";
                    }
                }
            }
        }

        if (isset($case['fixture_seed_path']) && is_string($case['fixture_seed_path'])) {
            $path = trim($case['fixture_seed_path']);
            if (! str_starts_with($path, 'storage/forge-rivals-corpus/')) {
                $invalid[] = 'fixture_seed_path_outside_canonical_root:'.$path;
            }
            $caseId = (string) ($case['case_id'] ?? '');
            if ($caseId !== '' && ! str_contains($path, '/'.$caseId.'/')) {
                $invalid[] = 'fixture_seed_path_case_id_mismatch:'.$path;
            }
        }

        if (isset($case['allowed_files_scope']) && is_array($case['allowed_files_scope'])) {
            foreach ($case['allowed_files_scope'] as $glob) {
                $g = (string) $glob;
                if (str_starts_with($g, 'atlas-desktop/src/voice/')
                    || str_contains($g, '/voice/')
                    || str_starts_with($g, 'atlas-cartografia/')
                    || str_contains($g, '/cartografia/')
                ) {
                    $invalid[] = 'allowed_scope_touches_voice_or_cartografia';
                    break;
                }
            }
        }

        if (isset($case['invalid_if']) && is_array($case['invalid_if'])) {
            foreach (self::REQUIRED_INVALID_IF_HARD_GATES as $gate) {
                if (! in_array($gate, $case['invalid_if'], true)) {
                    $invalid[] = 'invalid_if_missing_hard_gate:'.$gate;
                }
            }
        }

        foreach (['quick_test_command', 'full_test_command'] as $cmdField) {
            if (! isset($case[$cmdField]) || ! is_string($case[$cmdField])) {
                continue;
            }
            $cmd = strtolower($case[$cmdField]);
            foreach (['claude ', 'codex ', 'gemini ', 'curl ', 'wget ', 'http://', 'https://'] as $needle) {
                if (str_contains($cmd, $needle)) {
                    $invalid[] = "{$cmdField}_invokes_external_provider:{$needle}";
                    break;
                }
            }
        }

        return $invalid;
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $cases = $this->cases();
        $byCategory = [];
        foreach (self::TASK_CATEGORIES as $cat) {
            $byCategory[$cat] = 0;
        }
        foreach ($cases as $c) {
            $cat = (string) ($c['category'] ?? '');
            if (isset($byCategory[$cat])) {
                $byCategory[$cat]++;
            }
        }

        $byCaseSet = [];
        foreach (self::CASE_SETS as $set) {
            $byCaseSet[$set] = count($this->casesForCaseSet($set));
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'release_version' => self::RELEASE_VERSION,
            'count' => count($cases),
            'case_ids' => $this->caseIds(),
            'case_sets' => self::CASE_SETS,
            'case_set_counts' => $byCaseSet,
            'industrial_case_sets' => self::INDUSTRIAL_CASE_SETS,
            'industrial_min_valid_cases' => self::INDUSTRIAL_CASE_SET_MIN_VALID_CASES,
            'industrial_domains' => self::INDUSTRIAL_DOMAINS,
            'meta_provider_stress_coverage' => $this->metaProviderStressCoverageSummary(),
            'task_categories' => self::TASK_CATEGORIES,
            'by_task_category' => $byCategory,
            'quick_case_ids' => self::QUICK_CASE_IDS,
            'no_external_provider_call_admitted' => true,
            'separated_from_external_rivals_certification' => true,
            'claim_level' => self::CLAIM_LEVEL_CASE_RESULT_ONLY,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function metaProviderStressCoverageSummary(): array
    {
        $cases = $this->metaProviderStressCases();
        $domains = [];
        $riskLevels = [];
        $ambiguityLevels = [];
        $reasoningDepth = [];
        $criticalOrHighRisk = 0;
        $highAmbiguity = 0;
        $longContext = 0;
        $rollbackPlan = 0;
        $multiStepPlan = 0;
        $evidenceMatrix = 0;
        $minEstimatedContextTokens = null;
        $maxEstimatedContextTokens = null;

        foreach ($cases as $case) {
            foreach ($this->stringList($case['industrial_domains'] ?? []) as $domain) {
                $domains[$domain] = ($domains[$domain] ?? 0) + 1;
            }

            $risk = (string) ($case['risk_level'] ?? 'unknown');
            $riskLevels[$risk] = ($riskLevels[$risk] ?? 0) + 1;
            if (in_array($risk, ['critical', 'high'], true)) {
                $criticalOrHighRisk++;
            }

            $ambiguity = (string) ($case['ambiguity_level'] ?? 'unknown');
            $ambiguityLevels[$ambiguity] = ($ambiguityLevels[$ambiguity] ?? 0) + 1;
            if ($ambiguity === 'high') {
                $highAmbiguity++;
            }

            $profile = is_array($case['context_profile']['complexity_profile'] ?? null)
                ? (array) $case['context_profile']['complexity_profile']
                : [];
            $depth = (int) ($profile['reasoning_depth'] ?? 0);
            if ($depth > 0) {
                $reasoningDepth['depth_'.$depth] = ($reasoningDepth['depth_'.$depth] ?? 0) + 1;
            }
            $estimatedTokens = (int) ($profile['estimated_context_tokens'] ?? 0);
            if ($estimatedTokens > 0) {
                $minEstimatedContextTokens = $minEstimatedContextTokens === null
                    ? $estimatedTokens
                    : min($minEstimatedContextTokens, $estimatedTokens);
                $maxEstimatedContextTokens = $maxEstimatedContextTokens === null
                    ? $estimatedTokens
                    : max($maxEstimatedContextTokens, $estimatedTokens);
            }
            if (($profile['long_context_required'] ?? false) === true) {
                $longContext++;
            }
            if (($profile['requires_rollback_plan'] ?? false) === true) {
                $rollbackPlan++;
            }
            if (($profile['requires_multi_step_plan'] ?? false) === true) {
                $multiStepPlan++;
            }
            if (($profile['requires_evidence_matrix'] ?? false) === true) {
                $evidenceMatrix++;
            }
        }

        ksort($domains);
        ksort($riskLevels);
        ksort($ambiguityLevels);
        ksort($reasoningDepth);

        $caseCount = count($cases);
        $domainCount = count($domains);

        return [
            'schema_version' => 'atlas.forge.rivals.meta_provider_stress_coverage.v1',
            'case_count' => $caseCount,
            'target_case_count' => self::INDUSTRIAL_CASE_SET_MIN_VALID_CASES[self::CASE_SET_META_PROVIDER_STRESS],
            'domain_count' => $domainCount,
            'min_domain_count' => 8,
            'domains' => $domains,
            'risk_levels' => $riskLevels,
            'ambiguity_levels' => $ambiguityLevels,
            'reasoning_depth_distribution' => $reasoningDepth,
            'critical_or_high_risk_cases' => $criticalOrHighRisk,
            'high_ambiguity_cases' => $highAmbiguity,
            'long_context_cases' => $longContext,
            'rollback_plan_cases' => $rollbackPlan,
            'multi_step_plan_cases' => $multiStepPlan,
            'evidence_matrix_cases' => $evidenceMatrix,
            'min_estimated_context_tokens' => $minEstimatedContextTokens ?? 0,
            'max_estimated_context_tokens' => $maxEstimatedContextTokens ?? 0,
            'coverage_floor_met' => $caseCount >= self::INDUSTRIAL_CASE_SET_MIN_VALID_CASES[self::CASE_SET_META_PROVIDER_STRESS]
                && $domainCount >= 8
                && $criticalOrHighRisk >= 10
                && $highAmbiguity >= 8
                && $longContext === $caseCount
                && $rollbackPlan >= 8
                && $multiStepPlan === $caseCount
                && $evidenceMatrix === $caseCount,
            'advisory_only' => true,
            'routing_effect' => 'none',
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
     * Deterministic content hash of the corpus — same bytes ⇒ same hash on
     * any host. Used by the planner replay manifest and by docs-health.
     */
    public function contentHash(): string
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'release_version' => self::RELEASE_VERSION,
            'cases' => $this->cases(),
            'industrial_cases' => $this->industrialCases(),
            'case_sets' => self::CASE_SETS,
            'task_categories' => self::TASK_CATEGORIES,
            'quick_case_ids' => self::QUICK_CASE_IDS,
        ];

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    /**
     * Adapt a raw case (the 22-field canon) into the consumer-facing array,
     * automatically populating legacy alias fields. The raw declaration is
     * never mutated.
     *
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function adaptCase(array $case): array
    {
        $category = (string) ($case['category'] ?? '');
        $taskCategory = self::LEGACY_TASK_CATEGORY_MAP[$category] ?? '';
        $roleFocus = self::DEFAULT_ROLE_FOCUS_MAP[$category] ?? '';
        $fixturePath = (string) ($case['fixture_seed_path'] ?? '');
        $expectedChanged = (array) ($case['expected_changed_files'] ?? []);
        $acceptance = (array) ($case['acceptance_criteria'] ?? []);
        $evidence = (array) ($case['evidence_requirements'] ?? []);
        $weights = is_array($case['quality_weights'] ?? null) ? (array) $case['quality_weights'] : [];

        $baseFiles = array_values(array_map(
            static fn ($p): string => basename((string) $p),
            $expectedChanged,
        ));

        $adapted = $case;
        $adapted['id'] = (string) ($case['id'] ?? $case['case_id'] ?? '');
        $adapted['task_category'] = $taskCategory;
        $adapted['role_focus'] = $roleFocus;
        // Per-case difficulty_level (L1..L5) is canon and stays authoritative; only fall
        // back to the coarse difficulty bucket → L1/L3/L5 alias when a case hasn't
        // declared its own level. Keeps the difficulty_weight legacy alias intact for
        // aggregate scoring callers, but never overwrites a declared canonical level.
        if (! isset($adapted['difficulty_level']) || ! in_array((string) $adapted['difficulty_level'], self::DIFFICULTY_LEVELS, true)) {
            $adapted['difficulty_level'] = self::difficultyToLevel((string) ($case['difficulty'] ?? ''));
        }
        $adapted['difficulty_weight'] = self::difficultyLevelWeight((string) $adapted['difficulty_level']);
        $adapted['setup_fixture'] = [
            'seed_dir' => $fixturePath,
            'base_files' => $baseFiles,
        ];
        $adapted['quality_gates'] = $weights;
        // Canonical `test_command` alias = the hermetic case validation command.
        // A release battery must score the current challenge, not a broad
        // category suite that can import unrelated repository debt.
        $adapted['test_command'] = (string) (
            $case['test_command']
            ?? $case['quick_test_command']
            ?? $case['full_test_command']
            ?? ''
        );
        $adapted['human_prompt'] = (string) ($case['human_prompt'] ?? $this->humanPromptForCase($case));
        $adapted['context_profile'] = is_array($case['context_profile'] ?? null)
            ? (array) $case['context_profile']
            : $this->contextProfileForCase($case);
        $adapted['measurement_tags'] = array_values(array_unique(array_merge(
            (array) ($case['measurement_tags'] ?? []),
            ['human_prompt', 'long_context', 'ambiguity_handling', 'assumption_probe', 'scope_boundary_probe'],
        )));
        $adapted['human_prompt_probe'] = is_array($case['human_prompt_probe'] ?? null)
            ? (array) $case['human_prompt_probe']
            : $this->humanPromptProbeForCase($adapted);
        $adapted['expected_evidence'] = array_values(array_map(static fn ($v): string => (string) $v, $evidence));
        $adapted['expected_signal'] = (string) ($acceptance[0] ?? '');

        return $adapted;
    }

    /**
     * @param  array<string,mixed>  $case
     */
    private function humanPromptForCase(array $case): string
    {
        $title = (string) ($case['title'] ?? $case['case_id'] ?? 'caso sem titulo');
        $objective = (string) ($case['objective'] ?? '');
        $businessRule = (string) ($case['business_rule'] ?? '');
        $risk = (string) ($case['risk_level'] ?? 'medium');
        $ambiguity = (string) ($case['ambiguity_level'] ?? 'medium');
        $allowed = implode(', ', array_map(static fn ($v): string => (string) $v, (array) ($case['allowed_files_scope'] ?? [])));
        $forbidden = implode(', ', array_map(static fn ($v): string => (string) $v, (array) ($case['forbidden_files_scope'] ?? [])));

        return "Oi, preciso que voce pegue esse caso como se fosse um ticket real de engenharia, com contexto incompleto e pressao de entrega.\n"
            ."Titulo: {$title}\n"
            ."O que eu acho que precisa acontecer: {$objective}\n"
            ."Contexto de negocio que talvez esteja incompleto: {$businessRule}\n"
            ."Ambiguidade percebida: {$ambiguity}; risco: {$risk}.\n"
            ."Antes de editar, separe fatos, suposicoes e decisoes reversiveis. Se algo estiver inseguro, bloqueie honestamente em vez de inventar.\n"
            ."Pode mexer somente em: {$allowed}.\n"
            ."Nao toque em: {$forbidden}.\n"
            .'No final, deixe evidencia auditavel, testes locais e uma explicacao curta dos tradeoffs.';
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function contextProfileForCase(array $case): array
    {
        $complexity = $this->complexityProfileForCase($case);

        return [
            'schema_version' => 'atlas.forge.rivals.context_profile.v1',
            'prompt_style' => 'human_ambiguous_operator_ticket',
            'long_context_required' => (bool) $complexity['long_context_required'],
            'estimated_context_tokens' => $complexity['estimated_context_tokens'],
            'domain_count' => $complexity['domain_count'],
            'scope_surface_count' => $complexity['scope_surface_count'],
            'reasoning_depth' => $complexity['reasoning_depth'],
            'ambiguity_score' => $complexity['ambiguity_score'],
            'risk_score' => $complexity['risk_score'],
            'requires_assumption_log' => true,
            'requires_tradeoff_notes' => true,
            'requires_scope_boundary_reasoning' => true,
            'requires_replayable_evidence' => true,
            'requires_rollback_plan' => (bool) $complexity['requires_rollback_plan'],
            'requires_evidence_matrix' => true,
            'complexity_profile' => $complexity,
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function humanPromptProbeForCase(array $case): array
    {
        $domains = array_values(array_map(
            static fn ($domain): string => (string) $domain,
            (array) ($case['industrial_domains'] ?? []),
        ));
        $risk = (string) ($case['risk_level'] ?? 'medium');
        $ambiguity = (string) ($case['ambiguity_level'] ?? 'medium');
        $complexity = $this->complexityProfileForCase($case);

        return [
            'schema_version' => 'atlas.forge.rivals.human_prompt_probe.v1',
            'purpose' => 'score ambiguous human prompt handling without using synthetic claims',
            'min_prompt_chars' => 520,
            'min_reasoning_depth' => $complexity['reasoning_depth'],
            'min_context_tokens' => $complexity['estimated_context_tokens'],
            'requires_sections' => [
                'facts_observed',
                'assumptions',
                'reversible_decisions',
                'scope_boundaries',
                'evidence_plan',
                'replay_matrix',
                'tradeoffs',
                'honest_blockers',
            ],
            'must_reference' => [
                'allowed_files_scope',
                'forbidden_files_scope',
                'local_tests',
                'replayable_evidence',
            ],
            'domain_pressure' => $domains,
            'risk_level' => $risk,
            'ambiguity_level' => $ambiguity,
            'complexity_profile' => $complexity,
            'invalid_if_missing' => [
                'assumption_log',
                'scope_boundary_reasoning',
                'evidence_plan',
                'replay_matrix',
                'honest_blocker_when_uncertain',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function complexityProfileForCase(array $case): array
    {
        $level = (string) ($case['difficulty_level'] ?? self::DIFFICULTY_LEVEL_L3);
        $levelScore = self::DIFFICULTY_LEVEL_SCORE[$level] ?? self::DIFFICULTY_LEVEL_SCORE[self::DIFFICULTY_LEVEL_L3];
        $domains = array_values(array_unique(array_map(
            static fn ($domain): string => (string) $domain,
            (array) ($case['industrial_domains'] ?? []),
        )));
        $domainCount = max(1, count($domains));
        $allowedCount = max(1, count((array) ($case['allowed_files_scope'] ?? [])));
        $expectedChangedCount = max(1, count((array) ($case['expected_changed_files'] ?? [])));
        $scopeSurfaceCount = $allowedCount + $expectedChangedCount;
        $ambiguity = (string) ($case['ambiguity_level'] ?? 'medium');
        $risk = (string) ($case['risk_level'] ?? 'medium');
        $ambiguityScore = match ($ambiguity) {
            'high' => 3,
            'low' => 1,
            default => 2,
        };
        $riskScore = match ($risk) {
            'critical' => 4,
            'high' => 3,
            'low' => 1,
            default => 2,
        };
        $requiresRollbackPlan = in_array($risk, ['critical', 'high'], true)
            || array_intersect($domains, ['incident_rollback', 'migration', 'security']) !== [];
        $reasoningDepth = min(5, max(3, (int) ceil($levelScore + ($domainCount > 1 ? 1 : 0) + ($requiresRollbackPlan ? 1 : 0))));
        $estimatedContextTokens = 1800
            + ($domainCount * 650)
            + ($scopeSurfaceCount * 220)
            + ($reasoningDepth * 300)
            + ($ambiguityScore * 250)
            + ($riskScore * 180);

        return [
            'schema_version' => 'atlas.forge.rivals.case_complexity_profile.v1',
            'difficulty_level' => $level,
            'difficulty_score' => $levelScore,
            'domain_count' => $domainCount,
            'scope_surface_count' => $scopeSurfaceCount,
            'estimated_context_tokens' => $estimatedContextTokens,
            'reasoning_depth' => $reasoningDepth,
            'ambiguity_score' => $ambiguityScore,
            'risk_score' => $riskScore,
            'long_context_required' => $estimatedContextTokens >= 4200 || $reasoningDepth >= 4,
            'requires_multi_step_plan' => true,
            'requires_rollback_plan' => $requiresRollbackPlan,
            'requires_evidence_matrix' => true,
            'measured_dimensions' => [
                'long_context_retention',
                'ambiguous_human_prompt_handling',
                'multi_step_reasoning',
                'scope_boundary_discipline',
                'replayable_evidence_quality',
                'honest_blocker_behavior',
            ],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function industrialCases(): array
    {
        $cases = [];
        for ($i = 1; $i <= self::INDUSTRIAL_CASE_COUNT; $i++) {
            $cases[] = $this->adaptCase($this->industrialCase($i));
        }

        return $cases;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function industrialCasesForDomain(string $caseSet, string $domain, int $limit): array
    {
        return $this->expandIndustrialSelection($caseSet, array_values(array_filter(
            $this->industrialCases(),
            static fn (array $case): bool => in_array($domain, (array) ($case['industrial_domains'] ?? []), true),
        )), $limit);
    }

    /**
     * @param  list<string>  $domains
     * @return list<array<string,mixed>>
     */
    private function industrialCasesForDomains(string $caseSet, array $domains, int $limit): array
    {
        return $this->expandIndustrialSelection($caseSet, array_values(array_filter(
            $this->industrialCases(),
            static function (array $case) use ($domains): bool {
                $caseDomains = (array) ($case['industrial_domains'] ?? []);
                foreach ($domains as $domain) {
                    if (in_array($domain, $caseDomains, true)) {
                        return true;
                    }
                }

                return false;
            },
        )), $limit);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function statisticalRepeatCases(): array
    {
        $base = array_slice($this->industrialCases(), 0, 20);
        $cases = [];
        foreach ($base as $case) {
            for ($repeat = 1; $repeat <= 3; $repeat++) {
                $copy = $case;
                $copy['case_id'] = $case['case_id'].'-r'.$repeat;
                $copy['id'] = $copy['case_id'];
                $copy['fixture_seed_path'] = 'storage/forge-rivals-corpus/'.$copy['case_id'].'/seed';
                $scopeRoot = 'storage/forge-rivals-industrial/'.$copy['case_id'];
                $copy['allowed_files_scope'] = [
                    $scopeRoot.'/src/**',
                    $scopeRoot.'/tests/**',
                    $scopeRoot.'/docs/**',
                ];
                $copy['expected_changed_files'] = [
                    $scopeRoot.'/src/'.$copy['case_id'].'.php',
                    $scopeRoot.'/tests/'.$copy['case_id'].'Test.php',
                ];
                $copy['statistical_repeat'] = [
                    'repeat_index' => $repeat,
                    'repeat_group' => $case['case_id'],
                    'required_repetitions' => 3,
                    'variance_policy' => 'block_strong_claim_until_repetitions_complete',
                ];
                $copy['industrial_domains'] = array_values(array_unique(array_merge(
                    (array) ($copy['industrial_domains'] ?? []),
                    ['flakiness_repeat'],
                )));
                $copy = $this->retargetIndustrialCasePaths($copy, (string) $copy['case_id']);
                $cases[] = $copy;
            }
        }

        return $cases;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function metaProviderStressCases(): array
    {
        $domains = [
            'ambiguous_bug',
            'incomplete_requirements',
            'multi_day_task',
            'incident_rollback',
            'security',
            'product',
            'integration',
            'performance',
        ];

        $cases = $this->industrialCasesForDomains(self::CASE_SET_META_PROVIDER_STRESS, $domains, 50);

        return array_values(array_map(function (array $case): array {
            $complexity = $this->complexityProfileForCase($case);
            $case['meta_provider_stress'] = [
                'schema_version' => 'atlas.forge.rivals.meta_provider_stress.v1',
                'targets' => ['cursor_cli', 'composer_2_5', 'claude_code', 'codex_cli', 'gemini_cli'],
                'complexity_profile' => $complexity,
                'measurement_floor' => [
                    'min_case_count' => 50,
                    'min_context_tokens' => $complexity['estimated_context_tokens'],
                    'min_reasoning_depth' => $complexity['reasoning_depth'],
                    'requires_replay_matrix' => true,
                    'requires_honest_blocker_path' => true,
                    'synthetic_claim_allowed' => false,
                ],
                'measures' => [
                    'long_context_retention',
                    'ambiguous_human_prompt_handling',
                    'multi_step_reasoning',
                    'scope_boundary_discipline',
                    'replayable_evidence_quality',
                    'tool_event_evidence_quality',
                    'no_synthetic_claim_under_uncertainty',
                ],
                'cursor_meta_provider_expected_receipts' => [
                    'stream_json_system_init',
                    'stream_json_tool_events',
                    'terminal_result_or_honest_blocker',
                ],
            ];
            $case['measurement_tags'] = array_values(array_unique(array_merge(
                (array) ($case['measurement_tags'] ?? []),
                ['meta_provider_stress', 'cursor_meta_provider', 'composer_2_5_surface'],
            )));

            return $case;
        }, $cases));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function allGeneratedIndustrialCases(): array
    {
        return array_values(array_merge(
            $this->industrialCases(),
            $this->industrialCasesForDomain(self::CASE_SET_AMBIGUOUS_BUGS, 'ambiguous_bug', 50),
            $this->industrialCasesForDomain(self::CASE_SET_MULTI_DAY_REFACTORS, 'multi_day_task', 50),
            $this->industrialCasesForDomain(self::CASE_SET_INCIDENT_RESPONSE, 'incident_rollback', 50),
            $this->industrialCasesForDomains(self::CASE_SET_PRODUCT_SECURITY_MIGRATIONS, ['product', 'security', 'migration'], 50),
            $this->statisticalRepeatCases(),
            $this->metaProviderStressCases(),
            $this->extremeDifferentiatorCases(),
            $this->ceiling360Cases(),
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function extremeDifferentiatorCases(): array
    {
        $pool = array_values(array_filter(
            $this->industrialCases(),
            static fn (array $case): bool => in_array(
                (string) ($case['difficulty_level'] ?? ''),
                [self::DIFFICULTY_LEVEL_L4, self::DIFFICULTY_LEVEL_L5],
                true,
            ),
        ));

        $cases = $this->expandIndustrialSelection(self::CASE_SET_EXTREME_DIFFERENTIATOR, $pool, 80);

        return array_values(array_map(function (array $case): array {
            $case = $this->hardenExtremeDifferentiatorCase($case);
            $complexity = $this->complexityProfileForCase($case);
            $capabilityAxes = $this->extremeCapabilityAxes($case);
            $case['extreme_differentiator'] = [
                'schema_version' => 'atlas.forge.rivals.extreme_differentiator.v1',
                'purpose' => 'separate runner strengths when broad release batteries produce ties',
                'targets' => ['codex_cli', 'composer_2_5', 'claude_code', 'atlas_forge', 'atlas_dev'],
                'required_signal' => [
                    'min_cases' => 80,
                    'min_reasoning_depth' => 5,
                    'min_estimated_context_tokens' => 4200,
                    'allowed_difficulty_levels' => [self::DIFFICULTY_LEVEL_L5],
                    'requires_category_breakdown' => true,
                    'requires_difficulty_breakdown' => true,
                    'requires_separation_analysis' => true,
                    'tie_is_diagnostic_not_claim' => true,
                ],
                'measures' => $capabilityAxes,
                'capability_axes' => $capabilityAxes,
                'complexity_profile' => $complexity,
            ];
            $case['measured_capabilities'] = $capabilityAxes;
            $case['measurement_tags'] = array_values(array_unique(array_merge(
                (array) ($case['measurement_tags'] ?? []),
                ['extreme_differentiator', 'tie_breaker', 'runner_strength_probe'],
            )));

            return $case;
        }, $cases));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function ceiling360Cases(): array
    {
        $pool = array_values(array_filter(
            $this->industrialCases(),
            static fn (array $case): bool => (string) ($case['difficulty_level'] ?? '') === self::DIFFICULTY_LEVEL_L5,
        ));

        $cases = $this->expandIndustrialSelection(self::CASE_SET_CEILING_360, $pool, 120);

        return array_values(array_map(function (array $case): array {
            $case = $this->hardenExtremeDifferentiatorCase($case);
            $case['risk_level'] = 'critical';
            $case['ambiguity_level'] = 'high';
            $case['planning_weight'] = max(0.70, (float) ($case['planning_weight'] ?? 0.0));
            $case['execution_weight'] = round(1.0 - (float) $case['planning_weight'], 2);
            $case['difficulty_reason'] = (string) ($case['difficulty_reason'] ?? '')
                .' Ceiling-360 hardening requires maximum-pressure planning, rollback, ambiguity, replay and blocker quality across every required 360 axis.';
            $case['human_prompt'] = $this->humanPromptForCase($case)
                ."\n\nPressao adicional: trate este como caso de teto pratico do Rivals. Se faltar informacao para uma mudanca segura, bloqueie com evidencia; se seguir, entregue plano, rollback, matriz de replay, fronteiras de escopo e custo/risco por decisao."
                ."\n\nPressao L5+: ha requisitos parcialmente conflitantes, um oraculo incompleto, sinais de regressao nao obvios e risco operacional alto. Nao otimize apenas para passar o teste rapido; explicite as invariantes que protegeriam producao e diga exatamente o que voce nao conseguiu provar.";
            $case['context_profile'] = $this->contextProfileForCase($case);
            $case['human_prompt_probe'] = $this->humanPromptProbeForCase($case);

            $capabilityAxes = $this->ceilingCapabilityAxes($case);
            $complexity = $this->ceilingComplexityProfileForCase($case);
            $case['context_profile'] = $this->applyComplexityProfile($case['context_profile'], $complexity);
            $case['human_prompt_probe'] = $this->applyComplexityProfile($case['human_prompt_probe'], $complexity);
            $case['ceiling_pressure_profile'] = $this->ceilingPressureProfileForCase($case, $complexity, $capabilityAxes);
            $case['ceiling_360'] = [
                'schema_version' => 'atlas.forge.rivals.ceiling_360.v1',
                'purpose' => 'map the practical ceiling of runners across all mandatory 360 capabilities after broad batteries tie',
                'targets' => ['atlas_forge', 'atlas_dev', 'claude_code', 'codex_cli', 'gemini_cli', 'cursor_cli', 'composer_2_5'],
                'required_signal' => [
                    'min_cases' => 120,
                    'difficulty_level' => self::DIFFICULTY_LEVEL_L5,
                    'pressure_level' => 'L5+',
                    'min_reasoning_depth' => 6,
                    'min_estimated_context_tokens' => 12000,
                    'requires_all_360_capabilities' => true,
                    'requires_adversarial_constraints' => true,
                    'requires_non_obvious_regression_probe' => true,
                    'requires_honest_uncertainty_boundary' => true,
                    'requires_separation_analysis' => true,
                    'tie_is_diagnostic_not_claim' => true,
                ],
                'capability_axes' => $capabilityAxes,
                'complexity_profile' => $complexity,
                'pressure_profile' => $case['ceiling_pressure_profile'],
                'claim_policy' => [
                    'advisory_only' => true,
                    'external_claim_allowed' => false,
                    'routing_effect' => 'none',
                ],
            ];
            $case['measured_capabilities'] = $capabilityAxes;
            $case['measurement_tags'] = array_values(array_unique(array_merge(
                (array) ($case['measurement_tags'] ?? []),
                ['ceiling_360', 'runner_ceiling_probe', 'all_required_360_capabilities'],
            )));

            return $case;
        }, $cases));
    }

    /**
     * @param  array<string,mixed>  $profile
     * @param  array<string,mixed>  $complexity
     * @return array<string,mixed>
     */
    private function applyComplexityProfile(array $profile, array $complexity): array
    {
        $profile['estimated_context_tokens'] = $complexity['estimated_context_tokens'];
        $profile['domain_count'] = $complexity['domain_count'];
        $profile['scope_surface_count'] = $complexity['scope_surface_count'];
        $profile['reasoning_depth'] = $complexity['reasoning_depth'];
        $profile['ambiguity_score'] = $complexity['ambiguity_score'];
        $profile['risk_score'] = $complexity['risk_score'];
        $profile['long_context_required'] = (bool) ($complexity['long_context_required'] ?? true);
        $profile['requires_rollback_plan'] = (bool) ($complexity['requires_rollback_plan'] ?? true);
        $profile['requires_evidence_matrix'] = (bool) ($complexity['requires_evidence_matrix'] ?? true);
        $profile['complexity_profile'] = $complexity;

        if (array_key_exists('min_context_tokens', $profile)) {
            $profile['min_context_tokens'] = $complexity['estimated_context_tokens'];
        }
        if (array_key_exists('min_reasoning_depth', $profile)) {
            $profile['min_reasoning_depth'] = $complexity['reasoning_depth'];
        }

        return $profile;
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function ceilingComplexityProfileForCase(array $case): array
    {
        $complexity = $this->complexityProfileForCase($case);
        $domains = array_values(array_unique(array_map(
            static fn ($domain): string => (string) $domain,
            (array) ($case['industrial_domains'] ?? []),
        )));

        $complexity['pressure_level'] = 'L5+';
        $complexity['estimated_context_tokens'] = max(12000, (int) ($complexity['estimated_context_tokens'] ?? 0) + 2500);
        $complexity['reasoning_depth'] = max(6, (int) ($complexity['reasoning_depth'] ?? 0));
        $complexity['scope_surface_count'] = max(8, (int) ($complexity['scope_surface_count'] ?? 0));
        $complexity['long_context_required'] = true;
        $complexity['requires_multi_step_plan'] = true;
        $complexity['requires_rollback_plan'] = true;
        $complexity['requires_evidence_matrix'] = true;
        $complexity['requires_adversarial_constraints'] = true;
        $complexity['requires_non_obvious_regression_probe'] = true;
        $complexity['requires_honest_uncertainty_boundary'] = true;
        $complexity['measured_dimensions'] = array_values(array_unique(array_merge(
            (array) ($complexity['measured_dimensions'] ?? []),
            [
                'adversarial_constraint_handling',
                'non_obvious_regression_detection',
                'uncertainty_boundary_quality',
                'production_invariant_reasoning',
                'capability_separation_signal',
            ],
            $this->extremeCapabilityAxes($case),
        )));
        $complexity['domain_pressure'] = $domains;

        return $complexity;
    }

    /**
     * @param  array<string,mixed>  $case
     * @param  array<string,mixed>  $complexity
     * @param  list<string>  $capabilityAxes
     * @return array<string,mixed>
     */
    private function ceilingPressureProfileForCase(array $case, array $complexity, array $capabilityAxes): array
    {
        return [
            'schema_version' => 'atlas.forge.rivals.ceiling_pressure_profile.v1',
            'pressure_level' => 'L5+',
            'purpose' => 'force measurable separation among strong runners after easy batteries tie',
            'estimated_context_tokens_floor' => 12000,
            'reasoning_depth_floor' => 6,
            'requires' => [
                'conflicting_constraints_analysis',
                'non_obvious_regression_probe',
                'production_invariant_reasoning',
                'rollback_and_replay_matrix',
                'honest_uncertainty_boundary',
                'capability_specific_self_evaluation',
            ],
            'invalid_if_missing' => [
                'facts_assumptions_decisions_split',
                'tradeoff_matrix',
                'rollback_plan',
                'negative_test_or_replay_probe',
                'uncertainty_boundary',
                'capability_specific_evidence',
            ],
            'capability_axes' => $capabilityAxes,
            'domain_pressure' => array_values(array_map(
                static fn ($domain): string => (string) $domain,
                (array) ($case['industrial_domains'] ?? []),
            )),
            'complexity_profile' => $complexity,
            'advisory_only' => true,
            'routing_effect' => 'none',
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function hardenExtremeDifferentiatorCase(array $case): array
    {
        $originalAmbiguity = (string) ($case['ambiguity_level'] ?? 'medium');
        $originalRisk = (string) ($case['risk_level'] ?? 'medium');
        $originalLevel = (string) ($case['difficulty_level'] ?? self::DIFFICULTY_LEVEL_L3);
        $level = self::DIFFICULTY_LEVEL_L5;

        $case['difficulty'] = self::DIFFICULTY_HARD;
        $case['difficulty_level'] = self::DIFFICULTY_LEVEL_L5;
        $case['difficulty_score'] = self::DIFFICULTY_LEVEL_SCORE[self::DIFFICULTY_LEVEL_L5];
        $case['difficulty_weight'] = self::difficultyLevelWeight(self::DIFFICULTY_LEVEL_L5);
        $case['difficulty_reason'] = trim((string) ($case['difficulty_reason'] ?? '')) !== ''
            ? (string) $case['difficulty_reason'].' Extreme differentiator hardening upgrades this case to L5 so broad batteries cannot hide runner weaknesses behind easy ties.'
            : 'Extreme differentiator hardening upgrades this case to L5 so broad batteries cannot hide runner weaknesses behind easy ties.';
        $case['ambiguity_level'] = 'high';

        if (! in_array($originalRisk, ['critical', 'high'], true)) {
            $case['risk_level'] = 'high';
        }

        $case['human_prompt'] = $this->humanPromptForCase($case);
        $case['context_profile'] = $this->contextProfileForCase($case);
        $case['human_prompt_probe'] = $this->humanPromptProbeForCase($case);
        $case['extreme_hardening'] = [
            'schema_version' => 'atlas.forge.rivals.extreme_hardening.v1',
            'original_difficulty_level' => $originalLevel,
            'original_ambiguity_level' => $originalAmbiguity,
            'original_risk_level' => $originalRisk,
            'hardened_difficulty_level' => $level,
            'hardened_ambiguity_level' => (string) ($case['ambiguity_level'] ?? $originalAmbiguity),
            'hardened_risk_level' => (string) ($case['risk_level'] ?? $originalRisk),
            'reason' => 'Extreme differentiators measure decision quality under ambiguity/risk, not only implementation throughput.',
        ];

        return $case;
    }

    /**
     * @param  array<string,mixed>  $case
     * @return list<string>
     */
    private function extremeCapabilityAxes(array $case): array
    {
        $domains = (array) ($case['industrial_domains'] ?? []);
        $axes = [
            'ambiguity_resolution',
            'long_context_retention',
            'multi_step_execution',
            'evidence_replay_completeness',
            'honest_blocker_behavior',
        ];

        foreach ($domains as $domain) {
            $axes = array_merge($axes, match ((string) $domain) {
                'incident_rollback' => ['rollback_safety', 'mitigation_speed', 'postmortem_quality'],
                'security' => ['security_fail_closed', 'threat_model_quality'],
                'performance' => ['performance_tradeoff_quality', 'behavior_preservation'],
                'migration' => ['compatibility_planning', 'data_safety'],
                'integration' => ['contract_safety', 'observability'],
                'flakiness_repeat' => ['statistical_reproducibility', 'flake_isolation'],
                'ambiguous_bug', 'incomplete_requirements' => ['assumption_quality', 'scope_boundary_probe'],
                'large_refactor', 'multi_day_task' => ['decomposition_quality', 'rollback_safety'],
                'product' => ['ux_tradeoff_quality', 'business_fit'],
                default => [],
            });
        }

        return array_values(array_unique($axes));
    }

    /**
     * @param  array<string,mixed>  $case
     * @return list<string>
     */
    private function ceilingCapabilityAxes(array $case): array
    {
        return array_values(array_unique(array_merge([
            'long_context_retention',
            'multi_step_reasoning',
            'rollback_safety',
            'scope_boundary_discipline',
            'replayable_evidence_quality',
            'honest_blocker_behavior',
            'ambiguous_human_prompt_handling',
        ], $this->extremeCapabilityAxes($case))));
    }

    /**
     * Industrial thematic presets are batteries, not raw domain filters. When
     * the 200-case base corpus has fewer than 50 natural matches for a theme,
     * we cycle the matching pool into deterministic variants so the preset
     * still has the requested industrial floor without inventing hidden state.
     *
     * @param  list<array<string,mixed>>  $pool
     * @return list<array<string,mixed>>
     */
    private function expandIndustrialSelection(string $caseSet, array $pool, int $limit): array
    {
        if ($pool === []) {
            return [];
        }

        $cases = [];
        $count = count($pool);
        for ($i = 0; $i < $limit; $i++) {
            $base = $pool[$i % $count];
            $variant = intdiv($i, $count) + 1;
            $ordinal = str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
            $baseId = (string) ($base['case_id'] ?? 'industrial-case');
            $caseId = $caseSet.'-'.$ordinal.'-'.$baseId.($variant > 1 ? '-v'.$variant : '');
            $copy = $base;
            $copy['case_id'] = $caseId;
            $copy['id'] = $caseId;
            $copy['industrial_suite'] = 'atlas-forge-rivals-industrial-benchmark-suite-v1';
            $copy['industrial_case_set'] = $caseSet;
            $copy['industrial_variant'] = [
                'source_case_id' => $baseId,
                'variant_index' => $variant,
                'selection_ordinal' => $i + 1,
                'selection_policy' => 'deterministic_cycle_until_case_set_floor',
            ];
            $copy = $this->retargetIndustrialCasePaths($copy, $caseId);
            $cases[] = $copy;
        }

        return $cases;
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function retargetIndustrialCasePaths(array $case, string $caseId): array
    {
        $scopeRoot = 'storage/forge-rivals-industrial/'.$caseId;
        $case['fixture_seed_path'] = 'storage/forge-rivals-corpus/'.$caseId.'/seed';
        $case['setup_fixture'] = array_merge(
            is_array($case['setup_fixture'] ?? null) ? $case['setup_fixture'] : [],
            ['seed_dir' => $case['fixture_seed_path']],
        );
        $case['allowed_files_scope'] = [
            $scopeRoot.'/src/**',
            $scopeRoot.'/tests/**',
            $scopeRoot.'/docs/**',
        ];
        $case['expected_changed_files'] = [
            $scopeRoot.'/src/'.$caseId.'.php',
            $scopeRoot.'/tests/'.$caseId.'Test.php',
            $scopeRoot.'/docs/'.$caseId.'-runbook.md',
        ];
        $case['quick_test_command'] = 'php storage/forge-rivals-industrial/'.$caseId.'/tests/'.$caseId.'Test.php';
        $case['full_test_command'] = $case['quick_test_command'];
        $case['test_command'] = $case['quick_test_command'];

        return $case;
    }

    /**
     * @return array<string,mixed>
     */
    private function industrialCase(int $i): array
    {
        $category = self::TASK_CATEGORIES[($i - 1) % count(self::TASK_CATEGORIES)];
        $level = self::DIFFICULTY_LEVELS[($i - 1) % count(self::DIFFICULTY_LEVELS)];
        $difficulty = match ($level) {
            self::DIFFICULTY_LEVEL_L1, self::DIFFICULTY_LEVEL_L2 => self::DIFFICULTY_EASY,
            self::DIFFICULTY_LEVEL_L5 => self::DIFFICULTY_HARD,
            default => self::DIFFICULTY_MEDIUM,
        };
        $domain = self::INDUSTRIAL_DOMAINS[($i - 1) % count(self::INDUSTRIAL_DOMAINS)];
        $secondaryDomain = self::INDUSTRIAL_DOMAINS[$i % count(self::INDUSTRIAL_DOMAINS)];
        $slug = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
        $caseId = 'industrial-'.$slug.'-'.$domain;
        $scopeRoot = 'storage/forge-rivals-industrial/'.$caseId;

        return [
            'case_id' => $caseId,
            'title' => 'Industrial benchmark '.$slug.' · '.$this->domainTitle($domain),
            'category' => $category,
            'secondary_categories' => $this->industrialSecondaryCategories($category, $domain),
            'difficulty' => $difficulty,
            'difficulty_level' => $level,
            'difficulty_score' => self::DIFFICULTY_LEVEL_SCORE[$level],
            'difficulty_reason' => $this->difficultyReason($level, $domain),
            'planning_weight' => $this->planningWeight($domain, $level),
            'execution_weight' => 1.0 - $this->planningWeight($domain, $level),
            'ambiguity_level' => in_array($domain, ['ambiguous_bug', 'incomplete_requirements'], true) ? 'high' : 'medium',
            'risk_level' => in_array($domain, ['incident_rollback', 'security', 'migration'], true) ? 'critical' : ($level === 'L5' ? 'high' : 'medium'),
            'task_type' => $domain,
            'industrial_domains' => array_values(array_unique([$domain, $secondaryDomain])),
            'industrial_suite' => 'atlas-forge-rivals-industrial-benchmark-suite-v1',
            'objective' => $this->objectiveForDomain($domain, $slug),
            'business_rule' => $this->businessRuleForDomain($domain),
            'acceptance_criteria' => [
                'Entrega resolve o caso sem tocar arquivos proibidos.',
                'Patch inclui evidencia local suficiente para auditoria e replay.',
                'Resposta explicita incertezas, tradeoffs e limites quando o prompt for ambiguo.',
                'Scorecard por caso pode ser reconstruido a partir do evidence pack.',
            ],
            'allowed_files_scope' => [
                $scopeRoot.'/src/**',
                $scopeRoot.'/tests/**',
                $scopeRoot.'/docs/**',
            ],
            'forbidden_files_scope' => [
                'app/Services/Ai/SelfConstruction/**',
                'atlas-desktop/**',
                'atlas-cartografia/**',
                'app/Services/Ai/Voice/**',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/'.$caseId.'/seed',
            'quick_test_command' => 'php '.$scopeRoot.'/tests/'.$caseId.'Test.php',
            'full_test_command' => 'php '.$scopeRoot.'/tests/'.$caseId.'Test.php',
            'expected_changed_files' => [
                $scopeRoot.'/src/'.$caseId.'.php',
                $scopeRoot.'/tests/'.$caseId.'Test.php',
            ],
            'quality_weights' => [
                'dimensions' => $this->scoringDimensions($domain),
                'weights' => $this->scoringWeights($domain),
            ],
            'scoring_dimensions' => $this->scoringDimensions($domain),
            'oracle' => [
                'type' => in_array($domain, ['ambiguous_bug', 'security', 'incident_rollback'], true) ? 'hidden_oracle' : 'public_oracle',
                'metadata_version' => 'industrial_oracle_v1',
                'human_triage_required' => in_array($domain, ['ambiguous_bug', 'incomplete_requirements'], true),
            ],
            'hidden_oracle_metadata' => [
                'available_to_runner' => false,
                'used_by_adjudicator_only' => true,
                'oracle_hash' => hash('sha256', $caseId.'|'.$domain.'|industrial_hidden_oracle_v1'),
            ],
            'invalid_if' => [
                'synthetic_score_admitted',
                'touched_forbidden_files',
                'external_rivals_unlock_attempted',
                'missing_case_scorecard',
                'missing_replay',
                'missing_evidence_pack',
                'oracle_metadata_ignored',
            ],
            'timeout_policy' => [
                'wall_clock_seconds_max' => $level === 'L5' ? 1800 : 900,
                'per_stage_seconds_max' => $level === 'L5' ? 420 : 240,
                'hard_kill_after_seconds' => $level === 'L5' ? 2400 : 1200,
            ],
            'evidence_requirements' => [
                'patch_diff',
                'provider_receipt',
                'test_log',
                'scorecard_per_case',
                'workspace_hashes',
                'oracle_adjudication_notes',
            ],
            'replay_requirements' => [
                'fixture_seed_hash',
                'final_changed_files_hash',
                'test_exit_code',
                'scorecard_hash',
                'oracle_metadata_hash',
            ],
            'fairness_notes' => 'Industrial generated spec; no runner-specific hint. Claim forte exige evidence/replay/scorecard/matrix e floors industriais.',
            'human_review_notes' => 'Operador deve revisar ambiguity handling, rollback plan, security/product tradeoffs e variancia antes de qualquer claim.',
            'claim_level' => self::CLAIM_LEVEL_CASE_RESULT_ONLY,
        ];
    }

    private function domainTitle(string $domain): string
    {
        return str_replace('_', ' ', $domain);
    }

    private function objectiveForDomain(string $domain, string $slug): string
    {
        return match ($domain) {
            'ambiguous_bug' => "Triar e corrigir bug ambiguo industrial {$slug}, separando fatos, hipoteses e fix minimo.",
            'incomplete_requirements' => "Transformar requisito incompleto {$slug} em plano executavel com assumptions auditaveis.",
            'large_refactor' => "Refatorar modulo legado {$slug} sem regressao funcional e com passos reversiveis.",
            'multi_day_task' => "Decompor tarefa multi-dia {$slug} em slices, checkpoints e evidencia incremental.",
            'incident_rollback' => "Responder incidente {$slug} com mitigacao, rollback seguro e postmortem tecnico.",
            'migration' => "Executar migration {$slug} com compatibilidade, plano de rollback e validacao de dados.",
            'documentation' => "Atualizar documentacao operacional {$slug} mantendo exemplos, riscos e runbook testaveis.",
            'test_design' => "Projetar testes {$slug} que capturem regressao, edge cases e comportamento esperado.",
            'security' => "Corrigir risco de seguranca {$slug} com fail-closed e evidencia de nao regressao.",
            'product' => "Implementar ajuste de produto {$slug} equilibrando UX, regra de negocio e metricas.",
            'integration' => "Estabilizar integracao {$slug} com contrato externo, retries e observabilidade.",
            'performance' => "Reduzir custo/latencia {$slug} sem mudar payload publico nem esconder tradeoffs.",
            'flakiness_repeat' => "Investigar flakiness {$slug} com repeticao estatistica e isolamento de causa.",
            default => "Resolver caso industrial {$slug} com evidencia completa.",
        };
    }

    private function businessRuleForDomain(string $domain): string
    {
        return match ($domain) {
            'ambiguous_bug' => 'Suporte descreveu sintomas conflitantes; o runner deve evitar fix especulativo sem evidenciar a causa mais provavel.',
            'incomplete_requirements' => 'Produto deixou lacunas de regra; o runner deve explicitar assumptions e bloquear partes inseguras.',
            'large_refactor' => 'Modulo critico precisa melhorar manutencao sem alterar contrato publico.',
            'multi_day_task' => 'Trabalho longo deve permanecer auditavel mesmo se interrompido entre etapas.',
            'incident_rollback' => 'Incidente em producao exige mitigacao rapida, rollback e evidencias para postmortem.',
            'migration' => 'Mudanca de schema/dados deve preservar compatibilidade e permitir retorno seguro.',
            'documentation' => 'Runbook precisa guiar operador real sob pressao, nao apenas descrever a feature.',
            'test_design' => 'Regressao historica deve falhar antes do fix e passar depois dele.',
            'security' => 'Falha deve fechar por padrao seguro e evitar bypass silencioso.',
            'product' => 'Mudanca deve melhorar fluxo do usuario sem quebrar metricas existentes.',
            'integration' => 'Contrato externo pode falhar parcial; o sistema deve degradar com evidencia.',
            'performance' => 'Otimizacao deve provar latencia/custo menor sem regressao funcional.',
            'flakiness_repeat' => 'Resultado so conta com repeticoes suficientes e variancia explicita.',
            default => 'Caso industrial exige evidencia completa antes de qualquer claim.',
        };
    }

    private function difficultyReason(string $level, string $domain): string
    {
        return $level.' mede '.$domain.' com escopo industrial, evidencia obrigatoria e risco de regressao.';
    }

    private function planningWeight(string $domain, string $level): float
    {
        if (in_array($domain, ['multi_day_task', 'incident_rollback', 'migration', 'large_refactor'], true)) {
            return 0.65;
        }
        if (in_array($domain, ['ambiguous_bug', 'incomplete_requirements', 'product', 'security'], true)) {
            return 0.55;
        }

        return $level === self::DIFFICULTY_LEVEL_L5 ? 0.55 : 0.40;
    }

    /**
     * @return list<string>
     */
    private function industrialSecondaryCategories(string $category, string $domain): array
    {
        $map = [
            'ambiguous_bug' => 'realistic_bugfix',
            'incomplete_requirements' => 'planning',
            'large_refactor' => 'refactor',
            'multi_day_task' => 'architecture',
            'incident_rollback' => 'integration_performance',
            'migration' => 'backend_logic',
            'documentation' => 'planning',
            'test_design' => 'test_design',
            'security' => 'architecture',
            'product' => 'frontend_ui',
            'integration' => 'integration_performance',
            'performance' => 'integration_performance',
            'flakiness_repeat' => 'test_design',
        ];
        $secondary = $map[$domain] ?? 'planning';

        return $secondary === $category ? [] : [$secondary];
    }

    /**
     * @return list<string>
     */
    private function scoringDimensions(string $domain): array
    {
        $base = ['correctness', 'scope_discipline', 'evidence_quality', 'maintainability'];

        return match ($domain) {
            'ambiguous_bug', 'incomplete_requirements' => array_values(array_unique(array_merge($base, ['ambiguity_handling', 'assumption_quality']))),
            'large_refactor', 'multi_day_task' => array_values(array_unique(array_merge($base, ['decomposition', 'rollback_safety']))),
            'incident_rollback' => array_values(array_unique(array_merge($base, ['mitigation_speed', 'rollback_safety', 'postmortem_quality']))),
            'migration' => array_values(array_unique(array_merge($base, ['compatibility', 'data_safety']))),
            'security' => array_values(array_unique(array_merge($base, ['fail_closed', 'threat_model']))),
            'product' => array_values(array_unique(array_merge($base, ['ux_quality', 'business_fit']))),
            'integration' => array_values(array_unique(array_merge($base, ['contract_safety', 'observability']))),
            'performance' => array_values(array_unique(array_merge($base, ['performance', 'behavior_preservation']))),
            'flakiness_repeat' => array_values(array_unique(array_merge($base, ['statistical_reproducibility', 'flake_isolation']))),
            default => $base,
        };
    }

    /**
     * @return array<string,float>
     */
    private function scoringWeights(string $domain): array
    {
        $dimensions = $this->scoringDimensions($domain);
        $weight = round(1.0 / count($dimensions), 4);
        $weights = array_fill_keys($dimensions, $weight);
        $last = array_key_last($weights);
        if (is_string($last)) {
            $weights[$last] = round(1.0 - array_sum(array_slice($weights, 0, -1)), 4);
        }

        return $weights;
    }

    /**
     * @param  array<string,mixed>  $case
     */
    private function primaryOrSecondaryContains(array $case, string $category): bool
    {
        if ((string) ($case['category'] ?? '') === $category) {
            return true;
        }
        $sec = (array) ($case['secondary_categories'] ?? []);
        foreach ($sec as $s) {
            if ((string) $s === $category) {
                return true;
            }
        }

        return false;
    }

    /**
     * Twelve canonical Release v1 cases. Each is declared in the 22-field
     * canon; legacy alias fields are computed by adaptCase().
     *
     * @return list<array<string,mixed>>
     */
    private function corpus(): array
    {
        return [
            // planning (5)
            $this->casePlanningL1AcceptanceChecklist(),
            $this->casePlanningL2IncrementalSlices(),
            $this->casePlanningL3RiskRegister(),
            $this->casePlanningL4ContractFirstSpec(),
            $this->casePlanningL5PhasedMigrationPlan(),
            // frontend_ui (5)
            $this->caseFrontendL1ButtonLoadingState(),
            $this->caseFrontendExecutionStatusPanel(),
            $this->caseFrontendFormValidationAccessibility(),
            $this->caseFrontendFilterableTable(),
            $this->caseFrontendL5VirtualizedKeyboardGrid(),
            // backend_logic (5)
            $this->caseBackendL1StringNormalizer(),
            $this->caseBackendL2CurrencyFormatter(),
            $this->caseBackendCacheInvalidation(),
            $this->caseBackendPermissionPolicyLeak(),
            $this->caseBackendL5StateMachineTransitions(),
            // realistic_bugfix (5)
            $this->caseBackendPaginationOffByOne(),
            $this->caseBugfixL2TimezoneDoubleUtc(),
            $this->caseBugfixL3CounterRaceCondition(),
            $this->caseBugfixL4FlakyTimeDependentTest(),
            $this->caseBugfixL5CascadeFailureFanout(),
            // refactor (5)
            $this->caseRefactorL1ExtractMethod(),
            $this->caseRefactorL2RenameSymbolSafely(),
            $this->caseRefactorControllerToService(),
            $this->caseRefactorL4ReplaceSwitchWithStrategy(),
            $this->caseRefactorL5DecomposeGodClass(),
            // test_design (5)
            $this->caseTestdesignL1AddEdgeCaseTests(),
            $this->caseTestRegressionBeforeFix(),
            $this->caseTestdesignL3PropertyBasedParser(),
            $this->caseTestdesignL4ContractTestBetweenModules(),
            $this->caseTestdesignL5MutationBaseline(),
            // architecture (5)
            $this->caseArchitectureL1PublicApiReadme(),
            $this->caseArchitectureL2ModuleBoundaryNamespace(),
            $this->caseArchitectureL3AdrDocument(),
            $this->caseArchitectureL4VersionedContractStrategy(),
            $this->caseArchitectureSchemaVersionedReceipt(),
            // integration_performance (5)
            $this->caseIntperfL1EagerLoadRelation(),
            $this->casePerformanceNPlusOneQuery(),
            $this->caseBackendIdempotentWebhook(),
            $this->caseIntegrationFakeProviderTimeoutRetry(),
            $this->caseIntperfL5CircuitBreakerStateMachine(),
        ];
    }

    /** @return array<string,mixed> */
    private function caseBackendPaginationOffByOne(): array
    {
        return [
            'case_id' => 'backend-pagination-off-by-one',
            'title' => 'Paginator devolve total_pages off-by-one quando count é múltiplo do per_page',
            'category' => 'realistic_bugfix',
            'secondary_categories' => ['backend_logic'],
            'difficulty' => self::DIFFICULTY_EASY,
            'difficulty_level' => 'L1',
            'difficulty_score' => 1.0,
            'difficulty_reason' => 'Bug clássico de borda (intdiv vs ceil) com fix de 1 linha; teste de regressão também cabe em poucas linhas.',
            'planning_weight' => 0.20,
            'execution_weight' => 0.80,
            'ambiguity_level' => 'low',
            'risk_level' => 'low',
            'objective' => 'Corrigir o cálculo de lastPage() em PageCalculator para usar ceil() em vez de divisão inteira que arredonda para baixo e perde o último registro.',
            'business_rule' => 'Suporte reportou "Page 5 de 4" no rodapé do listing de capturas. Operadores perdiam o último page quando count era múltiplo exato do limit. O fix precisa ser mínimo e vir acompanhado de teste de regressão para evitar reincidência.',
            'acceptance_criteria' => [
                'PageCalculatorTest::test_last_page_rounds_up_without_off_by_one passa.',
                'PageCalculator::lastPage(20, 10) === 2 e lastPage(21, 10) === 3.',
                'PageCalculator::lastPage(0, 10) === 1 (paginação vazia continua devolvendo a primeira página).',
                'Nenhum byte tocado fora de app/Services/Pagination/PageCalculator.php.',
            ],
            'allowed_files_scope' => [
                'app/Services/Pagination/PageCalculator.php',
                'tests/Unit/Pagination/PageCalculatorTest.php',
            ],
            'forbidden_files_scope' => [
                'app/Http/**',
                'app/Services/Ai/**',
                'database/migrations/**',
                'atlas-desktop/src/voice/**',
                'atlas-cartografia/**',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/backend-pagination-off-by-one/seed',
            'quick_test_command' => "php artisan test --filter='PageCalculatorTest'",
            'full_test_command' => "php artisan test --filter='Pagination'",
            'expected_changed_files' => [
                'app/Services/Pagination/PageCalculator.php',
            ],
            'quality_weights' => [
                'dimensions' => ['minimal_diff', 'regression_prevention', 'test_pass'],
                'weights' => [
                    'minimal_diff' => 0.45,
                    'regression_prevention' => 0.35,
                    'test_pass' => 0.20,
                ],
            ],
            'invalid_if' => [
                'touched_forbidden_files',
                'patch_exceeds_twenty_lines',
                'production_test_skipped_or_removed',
                'synthetic_score_admitted',
                'external_rivals_unlock_attempted',
            ],
            'timeout_policy' => [
                'wall_clock_seconds_max' => 240,
                'per_stage_seconds_max' => 60,
                'hard_kill_after_seconds' => 360,
            ],
            'evidence_requirements' => [
                'patch_diff',
                'phpunit_output',
                'regression_test_listing',
            ],
            'replay_requirements' => [
                'fixture_seed_hash',
                'final_changed_files_hash',
                'phpunit_exit_code',
            ],
            'fairness_notes' => 'Nenhum hint específico para Atlas, Claude ou Codex. Comparação justa: todos os arms recebem PageCalculator quebrado + teste pendente; mede correção mínima + regressão.',
            'human_review_notes' => 'Operador valida: (a) patch realmente corrige a borda count==multiple, (b) nenhum side-effect introduzido em outros métodos do PageCalculator.',
            'claim_level' => self::CLAIM_LEVEL_CASE_RESULT_ONLY,
        ];
    }

    /** @return array<string,mixed> */
    private function caseBackendPermissionPolicyLeak(): array
    {
        return [
            'case_id' => 'backend-permission-policy-leak',
            'title' => 'CapturePolicy autoriza acesso cross-tenant — deny-first ausente',
            'category' => 'backend_logic',
            'secondary_categories' => [],
            'difficulty' => self::DIFFICULTY_HARD,
            'difficulty_level' => 'L4',
            'difficulty_score' => 4.0,
            'difficulty_reason' => 'Brecha de segurança com tenant boundary cross-tenant; exige deny-first em contrato com fail-closed e auditoria, não apenas patch funcional.',
            'planning_weight' => 0.55,
            'execution_weight' => 0.45,
            'ambiguity_level' => 'medium',
            'risk_level' => 'critical',
            'objective' => 'Reescrever CapturePolicy::view() para deny-first comparando tenant_id do user com tenant_id da captura, em vez de permitir tudo por default.',
            'business_rule' => 'Auditoria interna encontrou que um operador do tenant A conseguiu ver captura do tenant B. O policy estava devolvendo true sempre que o user estava autenticado. Precisa fechar a brecha sem quebrar acesso legítimo.',
            'acceptance_criteria' => [
                'CapturePolicyTest::test_denies_cross_tenant passa (user.tenant_id !== capture.tenant_id ⇒ false).',
                'CapturePolicyTest::test_allows_same_tenant passa (user.tenant_id === capture.tenant_id ⇒ true).',
                'Policy nunca devolve true sem comparar tenant_id explicitamente.',
                'Patch toca apenas CapturePolicy.php (a suíte de teste já existe e precisa passar).',
            ],
            'allowed_files_scope' => [
                'app/Policies/CapturePolicy.php',
                'tests/Unit/Policies/CapturePolicyTest.php',
            ],
            'forbidden_files_scope' => [
                'app/Http/Controllers/**',
                'config/auth.php',
                'app/Services/Ai/**',
                'database/migrations/**',
                'atlas-desktop/src/voice/**',
                'atlas-cartografia/**',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/backend-permission-policy-leak/seed',
            'quick_test_command' => "php artisan test --filter='CapturePolicyTest'",
            'full_test_command' => "php artisan test --filter='Policies'",
            'expected_changed_files' => [
                'app/Policies/CapturePolicy.php',
            ],
            'quality_weights' => [
                'dimensions' => ['security_safety', 'api_correctness', 'maintainability'],
                'weights' => [
                    'security_safety' => 0.55,
                    'api_correctness' => 0.30,
                    'maintainability' => 0.15,
                ],
            ],
            'invalid_if' => [
                'touched_forbidden_files',
                'cross_tenant_request_still_allowed',
                'tests_relaxed_to_pass',
                'synthetic_score_admitted',
                'external_rivals_unlock_attempted',
            ],
            'timeout_policy' => [
                'wall_clock_seconds_max' => 300,
                'per_stage_seconds_max' => 90,
                'hard_kill_after_seconds' => 420,
            ],
            'evidence_requirements' => [
                'patch_diff',
                'phpunit_output',
                'policy_decision_log',
            ],
            'replay_requirements' => [
                'fixture_seed_hash',
                'final_changed_files_hash',
                'phpunit_exit_code',
            ],
            'fairness_notes' => 'Test fixture nomeia o tenant_id; nenhum arm tem vantagem por convenção interna. Mede deny-first como princípio universal de policy.',
            'human_review_notes' => 'Operador audita o diff para garantir que (a) o branch de allow segue sempre uma comparação tenant_id, (b) não há "if not authenticated return true" residual.',
            'claim_level' => self::CLAIM_LEVEL_CASE_RESULT_ONLY,
        ];
    }

    /** @return array<string,mixed> */
    private function caseBackendIdempotentWebhook(): array
    {
        return [
            'case_id' => 'backend-idempotent-webhook',
            'title' => 'Webhook handler aceita event_id duplicado — falta dedup append-only',
            'category' => 'integration_performance',
            'secondary_categories' => ['backend_logic'],
            'difficulty' => self::DIFFICULTY_MEDIUM,
            'difficulty_level' => 'L3',
            'difficulty_score' => 3.0,
            'difficulty_reason' => 'Idempotência exige ordem correta (verifica → grava → side-effect) e teste de retry honesto sem racing real.',
            'planning_weight' => 0.45,
            'execution_weight' => 0.55,
            'ambiguity_level' => 'medium',
            'risk_level' => 'high',
            'objective' => 'Tornar WebhookController::handle idempotente: ignorar event_id já processado, gravar nova entrada em received_events na primeira vez, sem race obvia para retry.',
            'business_rule' => 'Provider externo retransmite eventos em retry; nosso handler estava processando o mesmo event_id duas vezes e duplicando capturas no banco. Suporte registrou três incidentes no último sprint.',
            'acceptance_criteria' => [
                'WebhookControllerTest::test_first_event_id_processes passa (event_id novo ⇒ side-effect ocorre).',
                'WebhookControllerTest::test_duplicate_event_id_is_ignored passa (mesmo event_id ⇒ 200, side-effect NÃO ocorre).',
                'ReceivedEvent log registra cada event_id processado exatamente uma vez.',
                'Patch toca apenas WebhookController + ReceivedEvent + test correspondente.',
            ],
            'allowed_files_scope' => [
                'app/Http/Controllers/WebhookController.php',
                'app/Models/ReceivedEvent.php',
                'tests/Feature/Webhook/WebhookControllerTest.php',
            ],
            'forbidden_files_scope' => [
                'config/**',
                'app/Services/Ai/**',
                'database/migrations/**',
                'atlas-desktop/src/voice/**',
                'atlas-cartografia/**',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/backend-idempotent-webhook/seed',
            'quick_test_command' => "php artisan test --filter='WebhookControllerTest'",
            'full_test_command' => "php artisan test --filter='Webhook'",
            'expected_changed_files' => [
                'app/Http/Controllers/WebhookController.php',
            ],
            'quality_weights' => [
                'dimensions' => ['integration_safety', 'api_correctness', 'observability'],
                'weights' => [
                    'integration_safety' => 0.50,
                    'api_correctness' => 0.30,
                    'observability' => 0.20,
                ],
            ],
            'invalid_if' => [
                'touched_forbidden_files',
                'duplicate_event_id_still_double_processed',
                'received_event_log_skipped',
                'synthetic_score_admitted',
                'external_rivals_unlock_attempted',
            ],
            'timeout_policy' => [
                'wall_clock_seconds_max' => 360,
                'per_stage_seconds_max' => 90,
                'hard_kill_after_seconds' => 480,
            ],
            'evidence_requirements' => [
                'patch_diff',
                'phpunit_output',
                'received_event_log_listing',
            ],
            'replay_requirements' => [
                'fixture_seed_hash',
                'final_changed_files_hash',
                'phpunit_exit_code',
            ],
            'fairness_notes' => 'Cenário clássico de retry seguro. Nenhuma vantagem de DSL atlas-específica; mede idempotência como princípio.',
            'human_review_notes' => 'Operador confirma que (a) dedup usa event_id como chave única, (b) handler continua respondendo 200 para o duplicado (provider externo não deve receber 4xx em retry legítimo).',
            'claim_level' => self::CLAIM_LEVEL_CASE_RESULT_ONLY,
        ];
    }

    /** @return array<string,mixed> */
    private function caseBackendCacheInvalidation(): array
    {
        return [
            'case_id' => 'backend-cache-invalidation',
            'title' => 'SettingsRepository devolve cache stale após update — invalidação ausente',
            'category' => 'backend_logic',
            'secondary_categories' => ['integration_performance'],
            'difficulty' => self::DIFFICULTY_MEDIUM,
            'difficulty_level' => 'L3',
            'difficulty_score' => 3.0,
            'difficulty_reason' => 'Cache write-through vs invalidate explicit é decisão arquitetural pequena mas precisa ser determinística (sem depender de TTL).',
            'planning_weight' => 0.40,
            'execution_weight' => 0.60,
            'ambiguity_level' => 'low',
            'risk_level' => 'medium',
            'objective' => 'Garantir que SettingsRepository::update() invalida a entrada de cache correspondente antes (ou imediatamente após) gravar no DB, de modo que get() seguinte devolve o novo valor sem cache stale.',
            'business_rule' => 'Operadores trocavam tema do app e o painel continuava mostrando o tema anterior por minutos. Cache estava com TTL longo e nunca era invalidado no write — bug clássico de write-through ausente.',
            'acceptance_criteria' => [
                'SettingsRepositoryTest::test_update_invalidates_cache passa (depois do update, get devolve o novo valor).',
                'SettingsRepositoryTest::test_read_uses_cache_on_hit continua passando.',
                'Invalidação é determinística — não depende de TTL nem de cron.',
                'Patch toca apenas SettingsRepository.',
            ],
            'allowed_files_scope' => [
                'app/Repositories/SettingsRepository.php',
                'tests/Unit/Repositories/SettingsRepositoryTest.php',
            ],
            'forbidden_files_scope' => [
                'config/cache.php',
                'app/Services/Ai/**',
                'database/migrations/**',
                'atlas-desktop/src/voice/**',
                'atlas-cartografia/**',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/backend-cache-invalidation/seed',
            'quick_test_command' => "php artisan test --filter='SettingsRepositoryTest'",
            'full_test_command' => "php artisan test --filter='Repositories'",
            'expected_changed_files' => [
                'app/Repositories/SettingsRepository.php',
            ],
            'quality_weights' => [
                'dimensions' => ['api_correctness', 'performance', 'maintainability'],
                'weights' => [
                    'api_correctness' => 0.50,
                    'performance' => 0.30,
                    'maintainability' => 0.20,
                ],
            ],
            'invalid_if' => [
                'touched_forbidden_files',
                'cache_invalidation_relies_on_ttl_only',
                'read_path_disabled_to_pass_test',
                'synthetic_score_admitted',
                'external_rivals_unlock_attempted',
            ],
            'timeout_policy' => [
                'wall_clock_seconds_max' => 300,
                'per_stage_seconds_max' => 90,
                'hard_kill_after_seconds' => 420,
            ],
            'evidence_requirements' => [
                'patch_diff',
                'phpunit_output',
                'cache_hit_miss_log',
            ],
            'replay_requirements' => [
                'fixture_seed_hash',
                'final_changed_files_hash',
                'phpunit_exit_code',
            ],
            'fairness_notes' => 'Nenhum facade Laravel atlas-específico; usa Cache::store padrão (no fixture, é um array store). Mede consistência sem favorecer dialeto de provider.',
            'human_review_notes' => 'Operador audita o diff e confirma que invalidate roda antes do return — não fica órfão num try/catch silencioso.',
            'claim_level' => self::CLAIM_LEVEL_CASE_RESULT_ONLY,
        ];
    }

    /** @return array<string,mixed> */
    private function caseFrontendExecutionStatusPanel(): array
    {
        return [
            'case_id' => 'frontend-execution-status-panel',
            'title' => 'ExecutionStatusPanel só mostra "loading" — falta estados running/blocked/passed enterprise',
            'category' => 'frontend_ui',
            'secondary_categories' => [],
            'difficulty' => self::DIFFICULTY_EASY,
            'difficulty_level' => 'L2',
            'difficulty_score' => 2.0,
            'difficulty_reason' => 'State machine de 4 estados com label visível + aria-live: pequeno, objetivo e fechado por testes — ideal para L2.',
            'planning_weight' => 0.30,
            'execution_weight' => 0.70,
            'ambiguity_level' => 'low',
            'risk_level' => 'low',
            'objective' => 'Estender ExecutionStatusPanel para renderizar estados enterprise (running, blocked, passed, failed) com label visível e announcement aria-live, mantendo loading como estado inicial.',
            'business_rule' => 'Operador acompanha rodadas longas (até 20 min) pelo painel; hoje só sabe que "está carregando", sem distinguir blocker honesto de progresso real. Precisamos feedback de processo longo legítimo, não spinner cego.',
            'acceptance_criteria' => [
                'ExecutionStatusPanel.test.tsx exercita os 4 estados (running, blocked, passed, failed) e passa.',
                'Cada estado tem aria-live=polite anunciando a transição.',
                'Visual: status dot estático + texto curto (sem pulse halo cafona).',
                'Patch toca apenas ExecutionStatusPanel.tsx + __tests__/ExecutionStatusPanel.test.tsx.',
            ],
            'allowed_files_scope' => [
                'atlas-desktop/src/components/forge/ExecutionStatusPanel.tsx',
                'atlas-desktop/src/components/forge/__tests__/ExecutionStatusPanel.test.tsx',
            ],
            'forbidden_files_scope' => [
                'atlas-desktop/src/voice/**',
                'atlas-cartografia/**',
                'app/**',
                'database/**',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/frontend-execution-status-panel/seed',
            'quick_test_command' => 'vitest run components/forge/__tests__/ExecutionStatusPanel.test.tsx --reporter=basic',
            'full_test_command' => 'vitest run --reporter=basic',
            'expected_changed_files' => [
                'atlas-desktop/src/components/forge/ExecutionStatusPanel.tsx',
            ],
            'quality_weights' => [
                'dimensions' => ['ui_correctness', 'accessibility', 'visual_polish', 'maintainability'],
                'weights' => [
                    'ui_correctness' => 0.40,
                    'accessibility' => 0.30,
                    'visual_polish' => 0.15,
                    'maintainability' => 0.15,
                ],
            ],
            'invalid_if' => [
                'touched_forbidden_files',
                'aria_live_announcement_missing',
                'states_collapsed_into_loading',
                'pulse_halo_introduced',
                'synthetic_score_admitted',
                'external_rivals_unlock_attempted',
            ],
            'timeout_policy' => [
                'wall_clock_seconds_max' => 360,
                'per_stage_seconds_max' => 90,
                'hard_kill_after_seconds' => 480,
            ],
            'evidence_requirements' => [
                'patch_diff',
                'vitest_output',
                'a11y_announcement_listing',
            ],
            'replay_requirements' => [
                'fixture_seed_hash',
                'final_changed_files_hash',
                'vitest_exit_code',
            ],
            'fairness_notes' => 'Nenhum design token atlas-específico exposto; pulse halo é vetado para todos os arms.',
            'human_review_notes' => 'Operador valida visualmente: estados aparecem distinguíveis em dark + light; sem animação que distraia.',
            'claim_level' => self::CLAIM_LEVEL_CASE_RESULT_ONLY,
        ];
    }

    /** @return array<string,mixed> */
    private function caseFrontendFilterableTable(): array
    {
        return [
            'case_id' => 'frontend-filterable-table',
            'title' => 'FilterableTable não filtra, não ordena, não tem empty state — listing operável zero',
            'category' => 'frontend_ui',
            'secondary_categories' => [],
            'difficulty' => self::DIFFICULTY_HARD,
            'difficulty_level' => 'L4',
            'difficulty_score' => 4.0,
            'difficulty_reason' => 'Três features simultâneas (busca + sort estável + empty state acessível) sob contrato de a11y e estabilidade em empate; precisa fail-closed em sort instável.',
            'planning_weight' => 0.50,
            'execution_weight' => 0.50,
            'ambiguity_level' => 'medium',
            'risk_level' => 'medium',
            'objective' => 'Implementar busca por substring, sort por coluna clicável e empty state acessível na FilterableTable, mantendo render performante para 200 linhas.',
            'business_rule' => 'Operador tem listing de 80 capturas pendentes; hoje precisa fazer scroll cego. Filtro + sort + empty state são o mínimo enterprise.',
            'acceptance_criteria' => [
                'FilterableTable.test.tsx cobre: busca por substring, sort ascendente/descendente, empty state acessível.',
                'Empty state anuncia via role=status + aria-live=polite citando o filtro.',
                'Sort estável (preserva ordem secundária em empate).',
                'Patch toca apenas FilterableTable.tsx + __tests__/FilterableTable.test.tsx.',
            ],
            'allowed_files_scope' => [
                'atlas-desktop/src/components/forge/FilterableTable.tsx',
                'atlas-desktop/src/components/forge/__tests__/FilterableTable.test.tsx',
            ],
            'forbidden_files_scope' => [
                'atlas-desktop/src/voice/**',
                'atlas-cartografia/**',
                'app/**',
                'database/**',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/frontend-filterable-table/seed',
            'quick_test_command' => 'vitest run components/forge/__tests__/FilterableTable.test.tsx --reporter=basic',
            'full_test_command' => 'vitest run --reporter=basic',
            'expected_changed_files' => [
                'atlas-desktop/src/components/forge/FilterableTable.tsx',
            ],
            'quality_weights' => [
                'dimensions' => ['ui_correctness', 'accessibility', 'performance', 'maintainability'],
                'weights' => [
                    'ui_correctness' => 0.40,
                    'accessibility' => 0.25,
                    'performance' => 0.20,
                    'maintainability' => 0.15,
                ],
            ],
            'invalid_if' => [
                'touched_forbidden_files',
                'empty_state_silent_to_screen_reader',
                'sort_unstable_on_ties',
                'synthetic_score_admitted',
                'external_rivals_unlock_attempted',
            ],
            'timeout_policy' => [
                'wall_clock_seconds_max' => 360,
                'per_stage_seconds_max' => 90,
                'hard_kill_after_seconds' => 480,
            ],
            'evidence_requirements' => [
                'patch_diff',
                'vitest_output',
                'a11y_announcement_listing',
            ],
            'replay_requirements' => [
                'fixture_seed_hash',
                'final_changed_files_hash',
                'vitest_exit_code',
            ],
            'fairness_notes' => 'Sem mock data — fixture entrega 8 linhas reais. Filtro/sort medidos contra estas 8 linhas, sem favorecer arm.',
            'human_review_notes' => 'Operador valida: filtro respeita o texto digitado e nada além; sort preserva ordem original em empate.',
            'claim_level' => self::CLAIM_LEVEL_CASE_RESULT_ONLY,
        ];
    }

    /** @return array<string,mixed> */
    private function caseFrontendFormValidationAccessibility(): array
    {
        return [
            'case_id' => 'frontend-form-validation-accessibility',
            'title' => 'SignupForm sem inline errors, sem labels associadas, sem foco automático — a11y zero',
            'category' => 'frontend_ui',
            'secondary_categories' => ['test_design'],
            'difficulty' => self::DIFFICULTY_MEDIUM,
            'difficulty_level' => 'L3',
            'difficulty_score' => 3.0,
            'difficulty_reason' => 'A11y exige coordenação de aria-describedby/htmlFor/foco automático; nenhuma dessas peças sozinha é trivial.',
            'planning_weight' => 0.40,
            'execution_weight' => 0.60,
            'ambiguity_level' => 'low',
            'risk_level' => 'medium',
            'objective' => 'Adicionar validação inline acessível ao SignupForm: erro inline por campo com aria-describedby, label associada via htmlFor, foco automático no primeiro erro após submit inválido.',
            'business_rule' => 'Onboarding tem 14% drop após o submit. UX atribui ao feedback ausente em campos inválidos — usuário não sabe o que corrigir. Acessibilidade exige labels associadas e anúncio por leitor de tela.',
            'acceptance_criteria' => [
                'SignupForm.test.tsx cobre: submit inválido mostra erros inline, primeiro erro recebe foco, label htmlFor presente em cada input.',
                'Erro por campo tem id e o input aponta para ele via aria-describedby.',
                'Validação dispara somente após primeiro submit; navegação por teclado funciona.',
                'Patch toca apenas SignupForm.tsx + __tests__/SignupForm.test.tsx.',
            ],
            'allowed_files_scope' => [
                'atlas-desktop/src/components/forge/SignupForm.tsx',
                'atlas-desktop/src/components/forge/__tests__/SignupForm.test.tsx',
            ],
            'forbidden_files_scope' => [
                'atlas-desktop/src/voice/**',
                'atlas-cartografia/**',
                'app/**',
                'database/**',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/frontend-form-validation-accessibility/seed',
            'quick_test_command' => 'vitest run components/forge/__tests__/SignupForm.test.tsx --reporter=basic',
            'full_test_command' => 'vitest run --reporter=basic',
            'expected_changed_files' => [
                'atlas-desktop/src/components/forge/SignupForm.tsx',
            ],
            'quality_weights' => [
                'dimensions' => ['accessibility', 'ui_correctness', 'test_pass', 'maintainability'],
                'weights' => [
                    'accessibility' => 0.45,
                    'ui_correctness' => 0.25,
                    'test_pass' => 0.15,
                    'maintainability' => 0.15,
                ],
            ],
            'invalid_if' => [
                'touched_forbidden_files',
                'label_htmlfor_missing',
                'aria_describedby_missing',
                'auto_focus_on_first_error_missing',
                'synthetic_score_admitted',
                'external_rivals_unlock_attempted',
            ],
            'timeout_policy' => [
                'wall_clock_seconds_max' => 360,
                'per_stage_seconds_max' => 90,
                'hard_kill_after_seconds' => 480,
            ],
            'evidence_requirements' => [
                'patch_diff',
                'vitest_output',
                'a11y_announcement_listing',
            ],
            'replay_requirements' => [
                'fixture_seed_hash',
                'final_changed_files_hash',
                'vitest_exit_code',
            ],
            'fairness_notes' => 'Sem framework atlas-específico; mede a11y básico. Cada arm pode escolher seu próprio padrão de gerenciamento de estado.',
            'human_review_notes' => 'Operador valida com leitor de tela: ao submeter inválido, anúncio cita o campo e o erro; foco move corretamente.',
            'claim_level' => self::CLAIM_LEVEL_CASE_RESULT_ONLY,
        ];
    }

    /** @return array<string,mixed> */
    private function caseRefactorControllerToService(): array
    {
        return [
            'case_id' => 'refactor-controller-to-service',
            'title' => 'ReportController concentra lógica de negócio — extrair ReportService sem mudar comportamento',
            'category' => 'refactor',
            'secondary_categories' => ['architecture'],
            'difficulty' => self::DIFFICULTY_MEDIUM,
            'difficulty_level' => 'L3',
            'difficulty_score' => 3.0,
            'difficulty_reason' => 'Refator com preservação byte-a-byte de comportamento + cobertura unitária nova; planejamento é maior que execução.',
            'planning_weight' => 0.55,
            'execution_weight' => 0.45,
            'ambiguity_level' => 'medium',
            'risk_level' => 'medium',
            'objective' => 'Extrair toda a lógica de agregação de ReportController::index() para um ReportService injetado, mantendo comportamento idêntico e os testes de feature existentes verdes sem alteração.',
            'business_rule' => 'Controller hoje tem 180 linhas, mistura HTTP + agregação + persistência. Refator é pré-requisito para colocar testes unitários e plug futuro de cache. O contrato HTTP NÃO pode mudar.',
            'acceptance_criteria' => [
                'ReportControllerTest::test_index_returns_expected_payload continua passando byte a byte.',
                'ReportServiceTest cobre 3 cenários de agregação isolados sem HTTP.',
                'ReportController::index() reduzido a coordenação (≤ 25 linhas).',
                'Patch toca apenas ReportController.php + ReportService.php + ReportServiceTest.php.',
            ],
            'allowed_files_scope' => [
                'app/Http/Controllers/ReportController.php',
                'app/Services/Report/ReportService.php',
                'tests/Unit/Report/ReportServiceTest.php',
                'tests/Feature/Report/ReportControllerTest.php',
            ],
            'forbidden_files_scope' => [
                'app/Services/Ai/**',
                'database/migrations/**',
                'atlas-desktop/src/voice/**',
                'atlas-cartografia/**',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/refactor-controller-to-service/seed',
            'quick_test_command' => "php artisan test --filter='ReportServiceTest|ReportControllerTest'",
            'full_test_command' => "php artisan test --filter='Report'",
            'expected_changed_files' => [
                'app/Http/Controllers/ReportController.php',
                'app/Services/Report/ReportService.php',
                'tests/Unit/Report/ReportServiceTest.php',
            ],
            'quality_weights' => [
                'dimensions' => ['cohesion', 'behavior_preservation', 'maintainability'],
                'weights' => [
                    'cohesion' => 0.40,
                    'behavior_preservation' => 0.40,
                    'maintainability' => 0.20,
                ],
            ],
            'invalid_if' => [
                'touched_forbidden_files',
                'controller_behavior_changed',
                'feature_test_modified_to_pass',
                'public_route_contract_broken',
                'synthetic_score_admitted',
                'external_rivals_unlock_attempted',
            ],
            'timeout_policy' => [
                'wall_clock_seconds_max' => 480,
                'per_stage_seconds_max' => 120,
                'hard_kill_after_seconds' => 600,
            ],
            'evidence_requirements' => [
                'patch_diff',
                'phpunit_output',
                'before_after_complexity_report',
            ],
            'replay_requirements' => [
                'fixture_seed_hash',
                'final_changed_files_hash',
                'phpunit_exit_code',
            ],
            'fairness_notes' => 'Refator clássico Controller→Service. Não favorece DSL atlas. Cada arm escolhe estilo (DI manual ou container).',
            'human_review_notes' => 'Operador audita: (a) controller continua thin; (b) service não vaza Request/Response; (c) nenhum teste foi relaxado.',
            'claim_level' => self::CLAIM_LEVEL_CASE_RESULT_ONLY,
        ];
    }

    /** @return array<string,mixed> */
    private function caseTestRegressionBeforeFix(): array
    {
        return [
            'case_id' => 'test-regression-before-fix',
            'title' => 'DateRange::contains() retorna true para data fora do range — escrever teste que falha primeiro, depois corrigir',
            'category' => 'test_design',
            'secondary_categories' => ['realistic_bugfix'],
            'difficulty' => self::DIFFICULTY_EASY,
            'difficulty_level' => 'L2',
            'difficulty_score' => 2.0,
            'difficulty_reason' => 'Disciplina TDD pequena mas com sequência obrigatória (red → green) que precisa aparecer no histórico.',
            'planning_weight' => 0.35,
            'execution_weight' => 0.65,
            'ambiguity_level' => 'low',
            'risk_level' => 'low',
            'objective' => 'TDD honesto: adicionar teste que reproduz o bug em DateRange::contains() (falha vermelha primeiro), depois corrigir a implementação para passar — sem ajustar o teste para o bug.',
            'business_rule' => 'Política do projeto: bugfix sem teste de regressão antes do fix não merge. Esta é a vitrine da prática — fixture entrega DateRange com bug + teste pendente; arm precisa escrever teste vermelho, depois fix.',
            'acceptance_criteria' => [
                'DateRangeTest::test_contains_excludes_dates_after_end é adicionado, falha vermelha contra o código original.',
                'Depois do fix em DateRange::contains(), test fica verde sem mudança nas asserções.',
                'Nenhum outro teste é modificado.',
                'Patch toca apenas DateRange.php + DateRangeTest.php.',
            ],
            'allowed_files_scope' => [
                'app/Support/DateRange.php',
                'tests/Unit/Support/DateRangeTest.php',
            ],
            'forbidden_files_scope' => [
                'app/Http/**',
                'app/Services/Ai/**',
                'database/**',
                'atlas-desktop/src/voice/**',
                'atlas-cartografia/**',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/test-regression-before-fix/seed',
            'quick_test_command' => "php artisan test --filter='DateRangeTest'",
            'full_test_command' => "php artisan test --filter='Support'",
            'expected_changed_files' => [
                'app/Support/DateRange.php',
                'tests/Unit/Support/DateRangeTest.php',
            ],
            'quality_weights' => [
                'dimensions' => ['test_first_discipline', 'regression_prevention', 'minimal_diff'],
                'weights' => [
                    'test_first_discipline' => 0.45,
                    'regression_prevention' => 0.35,
                    'minimal_diff' => 0.20,
                ],
            ],
            'invalid_if' => [
                'touched_forbidden_files',
                'test_added_after_fix_in_same_commit_only',
                'fix_without_regression_test',
                'unrelated_tests_modified',
                'synthetic_score_admitted',
                'external_rivals_unlock_attempted',
            ],
            'timeout_policy' => [
                'wall_clock_seconds_max' => 240,
                'per_stage_seconds_max' => 60,
                'hard_kill_after_seconds' => 360,
            ],
            'evidence_requirements' => [
                'patch_diff',
                'phpunit_output',
                'red_then_green_proof',
            ],
            'replay_requirements' => [
                'fixture_seed_hash',
                'final_changed_files_hash',
                'phpunit_exit_code',
            ],
            'fairness_notes' => 'Disciplina TDD mensurada como sequência: red_then_green_proof exige evidência de teste falhando antes do fix. Nenhum arm tem vantagem de ferramenta interna.',
            'human_review_notes' => 'Operador audita o histórico do diff: o teste de regressão precisa estar presente e cobrir exatamente a borda que estava quebrada.',
            'claim_level' => self::CLAIM_LEVEL_CASE_RESULT_ONLY,
        ];
    }

    /** @return array<string,mixed> */
    private function caseIntegrationFakeProviderTimeoutRetry(): array
    {
        return [
            'case_id' => 'integration-fake-provider-timeout-retry',
            'title' => 'FakeProviderClient não tem timeout nem retry — chamadas penduram indefinidamente',
            'category' => 'integration_performance',
            'secondary_categories' => [],
            'difficulty' => self::DIFFICULTY_MEDIUM,
            'difficulty_level' => 'L4',
            'difficulty_score' => 4.0,
            'difficulty_reason' => 'Política de retry/timeout com clock injetável + blocker honesto + sem rede real exige raciocínio explícito sobre estados de falha.',
            'planning_weight' => 0.55,
            'execution_weight' => 0.45,
            'ambiguity_level' => 'medium',
            'risk_level' => 'high',
            'objective' => 'Adicionar timeout configurável e retry com backoff exponencial (limite de tentativas) ao FakeProviderClient, e blocker honesto após hard timeout em vez de loop infinito.',
            'business_rule' => 'Provider externo flaky penduraria batch de batteries; precisamos timeout duro + retry curto + blocker explícito sem mascarar como heartbeat. Caso usa FakeProviderClient (não chama provider real).',
            'acceptance_criteria' => [
                'FakeProviderClientTest::test_returns_on_first_success passa.',
                'FakeProviderClientTest::test_retries_up_to_limit_then_hard_blocker passa (último resultado é blocker, não exception silenciosa).',
                'FakeProviderClientTest::test_hard_timeout_returns_blocker passa (sem rede real — FakeProvider só simula latência via sleep curto / clock injetável).',
                'Patch toca apenas FakeProviderClient.php + FakeProviderClientTest.php.',
            ],
            'allowed_files_scope' => [
                'app/Services/Integration/FakeProviderClient.php',
                'tests/Unit/Integration/FakeProviderClientTest.php',
            ],
            'forbidden_files_scope' => [
                'app/Services/Ai/**',
                'config/**',
                'database/**',
                'atlas-desktop/src/voice/**',
                'atlas-cartografia/**',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/integration-fake-provider-timeout-retry/seed',
            'quick_test_command' => "php artisan test --filter='FakeProviderClientTest'",
            'full_test_command' => "php artisan test --filter='Integration'",
            'expected_changed_files' => [
                'app/Services/Integration/FakeProviderClient.php',
            ],
            'quality_weights' => [
                'dimensions' => ['integration_safety', 'reliability', 'observability'],
                'weights' => [
                    'integration_safety' => 0.45,
                    'reliability' => 0.35,
                    'observability' => 0.20,
                ],
            ],
            'invalid_if' => [
                'touched_forbidden_files',
                'real_network_call_admitted',
                'silent_swallow_after_timeout',
                'retry_without_upper_bound',
                'synthetic_score_admitted',
                'external_rivals_unlock_attempted',
            ],
            'timeout_policy' => [
                'wall_clock_seconds_max' => 420,
                'per_stage_seconds_max' => 120,
                'hard_kill_after_seconds' => 540,
            ],
            'evidence_requirements' => [
                'patch_diff',
                'phpunit_output',
                'retry_attempt_log',
            ],
            'replay_requirements' => [
                'fixture_seed_hash',
                'final_changed_files_hash',
                'phpunit_exit_code',
            ],
            'fairness_notes' => 'FakeProvider em-memória; nenhum arm chama provider real. Mede política de timeout/retry, não conectividade.',
            'human_review_notes' => 'Operador audita: timeout configurável é parâmetro do construtor (não env crua); blocker é retornado, nunca exception engolida.',
            'claim_level' => self::CLAIM_LEVEL_CASE_RESULT_ONLY,
        ];
    }

    /** @return array<string,mixed> */
    private function caseArchitectureSchemaVersionedReceipt(): array
    {
        return [
            'case_id' => 'architecture-schema-versioned-receipt',
            'title' => 'ReceiptWriter grava JSON sem schema_version e sem hash — replay não auditável',
            'category' => 'architecture',
            'secondary_categories' => [],
            'difficulty' => self::DIFFICULTY_HARD,
            'difficulty_level' => 'L5',
            'difficulty_score' => 5.0,
            'difficulty_reason' => 'Arquitetura canonical (schema version + sha256 determinístico + replay_manifest) afeta replayability inteira do Rivals; alto risco de drift.',
            'planning_weight' => 0.65,
            'execution_weight' => 0.35,
            'ambiguity_level' => 'high',
            'risk_level' => 'critical',
            'objective' => 'Tornar ReceiptWriter::write() canonical: incluir schema_version explícito, sha256 do payload e replay_manifest mínimo, garantindo replayability byte-a-byte.',
            'business_rule' => 'Replay é DNA inviolável do Rivals. Hoje o receipt grava só timestamp + status, sem versão de schema nem hash — impossível garantir que outro host reproduz a mesma evidence. Esta arquitetura precisa virar canon antes de qualquer claim ser emitido.',
            'acceptance_criteria' => [
                'ReceiptWriterTest::test_writes_schema_version_and_hash passa.',
                'ReceiptWriterTest::test_same_input_same_hash passa (mesmo payload em qualquer host ⇒ mesmo sha256).',
                'ReceiptWriterTest::test_includes_replay_manifest passa.',
                'Patch toca apenas ReceiptWriter.php + ReceiptWriterTest.php.',
            ],
            'allowed_files_scope' => [
                'app/Services/Receipt/ReceiptWriter.php',
                'tests/Unit/Receipt/ReceiptWriterTest.php',
            ],
            'forbidden_files_scope' => [
                'app/Services/Ai/**',
                'config/**',
                'database/migrations/**',
                'atlas-desktop/src/voice/**',
                'atlas-cartografia/**',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/architecture-schema-versioned-receipt/seed',
            'quick_test_command' => "php artisan test --filter='ReceiptWriterTest'",
            'full_test_command' => "php artisan test --filter='Receipt'",
            'expected_changed_files' => [
                'app/Services/Receipt/ReceiptWriter.php',
            ],
            'quality_weights' => [
                'dimensions' => ['boundary_integrity', 'reproducibility', 'maintainability'],
                'weights' => [
                    'boundary_integrity' => 0.40,
                    'reproducibility' => 0.40,
                    'maintainability' => 0.20,
                ],
            ],
            'invalid_if' => [
                'touched_forbidden_files',
                'schema_version_string_missing',
                'hash_not_deterministic_across_hosts',
                'replay_manifest_omitted',
                'synthetic_score_admitted',
                'external_rivals_unlock_attempted',
            ],
            'timeout_policy' => [
                'wall_clock_seconds_max' => 480,
                'per_stage_seconds_max' => 120,
                'hard_kill_after_seconds' => 600,
            ],
            'evidence_requirements' => [
                'patch_diff',
                'phpunit_output',
                'receipt_sample_pretty_printed',
                'replay_manifest_sample',
            ],
            'replay_requirements' => [
                'fixture_seed_hash',
                'final_changed_files_hash',
                'phpunit_exit_code',
                'receipt_payload_hash',
            ],
            'fairness_notes' => 'Especificação de schema canon (JSON estruturado, sha256). Nenhum framework atlas-específico exigido; arms podem usar json_encode padrão.',
            'human_review_notes' => 'Operador audita uma receipt real: schema_version explícito, sha256 reproduzível em dois hosts, replay_manifest cita o fixture_seed_hash.',
            'claim_level' => self::CLAIM_LEVEL_CASE_RESULT_ONLY,
        ];
    }

    /** @return array<string,mixed> */
    private function casePerformanceNPlusOneQuery(): array
    {
        return [
            'case_id' => 'performance-n-plus-one-query',
            'title' => 'PostListService faz N+1 query no loop — derrubar custo sem regressão funcional',
            'category' => 'integration_performance',
            'secondary_categories' => ['backend_logic'],
            'difficulty' => self::DIFFICULTY_EASY,
            'difficulty_level' => 'L2',
            'difficulty_score' => 2.0,
            'difficulty_reason' => 'Eager load + assertQueryCount: padrão pequeno e bem documentado, sem decisões arquiteturais — caso de demonstração de hábito em performance.',
            'planning_weight' => 0.30,
            'execution_weight' => 0.70,
            'ambiguity_level' => 'low',
            'risk_level' => 'low',
            'objective' => 'Eliminar o N+1 em PostListService::summarize() (que hoje carrega author dentro do loop) usando eager load, sem mudar o payload de saída nem o contrato público.',
            'business_rule' => 'Endpoint /posts/summary subiu para 1.4s em P95 com 80 posts. O log do query counter mostrou 81 queries (1 + N). Precisamos descer para ≤ 2 queries totais mantendo o JSON idêntico.',
            'acceptance_criteria' => [
                'PostListServiceTest::test_summary_returns_expected_payload continua passando byte a byte.',
                'PostListServiceTest::test_summary_runs_in_at_most_two_queries passa (assertQueryCount(<= 2)).',
                'Output JSON do summary não muda.',
                'Patch toca apenas PostListService.php + PostListServiceTest.php.',
            ],
            'allowed_files_scope' => [
                'app/Services/Posts/PostListService.php',
                'tests/Unit/Posts/PostListServiceTest.php',
            ],
            'forbidden_files_scope' => [
                'app/Http/**',
                'app/Services/Ai/**',
                'database/migrations/**',
                'atlas-desktop/src/voice/**',
                'atlas-cartografia/**',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/performance-n-plus-one-query/seed',
            'quick_test_command' => "php artisan test --filter='PostListServiceTest'",
            'full_test_command' => "php artisan test --filter='Posts'",
            'expected_changed_files' => [
                'app/Services/Posts/PostListService.php',
            ],
            'quality_weights' => [
                'dimensions' => ['performance', 'behavior_preservation', 'maintainability'],
                'weights' => [
                    'performance' => 0.50,
                    'behavior_preservation' => 0.35,
                    'maintainability' => 0.15,
                ],
            ],
            'invalid_if' => [
                'touched_forbidden_files',
                'output_payload_changed',
                'eager_load_replaced_by_in_memory_join_with_extra_queries',
                'test_query_count_assertion_relaxed',
                'synthetic_score_admitted',
                'external_rivals_unlock_attempted',
            ],
            'timeout_policy' => [
                'wall_clock_seconds_max' => 360,
                'per_stage_seconds_max' => 90,
                'hard_kill_after_seconds' => 480,
            ],
            'evidence_requirements' => [
                'patch_diff',
                'phpunit_output',
                'query_count_proof',
            ],
            'replay_requirements' => [
                'fixture_seed_hash',
                'final_changed_files_hash',
                'phpunit_exit_code',
            ],
            'fairness_notes' => 'Fixture usa abstração in-memory de DB com contador de queries. Nenhuma vantagem por dialeto de ORM atlas; mede política de eager load.',
            'human_review_notes' => 'Operador audita: query count desce para ≤ 2; payload pretty-printed continua idêntico ao baseline guardado.',
            'claim_level' => self::CLAIM_LEVEL_CASE_RESULT_ONLY,
        ];
    }

    /* ================================================================
     * Release Matrix v1 — 28 cases adicionais para completar 8x5 = 40.
     * Cada categoria preenche os 5 níveis L1..L5. As variações por caso
     * estão concentradas no que importa (allowed scope, comando, ambiguity,
     * risk, fairness, human review). Defaults estruturais (forbidden_files
     * legacy, hard gates canon, timeout_policy, evidence/replay padrão)
     * vêm de makeMatrixCase para evitar duplicação inerte.
     * ================================================================ */

    /**
     * Default builder shared by every new matrix case. Garante que os 29
     * campos canon estejam presentes mesmo quando o caso só sobrescreve as
     * dimensões variantes — sem permitir que o autor esqueça um hard gate
     * ou alias legado.
     *
     * @param  array<string,mixed>  $core
     * @return array<string,mixed>
     */
    private function makeMatrixCase(array $core): array
    {
        $hardGates = self::REQUIRED_INVALID_IF_HARD_GATES;
        $extraInvalid = is_array($core['invalid_if'] ?? null) ? $core['invalid_if'] : [];
        $allowed = (array) ($core['allowed_files_scope'] ?? []);

        $defaults = [
            'secondary_categories' => [],
            'forbidden_files_scope' => [
                'atlas-desktop/src/voice/**',
                'atlas-cartografia/**',
                'app/Services/Ai/Forge/**',
                'database/migrations/**',
                'config/auth.php',
            ],
            'expected_changed_files' => $allowed,
            'invalid_if' => array_values(array_unique(array_merge($extraInvalid, $hardGates))),
            'timeout_policy' => [
                'wall_clock_seconds_max' => 480,
                'per_stage_seconds_max' => 120,
                'hard_kill_after_seconds' => 600,
            ],
            'evidence_requirements' => ['patch_diff', 'test_output'],
            'replay_requirements' => ['fixture_seed_hash', 'final_changed_files_hash', 'test_exit_code'],
            'claim_level' => self::CLAIM_LEVEL_CASE_RESULT_ONLY,
        ];

        $merged = array_replace($defaults, $core);
        // Garantir que invalid_if final SEMPRE inclui os hard gates canon mesmo
        // que o core tenha sobrescrito a chave inteira.
        $merged['invalid_if'] = array_values(array_unique(array_merge(
            (array) ($merged['invalid_if'] ?? []),
            $hardGates,
        )));

        return $merged;
    }

    /* -------- planning (5) -------- */

    /** @return array<string,mixed> */
    private function casePlanningL1AcceptanceChecklist(): array
    {
        return $this->makeMatrixCase([
            'case_id' => 'planning-l1-acceptance-checklist',
            'title' => 'Transformar descrição livre de feature em checklist de aceitação numerado',
            'category' => 'planning',
            'difficulty' => self::DIFFICULTY_EASY,
            'difficulty_level' => 'L1',
            'difficulty_score' => 1.0,
            'difficulty_reason' => 'Transformação textual com golden file: produzir 4-6 bullets objetivos a partir de uma descrição de feature; sem decisões arquiteturais.',
            'planning_weight' => 0.85,
            'execution_weight' => 0.15,
            'ambiguity_level' => 'low',
            'risk_level' => 'low',
            'objective' => 'A partir de docs/planning/inbox/feature.md, produzir docs/planning/inbox/feature.acceptance.md com 4-6 critérios objetivos, cada um testável.',
            'business_rule' => 'Time descobriu que features sem checklist explícito viram backlog infinito; queremos um entregável padronizado antes de qualquer linha de código.',
            'acceptance_criteria' => [
                'docs/planning/inbox/feature.acceptance.md existe e bate byte-a-byte com golden em tests/Unit/Planning/AcceptanceChecklistTest.php.',
                'Cada bullet começa com verbo no infinitivo e cita um critério verificável.',
                'Nenhum bullet referencia implementação (sem nome de classe/arquivo).',
                'Nenhum byte tocado fora de docs/planning/inbox/.',
            ],
            'allowed_files_scope' => [
                'docs/planning/inbox/feature.acceptance.md',
                'tests/Unit/Planning/AcceptanceChecklistTest.php',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/planning-l1-acceptance-checklist/seed',
            'quick_test_command' => "php artisan test --filter='AcceptanceChecklistTest'",
            'full_test_command' => "php artisan test --filter='Planning'",
            'test_command' => "php artisan test --filter='AcceptanceChecklistTest'",
            'expected_changed_files' => ['docs/planning/inbox/feature.acceptance.md'],
            'quality_weights' => [
                'dimensions' => ['planning_clarity', 'completeness', 'consistency'],
                'weights' => ['planning_clarity' => 0.50, 'completeness' => 0.30, 'consistency' => 0.20],
            ],
            'invalid_if' => [
                'criteria_reference_implementation_details',
                'fewer_than_four_or_more_than_six_bullets',
            ],
            'evidence_requirements' => ['patch_diff', 'acceptance_md_render', 'golden_diff'],
            'fairness_notes' => 'Texto livre + golden file determinístico. Sem vantagem de DSL atlas.',
            'human_review_notes' => 'Operador audita se os bullets representam decisões testáveis e se a numeração se sustenta para refactors futuros.',
        ]);
    }

    /** @return array<string,mixed> */
    private function casePlanningL2IncrementalSlices(): array
    {
        return $this->makeMatrixCase([
            'case_id' => 'planning-l2-incremental-slices',
            'title' => 'Decompor feature em 3-5 incrementos com dependências explícitas',
            'category' => 'planning',
            'difficulty' => self::DIFFICULTY_EASY,
            'difficulty_level' => 'L2',
            'difficulty_score' => 2.0,
            'difficulty_reason' => 'Decomposição em slices de 1-2 dias com dependências e ordem; pequeno, mas exige raciocínio sobre escopo mínimo entregável.',
            'planning_weight' => 0.80,
            'execution_weight' => 0.20,
            'ambiguity_level' => 'low',
            'risk_level' => 'low',
            'objective' => 'Quebrar docs/planning/inbox/feature.md em 3-5 slices com depends_on explícito e definition_of_done por slice, sem buracos.',
            'business_rule' => 'Operador precisa fatiar features grandes em incrementos para evitar long-running branches; a régua é cobertura sem buracos.',
            'acceptance_criteria' => [
                'docs/planning/inbox/feature.slices.md existe com 3-5 slices.',
                'Cada slice tem id, title, depends_on (list), definition_of_done (list).',
                'Test golden valida que toda criteria do acceptance_checklist cai em pelo menos um slice (cobertura sem buraco).',
                'Nenhum slice depende de si mesmo nem cria ciclo.',
            ],
            'allowed_files_scope' => [
                'docs/planning/inbox/feature.slices.md',
                'tests/Unit/Planning/IncrementalSlicesTest.php',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/planning-l2-incremental-slices/seed',
            'quick_test_command' => "php artisan test --filter='IncrementalSlicesTest'",
            'full_test_command' => "php artisan test --filter='Planning'",
            'test_command' => "php artisan test --filter='IncrementalSlicesTest'",
            'expected_changed_files' => ['docs/planning/inbox/feature.slices.md'],
            'quality_weights' => [
                'dimensions' => ['decomposition_quality', 'dependency_correctness', 'coverage'],
                'weights' => ['decomposition_quality' => 0.45, 'dependency_correctness' => 0.30, 'coverage' => 0.25],
            ],
            'invalid_if' => [
                'slice_dependency_cycle_introduced',
                'acceptance_criterion_orphaned_no_slice',
            ],
            'evidence_requirements' => ['patch_diff', 'slices_md_render', 'dependency_graph_render'],
            'fairness_notes' => 'Critério de cobertura é uma asserção mecânica; nenhum framework atlas específico.',
            'human_review_notes' => 'Operador valida que cada slice gera valor independente e nenhum slice esconde decisão arquitetural maior.',
        ]);
    }

    /** @return array<string,mixed> */
    private function casePlanningL3RiskRegister(): array
    {
        return $this->makeMatrixCase([
            'case_id' => 'planning-l3-risk-register',
            'title' => 'Produzir risk register com likelihood × impact × mitigation por risco',
            'category' => 'planning',
            'difficulty' => self::DIFFICULTY_MEDIUM,
            'difficulty_level' => 'L3',
            'difficulty_score' => 3.0,
            'difficulty_reason' => 'Risk register exige identificar riscos reais (não genéricos) e estimar likelihood/impact com critérios calibrados — produto real, não checklist.',
            'planning_weight' => 0.75,
            'execution_weight' => 0.25,
            'ambiguity_level' => 'medium',
            'risk_level' => 'medium',
            'objective' => 'Produzir docs/planning/inbox/feature.risks.md com 4-8 riscos, cada um com likelihood (low|medium|high), impact (low|medium|high|critical), mitigation e owner.',
            'business_rule' => 'Pos-mortems mostram que 70% dos incidentes têm risco identificável antes da release; queremos forçar a discussão.',
            'acceptance_criteria' => [
                'docs/planning/inbox/feature.risks.md tem 4-8 riscos com os 4 campos obrigatórios.',
                'Pelo menos 1 risco com impact=critical OU likelihood=high.',
                'Cada mitigation tem ação concreta (não "monitorar").',
                'Golden test valida formato + ranges + ao menos uma mitigation por risco alto.',
            ],
            'allowed_files_scope' => [
                'docs/planning/inbox/feature.risks.md',
                'tests/Unit/Planning/RiskRegisterTest.php',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/planning-l3-risk-register/seed',
            'quick_test_command' => "php artisan test --filter='RiskRegisterTest'",
            'full_test_command' => "php artisan test --filter='Planning'",
            'test_command' => "php artisan test --filter='RiskRegisterTest'",
            'expected_changed_files' => ['docs/planning/inbox/feature.risks.md'],
            'quality_weights' => [
                'dimensions' => ['risk_realism', 'mitigation_quality', 'completeness'],
                'weights' => ['risk_realism' => 0.45, 'mitigation_quality' => 0.35, 'completeness' => 0.20],
            ],
            'invalid_if' => [
                'mitigation_is_just_monitor_or_check',
                'no_high_or_critical_risk_identified',
            ],
            'evidence_requirements' => ['patch_diff', 'risks_md_render', 'severity_matrix'],
            'fairness_notes' => 'Likelihood/impact são enums fechados, sem vantagem de framework.',
            'human_review_notes' => 'Operador valida que riscos não são genéricos ("código pode quebrar") e mitigações têm dono.',
        ]);
    }

    /** @return array<string,mixed> */
    private function casePlanningL4ContractFirstSpec(): array
    {
        return $this->makeMatrixCase([
            'case_id' => 'planning-l4-contract-first-spec',
            'title' => 'Escrever spec de contrato (DTO + invariants) antes da implementação',
            'category' => 'planning',
            'secondary_categories' => ['architecture'],
            'difficulty' => self::DIFFICULTY_HARD,
            'difficulty_level' => 'L4',
            'difficulty_score' => 4.0,
            'difficulty_reason' => 'Contract-first: declarar DTO + invariants + golden examples + erro-modes ANTES de qualquer implementação; exige fail-closed e compatibility planning.',
            'planning_weight' => 0.70,
            'execution_weight' => 0.30,
            'ambiguity_level' => 'medium',
            'risk_level' => 'high',
            'objective' => 'Produzir docs/planning/inbox/feature.contract.md + docs/planning/inbox/feature.contract.examples.json definindo DTO, invariants, error modes; ContractSpecTest valida shape + examples.',
            'business_rule' => 'Sem contrato escrito, time descobre divergência só na integração; queremos forçar contract-first em features que cruzam módulos.',
            'acceptance_criteria' => [
                'docs/planning/inbox/feature.contract.md declara DTO com tipos PHP/TypeScript-equivalentes.',
                'feature.contract.examples.json tem ao menos 3 exemplos válidos + 2 inválidos com error_code.',
                'ContractSpecTest valida que cada example válido passa e cada inválido falha exatamente com error_code declarado.',
                'Backward-compatibility note explicitamente declara o que pode/não pode mudar em release futura.',
            ],
            'allowed_files_scope' => [
                'docs/planning/inbox/feature.contract.md',
                'docs/planning/inbox/feature.contract.examples.json',
                'tests/Unit/Planning/ContractSpecTest.php',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/planning-l4-contract-first-spec/seed',
            'quick_test_command' => "php artisan test --filter='ContractSpecTest'",
            'full_test_command' => "php artisan test --filter='Planning'",
            'test_command' => "php artisan test --filter='ContractSpecTest'",
            'expected_changed_files' => ['docs/planning/inbox/feature.contract.md', 'docs/planning/inbox/feature.contract.examples.json'],
            'quality_weights' => [
                'dimensions' => ['contract_completeness', 'fail_closed_discipline', 'compatibility_explicit'],
                'weights' => ['contract_completeness' => 0.40, 'fail_closed_discipline' => 0.35, 'compatibility_explicit' => 0.25],
            ],
            'invalid_if' => [
                'contract_silent_on_error_modes',
                'examples_dont_exercise_failure_branches',
                'backward_compat_unspecified',
            ],
            'evidence_requirements' => ['patch_diff', 'contract_md_render', 'examples_json', 'phpunit_output'],
            'fairness_notes' => 'Contract document é JSON+MD canônico; nenhum framework atlas exclusivo.',
            'human_review_notes' => 'Operador audita: contract é honesto sobre o que vai falhar e quem é dono da estabilidade.',
        ]);
    }

    /** @return array<string,mixed> */
    private function casePlanningL5PhasedMigrationPlan(): array
    {
        return $this->makeMatrixCase([
            'case_id' => 'planning-l5-phased-migration-plan',
            'title' => 'Plano de migração multi-fase com rollback explícito e decision gates',
            'category' => 'planning',
            'secondary_categories' => ['architecture'],
            'difficulty' => self::DIFFICULTY_HARD,
            'difficulty_level' => 'L5',
            'difficulty_score' => 5.0,
            'difficulty_reason' => 'Migração multi-fase com rollback por fase, decision gates, mitigação para falha parcial e métricas de progresso — exige decomposição + risco + tradeoffs.',
            'planning_weight' => 0.85,
            'execution_weight' => 0.15,
            'ambiguity_level' => 'high',
            'risk_level' => 'critical',
            'objective' => 'Produzir docs/planning/migration/feature.migration.md com 3-5 fases, cada uma com pré-condições, rollback, decision gate e métrica de sucesso.',
            'business_rule' => 'Migração canônica passada falhou em produção porque rollback não estava planejado; precisamos régua para evitar repetição.',
            'acceptance_criteria' => [
                'docs/planning/migration/feature.migration.md tem 3-5 fases sequenciais com depends_on.',
                'Cada fase tem rollback procedure explícito (não "voltar tudo").',
                'Cada fase tem decision_gate (métrica + threshold + responsável).',
                'MigrationPlanTest valida formato, sequência sem ciclo, rollback presente em cada fase e cobertura de risk_register.',
            ],
            'allowed_files_scope' => [
                'docs/planning/migration/feature.migration.md',
                'docs/planning/migration/risks.md',
                'tests/Unit/Planning/MigrationPlanTest.php',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/planning-l5-phased-migration-plan/seed',
            'quick_test_command' => "php artisan test --filter='MigrationPlanTest'",
            'full_test_command' => "php artisan test --filter='Migration|Planning'",
            'test_command' => "php artisan test --filter='MigrationPlanTest'",
            'expected_changed_files' => ['docs/planning/migration/feature.migration.md'],
            'quality_weights' => [
                'dimensions' => ['phasing_discipline', 'rollback_quality', 'decision_gate_quality', 'risk_coverage'],
                'weights' => ['phasing_discipline' => 0.30, 'rollback_quality' => 0.30, 'decision_gate_quality' => 0.25, 'risk_coverage' => 0.15],
            ],
            'invalid_if' => [
                'rollback_procedure_generic_or_missing',
                'decision_gate_lacks_threshold_or_owner',
                'phase_dependency_cycle_introduced',
            ],
            'evidence_requirements' => ['patch_diff', 'migration_md_render', 'phase_graph', 'decision_gate_summary'],
            'fairness_notes' => 'Critérios mecânicos (rollback present? threshold present? cycle?) garantem comparação justa.',
            'human_review_notes' => 'Operador valida: rollback é executável e decision_gate aciona pessoa real, não só dashboard.',
        ]);
    }

    /* -------- frontend_ui (2 novos) -------- */

    /** @return array<string,mixed> */
    private function caseFrontendL1ButtonLoadingState(): array
    {
        return $this->makeMatrixCase([
            'case_id' => 'frontend-l1-button-loading-state',
            'title' => 'Botão Submit sem spinner durante submit — adicionar loading + disabled',
            'category' => 'frontend_ui',
            'difficulty' => self::DIFFICULTY_EASY,
            'difficulty_level' => 'L1',
            'difficulty_score' => 1.0,
            'difficulty_reason' => 'Estado loading + disabled em botão único: 1 prop, 1 ramo, 1 teste; clássico L1.',
            'planning_weight' => 0.15,
            'execution_weight' => 0.85,
            'ambiguity_level' => 'low',
            'risk_level' => 'low',
            'objective' => 'Adicionar prop isSubmitting que exibe spinner e desabilita Submit no SignupSubmitButton; restaurar estado em sucesso/erro.',
            'business_rule' => 'Operador clicou 4x em latência alta e gerou 4 capturas duplicadas; loading state é o fix mínimo.',
            'acceptance_criteria' => [
                'SignupSubmitButton.test.tsx cobre os 3 estados (idle, submitting, restaurado).',
                'aria-busy=true presente quando submitting.',
                'click handler não dispara enquanto submitting.',
                'Patch toca apenas SignupSubmitButton.tsx + test.',
            ],
            'allowed_files_scope' => [
                'atlas-desktop/src/components/forge/SignupSubmitButton.tsx',
                'atlas-desktop/src/components/forge/__tests__/SignupSubmitButton.test.tsx',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/frontend-l1-button-loading-state/seed',
            'quick_test_command' => 'vitest run components/forge/__tests__/SignupSubmitButton.test.tsx --reporter=basic',
            'full_test_command' => 'vitest run --reporter=basic',
            'test_command' => 'vitest run components/forge/__tests__/SignupSubmitButton.test.tsx --reporter=basic',
            'expected_changed_files' => ['atlas-desktop/src/components/forge/SignupSubmitButton.tsx'],
            'quality_weights' => [
                'dimensions' => ['ui_correctness', 'accessibility', 'maintainability'],
                'weights' => ['ui_correctness' => 0.50, 'accessibility' => 0.30, 'maintainability' => 0.20],
            ],
            'invalid_if' => ['aria_busy_missing', 'double_click_still_fires_handler'],
            'evidence_requirements' => ['patch_diff', 'vitest_output'],
            'fairness_notes' => 'Patch trivial, sem framework atlas exclusivo.',
            'human_review_notes' => 'Operador clica e confirma visual + leitor de tela anunciando "carregando".',
        ]);
    }

    /** @return array<string,mixed> */
    private function caseFrontendL5VirtualizedKeyboardGrid(): array
    {
        return $this->makeMatrixCase([
            'case_id' => 'frontend-l5-virtualized-keyboard-grid',
            'title' => 'Grid virtualizado de 10k linhas com navegação por teclado, aria-grid, budget de performance',
            'category' => 'frontend_ui',
            'secondary_categories' => ['integration_performance'],
            'difficulty' => self::DIFFICULTY_HARD,
            'difficulty_level' => 'L5',
            'difficulty_score' => 5.0,
            'difficulty_reason' => 'Virtualização + roving tabindex + aria-grid + budget de FPS sob 10k rows: três sub-problemas técnicos com riscos cruzados.',
            'planning_weight' => 0.60,
            'execution_weight' => 0.40,
            'ambiguity_level' => 'high',
            'risk_level' => 'high',
            'objective' => 'Implementar VirtualizedGrid com 10k linhas, navegação completa por teclado (setas, home/end, page up/down), aria-grid e budget de 16ms por frame medido.',
            'business_rule' => 'Telas operacionais precisam exibir 10k entries sem travar; teclado é DNA de operador profissional, não opção.',
            'acceptance_criteria' => [
                'VirtualizedGrid.test.tsx cobre: render inicial < 200ms, navegação por teclado em 6 direções, aria-grid + role=row/cell, screenreader announces row/col.',
                'PerformanceProbe demonstra ≤ 16ms por frame em scroll com 10k linhas (snapshot in repository).',
                'roving tabindex preservado em todas as transições.',
                'Patch toca apenas VirtualizedGrid + hooks + test.',
            ],
            'allowed_files_scope' => [
                'atlas-desktop/src/components/forge/VirtualizedGrid.tsx',
                'atlas-desktop/src/components/forge/useGridKeyboard.ts',
                'atlas-desktop/src/components/forge/__tests__/VirtualizedGrid.test.tsx',
                'atlas-desktop/src/components/forge/__tests__/VirtualizedGrid.perf.test.tsx',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/frontend-l5-virtualized-keyboard-grid/seed',
            'quick_test_command' => 'vitest run components/forge/__tests__/VirtualizedGrid.test.tsx --reporter=basic',
            'full_test_command' => 'vitest run components/forge/__tests__ --reporter=basic',
            'test_command' => 'vitest run components/forge/__tests__/VirtualizedGrid.test.tsx --reporter=basic',
            'expected_changed_files' => [
                'atlas-desktop/src/components/forge/VirtualizedGrid.tsx',
                'atlas-desktop/src/components/forge/useGridKeyboard.ts',
            ],
            'quality_weights' => [
                'dimensions' => ['performance', 'accessibility', 'ui_correctness', 'maintainability'],
                'weights' => ['performance' => 0.35, 'accessibility' => 0.30, 'ui_correctness' => 0.20, 'maintainability' => 0.15],
            ],
            'invalid_if' => [
                'frame_budget_exceeds_sixteen_ms',
                'aria_grid_role_missing',
                'keyboard_navigation_skips_rows',
            ],
            'evidence_requirements' => ['patch_diff', 'vitest_output', 'frame_budget_log', 'a11y_announcement_listing'],
            'fairness_notes' => 'Performance probe usa requestAnimationFrame medido; nenhum vendor lib trava o caso.',
            'human_review_notes' => 'Operador roda 5 minutos com keyboard e confirma sem flicker nem perda de foco.',
        ]);
    }

    /* -------- backend_logic (3 novos) -------- */

    /** @return array<string,mixed> */
    private function caseBackendL1StringNormalizer(): array
    {
        return $this->makeMatrixCase([
            'case_id' => 'backend-l1-string-normalizer',
            'title' => 'Normalizador de string para comparação canônica (trim + collapse + casefold)',
            'category' => 'backend_logic',
            'difficulty' => self::DIFFICULTY_EASY,
            'difficulty_level' => 'L1',
            'difficulty_score' => 1.0,
            'difficulty_reason' => 'Função pura com 3 regras (trim, collapse whitespace, lowercase) e tabela de teste; mínimo de ambiguidade.',
            'planning_weight' => 0.15,
            'execution_weight' => 0.85,
            'ambiguity_level' => 'low',
            'risk_level' => 'low',
            'objective' => 'Implementar StringNormalizer::canonical(string) que aplica trim + collapse interno + casefold sem perder caracteres não-ASCII relevantes.',
            'business_rule' => 'Busca de operador falha por espaços extras e diferenças de capitalização; normalização é o passo zero antes do equals.',
            'acceptance_criteria' => [
                'StringNormalizerTest::test_canonical cobre 8 entradas tabuladas (incluindo unicode).',
                'canonical(canonical($x)) === canonical($x) (idempotência).',
                'Função pura: não muta global, sem side effect.',
                'Patch toca apenas StringNormalizer.php + test.',
            ],
            'allowed_files_scope' => [
                'app/Support/StringNormalizer.php',
                'tests/Unit/Support/StringNormalizerTest.php',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/backend-l1-string-normalizer/seed',
            'quick_test_command' => "php artisan test --filter='StringNormalizerTest'",
            'full_test_command' => "php artisan test --filter='Support'",
            'test_command' => "php artisan test --filter='StringNormalizerTest'",
            'expected_changed_files' => [
                'app/Support/StringNormalizer.php',
                'tests/Unit/Support/StringNormalizerTest.php',
            ],
            'quality_weights' => [
                'dimensions' => ['correctness', 'idempotence', 'maintainability'],
                'weights' => ['correctness' => 0.55, 'idempotence' => 0.25, 'maintainability' => 0.20],
            ],
            'invalid_if' => ['breaks_idempotence', 'side_effect_introduced'],
            'evidence_requirements' => ['patch_diff', 'phpunit_output'],
            'fairness_notes' => 'Função pura sem dependência de framework.',
            'human_review_notes' => 'Operador audita: nenhuma localidade força chunkers exóticos.',
        ]);
    }

    /** @return array<string,mixed> */
    private function caseBackendL2CurrencyFormatter(): array
    {
        return $this->makeMatrixCase([
            'case_id' => 'backend-l2-currency-formatter',
            'title' => 'Formatter de moeda locale-aware com fallback explícito',
            'category' => 'backend_logic',
            'difficulty' => self::DIFFICULTY_EASY,
            'difficulty_level' => 'L2',
            'difficulty_score' => 2.0,
            'difficulty_reason' => 'Formatação por locale + currency + fallback: ~50 linhas com tabela de teste; segue padrão claro mas exige mais que pura função.',
            'planning_weight' => 0.30,
            'execution_weight' => 0.70,
            'ambiguity_level' => 'low',
            'risk_level' => 'low',
            'objective' => 'Implementar CurrencyFormatter::format(int $cents, string $currency, string $locale) com fallback en_US se locale desconhecido; sem lib externa.',
            'business_rule' => 'Painel exibe valores quebrados em PT/EN; precisamos formatação determinística sem depender de ICU instalado em todo host.',
            'acceptance_criteria' => [
                'CurrencyFormatterTest cobre BRL+pt_BR, USD+en_US, EUR+de_DE e locale=zz_ZZ (fallback).',
                'Saída tem separador correto (BR=., decimal=,; US=, decimal=.).',
                'Locale desconhecido NÃO lança — devolve formato en_US documentado.',
                'Patch toca apenas CurrencyFormatter.php + test.',
            ],
            'allowed_files_scope' => [
                'app/Support/CurrencyFormatter.php',
                'tests/Unit/Support/CurrencyFormatterTest.php',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/backend-l2-currency-formatter/seed',
            'quick_test_command' => "php artisan test --filter='CurrencyFormatterTest'",
            'full_test_command' => "php artisan test --filter='Support'",
            'test_command' => "php artisan test --filter='CurrencyFormatterTest'",
            'expected_changed_files' => ['app/Support/CurrencyFormatter.php'],
            'quality_weights' => [
                'dimensions' => ['correctness', 'fallback_discipline', 'maintainability'],
                'weights' => ['correctness' => 0.55, 'fallback_discipline' => 0.25, 'maintainability' => 0.20],
            ],
            'invalid_if' => ['unknown_locale_throws', 'requires_icu_extension'],
            'evidence_requirements' => ['patch_diff', 'phpunit_output', 'locale_matrix_render'],
            'fairness_notes' => 'Sem dependência de extensão PHP opcional; sem framework atlas exclusivo.',
            'human_review_notes' => 'Operador valida: fallback documentado, sem surpresa em locale exótico.',
        ]);
    }

    /** @return array<string,mixed> */
    private function caseBackendL5StateMachineTransitions(): array
    {
        return $this->makeMatrixCase([
            'case_id' => 'backend-l5-state-machine-transitions',
            'title' => 'State machine de Captura com transitions allowlist + audit log + deny-by-default',
            'category' => 'backend_logic',
            'secondary_categories' => ['architecture'],
            'difficulty' => self::DIFFICULTY_HARD,
            'difficulty_level' => 'L5',
            'difficulty_score' => 5.0,
            'difficulty_reason' => 'State machine canônica com transitions allowlist, deny-by-default, audit por transição e snapshot reproduzível — exige decomposição arquitetural.',
            'planning_weight' => 0.55,
            'execution_weight' => 0.45,
            'ambiguity_level' => 'medium',
            'risk_level' => 'critical',
            'objective' => 'Implementar CaptureStateMachine com 5 estados, transitions allowlist explícita, deny-by-default, audit log append-only e replay byte-a-byte.',
            'business_rule' => 'Captura tem ciclo de vida complexo (drafted → in_review → approved → archived ou rejected); estado inconsistente já causou 3 incidentes.',
            'acceptance_criteria' => [
                'CaptureStateMachineTest cobre 5 estados x 5 estados = 25 cells; só transitions allowlisted retornam ok, resto retorna blocker explícito.',
                'Audit log registra (from, to, by, at, reason) para cada transição (sucesso ou bloqueio).',
                'Replay test: replicar audit log produz mesmo estado final + mesmo hash.',
                'Patch toca apenas CaptureStateMachine + AuditLog + tests.',
            ],
            'allowed_files_scope' => [
                'app/Domain/Captures/CaptureStateMachine.php',
                'app/Domain/Captures/CaptureAuditLog.php',
                'tests/Unit/Captures/CaptureStateMachineTest.php',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/backend-l5-state-machine-transitions/seed',
            'quick_test_command' => "php artisan test --filter='CaptureStateMachineTest'",
            'full_test_command' => "php artisan test --filter='Captures'",
            'test_command' => "php artisan test --filter='CaptureStateMachineTest'",
            'expected_changed_files' => ['app/Domain/Captures/CaptureStateMachine.php'],
            'quality_weights' => [
                'dimensions' => ['correctness', 'fail_closed_discipline', 'auditability', 'replayability'],
                'weights' => ['correctness' => 0.30, 'fail_closed_discipline' => 0.30, 'auditability' => 0.25, 'replayability' => 0.15],
            ],
            'invalid_if' => [
                'transition_table_missing_deny_by_default',
                'audit_log_omits_blocked_transitions',
                'replay_not_deterministic',
            ],
            'evidence_requirements' => ['patch_diff', 'phpunit_output', 'transition_matrix', 'audit_log_excerpt'],
            'fairness_notes' => 'State machine in-memory + audit log determinístico; sem dependência atlas-específica.',
            'human_review_notes' => 'Operador audita: cada bloqueio aparece no log com motivo; replay reproduz estado e log.',
        ]);
    }

    /* -------- realistic_bugfix (4 novos) -------- */

    /** @return array<string,mixed> */
    private function caseBugfixL2TimezoneDoubleUtc(): array
    {
        return $this->makeMatrixCase([
            'case_id' => 'bugfix-l2-timezone-double-utc',
            'title' => 'Timestamp formatter aplica UTC offset duas vezes — datas viram 6h atrás',
            'category' => 'realistic_bugfix',
            'difficulty' => self::DIFFICULTY_EASY,
            'difficulty_level' => 'L2',
            'difficulty_score' => 2.0,
            'difficulty_reason' => 'Bug com root cause clara (DateTime parse já em UTC sendo convertido de novo) e fix pequeno; teste de regressão é o ponto chave.',
            'planning_weight' => 0.30,
            'execution_weight' => 0.70,
            'ambiguity_level' => 'low',
            'risk_level' => 'medium',
            'objective' => 'Corrigir TimestampFormatter::format() que aplica DateTimeZone(UTC) sobre timestamp já em UTC; teste regressão cobre 3 timezones de origem.',
            'business_rule' => 'Operadores reportaram "captura de 3h da tarde aparece como 9h da manhã"; suporte cancelou 4 demos por causa do bug.',
            'acceptance_criteria' => [
                'TimestampFormatterTest::test_format_does_not_apply_offset_twice passa (timestamp 12:00:00 UTC permanece 12:00:00 UTC).',
                'Cenários cobrem origens em America/Sao_Paulo, Europe/Berlin e UTC.',
                'format() permanece pura — sem side effect.',
                'Patch toca apenas TimestampFormatter.php + test.',
            ],
            'allowed_files_scope' => [
                'app/Support/Formatter/TimestampFormatter.php',
                'tests/Unit/Support/TimestampFormatterTest.php',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/bugfix-l2-timezone-double-utc/seed',
            'quick_test_command' => "php artisan test --filter='TimestampFormatterTest'",
            'full_test_command' => "php artisan test --filter='Formatter|Support'",
            'test_command' => "php artisan test --filter='TimestampFormatterTest'",
            'expected_changed_files' => ['app/Support/Formatter/TimestampFormatter.php'],
            'quality_weights' => [
                'dimensions' => ['regression_prevention', 'minimal_diff', 'test_pass'],
                'weights' => ['regression_prevention' => 0.50, 'minimal_diff' => 0.30, 'test_pass' => 0.20],
            ],
            'invalid_if' => ['offset_still_double_applied', 'unrelated_method_modified'],
            'evidence_requirements' => ['patch_diff', 'phpunit_output', 'before_after_timestamp_table'],
            'fairness_notes' => 'DateTimeImmutable + DateTimeZone padrão PHP; nenhum framework atlas exclusivo.',
            'human_review_notes' => 'Operador roda format() com 3 origens e confere com UTC reference.',
        ]);
    }

    /** @return array<string,mixed> */
    private function caseBugfixL3CounterRaceCondition(): array
    {
        return $this->makeMatrixCase([
            'case_id' => 'bugfix-l3-counter-race-condition',
            'title' => 'Counter decrementado em concorrência perde decrementos — adicionar lock seguro',
            'category' => 'realistic_bugfix',
            'secondary_categories' => ['backend_logic'],
            'difficulty' => self::DIFFICULTY_MEDIUM,
            'difficulty_level' => 'L3',
            'difficulty_score' => 3.0,
            'difficulty_reason' => 'Race condition reproduzida em-memória + lock pequeno + teste com simulação de concorrência via clock injetado — integração real entre Counter e Lock.',
            'planning_weight' => 0.40,
            'execution_weight' => 0.60,
            'ambiguity_level' => 'medium',
            'risk_level' => 'high',
            'objective' => 'Tornar Counter::decrement() thread-safe via lock canônico injetado; teste simula 10 decrementos paralelos e asserta valor final correto.',
            'business_rule' => 'Quotas de uso ficavam 8% acima do correto sob carga; race no decrement causava perda de decremento.',
            'acceptance_criteria' => [
                'CounterTest::test_concurrent_decrements_do_not_lose_updates passa (10 decrementos = 10 reduzido).',
                'decrement() usa LockInterface injetado; production usa FileLock real, test usa FakeLock determinístico.',
                'Patch toca apenas Counter + LockInterface + test.',
                'Sem sleep/microtime real no teste.',
            ],
            'allowed_files_scope' => [
                'app/Domain/Quota/Counter.php',
                'app/Domain/Quota/LockInterface.php',
                'app/Domain/Quota/FakeLock.php',
                'tests/Unit/Quota/CounterTest.php',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/bugfix-l3-counter-race-condition/seed',
            'quick_test_command' => "php artisan test --filter='CounterTest'",
            'full_test_command' => "php artisan test --filter='Quota'",
            'test_command' => "php artisan test --filter='CounterTest'",
            'expected_changed_files' => [
                'app/Domain/Quota/Counter.php',
                'app/Domain/Quota/LockInterface.php',
                'tests/Unit/Quota/CounterTest.php',
            ],
            'quality_weights' => [
                'dimensions' => ['regression_prevention', 'integration_safety', 'maintainability'],
                'weights' => ['regression_prevention' => 0.45, 'integration_safety' => 0.35, 'maintainability' => 0.20],
            ],
            'invalid_if' => ['lock_acquired_after_read', 'sleep_or_microtime_in_test', 'unrelated_quota_changed'],
            'evidence_requirements' => ['patch_diff', 'phpunit_output', 'concurrent_simulation_log'],
            'fairness_notes' => 'LockInterface é abstração mínima; sem dependência atlas exclusiva.',
            'human_review_notes' => 'Operador audita: lock acquired ANTES do read+write; release garantido via finally.',
        ]);
    }

    /** @return array<string,mixed> */
    private function caseBugfixL4FlakyTimeDependentTest(): array
    {
        return $this->makeMatrixCase([
            'case_id' => 'bugfix-l4-flaky-time-dependent-test',
            'title' => 'Test flaky por dependência de microtime — injetar clock + provar determinismo em 50 reruns',
            'category' => 'realistic_bugfix',
            'secondary_categories' => ['test_design'],
            'difficulty' => self::DIFFICULTY_HARD,
            'difficulty_level' => 'L4',
            'difficulty_score' => 4.0,
            'difficulty_reason' => 'Diagnóstico + clock injection + prova de determinismo em CI: exige contrato (ClockInterface) + fail-closed + 50-rerun assertion.',
            'planning_weight' => 0.50,
            'execution_weight' => 0.50,
            'ambiguity_level' => 'medium',
            'risk_level' => 'high',
            'objective' => 'Diagnosticar flakiness em ExpirationServiceTest, introduzir ClockInterface canônico, e provar determinismo com 50 iterações internas sem depender de sleep ou repeat do runner.',
            'business_rule' => 'CI vermelho 5% das vezes por flaky test; PRs ficam bloqueados aguardando re-run manual.',
            'acceptance_criteria' => [
                'ClockInterface canônico em app/Support/Clock/ com SystemClock (produção) + FrozenClock (teste).',
                'ExpirationService recebe ClockInterface; production code não usa microtime() direto.',
                'ExpirationServiceTest passa com 50 iterações internas consecutivas sem nenhum flake.',
                'Nenhum sleep em test.',
            ],
            'allowed_files_scope' => [
                'app/Support/Clock/ClockInterface.php',
                'app/Support/Clock/SystemClock.php',
                'app/Support/Clock/FrozenClock.php',
                'app/Domain/Expiration/ExpirationService.php',
                'tests/Unit/Expiration/ExpirationServiceTest.php',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/bugfix-l4-flaky-time-dependent-test/seed',
            'quick_test_command' => "php artisan test --filter='ExpirationServiceTest'",
            'full_test_command' => "php artisan test --filter='Expiration|Clock'",
            'test_command' => "php artisan test --filter='ExpirationServiceTest'",
            'expected_changed_files' => [
                'app/Domain/Expiration/ExpirationService.php',
                'app/Support/Clock/ClockInterface.php',
                'app/Support/Clock/SystemClock.php',
                'app/Support/Clock/FrozenClock.php',
                'tests/Unit/Expiration/ExpirationServiceTest.php',
            ],
            'quality_weights' => [
                'dimensions' => ['determinism', 'contract_design', 'maintainability'],
                'weights' => ['determinism' => 0.50, 'contract_design' => 0.30, 'maintainability' => 0.20],
            ],
            'invalid_if' => ['microtime_remaining_in_production_path', 'sleep_or_microtime_in_test', 'fewer_than_50_reruns'],
            'evidence_requirements' => ['patch_diff', 'phpunit_output', 'repeat_run_log_50_clean'],
            'fairness_notes' => 'ClockInterface é padrão de mercado; sem dependência atlas-específica.',
            'human_review_notes' => 'Operador roda --repeat=50 em outro host e confirma zero flakes.',
        ]);
    }

    /** @return array<string,mixed> */
    private function caseBugfixL5CascadeFailureFanout(): array
    {
        return $this->makeMatrixCase([
            'case_id' => 'bugfix-l5-cascade-failure-fanout',
            'title' => 'Falha em cascata por timeout em fanout — corrigir no nó raiz, não nos consumidores',
            'category' => 'realistic_bugfix',
            'secondary_categories' => ['integration_performance'],
            'difficulty' => self::DIFFICULTY_HARD,
            'difficulty_level' => 'L5',
            'difficulty_score' => 5.0,
            'difficulty_reason' => 'Diagnóstico em chain de 4 serviços, fix na camada certa (não no consumidor), teste reproduz cascade + valida não-regressão dos outros consumidores.',
            'planning_weight' => 0.65,
            'execution_weight' => 0.35,
            'ambiguity_level' => 'high',
            'risk_level' => 'critical',
            'objective' => 'Diagnosticar cascade de timeout em fanout NotificationDispatcher → 4 channels; fix na camada raiz (timeout per-channel + circuit breaker), não em cada consumidor.',
            'business_rule' => 'Incidente real: 1 channel slow derrubou os outros 3 ao consumir o budget global; precisamos isolamento.',
            'acceptance_criteria' => [
                'NotificationDispatcherTest::test_slow_channel_does_not_starve_others passa.',
                'Cada channel tem timeout per-channel + circuit breaker isolado.',
                'Fix é uma única camada (não 4 patches espalhados).',
                'PHPStan level mantém-se; nenhum teste relaxado.',
            ],
            'allowed_files_scope' => [
                'app/Domain/Notifications/NotificationDispatcher.php',
                'app/Domain/Notifications/ChannelGuard.php',
                'tests/Unit/Notifications/NotificationDispatcherTest.php',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/bugfix-l5-cascade-failure-fanout/seed',
            'quick_test_command' => "php artisan test --filter='NotificationDispatcherTest'",
            'full_test_command' => "php artisan test --filter='Notifications'",
            'test_command' => "php artisan test --filter='NotificationDispatcherTest'",
            'expected_changed_files' => ['app/Domain/Notifications/NotificationDispatcher.php'],
            'quality_weights' => [
                'dimensions' => ['root_cause_discipline', 'integration_safety', 'minimal_blast_radius', 'maintainability'],
                'weights' => ['root_cause_discipline' => 0.35, 'integration_safety' => 0.30, 'minimal_blast_radius' => 0.20, 'maintainability' => 0.15],
            ],
            'invalid_if' => [
                'fix_spread_across_each_consumer',
                'no_circuit_breaker_introduced',
                'test_does_not_simulate_slow_channel',
            ],
            'evidence_requirements' => ['patch_diff', 'phpunit_output', 'cascade_repro_log', 'channel_isolation_report'],
            'fairness_notes' => 'ChannelGuard é abstração in-memory; nenhum vendor lib gating.',
            'human_review_notes' => 'Operador valida: fix está na camada raiz, não há patch em cada channel.',
        ]);
    }

    /* -------- refactor (4 novos) -------- */

    /** @return array<string,mixed> */
    private function caseRefactorL1ExtractMethod(): array
    {
        return $this->makeMatrixCase([
            'case_id' => 'refactor-l1-extract-method',
            'title' => 'Extrair função pura de 10 linhas com nome explícito — comportamento preservado byte-a-byte',
            'category' => 'refactor',
            'difficulty' => self::DIFFICULTY_EASY,
            'difficulty_level' => 'L1',
            'difficulty_score' => 1.0,
            'difficulty_reason' => 'Extract method clássico em função pura: cobertura de teste prévia, fix mecânico de ~10 linhas, sem decisões arquiteturais.',
            'planning_weight' => 0.20,
            'execution_weight' => 0.80,
            'ambiguity_level' => 'low',
            'risk_level' => 'low',
            'objective' => 'Extrair bloco de cálculo de score do InboxRankingService::rank() para método privado calculateScore(); testes existentes continuam verdes byte-a-byte.',
            'business_rule' => 'Refator pré-requisito para próxima feature; precisamos primeiro tornar o cálculo isolado.',
            'acceptance_criteria' => [
                'InboxRankingService::rank() chama calculateScore() para cada item.',
                'InboxRankingServiceTest passa sem qualquer alteração.',
                'calculateScore() é private final, sem side effect.',
                'Patch toca apenas InboxRankingService.php.',
            ],
            'allowed_files_scope' => ['app/Domain/Inbox/InboxRankingService.php'],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/refactor-l1-extract-method/seed',
            'quick_test_command' => "php artisan test --filter='InboxRankingServiceTest'",
            'full_test_command' => "php artisan test --filter='Inbox'",
            'test_command' => "php artisan test --filter='InboxRankingServiceTest'",
            'expected_changed_files' => ['app/Domain/Inbox/InboxRankingService.php'],
            'quality_weights' => [
                'dimensions' => ['behavior_preservation', 'cohesion', 'maintainability'],
                'weights' => ['behavior_preservation' => 0.45, 'cohesion' => 0.30, 'maintainability' => 0.25],
            ],
            'invalid_if' => ['behavior_changed', 'test_modified_to_pass'],
            'evidence_requirements' => ['patch_diff', 'phpunit_output'],
            'fairness_notes' => 'Método privado puro; nenhum framework exclusivo.',
            'human_review_notes' => 'Operador audita: rank() continua thin; calculateScore() tem nome auto-explicativo.',
        ]);
    }

    /** @return array<string,mixed> */
    private function caseRefactorL2RenameSymbolSafely(): array
    {
        return $this->makeMatrixCase([
            'case_id' => 'refactor-l2-rename-symbol-safely',
            'title' => 'Renomear símbolo público em 3 arquivos sem quebrar callers — manter alias depreciado',
            'category' => 'refactor',
            'difficulty' => self::DIFFICULTY_EASY,
            'difficulty_level' => 'L2',
            'difficulty_score' => 2.0,
            'difficulty_reason' => 'Rename + alias de back-compat em 3 arquivos com callers: pequeno mas requer disciplina de não quebrar contrato externo.',
            'planning_weight' => 0.30,
            'execution_weight' => 0.70,
            'ambiguity_level' => 'low',
            'risk_level' => 'medium',
            'objective' => 'Renomear método AtlasUserService::getById() → findById() em 3 arquivos; manter alias getById() marcado deprecated com @deprecated docblock + emite warning em test.',
            'business_rule' => 'Convenção do projeto adotada em 2026-Q1: findX para retornos opcionais; getX para garantia. Migração precisa preservar 14 callers existentes.',
            'acceptance_criteria' => [
                'AtlasUserService::findById() implementa a lógica; getById() chama findById() com @deprecated.',
                'AtlasUserServiceTest cobre ambos com mesmo comportamento.',
                'Teste novo `test_deprecated_alias_emits_notice` passa.',
                'Patch toca apenas AtlasUserService.php + AtlasUserServiceTest.php.',
            ],
            'allowed_files_scope' => [
                'app/Domain/Users/AtlasUserService.php',
                'tests/Unit/Users/AtlasUserServiceTest.php',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/refactor-l2-rename-symbol-safely/seed',
            'quick_test_command' => "php artisan test --filter='AtlasUserServiceTest'",
            'full_test_command' => "php artisan test --filter='Users'",
            'test_command' => "php artisan test --filter='AtlasUserServiceTest'",
            'expected_changed_files' => ['app/Domain/Users/AtlasUserService.php'],
            'quality_weights' => [
                'dimensions' => ['backward_compatibility', 'rename_completeness', 'maintainability'],
                'weights' => ['backward_compatibility' => 0.45, 'rename_completeness' => 0.35, 'maintainability' => 0.20],
            ],
            'invalid_if' => ['legacy_alias_removed', 'deprecation_notice_missing'],
            'evidence_requirements' => ['patch_diff', 'phpunit_output', 'caller_audit_listing'],
            'fairness_notes' => 'Padrão deprecated alias é universal; sem framework exclusivo.',
            'human_review_notes' => 'Operador audita: alias permanece, callers externos não quebram.',
        ]);
    }

    /** @return array<string,mixed> */
    private function caseRefactorL4ReplaceSwitchWithStrategy(): array
    {
        return $this->makeMatrixCase([
            'case_id' => 'refactor-l4-replace-switch-with-strategy',
            'title' => 'Substituir switch de 6 ramos por strategy pattern — comportamento preservado + extensível',
            'category' => 'refactor',
            'secondary_categories' => ['architecture'],
            'difficulty' => self::DIFFICULTY_HARD,
            'difficulty_level' => 'L4',
            'difficulty_score' => 4.0,
            'difficulty_reason' => 'Strategy pattern com 6 estratégias + registry + interface; exige contrato consistent e fail-closed para estratégia desconhecida.',
            'planning_weight' => 0.55,
            'execution_weight' => 0.45,
            'ambiguity_level' => 'medium',
            'risk_level' => 'medium',
            'objective' => 'Substituir switch em CaptureFormatter::format() por StrategyRegistry com 6 strategies (json, csv, md, html, xml, plain); comportamento idêntico, sem nova capability.',
            'business_rule' => 'Switch hoje tem 180 linhas e fica intratável quando precisamos adicionar formato novo; refator é pré-requisito para PDF futuro.',
            'acceptance_criteria' => [
                'CaptureFormatterTest passa byte-a-byte sem alteração.',
                'StrategyRegistry::register() + ::resolve() expostos; resolve devolve blocker explícito para formato desconhecido.',
                'Cada strategy é classe final implementando FormatterStrategy.',
                'Nenhuma capability nova adicionada (strategy de PDF fica fora deste case).',
            ],
            'allowed_files_scope' => [
                'app/Domain/Captures/Format/CaptureFormatter.php',
                'app/Domain/Captures/Format/FormatterStrategy.php',
                'app/Domain/Captures/Format/StrategyRegistry.php',
                'app/Domain/Captures/Format/Strategies/**',
                'tests/Unit/Captures/CaptureFormatterTest.php',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/refactor-l4-replace-switch-with-strategy/seed',
            'quick_test_command' => "php artisan test --filter='CaptureFormatterTest'",
            'full_test_command' => "php artisan test --filter='Captures'",
            'test_command' => "php artisan test --filter='CaptureFormatterTest'",
            'expected_changed_files' => [
                'app/Domain/Captures/Format/CaptureFormatter.php',
                'app/Domain/Captures/Format/FormatterStrategy.php',
                'app/Domain/Captures/Format/StrategyRegistry.php',
                'app/Domain/Captures/Format/Strategies/**',
            ],
            'quality_weights' => [
                'dimensions' => ['behavior_preservation', 'cohesion', 'open_closed_compliance', 'maintainability'],
                'weights' => ['behavior_preservation' => 0.35, 'cohesion' => 0.25, 'open_closed_compliance' => 0.25, 'maintainability' => 0.15],
            ],
            'invalid_if' => ['behavior_diverged_in_any_format', 'registry_silent_on_unknown_strategy', 'new_capability_introduced'],
            'evidence_requirements' => ['patch_diff', 'phpunit_output', 'strategy_inventory'],
            'fairness_notes' => 'Strategy registry é padrão; sem DSL atlas.',
            'human_review_notes' => 'Operador audita: nenhuma feature nova entrou; switch ficou registry.',
        ]);
    }

    /** @return array<string,mixed> */
    private function caseRefactorL5DecomposeGodClass(): array
    {
        return $this->makeMatrixCase([
            'case_id' => 'refactor-l5-decompose-god-class',
            'title' => 'Decompor god-class de 600 linhas em 3 serviços focados, preservando API pública',
            'category' => 'refactor',
            'secondary_categories' => ['architecture'],
            'difficulty' => self::DIFFICULTY_HARD,
            'difficulty_level' => 'L5',
            'difficulty_score' => 5.0,
            'difficulty_reason' => 'God-class com 12 responsabilidades; identificar limites, extrair sem quebrar contrato HTTP, manter coverage e PHPStan — exige planejamento + tradeoffs.',
            'planning_weight' => 0.70,
            'execution_weight' => 0.30,
            'ambiguity_level' => 'high',
            'risk_level' => 'critical',
            'objective' => 'Quebrar InboxOrchestrator (600 linhas, 12 responsabilidades) em InboxIngest + InboxRanking + InboxNotification; API pública (3 métodos) idêntica; testes feature passam sem alteração.',
            'business_rule' => 'Operador novo demora 1 semana para entender InboxOrchestrator; decomposição é pré-requisito para onboarding decente.',
            'acceptance_criteria' => [
                'InboxOrchestrator vira fachada thin (≤ 80 linhas) delegando para os 3 serviços.',
                'InboxOrchestratorFeatureTest passa byte-a-byte.',
                '3 unit tests novos cobrem cada serviço isolado (≥ 80% cobertura cada).',
                'PHPStan level se mantém; baseline não infla.',
            ],
            'allowed_files_scope' => [
                'app/Domain/Inbox/InboxOrchestrator.php',
                'app/Domain/Inbox/InboxIngest.php',
                'app/Domain/Inbox/InboxRanking.php',
                'app/Domain/Inbox/InboxNotification.php',
                'tests/Unit/Inbox/',
                'tests/Feature/Inbox/',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/refactor-l5-decompose-god-class/seed',
            'quick_test_command' => "php artisan test --filter='Inbox'",
            'full_test_command' => "php artisan test --filter='Inbox'",
            'test_command' => "php artisan test --filter='Inbox'",
            'expected_changed_files' => ['app/Domain/Inbox/InboxOrchestrator.php'],
            'quality_weights' => [
                'dimensions' => ['decomposition_quality', 'behavior_preservation', 'cohesion_per_class', 'phpstan_stability'],
                'weights' => ['decomposition_quality' => 0.30, 'behavior_preservation' => 0.30, 'cohesion_per_class' => 0.25, 'phpstan_stability' => 0.15],
            ],
            'invalid_if' => ['orchestrator_remains_god', 'phpstan_baseline_inflated', 'feature_test_modified_to_pass'],
            'evidence_requirements' => ['patch_diff', 'phpunit_output', 'before_after_loc_per_class', 'phpstan_baseline_diff'],
            'fairness_notes' => 'Tradeoff de fronteiras é universal; sem DSL atlas.',
            'human_review_notes' => 'Operador audita: cada nova classe tem propósito único; orquestrador não esconde lógica.',
        ]);
    }

    /* -------- test_design (4 novos) -------- */

    /** @return array<string,mixed> */
    private function caseTestdesignL1AddEdgeCaseTests(): array
    {
        return $this->makeMatrixCase([
            'case_id' => 'testdesign-l1-add-edge-case-tests',
            'title' => 'Adicionar 4 edge case tests a parser existente sem tocar produção',
            'category' => 'test_design',
            'difficulty' => self::DIFFICULTY_EASY,
            'difficulty_level' => 'L1',
            'difficulty_score' => 1.0,
            'difficulty_reason' => 'Adicionar tests a parser estável; sem mudança em código; foco em cobertura de borda; clássico L1.',
            'planning_weight' => 0.25,
            'execution_weight' => 0.75,
            'ambiguity_level' => 'low',
            'risk_level' => 'low',
            'objective' => 'Adicionar 4 edge case tests ao CaptureMarkdownParserTest (entrada vazia, só whitespace, só emoji, json inválido); produção intocada.',
            'business_rule' => 'Cobertura do parser hoje é 51%; queremos 80% antes de mexer no parser.',
            'acceptance_criteria' => [
                'CaptureMarkdownParserTest cresce com 4 cases nomeados.',
                'pcov reporta ≥ 80% line coverage no parser.',
                'Nenhum byte tocado em app/.',
                'Tests determinísticos (sem random sem seed).',
            ],
            'allowed_files_scope' => ['tests/Unit/Captures/CaptureMarkdownParserTest.php'],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/testdesign-l1-add-edge-case-tests/seed',
            'quick_test_command' => "php artisan test --filter='CaptureMarkdownParserTest'",
            'full_test_command' => "php artisan test --filter='Captures' --coverage",
            'test_command' => "php artisan test --filter='CaptureMarkdownParserTest'",
            'expected_changed_files' => ['tests/Unit/Captures/CaptureMarkdownParserTest.php'],
            'quality_weights' => [
                'dimensions' => ['coverage_quality', 'determinism', 'maintainability'],
                'weights' => ['coverage_quality' => 0.55, 'determinism' => 0.25, 'maintainability' => 0.20],
            ],
            'invalid_if' => ['production_code_modified', 'tests_use_random_without_seed'],
            'evidence_requirements' => ['patch_diff', 'phpunit_output', 'coverage_report'],
            'fairness_notes' => 'Cobertura medida com pcov; sem framework atlas exclusivo.',
            'human_review_notes' => 'Operador audita: edges cobertos representam falhas reais possíveis.',
        ]);
    }

    /** @return array<string,mixed> */
    private function caseTestdesignL3PropertyBasedParser(): array
    {
        return $this->makeMatrixCase([
            'case_id' => 'testdesign-l3-property-based-parser',
            'title' => 'Adicionar property-based test ao parser com seed + shrink reproduzível',
            'category' => 'test_design',
            'difficulty' => self::DIFFICULTY_MEDIUM,
            'difficulty_level' => 'L3',
            'difficulty_score' => 3.0,
            'difficulty_reason' => 'Property-based test exige integração com lib (eris ou equivalente) + propriedade bem-formada (parse∘serialize = id) + seed determinístico — produto real.',
            'planning_weight' => 0.45,
            'execution_weight' => 0.55,
            'ambiguity_level' => 'medium',
            'risk_level' => 'medium',
            'objective' => 'Adicionar PropertyBasedParserTest com propriedade roundtrip (serialize(parse(x)) === x para strings markdown válidas), seed fixo + shrink reproduzível.',
            'business_rule' => 'Parser falhou em produção em input que tests example-based não cobriam; property-based aumenta coverage real.',
            'acceptance_criteria' => [
                'PropertyBasedParserTest roda 100 inputs gerados a partir de seed 42.',
                'Propriedade roundtrip passa.',
                'Failure run mostra shrink determinístico (não input gigante).',
                'Patch toca apenas o test.',
            ],
            'allowed_files_scope' => ['tests/Unit/Captures/PropertyBasedParserTest.php'],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/testdesign-l3-property-based-parser/seed',
            'quick_test_command' => "php artisan test --filter='PropertyBasedParserTest'",
            'full_test_command' => "php artisan test --filter='PropertyBased|Parser'",
            'test_command' => "php artisan test --filter='PropertyBasedParserTest'",
            'expected_changed_files' => ['tests/Unit/Captures/PropertyBasedParserTest.php'],
            'quality_weights' => [
                'dimensions' => ['property_quality', 'determinism', 'shrink_quality'],
                'weights' => ['property_quality' => 0.50, 'determinism' => 0.30, 'shrink_quality' => 0.20],
            ],
            'invalid_if' => ['seed_not_fixed', 'shrink_not_deterministic', 'production_code_modified'],
            'evidence_requirements' => ['patch_diff', 'phpunit_output', 'property_seed_log'],
            'fairness_notes' => 'PHP property-based libs são open; sem framework atlas exclusivo.',
            'human_review_notes' => 'Operador audita: propriedade é não-trivial, shrink reproduzível em outro host.',
        ]);
    }

    /** @return array<string,mixed> */
    private function caseTestdesignL4ContractTestBetweenModules(): array
    {
        return $this->makeMatrixCase([
            'case_id' => 'testdesign-l4-contract-test-between-modules',
            'title' => 'Contract test que congela a interface entre InboxIngest e CaptureFormatter',
            'category' => 'test_design',
            'secondary_categories' => ['architecture'],
            'difficulty' => self::DIFFICULTY_HARD,
            'difficulty_level' => 'L4',
            'difficulty_score' => 4.0,
            'difficulty_reason' => 'Contract test entre módulos com golden fixture + fail-closed em divergência: exige design do contrato + caching do golden.',
            'planning_weight' => 0.55,
            'execution_weight' => 0.45,
            'ambiguity_level' => 'medium',
            'risk_level' => 'high',
            'objective' => 'Adicionar IngestFormatterContractTest pinning shape + valores entre InboxIngest output e CaptureFormatter input; golden fixture versionada.',
            'business_rule' => 'Refator anterior quebrou a integração silenciosamente; contract test impede regressão sem precisar de integration test pesado.',
            'acceptance_criteria' => [
                'IngestFormatterContractTest passa contra golden em tests/fixtures/contracts/ingest_formatter_v1.json.',
                'Test falha com diff legível quando contrato muda.',
                'Comentário no test cita versão do contrato e canal de aprovação.',
                'Nenhum byte tocado em produção.',
            ],
            'allowed_files_scope' => [
                'tests/Contract/IngestFormatterContractTest.php',
                'tests/fixtures/contracts/ingest_formatter_v1.json',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/testdesign-l4-contract-test-between-modules/seed',
            'quick_test_command' => "php artisan test --filter='IngestFormatterContractTest'",
            'full_test_command' => "php artisan test --filter='Contract'",
            'test_command' => "php artisan test --filter='IngestFormatterContractTest'",
            'expected_changed_files' => ['tests/Contract/IngestFormatterContractTest.php'],
            'quality_weights' => [
                'dimensions' => ['contract_completeness', 'fail_closed_diff', 'maintainability'],
                'weights' => ['contract_completeness' => 0.45, 'fail_closed_diff' => 0.35, 'maintainability' => 0.20],
            ],
            'invalid_if' => ['production_code_modified', 'contract_silent_on_shape_change'],
            'evidence_requirements' => ['patch_diff', 'phpunit_output', 'contract_golden_render'],
            'fairness_notes' => 'Contract test é padrão; sem DSL atlas.',
            'human_review_notes' => 'Operador audita: golden representa shape real e versão aparece no nome.',
        ]);
    }

    /** @return array<string,mixed> */
    private function caseTestdesignL5MutationBaseline(): array
    {
        return $this->makeMatrixCase([
            'case_id' => 'testdesign-l5-mutation-baseline',
            'title' => 'Estabelecer baseline de mutation testing para módulo crítico e documentar survivors',
            'category' => 'test_design',
            'difficulty' => self::DIFFICULTY_HARD,
            'difficulty_level' => 'L5',
            'difficulty_score' => 5.0,
            'difficulty_reason' => 'Rodar mutation testing local + analisar survivors + decidir quais matam vs documentar como aceitos: trabalho intenso de raciocínio sobre cada survivor.',
            'planning_weight' => 0.65,
            'execution_weight' => 0.35,
            'ambiguity_level' => 'high',
            'risk_level' => 'medium',
            'objective' => 'Estabelecer mutation testing baseline (Infection PHP) para módulo CaptureMarkdownParser; gravar baseline.json + mutation-survivors-decision.md decidindo o destino de cada survivor.',
            'business_rule' => 'Cobertura alta esconde testes fracos; mutation testing prova qualidade dos asserts. Time precisa baseline antes de exigir limite.',
            'acceptance_criteria' => [
                'tests/Mutation/baseline.json existe e cita MSI atual + killed count + escaped count.',
                'docs/quality/mutation-survivors-decision.md cita cada survivor com decisão (kill em PR futuro, aceito, falso positivo).',
                'Patch toca apenas tests/Mutation/ e docs/quality/.',
                'Nenhum byte tocado em produção.',
            ],
            'allowed_files_scope' => [
                'tests/Mutation/baseline.json',
                'docs/quality/mutation-survivors-decision.md',
                'docs/quality/mutation-survivors-template.md',
                'infection.json.dist',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/testdesign-l5-mutation-baseline/seed',
            'quick_test_command' => "php artisan test --filter='Mutation'",
            'full_test_command' => "php artisan test --filter='Mutation'",
            'test_command' => "php artisan test --filter='Mutation'",
            'expected_changed_files' => [
                'tests/Mutation/baseline.json',
                'docs/quality/mutation-survivors-decision.md',
            ],
            'quality_weights' => [
                'dimensions' => ['msi_quality', 'survivor_analysis_quality', 'documentation_quality'],
                'weights' => ['msi_quality' => 0.40, 'survivor_analysis_quality' => 0.40, 'documentation_quality' => 0.20],
            ],
            'invalid_if' => ['survivors_undocumented', 'production_code_modified', 'baseline_lacks_msi'],
            'evidence_requirements' => ['patch_diff', 'mutation_report', 'survivor_decision_md'],
            'fairness_notes' => 'Infection é open; nenhum tool atlas exclusivo.',
            'human_review_notes' => 'Operador audita: cada survivor tem decisão consciente, não default "aceito".',
        ]);
    }

    /* -------- architecture (4 novos) -------- */

    /** @return array<string,mixed> */
    private function caseArchitectureL1PublicApiReadme(): array
    {
        return $this->makeMatrixCase([
            'case_id' => 'architecture-l1-public-api-readme',
            'title' => 'README do módulo Captures documentando exclusivamente a API pública',
            'category' => 'architecture',
            'secondary_categories' => ['planning'],
            'difficulty' => self::DIFFICULTY_EASY,
            'difficulty_level' => 'L1',
            'difficulty_score' => 1.0,
            'difficulty_reason' => 'Documentar a API pública existente em README curto; sem código; clássico L1.',
            'planning_weight' => 0.40,
            'execution_weight' => 0.60,
            'ambiguity_level' => 'low',
            'risk_level' => 'low',
            'objective' => 'Escrever app/Domain/Captures/README.md documentando os 4 métodos públicos de CaptureService + exemplos de chamada + erros documentados.',
            'business_rule' => 'Times consumidores quebravam o módulo por chamar métodos não-públicos; README explícito reduz acidente.',
            'acceptance_criteria' => [
                'README.md lista exatamente os 4 métodos públicos.',
                'Cada método tem assinatura PHP + descrição + erro documentado.',
                'Markdownlint clean.',
                'Patch toca apenas README.md + readme test.',
            ],
            'allowed_files_scope' => [
                'app/Domain/Captures/README.md',
                'tests/Unit/Captures/PublicApiReadmeTest.php',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/architecture-l1-public-api-readme/seed',
            'quick_test_command' => "php artisan test --filter='PublicApiReadmeTest'",
            'full_test_command' => "php artisan test --filter='Captures'",
            'test_command' => "php artisan test --filter='PublicApiReadmeTest'",
            'expected_changed_files' => ['app/Domain/Captures/README.md'],
            'quality_weights' => [
                'dimensions' => ['clarity', 'completeness', 'maintainability'],
                'weights' => ['clarity' => 0.45, 'completeness' => 0.35, 'maintainability' => 0.20],
            ],
            'invalid_if' => ['private_methods_documented', 'code_examples_use_placeholders'],
            'evidence_requirements' => ['patch_diff', 'markdownlint_output', 'phpunit_output'],
            'fairness_notes' => 'Markdownlint open; sem framework atlas.',
            'human_review_notes' => 'Operador audita: README casa com a real API; exemplos rodam sem ajuste.',
        ]);
    }

    /** @return array<string,mixed> */
    private function caseArchitectureL2ModuleBoundaryNamespace(): array
    {
        return $this->makeMatrixCase([
            'case_id' => 'architecture-l2-module-boundary-namespace',
            'title' => 'Adicionar phpstan rule que impede cross-namespace import entre Inbox e Captures',
            'category' => 'architecture',
            'difficulty' => self::DIFFICULTY_EASY,
            'difficulty_level' => 'L2',
            'difficulty_score' => 2.0,
            'difficulty_reason' => 'Adicionar phpstan rule e configurar boundaries: pequeno mas exige entender phpstan/deptrac.',
            'planning_weight' => 0.45,
            'execution_weight' => 0.55,
            'ambiguity_level' => 'low',
            'risk_level' => 'medium',
            'objective' => 'Configurar phpstan-deptrac (ou equivalente) bloqueando import direto de Captures dentro de Inbox; arquivos legítimos devem usar IngestPort.',
            'business_rule' => 'Inbox importava Captures direto e dependia de implementação interna; boundary explícita força port pattern.',
            'acceptance_criteria' => [
                'deptrac.yaml define dois layers (Inbox, Captures) com Inbox proibido de importar Captures direto.',
                'BoundaryEnforcementTest roda deptrac e falha se nova violação aparece.',
                'IngestPort criado para acesso autorizado.',
                'Nenhum import existente quebrado.',
            ],
            'allowed_files_scope' => [
                'deptrac.yaml',
                'app/Domain/Inbox/Port/IngestPort.php',
                'tests/Architecture/BoundaryEnforcementTest.php',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/architecture-l2-module-boundary-namespace/seed',
            'quick_test_command' => "php artisan test --filter='BoundaryEnforcementTest'",
            'full_test_command' => "php artisan test --filter='Architecture'",
            'test_command' => "php artisan test --filter='BoundaryEnforcementTest'",
            'expected_changed_files' => ['deptrac.yaml'],
            'quality_weights' => [
                'dimensions' => ['boundary_integrity', 'fail_closed_discipline', 'maintainability'],
                'weights' => ['boundary_integrity' => 0.50, 'fail_closed_discipline' => 0.30, 'maintainability' => 0.20],
            ],
            'invalid_if' => ['existing_import_broken_silently', 'deptrac_warns_instead_of_failing'],
            'evidence_requirements' => ['patch_diff', 'deptrac_report'],
            'fairness_notes' => 'Deptrac é open; sem framework atlas.',
            'human_review_notes' => 'Operador roda deptrac local e confirma violation detected.',
        ]);
    }

    /** @return array<string,mixed> */
    private function caseArchitectureL3AdrDocument(): array
    {
        return $this->makeMatrixCase([
            'case_id' => 'architecture-l3-adr-document',
            'title' => 'Escrever ADR documentando decisão de persistência (RDBMS vs append-only log)',
            'category' => 'architecture',
            'secondary_categories' => ['planning'],
            'difficulty' => self::DIFFICULTY_MEDIUM,
            'difficulty_level' => 'L3',
            'difficulty_score' => 3.0,
            'difficulty_reason' => 'ADR exige contexto + 2 alternativas + tradeoffs + decisão + consequência; produto real de raciocínio arquitetural.',
            'planning_weight' => 0.75,
            'execution_weight' => 0.25,
            'ambiguity_level' => 'medium',
            'risk_level' => 'medium',
            'objective' => 'Escrever docs/adr/0001-persistence-strategy.md no template MADR cobrindo decisão de persistência (RDBMS vs append-only) com 2 alternativas e tradeoffs.',
            'business_rule' => 'Decisões arquiteturais ficam tácitas e somem com o time; ADRs viram fonte de verdade auditável.',
            'acceptance_criteria' => [
                'ADR segue template MADR (status, context, decision, consequences).',
                '2 alternativas consideradas, cada uma com pro+contra.',
                'Tradeoff explicito (perda vs ganho).',
                'AdrLintTest valida que todos os ADRs em docs/adr/ seguem template.',
            ],
            'allowed_files_scope' => [
                'docs/adr/0001-persistence-strategy.md',
                'tests/Unit/Docs/AdrLintTest.php',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/architecture-l3-adr-document/seed',
            'quick_test_command' => "php artisan test --filter='AdrLintTest'",
            'full_test_command' => "php artisan test --filter='Adr|Docs'",
            'test_command' => "php artisan test --filter='AdrLintTest'",
            'expected_changed_files' => ['docs/adr/0001-persistence-strategy.md'],
            'quality_weights' => [
                'dimensions' => ['decision_clarity', 'tradeoff_quality', 'template_compliance'],
                'weights' => ['decision_clarity' => 0.40, 'tradeoff_quality' => 0.40, 'template_compliance' => 0.20],
            ],
            'invalid_if' => ['fewer_than_two_alternatives', 'consequences_section_missing', 'template_violation'],
            'evidence_requirements' => ['patch_diff', 'adr_md_render', 'phpunit_output'],
            'fairness_notes' => 'MADR é padrão público; sem framework atlas.',
            'human_review_notes' => 'Operador valida: decisão é executável, tradeoff honesto, status real (proposed/accepted).',
        ]);
    }

    /** @return array<string,mixed> */
    private function caseArchitectureL4VersionedContractStrategy(): array
    {
        return $this->makeMatrixCase([
            'case_id' => 'architecture-l4-versioned-contract-strategy',
            'title' => 'Versionar contrato externo CaptureEvent v1→v2 com backward-compat de 1 release',
            'category' => 'architecture',
            'secondary_categories' => ['integration_performance'],
            'difficulty' => self::DIFFICULTY_HARD,
            'difficulty_level' => 'L4',
            'difficulty_score' => 4.0,
            'difficulty_reason' => 'Versionamento de contrato + estratégia de compatibilidade + fail-closed em divergência exige raciocínio sobre downstream consumers.',
            'planning_weight' => 0.55,
            'execution_weight' => 0.45,
            'ambiguity_level' => 'medium',
            'risk_level' => 'high',
            'objective' => 'Introduzir CaptureEvent v2 com novo campo opcional + estratégia de back-compat: v1 e v2 aceitos por 1 release; v1 emite deprecation; v3 quebra.',
            'business_rule' => 'Consumidores externos quebram em release anterior por adições silenciosas; precisamos versão + estratégia.',
            'acceptance_criteria' => [
                'CaptureEventV1 e CaptureEventV2 coexistem; CaptureEventGateway aceita ambos.',
                'CaptureEventGatewayTest valida deprecation notice em v1 + aceitação em v2.',
                'docs/contracts/capture_event.md documenta release-by-release compatibility.',
                'Patch sem novo dependency.',
            ],
            'allowed_files_scope' => [
                'app/Domain/Captures/Events/CaptureEventV1.php',
                'app/Domain/Captures/Events/CaptureEventV2.php',
                'app/Domain/Captures/Events/CaptureEventGateway.php',
                'docs/contracts/capture_event.md',
                'tests/Unit/Captures/Events/CaptureEventGatewayTest.php',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/architecture-l4-versioned-contract-strategy/seed',
            'quick_test_command' => "php artisan test --filter='CaptureEventGatewayTest'",
            'full_test_command' => "php artisan test --filter='Captures|Events'",
            'test_command' => "php artisan test --filter='CaptureEventGatewayTest'",
            'expected_changed_files' => ['app/Domain/Captures/Events/CaptureEventGateway.php'],
            'quality_weights' => [
                'dimensions' => ['contract_design', 'backward_compatibility', 'documentation_quality'],
                'weights' => ['contract_design' => 0.40, 'backward_compatibility' => 0.40, 'documentation_quality' => 0.20],
            ],
            'invalid_if' => ['v1_silently_broken', 'no_deprecation_notice', 'documentation_omits_release_plan'],
            'evidence_requirements' => ['patch_diff', 'phpunit_output', 'contract_md_render'],
            'fairness_notes' => 'Versionamento de contrato é padrão; sem dependência atlas exclusiva.',
            'human_review_notes' => 'Operador valida: cliente v1 não quebra; cliente v2 ganha campo; release plan honesto.',
        ]);
    }

    /* -------- integration_performance (2 novos) -------- */

    /** @return array<string,mixed> */
    private function caseIntperfL1EagerLoadRelation(): array
    {
        return $this->makeMatrixCase([
            'case_id' => 'intperf-l1-eager-load-relation',
            'title' => 'Adicionar eager load em listagem com N+1 conhecida — fix de 1 linha',
            'category' => 'integration_performance',
            'difficulty' => self::DIFFICULTY_EASY,
            'difficulty_level' => 'L1',
            'difficulty_score' => 1.0,
            'difficulty_reason' => 'N+1 documentada, fix mecânico de 1 linha (with), assertQueryCount cobre regressão; clássico L1 de perf.',
            'planning_weight' => 0.15,
            'execution_weight' => 0.85,
            'ambiguity_level' => 'low',
            'risk_level' => 'low',
            'objective' => 'Adicionar eager load para CaptureListing::recent() que hoje faz 1 query + N queries de author; resultado deve ficar ≤ 2 queries via test.',
            'business_rule' => 'Endpoint /captures/recent subiu para 1.2s P95; query log mostra N+1.',
            'acceptance_criteria' => [
                'CaptureListingTest::test_recent_uses_at_most_two_queries passa.',
                'Payload JSON do recent() não muda.',
                'Patch toca apenas CaptureListing.php + test.',
                'Sem alteração de DB schema.',
            ],
            'allowed_files_scope' => [
                'app/Domain/Captures/CaptureListing.php',
                'tests/Unit/Captures/CaptureListingTest.php',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/intperf-l1-eager-load-relation/seed',
            'quick_test_command' => "php artisan test --filter='CaptureListingTest'",
            'full_test_command' => "php artisan test --filter='Captures'",
            'test_command' => "php artisan test --filter='CaptureListingTest'",
            'expected_changed_files' => ['app/Domain/Captures/CaptureListing.php'],
            'quality_weights' => [
                'dimensions' => ['performance', 'behavior_preservation', 'maintainability'],
                'weights' => ['performance' => 0.55, 'behavior_preservation' => 0.30, 'maintainability' => 0.15],
            ],
            'invalid_if' => ['payload_changed', 'query_count_assertion_relaxed'],
            'evidence_requirements' => ['patch_diff', 'phpunit_output', 'query_count_proof'],
            'fairness_notes' => 'Padrão eager load existe em todo ORM moderno; sem framework atlas exclusivo.',
            'human_review_notes' => 'Operador valida: query count = 2; payload byte-a-byte igual.',
        ]);
    }

    /** @return array<string,mixed> */
    private function caseIntperfL5CircuitBreakerStateMachine(): array
    {
        return $this->makeMatrixCase([
            'case_id' => 'intperf-l5-circuit-breaker-state-machine',
            'title' => 'Circuit breaker três estados (closed/open/half-open) com backoff exponencial e observability',
            'category' => 'integration_performance',
            'secondary_categories' => ['architecture'],
            'difficulty' => self::DIFFICULTY_HARD,
            'difficulty_level' => 'L5',
            'difficulty_score' => 5.0,
            'difficulty_reason' => 'Circuit breaker canônico: 3 estados, transitions automáticas, backoff exponencial, métrica observável, blocker honesto — exige decomposição arquitetural completa.',
            'planning_weight' => 0.60,
            'execution_weight' => 0.40,
            'ambiguity_level' => 'medium',
            'risk_level' => 'critical',
            'objective' => 'Implementar CircuitBreaker com 3 estados (closed/open/half-open), thresholds configuráveis, backoff exponencial entre half-open trials e métrica por transição.',
            'business_rule' => 'Provider externo flaky degradou painel inteiro; precisamos circuit breaker antes do client; observability é mandatória.',
            'acceptance_criteria' => [
                'CircuitBreakerTest cobre os 3 estados + transitions automáticas (closed→open após N falhas; open→half-open após backoff; half-open→closed após sucesso; half-open→open após falha).',
                'Backoff exponencial configurável com cap.',
                'Cada transição emite evento observável (CircuitBreakerEvent).',
                'Blocker honesto retornado quando aberto; sem fallback silencioso.',
            ],
            'allowed_files_scope' => [
                'app/Domain/Resilience/CircuitBreaker.php',
                'app/Domain/Resilience/CircuitBreakerEvent.php',
                'app/Domain/Resilience/BackoffPolicy.php',
                'tests/Unit/Resilience/CircuitBreakerTest.php',
            ],
            'fixture_seed_path' => 'storage/forge-rivals-corpus/intperf-l5-circuit-breaker-state-machine/seed',
            'quick_test_command' => "php artisan test --filter='CircuitBreakerTest'",
            'full_test_command' => "php artisan test --filter='Resilience'",
            'test_command' => "php artisan test --filter='CircuitBreakerTest'",
            'expected_changed_files' => ['app/Domain/Resilience/CircuitBreaker.php'],
            'quality_weights' => [
                'dimensions' => ['state_machine_correctness', 'observability', 'fail_closed_discipline', 'maintainability'],
                'weights' => ['state_machine_correctness' => 0.35, 'observability' => 0.25, 'fail_closed_discipline' => 0.25, 'maintainability' => 0.15],
            ],
            'invalid_if' => ['silent_fallback_when_open', 'backoff_unbounded', 'events_dropped_on_transition'],
            'evidence_requirements' => ['patch_diff', 'phpunit_output', 'state_transition_log', 'backoff_curve_snapshot'],
            'fairness_notes' => 'Circuit breaker é padrão; sem dependency atlas exclusiva.',
            'human_review_notes' => 'Operador valida: blocker honesto quando aberto, métrica visível, backoff cap respeitado.',
        ]);
    }
}
