<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals\Schema;

/**
 * Atlas Forge Rivals · Schema Contract (single source of truth).
 *
 * Centraliza, em um só lugar, o canon dos cinco schemas que orbitam a
 * Provider Arena / run-battery pipeline:
 *
 *   1. corpus_case        — atlas.forge.rivals.provider_arena_corpus.v1
 *   2. run_result         — atlas.forge.rivals.run_battery.v1
 *   3. evidence_pack      — atlas.forge.rivals.evidence_pack.v2
 *   4. adjudication       — atlas.forge.rivals.adjudication.v2
 *   5. report             — atlas.forge.rivals.report.v3
 *
 * Cada schema declara REQUIRED_FIELDS_<NAME> + um método validate<Name>()
 * que devolve list<string> de violações (lista vazia ⇒ válido). Os
 * SchemaContractService é a única fonte que pode aprovar um payload como
 * "schema-conformant"; serviços de produção (corpus, run-battery,
 * evidence collector, adjudicator, report) podem manter seus próprios
 * SCHEMA_VERSION strings para back-compat, mas precisam invocar o
 * contract quando aceitam ou emitem payload externo.
 *
 * Fail-closed: para "real cases" (claim_level === 'case_result_only',
 * `case_source` === 'provider_arena_corpus' ou contexto equivalente), o
 * difficulty_block é OBRIGATÓRIO. Aliases antigos (difficulty:
 * easy|medium|hard) continuam aceitos como leitura, mas o caminho de
 * escrita canon é difficulty_level (L1..L5) + difficulty_score +
 * planning_weight + execution_weight + ambiguity_level + risk_level +
 * difficulty_reason.
 *
 * Nada deste serviço chama provider. Nada deste serviço desbloqueia
 * `external_rivals_certification`.
 */
final class AtlasForgeRivalsSchemaContractService
{
    public const CONTRACT_VERSION = 'atlas.forge.rivals.schema_contract.v1';

    public const SCHEMA_CORPUS_CASE = 'atlas.forge.rivals.provider_arena_corpus.v1';

    public const SCHEMA_RUN_RESULT = 'atlas.forge.rivals.run_battery.v1';

    public const SCHEMA_EVIDENCE_PACK = 'atlas.forge.rivals.evidence_pack.v2';

    public const SCHEMA_ADJUDICATION = 'atlas.forge.rivals.adjudication.v2';

    public const SCHEMA_REPORT = 'atlas.forge.rivals.report.v3';

    /** @var list<string> */
    public const ALL_SCHEMAS = [
        self::SCHEMA_CORPUS_CASE,
        self::SCHEMA_RUN_RESULT,
        self::SCHEMA_EVIDENCE_PACK,
        self::SCHEMA_ADJUDICATION,
        self::SCHEMA_REPORT,
    ];

    /* ---------- difficulty canon (L1..L5) ---------- */

    public const DIFFICULTY_LEVELS = ['L1', 'L2', 'L3', 'L4', 'L5'];

    /** Score canonical per level (1-5). */
    public const DIFFICULTY_LEVEL_SCORE = [
        'L1' => 1.0,
        'L2' => 2.0,
        'L3' => 3.0,
        'L4' => 4.0,
        'L5' => 5.0,
    ];

    /** Legacy difficulty alias map (back-compat with easy|medium|hard). */
    public const LEGACY_DIFFICULTY_ALIAS = [
        'L1' => 'easy',
        'L2' => 'easy',
        'L3' => 'medium',
        'L4' => 'medium',
        'L5' => 'hard',
    ];

    public const AMBIGUITY_LEVELS = ['low', 'medium', 'high'];

    public const RISK_LEVELS = ['low', 'medium', 'high', 'critical'];

    public const DIFFICULTY_REASON_MIN_LENGTH = 16;

    /**
     * Canonical multiplier formula: L3 (score=3.0) is neutral (multiplier=1.0).
     * L1 ≈ 0.33, L2 ≈ 0.67, L3 = 1.00, L4 ≈ 1.33, L5 ≈ 1.67.
     *
     * difficulty_weighted_score = raw_score * difficulty_multiplier.
     */
    public function difficultyMultiplier(float $score): float
    {
        return round($score / 3.0, 4);
    }

    public function difficultyWeightedScore(float $rawScore, float $difficultyScore): float
    {
        return round($rawScore * $this->difficultyMultiplier($difficultyScore), 4);
    }

    /* ---------- 1. corpus_case ---------- */

    /**
     * @var list<string>
     *
     * Canon do corpus_case (Release Matrix v1):
     *   - 22 originais do Release v1
     *   - 7 do difficulty block L1..L5
     *   - 2 aliases canon adicionados nesta release (test_command, quality_gates),
     *     auto-populados pelo adaptCase a partir de full_test_command/quality_weights.
     */
    public const REQUIRED_FIELDS_CORPUS_CASE = [
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
        'human_prompt',
        'context_profile',
        'measurement_tags',
        'human_prompt_probe',
        'acceptance_criteria',
        'allowed_files_scope',
        'forbidden_files_scope',
        'fixture_seed_path',
        'quick_test_command',
        'full_test_command',
        'test_command',
        'expected_changed_files',
        'quality_weights',
        'quality_gates',
        'invalid_if',
        'timeout_policy',
        'evidence_requirements',
        'replay_requirements',
        'fairness_notes',
        'human_review_notes',
        'claim_level',
    ];

    /**
     * @param  array<string,mixed>  $case
     * @return list<string> violations
     */
    public function validateCorpusCase(array $case): array
    {
        $violations = [];
        foreach (self::REQUIRED_FIELDS_CORPUS_CASE as $field) {
            if (! array_key_exists($field, $case)) {
                $violations[] = "corpus_case.missing_field:{$field}";
            }
        }

        $violations = array_merge(
            $violations,
            $this->validateDifficultyBlock($case, 'corpus_case'),
        );

        if (array_key_exists('claim_level', $case) && (string) $case['claim_level'] !== 'case_result_only') {
            $violations[] = 'corpus_case.claim_level_must_be_case_result_only:'.(string) $case['claim_level'];
        }
        if (array_key_exists('human_prompt', $case) && trim((string) $case['human_prompt']) === '') {
            $violations[] = 'corpus_case.human_prompt_must_be_non_empty';
        }
        if (array_key_exists('context_profile', $case)) {
            $context = is_array($case['context_profile']) ? $case['context_profile'] : [];
            if (($context['schema_version'] ?? null) !== 'atlas.forge.rivals.context_profile.v1') {
                $violations[] = 'corpus_case.context_profile_schema_version_invalid';
            }
            if (($context['requires_assumption_log'] ?? false) !== true) {
                $violations[] = 'corpus_case.context_profile_requires_assumption_log_missing';
            }
            if (($context['complexity_profile']['schema_version'] ?? null) !== 'atlas.forge.rivals.case_complexity_profile.v1') {
                $violations[] = 'corpus_case.context_profile_complexity_profile_missing';
            }
        }
        if (array_key_exists('measurement_tags', $case) && ! in_array('human_prompt', (array) $case['measurement_tags'], true)) {
            $violations[] = 'corpus_case.measurement_tags_missing_human_prompt';
        }
        if (array_key_exists('human_prompt_probe', $case)) {
            $probe = is_array($case['human_prompt_probe']) ? $case['human_prompt_probe'] : [];
            if (($probe['schema_version'] ?? null) !== 'atlas.forge.rivals.human_prompt_probe.v1') {
                $violations[] = 'corpus_case.human_prompt_probe_schema_version_invalid';
            }
            foreach (['facts_observed', 'assumptions', 'reversible_decisions', 'scope_boundaries', 'evidence_plan', 'replay_matrix', 'tradeoffs', 'honest_blockers'] as $section) {
                if (! in_array($section, (array) ($probe['requires_sections'] ?? []), true)) {
                    $violations[] = 'corpus_case.human_prompt_probe_missing_section:'.$section;
                }
            }
            if (($probe['complexity_profile']['schema_version'] ?? null) !== 'atlas.forge.rivals.case_complexity_profile.v1') {
                $violations[] = 'corpus_case.human_prompt_probe_complexity_profile_missing';
            }
        }

        return $violations;
    }

    /* ---------- 2. run_result ---------- */

    /** @var list<string> */
    public const REQUIRED_FIELDS_RUN_RESULT = [
        'action',
        'run_battery_schema_version',
        'status',
        'mode',
        'external_provider_call',
        'provider_tokens_spent',
    ];

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    public function validateRunResult(array $payload): array
    {
        $violations = [];
        foreach (self::REQUIRED_FIELDS_RUN_RESULT as $field) {
            if (! array_key_exists($field, $payload)) {
                $violations[] = "run_result.missing_field:{$field}";
            }
        }
        if (array_key_exists('run_battery_schema_version', $payload)
            && (string) $payload['run_battery_schema_version'] !== self::SCHEMA_RUN_RESULT
        ) {
            $violations[] = 'run_result.schema_version_mismatch:'.(string) $payload['run_battery_schema_version'];
        }
        if (array_key_exists('external_provider_call', $payload)
            && $payload['external_provider_call'] === true
            && (string) ($payload['mode'] ?? '') === 'local_fake'
        ) {
            $violations[] = 'run_result.local_fake_must_not_call_provider';
        }

        return $violations;
    }

    /* ---------- 3. evidence_pack ---------- */

    /** @var list<string> */
    public const REQUIRED_FIELDS_EVIDENCE_PACK = [
        'schema_version',
        'run_id',
        'manifest_path',
        'atlas_paths',
        'rival_paths',
    ];

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    public function validateEvidencePack(array $payload): array
    {
        $violations = [];
        foreach (self::REQUIRED_FIELDS_EVIDENCE_PACK as $field) {
            if (! array_key_exists($field, $payload)) {
                $violations[] = "evidence_pack.missing_field:{$field}";
            }
        }
        $version = (string) ($payload['schema_version'] ?? '');
        if ($version !== self::SCHEMA_EVIDENCE_PACK && $version !== 'atlas.forge.rivals.evidence_pack.v1') {
            $violations[] = 'evidence_pack.schema_version_not_recognized:'.$version;
        }

        return $violations;
    }

    /* ---------- 4. adjudication ---------- */

    /** @var list<string> */
    public const REQUIRED_FIELDS_ADJUDICATION = [
        'schema_version',
        'run_id',
        'winner',
        'hard_failures',
        'atlas_score',
        'rival_score',
    ];

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    public function validateAdjudication(array $payload): array
    {
        $violations = [];
        foreach (self::REQUIRED_FIELDS_ADJUDICATION as $field) {
            if (! array_key_exists($field, $payload)) {
                $violations[] = "adjudication.missing_field:{$field}";
            }
        }
        $version = (string) ($payload['schema_version'] ?? '');
        if ($version !== self::SCHEMA_ADJUDICATION && $version !== 'atlas.forge.rivals.adjudication.v1') {
            $violations[] = 'adjudication.schema_version_not_recognized:'.$version;
        }

        // If a difficulty block is present on the adjudication payload (real case),
        // it must be canonical — fail-closed.
        if (array_key_exists('difficulty_block', $payload)) {
            $violations = array_merge(
                $violations,
                $this->validateDifficultyBlock((array) $payload['difficulty_block'], 'adjudication.difficulty_block', flat: true),
            );
        }

        return $violations;
    }

    /* ---------- 5. report ---------- */

    /** @var list<string> */
    public const REQUIRED_FIELDS_REPORT = [
        'schema_version',
        'run_id',
        'winner',
        'atlas_score',
        'rival_score',
        'case_results',
        'claim_status',
        'provider_performance_signal',
    ];

    /** @var list<string> */
    public const REQUIRED_FIELDS_REPORT_CASE = [
        'case_id',
        'task_category',
        'atlas_score',
        'rival_score',
        'raw_score',
        'difficulty_multiplier',
        'difficulty_weighted_score',
    ];

    /** @var list<string> */
    public const REQUIRED_FIELDS_REPORT_CLAIM_STATUS = [
        'can_feed_ledger',
        'can_feed_decide_signal',
        'ledger_blockers',
    ];

    /** @var list<string> */
    public const REQUIRED_FIELDS_REPORT_PROVIDER_SIGNAL = [
        'schema_version',
        'advisory_only',
        'should_update_provider_topology',
        'never_changes_atlas_decide_topology',
        'owner_of_model_routing',
        'routing_effect',
        'can_feed_ledger',
        'ledger_blockers',
        'do_not_use_when',
        'human_prompt_contract_coverage',
        'complexity_profile_coverage',
    ];

    /** @var list<string> */
    public const REQUIRED_FIELDS_REPORT_HUMAN_PROMPT_COVERAGE = [
        'schema_version',
        'advisory_only',
        'routing_effect',
        'case_count',
        'cases_with_contract',
        'complete_contract_cases',
        'coverage_ratio',
        'complete_ratio',
    ];

    /** @var list<string> */
    public const REQUIRED_FIELDS_REPORT_COMPLEXITY_COVERAGE = [
        'schema_version',
        'advisory_only',
        'routing_effect',
        'case_count',
        'cases_with_complexity_profile',
        'coverage_ratio',
        'long_context_required_cases',
        'evidence_matrix_required_cases',
        'multi_step_plan_required_cases',
        'meta_provider_claim_floor_met',
    ];

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    public function validateReport(array $payload): array
    {
        $violations = [];
        foreach (self::REQUIRED_FIELDS_REPORT as $field) {
            if (! array_key_exists($field, $payload)) {
                $violations[] = "report.missing_field:{$field}";
            }
        }
        $version = (string) ($payload['schema_version'] ?? '');
        if ($version !== self::SCHEMA_REPORT) {
            $violations[] = 'report.schema_version_mismatch:'.$version;
        }
        if (array_key_exists('case_results', $payload) && is_array($payload['case_results'])) {
            foreach ($payload['case_results'] as $i => $case) {
                if (! is_array($case)) {
                    $violations[] = "report.case_results[{$i}].not_an_object";

                    continue;
                }
                foreach (self::REQUIRED_FIELDS_REPORT_CASE as $field) {
                    if (! array_key_exists($field, $case)) {
                        $violations[] = "report.case_results[{$i}].missing_field:{$field}";
                    }
                }
            }
        }
        if (array_key_exists('claim_status', $payload)) {
            if (! is_array($payload['claim_status'])) {
                $violations[] = 'report.claim_status.not_an_object';
            } else {
                foreach (self::REQUIRED_FIELDS_REPORT_CLAIM_STATUS as $field) {
                    if (! array_key_exists($field, $payload['claim_status'])) {
                        $violations[] = "report.claim_status.missing_field:{$field}";
                    }
                }
                if (array_key_exists('ledger_blockers', $payload['claim_status']) && ! is_array($payload['claim_status']['ledger_blockers'])) {
                    $violations[] = 'report.claim_status.ledger_blockers.not_an_array';
                }
                if (($payload['claim_status']['can_feed_ledger'] ?? null) === true
                    && is_array($payload['claim_status']['ledger_blockers'] ?? null)
                    && $payload['claim_status']['ledger_blockers'] !== []) {
                    $violations[] = 'report.claim_status.can_feed_ledger_true_with_ledger_blockers';
                }
            }
        }
        if (array_key_exists('provider_performance_signal', $payload)) {
            if (! is_array($payload['provider_performance_signal'])) {
                $violations[] = 'report.provider_performance_signal.not_an_object';
            } else {
                foreach (self::REQUIRED_FIELDS_REPORT_PROVIDER_SIGNAL as $field) {
                    if (! array_key_exists($field, $payload['provider_performance_signal'])) {
                        $violations[] = "report.provider_performance_signal.missing_field:{$field}";
                    }
                }
                if (($payload['provider_performance_signal']['advisory_only'] ?? null) !== true) {
                    $violations[] = 'report.provider_performance_signal.advisory_only_must_be_true';
                }
                if (($payload['provider_performance_signal']['should_update_provider_topology'] ?? null) !== false) {
                    $violations[] = 'report.provider_performance_signal.should_update_provider_topology_must_be_false';
                }
                if (($payload['provider_performance_signal']['never_changes_atlas_decide_topology'] ?? null) !== true) {
                    $violations[] = 'report.provider_performance_signal.never_changes_atlas_decide_topology_must_be_true';
                }
                if (($payload['provider_performance_signal']['owner_of_model_routing'] ?? null) !== 'atlas_decide') {
                    $violations[] = 'report.provider_performance_signal.owner_of_model_routing_must_be_atlas_decide';
                }
                if (($payload['provider_performance_signal']['routing_effect'] ?? null) !== 'none') {
                    $violations[] = 'report.provider_performance_signal.routing_effect_must_be_none';
                }
                if (array_key_exists('ledger_blockers', $payload['provider_performance_signal']) && ! is_array($payload['provider_performance_signal']['ledger_blockers'])) {
                    $violations[] = 'report.provider_performance_signal.ledger_blockers.not_an_array';
                }
                if (array_key_exists('do_not_use_when', $payload['provider_performance_signal']) && ! is_array($payload['provider_performance_signal']['do_not_use_when'])) {
                    $violations[] = 'report.provider_performance_signal.do_not_use_when.not_an_array';
                }
                if (($payload['provider_performance_signal']['can_feed_ledger'] ?? null) === true
                    && is_array($payload['provider_performance_signal']['ledger_blockers'] ?? null)
                    && $payload['provider_performance_signal']['ledger_blockers'] !== []) {
                    $violations[] = 'report.provider_performance_signal.can_feed_ledger_true_with_ledger_blockers';
                }
                if (($payload['provider_performance_signal']['can_feed_ledger'] ?? null) === true
                    && is_array($payload['provider_performance_signal']['do_not_use_when'] ?? null)
                    && $payload['provider_performance_signal']['do_not_use_when'] !== []) {
                    $violations[] = 'report.provider_performance_signal.can_feed_ledger_true_with_do_not_use_when';
                }
                if (($payload['claim_status']['can_feed_ledger'] ?? null) === true
                    && ($payload['provider_performance_signal']['can_feed_ledger'] ?? null) === false) {
                    $violations[] = 'report.claim_status.can_feed_ledger_true_while_provider_signal_blocks_ledger';
                }
                $violations = array_merge(
                    $violations,
                    $this->validateReportHumanPromptCoverage(
                        $payload['provider_performance_signal']['human_prompt_contract_coverage'] ?? null,
                    ),
                    $this->validateReportComplexityCoverage(
                        $payload['provider_performance_signal']['complexity_profile_coverage'] ?? null,
                    ),
                );
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function validateReportHumanPromptCoverage(mixed $coverage): array
    {
        if (! is_array($coverage)) {
            return ['report.provider_performance_signal.human_prompt_contract_coverage.not_an_object'];
        }

        $violations = [];
        foreach (self::REQUIRED_FIELDS_REPORT_HUMAN_PROMPT_COVERAGE as $field) {
            if (! array_key_exists($field, $coverage)) {
                $violations[] = "report.provider_performance_signal.human_prompt_contract_coverage.missing_field:{$field}";
            }
        }
        if (($coverage['schema_version'] ?? null) !== 'atlas.forge.rivals.human_prompt_contract_coverage.v1') {
            $violations[] = 'report.provider_performance_signal.human_prompt_contract_coverage.schema_version_invalid';
        }
        if (($coverage['advisory_only'] ?? null) !== true) {
            $violations[] = 'report.provider_performance_signal.human_prompt_contract_coverage.advisory_only_must_be_true';
        }
        if (($coverage['routing_effect'] ?? null) !== 'none') {
            $violations[] = 'report.provider_performance_signal.human_prompt_contract_coverage.routing_effect_must_be_none';
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function validateReportComplexityCoverage(mixed $coverage): array
    {
        if (! is_array($coverage)) {
            return ['report.provider_performance_signal.complexity_profile_coverage.not_an_object'];
        }

        $violations = [];
        foreach (self::REQUIRED_FIELDS_REPORT_COMPLEXITY_COVERAGE as $field) {
            if (! array_key_exists($field, $coverage)) {
                $violations[] = "report.provider_performance_signal.complexity_profile_coverage.missing_field:{$field}";
            }
        }
        if (($coverage['schema_version'] ?? null) !== 'atlas.forge.rivals.complexity_profile_coverage.v1') {
            $violations[] = 'report.provider_performance_signal.complexity_profile_coverage.schema_version_invalid';
        }
        if (($coverage['advisory_only'] ?? null) !== true) {
            $violations[] = 'report.provider_performance_signal.complexity_profile_coverage.advisory_only_must_be_true';
        }
        if (($coverage['routing_effect'] ?? null) !== 'none') {
            $violations[] = 'report.provider_performance_signal.complexity_profile_coverage.routing_effect_must_be_none';
        }

        return $violations;
    }

    /* ---------- multi-schema helpers ---------- */

    /**
     * Validate a payload against a named schema. Returns list of violations.
     *
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    public function validate(string $schema, array $payload): array
    {
        return match ($schema) {
            self::SCHEMA_CORPUS_CASE => $this->validateCorpusCase($payload),
            self::SCHEMA_RUN_RESULT => $this->validateRunResult($payload),
            self::SCHEMA_EVIDENCE_PACK => $this->validateEvidencePack($payload),
            self::SCHEMA_ADJUDICATION => $this->validateAdjudication($payload),
            self::SCHEMA_REPORT => $this->validateReport($payload),
            default => ['schema_unknown:'.$schema],
        };
    }

    /**
     * Validate the canonical difficulty block. Used both as part of corpus_case
     * validation and as a standalone check when a downstream stage needs to
     * fail-closed on a missing difficulty.
     *
     * @param  array<string,mixed>  $case
     * @param  bool  $flat  When true, the difficulty fields are expected at the root of $case (e.g. adjudication.difficulty_block).
     * @return list<string>
     */
    public function validateDifficultyBlock(array $case, string $prefix, bool $flat = false): array
    {
        $get = static fn (string $key): mixed => $case[$key] ?? null;
        $violations = [];

        // 1. difficulty_level
        $level = $get('difficulty_level');
        if (! is_string($level) || ! in_array($level, self::DIFFICULTY_LEVELS, true)) {
            $violations[] = $prefix.'.difficulty_level_not_in_canon:'.(is_scalar($level) ? (string) $level : 'null');
        }

        // 2. difficulty_score (must match the level)
        $score = $get('difficulty_score');
        if (! is_int($score) && ! is_float($score)) {
            $violations[] = $prefix.'.difficulty_score_not_numeric';
        } else {
            $scoreFloat = (float) $score;
            if ($scoreFloat < 1.0 || $scoreFloat > 5.0) {
                $violations[] = $prefix.'.difficulty_score_out_of_range:'.$scoreFloat;
            }
            if (is_string($level) && isset(self::DIFFICULTY_LEVEL_SCORE[$level])) {
                $expected = self::DIFFICULTY_LEVEL_SCORE[$level];
                if (abs($scoreFloat - $expected) > 0.01) {
                    $violations[] = $prefix.'.difficulty_score_does_not_match_level:'.$level.'='.$scoreFloat;
                }
            }
        }

        // 3. difficulty_reason
        $reason = $get('difficulty_reason');
        if (! is_string($reason) || strlen(trim($reason)) < self::DIFFICULTY_REASON_MIN_LENGTH) {
            $violations[] = $prefix.'.difficulty_reason_too_short_or_missing';
        }

        // 4 + 5. planning_weight + execution_weight (must sum to 1.0 ± 0.01)
        $planning = $get('planning_weight');
        $execution = $get('execution_weight');
        if (! is_numeric($planning)) {
            $violations[] = $prefix.'.planning_weight_not_numeric';
        }
        if (! is_numeric($execution)) {
            $violations[] = $prefix.'.execution_weight_not_numeric';
        }
        if (is_numeric($planning) && is_numeric($execution)) {
            $sum = (float) $planning + (float) $execution;
            if (abs($sum - 1.0) > 0.01) {
                $violations[] = $prefix.'.planning_execution_weights_do_not_sum_to_one:'.$sum;
            }
            if ((float) $planning < 0 || (float) $execution < 0) {
                $violations[] = $prefix.'.planning_or_execution_weight_negative';
            }
        }

        // 6. ambiguity_level
        $amb = $get('ambiguity_level');
        if (! is_string($amb) || ! in_array($amb, self::AMBIGUITY_LEVELS, true)) {
            $violations[] = $prefix.'.ambiguity_level_not_in_canon:'.(is_scalar($amb) ? (string) $amb : 'null');
        }

        // 7. risk_level
        $risk = $get('risk_level');
        if (! is_string($risk) || ! in_array($risk, self::RISK_LEVELS, true)) {
            $violations[] = $prefix.'.risk_level_not_in_canon:'.(is_scalar($risk) ? (string) $risk : 'null');
        }

        return $violations;
    }

    /**
     * Convenience: pull the difficulty block from a (possibly real) case and
     * fail-closed if absent. Used by stages that need to surface difficulty
     * downstream (adjudicator, report).
     *
     * @param  array<string,mixed>  $case
     * @return array{
     *   ok:bool,
     *   block: array<string,mixed>|null,
     *   violations: list<string>,
     * }
     */
    public function difficultyBlockOf(array $case, bool $required = true): array
    {
        $hasAny = isset($case['difficulty_level'])
            || isset($case['difficulty_score'])
            || isset($case['difficulty_reason']);

        if (! $hasAny) {
            return [
                'ok' => ! $required,
                'block' => null,
                'violations' => $required ? ['difficulty_block_missing'] : [],
            ];
        }

        $violations = $this->validateDifficultyBlock($case, 'difficulty_block', flat: true);
        if ($violations !== []) {
            return ['ok' => false, 'block' => null, 'violations' => $violations];
        }
        $score = (float) $case['difficulty_score'];

        return [
            'ok' => true,
            'block' => [
                'difficulty_level' => (string) $case['difficulty_level'],
                'difficulty_score' => $score,
                'difficulty_reason' => (string) $case['difficulty_reason'],
                'planning_weight' => (float) $case['planning_weight'],
                'execution_weight' => (float) $case['execution_weight'],
                'ambiguity_level' => (string) $case['ambiguity_level'],
                'risk_level' => (string) $case['risk_level'],
                'difficulty_multiplier' => $this->difficultyMultiplier($score),
            ],
            'violations' => [],
        ];
    }

    /**
     * Snapshot of the contract — used by docs/cert/audit to prove the schemas
     * declared here match the ones running in production code.
     *
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'schemas' => [
                'corpus_case' => self::SCHEMA_CORPUS_CASE,
                'run_result' => self::SCHEMA_RUN_RESULT,
                'evidence_pack' => self::SCHEMA_EVIDENCE_PACK,
                'adjudication' => self::SCHEMA_ADJUDICATION,
                'report' => self::SCHEMA_REPORT,
            ],
            'required_fields' => [
                'corpus_case' => self::REQUIRED_FIELDS_CORPUS_CASE,
                'run_result' => self::REQUIRED_FIELDS_RUN_RESULT,
                'evidence_pack' => self::REQUIRED_FIELDS_EVIDENCE_PACK,
                'adjudication' => self::REQUIRED_FIELDS_ADJUDICATION,
                'report' => self::REQUIRED_FIELDS_REPORT,
                'report_case' => self::REQUIRED_FIELDS_REPORT_CASE,
            ],
            'difficulty_canon' => [
                'levels' => self::DIFFICULTY_LEVELS,
                'level_score' => self::DIFFICULTY_LEVEL_SCORE,
                'legacy_alias' => self::LEGACY_DIFFICULTY_ALIAS,
                'ambiguity_levels' => self::AMBIGUITY_LEVELS,
                'risk_levels' => self::RISK_LEVELS,
                'reason_min_length' => self::DIFFICULTY_REASON_MIN_LENGTH,
                'multiplier_formula' => 'difficulty_score / 3.0 (L3 == neutral 1.0)',
            ],
            'external_provider_call' => false,
            'separated_from_external_rivals_certification' => true,
        ];
    }
}
