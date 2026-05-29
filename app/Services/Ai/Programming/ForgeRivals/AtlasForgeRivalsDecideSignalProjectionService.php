<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Atlas Forge Rivals · Decide Signal Projection.
 *
 * Read-model projection that turns the Provider Performance Ledger into an
 * advisory signal Atlas Decide can consume. The projection NEVER decides as
 * authority — it ONLY emits measured evidence:
 *
 *   - which provider + model measured ahead for the given (task_category,
 *     role) pair, restricted to valid (hard-gate-clean) entries;
 *   - whether the operator should consider exploring an alternative
 *     (close runner-up, stale evidence, low sample);
 *   - whether `full_power` measured enough delta over `fair` for this category;
 *   - whether the situation requires human review (tie, hard failures).
 *
 * Schema: atlas.forge.rivals.decide_signal.v1
 *
 * Output never claims a winner globally and never unblocks
 * `external_rivals_certification`.
 */
final class AtlasForgeRivalsDecideSignalProjectionService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.decide_signal.v1';

    public const SIGNAL_INSUFFICIENT = 'insufficient_evidence';

    public const SIGNAL_OK = 'ok';

    public const SIGNAL_HUMAN_REVIEW = 'human_review_required';

    /** A gap below this means the two top candidates are too close to confidently prefer one. */
    public const CLOSE_RACE_GAP = 4.0;

    /** A gap at or above this is a material measured advantage when the sample is not weak. */
    public const MATERIAL_ADVANTAGE_GAP = 8.0;

    /** A runner-up cost-per-point at or below this ratio deserves exploration when quality is close. */
    public const COST_EFFICIENT_RUNNER_UP_RATIO = 0.75;

    /** A delta below this means full_power is not worth the extra cost over fair. */
    public const FULL_POWER_WORTH_IT_DELTA = 3.0;

    public function __construct(
        private readonly AtlasForgeRivalsProviderPerformanceLedgerService $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(array $input = []): array
    {
        $taskCategory = trim((string) ($input['task_category'] ?? ''));
        $role = trim((string) ($input['role'] ?? ''));
        $framework = trim((string) ($input['framework'] ?? ''));
        $difficulty = trim((string) ($input['difficulty'] ?? $input['difficulty_level'] ?? ''));
        $runFamily = trim((string) ($input['run_family'] ?? ''));
        $promptMode = trim((string) ($input['prompt_mode'] ?? $input['human_prompt_mode'] ?? ''));

        if ($taskCategory === '' || $role === '') {
            return $this->envelope([
                'signal' => self::SIGNAL_INSUFFICIENT,
                'reason' => ['task_category_and_role_required'],
                'task_category' => $taskCategory === '' ? null : $taskCategory,
                'role' => $role === '' ? null : $role,
                'evidence_count' => 0,
                'top_measured_provider' => null,
                'top_measured_model' => null,
                'confidence' => $this->ledger->confidenceFor(0),
                'latest_run_ids' => [],
                'should_explore_alternative' => false,
                'should_use_full_power' => false,
                'should_require_human_review' => false,
                'advisory_only' => true,
                'should_update_provider_topology' => false,
                'never_changes_atlas_decide_topology' => true,
                'owner_of_model_routing' => 'atlas_decide',
                'routing_effect' => 'none',
                'note' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
            ]);
        }

        $allEntries = $this->ledger->loadEntries();
        $difficultyFilter = $difficulty === '' ? null : strtoupper($difficulty);
        $runFamilyFilter = $runFamily === '' ? null : strtolower($runFamily);
        $promptModeFilter = $promptMode === '' ? null : strtolower($promptMode);
        $relevant = $this->filterRelevant(
            $allEntries,
            $taskCategory,
            $role,
            $framework === '' ? null : $framework,
            $difficultyFilter,
            $runFamilyFilter,
            $promptModeFilter,
        );
        $validEntries = array_values(array_filter($relevant, static fn (array $e): bool => (bool) ($e['valid_for_ranking'] ?? false)));
        $hardFailEntries = array_values(array_filter($relevant, static fn (array $e): bool => ! (bool) ($e['valid_for_ranking'] ?? false)));
        $tieEntries = array_values(array_filter($relevant, static fn (array $e): bool => ($e['outcome'] ?? null) === 'human_review_required'));

        $evidenceCount = count($validEntries);
        $confidence = $this->ledger->confidenceFor($evidenceCount);

        if ($evidenceCount === 0) {
            $shouldHumanReview = $tieEntries !== [] || $hardFailEntries !== [];
            $signal = $shouldHumanReview && $evidenceCount < AtlasForgeRivalsProviderPerformanceLedgerService::CONFIDENCE_MEDIUM_THRESHOLD
                ? self::SIGNAL_HUMAN_REVIEW
                : self::SIGNAL_INSUFFICIENT;

            return $this->envelope([
                'signal' => $signal,
                'reason' => ['no_valid_evidence_for_task_category_role'],
                'task_category' => $taskCategory,
                'role' => $role,
                'framework' => $framework === '' ? null : $framework,
                'difficulty_level' => $difficultyFilter,
                'run_family' => $runFamilyFilter,
                'prompt_mode' => $promptModeFilter,
                'evidence_count' => 0,
                'top_measured_provider' => null,
                'top_measured_model' => null,
                'confidence' => $confidence,
                'alternative_measured_candidate' => null,
                'latest_run_ids' => $this->latestRunIds($relevant, 5),
                'should_explore_alternative' => false,
                'should_use_full_power' => false,
                'should_require_human_review' => $shouldHumanReview,
                'invalid_entries_seen' => count($hardFailEntries),
                'tie_entries_seen' => count($tieEntries),
                'advisory_only' => true,
                'should_update_provider_topology' => false,
                'never_changes_atlas_decide_topology' => true,
                'owner_of_model_routing' => 'atlas_decide',
                'routing_effect' => 'none',
                'note' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
            ]);
        }

        $rankings = $this->rankByProviderModel($validEntries);
        $top = $rankings[0];
        $runnerUp = $rankings[1] ?? null;
        $latestIso = $top['latest_recorded_at'] ?? null;
        $ageDays = $latestIso !== null ? $this->ledger->ageDays($latestIso) : null;
        $stale = $ageDays !== null && $ageDays > AtlasForgeRivalsProviderPerformanceLedgerService::STALE_AGE_DAYS;

        $reasons = [];
        $reasons[] = sprintf(
            'top:provider=%s model=%s avg_score=%.2f median_score=%.2f sample=%d',
            $top['provider'],
            $top['model'],
            $top['average_score_valid'],
            $top['median_score_valid'],
            $top['valid_count']
        );

        $shouldExplore = false;
        $alternative = null;
        $gap = null;
        $advantageBand = 'no_runner_up';
        $valueBand = 'single_candidate';
        if ($runnerUp !== null) {
            $gap = (float) $top['average_score_valid'] - (float) $runnerUp['average_score_valid'];
            $reasons[] = sprintf(
                'gap_vs_runner_up=%.2f (runner_up:provider=%s model=%s)',
                $gap,
                $runnerUp['provider'],
                $runnerUp['model']
            );
            if ($gap < self::CLOSE_RACE_GAP) {
                $shouldExplore = true;
                $reasons[] = 'close_race_below_'.self::CLOSE_RACE_GAP;
                $alternative = [
                    'provider' => $runnerUp['provider'],
                    'model' => $runnerUp['model'],
                    'average_score' => $runnerUp['average_score_valid'],
                    'median_score' => $runnerUp['median_score_valid'],
                    'average_cost_estimate' => $runnerUp['average_cost_estimate_valid'],
                    'average_duration_ms' => $runnerUp['average_duration_ms_valid'],
                    'average_tokens_used' => $runnerUp['average_tokens_used_valid'],
                    'cost_per_score_point' => $runnerUp['cost_per_score_point_valid'],
                    'sample_size' => $runnerUp['valid_count'],
                    'confidence' => $this->ledger->confidenceFor((int) $runnerUp['valid_count']),
                ];
            }
            $advantageBand = $this->advantageBand($gap);
            $valueBand = $this->valueBand($top, $runnerUp, $gap);
            if ($valueBand === 'runner_up_more_cost_efficient_without_material_quality_gap') {
                $shouldExplore = true;
                $reasons[] = 'runner_up_more_cost_efficient_without_material_quality_gap';
                $alternative ??= [
                    'provider' => $runnerUp['provider'],
                    'model' => $runnerUp['model'],
                    'average_score' => $runnerUp['average_score_valid'],
                    'median_score' => $runnerUp['median_score_valid'],
                    'average_cost_estimate' => $runnerUp['average_cost_estimate_valid'],
                    'average_duration_ms' => $runnerUp['average_duration_ms_valid'],
                    'average_tokens_used' => $runnerUp['average_tokens_used_valid'],
                    'cost_per_score_point' => $runnerUp['cost_per_score_point_valid'],
                    'sample_size' => $runnerUp['valid_count'],
                    'confidence' => $this->ledger->confidenceFor((int) $runnerUp['valid_count']),
                ];
            }
        }
        if ($confidence === AtlasForgeRivalsProviderPerformanceLedgerService::CONFIDENCE_LOW) {
            $shouldExplore = true;
            $reasons[] = 'low_confidence_sample_size_below_'.AtlasForgeRivalsProviderPerformanceLedgerService::CONFIDENCE_MEDIUM_THRESHOLD;
        }
        if ($stale) {
            $shouldExplore = true;
            $reasons[] = 'stale_data_latest_age_days='.$ageDays;
        }
        if (($top['score_stability'] ?? null) === 'unstable') {
            $shouldExplore = true;
            $reasons[] = 'unstable_score_stddev='.$top['score_stddev'];
        }

        $shouldUseFullPower = $this->shouldUseFullPower($validEntries, $reasons);
        $shouldHumanReview = $tieEntries !== [] || $hardFailEntries !== [];

        if ($shouldHumanReview) {
            $reasons[] = $tieEntries !== []
                ? 'recent_tie_entries='.count($tieEntries)
                : 'recent_invalid_entries='.count($hardFailEntries);
        }

        $signal = $shouldHumanReview && $evidenceCount < AtlasForgeRivalsProviderPerformanceLedgerService::CONFIDENCE_MEDIUM_THRESHOLD
            ? self::SIGNAL_HUMAN_REVIEW
            : self::SIGNAL_OK;
        $decisionReadiness = $this->decisionReadiness($signal, $confidence, $shouldExplore, $advantageBand, (string) ($top['score_stability'] ?? ''));

        return $this->envelope([
            'signal' => $signal,
            'reason' => $reasons,
            'task_category' => $taskCategory,
            'role' => $role,
            'framework' => $framework === '' ? null : $framework,
            'difficulty_level' => $difficultyFilter,
            'run_family' => $runFamilyFilter,
            'prompt_mode' => $promptModeFilter,
            'top_measured_provider' => $top['provider'],
            'top_measured_model' => $top['model'],
            'top_measured_average_score' => $top['average_score_valid'],
            'top_measured_median_score' => $top['median_score_valid'],
            'top_score_stddev' => $top['score_stddev'],
            'top_confidence_interval_95' => $top['confidence_interval_95'],
            'top_score_stability' => $top['score_stability'],
            'top_average_cost_estimate' => $top['average_cost_estimate_valid'],
            'top_average_duration_ms' => $top['average_duration_ms_valid'],
            'top_average_tokens_used' => $top['average_tokens_used_valid'],
            'top_cost_per_score_point' => $top['cost_per_score_point_valid'],
            'top_gap_vs_runner_up' => $gap,
            'top_advantage_band' => $advantageBand,
            'top_value_band' => $valueBand,
            'decision_readiness' => $decisionReadiness,
            'evidence_count' => $top['valid_count'],
            'confidence' => $confidence,
            'alternative_measured_candidate' => $alternative,
            'latest_run_ids' => array_slice((array) ($top['latest_run_ids'] ?? []), 0, 5),
            'latest_recorded_at' => $latestIso,
            'latest_age_days' => $ageDays,
            'stale_data' => $stale,
            'invalid_entries_seen' => count($hardFailEntries),
            'tie_entries_seen' => count($tieEntries),
            'should_explore_alternative' => $shouldExplore,
            'should_use_full_power' => $shouldUseFullPower,
            'should_require_human_review' => $shouldHumanReview,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'note' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
        ]);
    }

    /**
     * Build a broad advisory map of "which measured provider/model looks best
     * for which task segment" without making any routing decision.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function map(array $input = []): array
    {
        $snapshot = $this->ledger->snapshot($input);
        $rows = (array) data_get($snapshot, 'aggregates.by_task_category_difficulty_role_model', []);
        $validRows = array_values(array_filter($rows, static fn (mixed $row): bool => is_array($row)
            && (int) ($row['valid_count'] ?? 0) > 0
            && is_numeric($row['average_score_valid'] ?? null)));

        $segments = [];
        foreach ($validRows as $row) {
            $segmentKey = implode('|', [
                (string) ($row['task_category'] ?? 'unknown'),
                (string) ($row['difficulty_level'] ?? 'unknown'),
                (string) ($row['role'] ?? 'unknown'),
            ]);
            $segments[$segmentKey] ??= [];
            $segments[$segmentKey][] = $row;
        }

        $recommendations = [];
        foreach ($segments as $segmentKey => $candidates) {
            usort($candidates, static function (array $a, array $b): int {
                $scoreCompare = ((float) ($b['average_score_valid'] ?? 0.0)) <=> ((float) ($a['average_score_valid'] ?? 0.0));
                if ($scoreCompare !== 0) {
                    return $scoreCompare;
                }

                return ((int) ($b['valid_count'] ?? 0)) <=> ((int) ($a['valid_count'] ?? 0));
            });

            [$category, $difficulty, $role] = explode('|', $segmentKey, 3) + ['unknown', 'unknown', 'unknown'];
            $top = $candidates[0];
            $runnerUp = $candidates[1] ?? null;
            $gap = $runnerUp === null
                ? null
                : round((float) ($top['average_score_valid'] ?? 0.0) - (float) ($runnerUp['average_score_valid'] ?? 0.0), 4);
            $advantageBand = $gap === null ? 'no_runner_up' : $this->advantageBand($gap);
            $confidence = (string) ($top['confidence'] ?? $this->ledger->confidenceFor((int) ($top['valid_count'] ?? 0)));
            $stability = (string) ($top['score_stability'] ?? 'insufficient_sample');
            $candidateProvider = (string) ($top['provider'] ?? 'unknown');
            $candidateModel = (string) ($top['model'] ?? 'unknown');
            $candidateResolutionBlockers = $this->candidateResolutionBlockers($candidateProvider, $candidateModel);
            $shouldExplore = $confidence === AtlasForgeRivalsProviderPerformanceLedgerService::CONFIDENCE_LOW
                || $advantageBand === 'technical_tie'
                || $stability === 'unstable'
                || (bool) ($top['stale_data'] ?? false);
            $decisionReadiness = $this->decisionReadiness(
                self::SIGNAL_OK,
                $confidence,
                $shouldExplore,
                $advantageBand,
                $stability,
            );
            if ($candidateResolutionBlockers !== []) {
                $decisionReadiness = 'insufficient_evidence';
                $shouldExplore = false;
            }

            $recommendations[] = [
                'segment_key' => $segmentKey,
                'task_category' => $category,
                'difficulty_level' => $difficulty === 'unknown' ? null : $difficulty,
                'role' => $role,
                'top_measured_provider' => $candidateProvider,
                'top_measured_model' => $candidateModel,
                'candidate_resolution_status' => $candidateResolutionBlockers === [] ? 'resolved' : 'blocked_unresolved_provider_model',
                'candidate_resolution_blockers' => $candidateResolutionBlockers,
                'top_average_score' => $top['average_score_valid'] ?? null,
                'top_median_score' => $top['median_score_valid'] ?? null,
                'top_valid_count' => (int) ($top['valid_count'] ?? 0),
                'top_confidence' => $confidence,
                'top_score_stability' => $stability,
                'top_confidence_interval_95' => $top['confidence_interval_95'] ?? null,
                'top_average_cost_estimate' => $top['average_cost_estimate_valid'] ?? null,
                'top_average_duration_ms' => $top['average_duration_ms_valid'] ?? null,
                'top_average_tokens_used' => $top['average_tokens_used_valid'] ?? null,
                'top_cost_per_score_point' => $top['cost_per_score_point_valid'] ?? null,
                'top_latest_run_ids' => array_slice((array) ($top['latest_run_ids'] ?? []), 0, 5),
                'runner_up' => $runnerUp === null ? null : [
                    'provider' => $runnerUp['provider'] ?? 'unknown',
                    'model' => $runnerUp['model'] ?? 'unknown',
                    'average_score' => $runnerUp['average_score_valid'] ?? null,
                    'valid_count' => (int) ($runnerUp['valid_count'] ?? 0),
                    'confidence' => $runnerUp['confidence'] ?? $this->ledger->confidenceFor((int) ($runnerUp['valid_count'] ?? 0)),
                    'average_cost_estimate' => $runnerUp['average_cost_estimate_valid'] ?? null,
                    'average_duration_ms' => $runnerUp['average_duration_ms_valid'] ?? null,
                    'average_tokens_used' => $runnerUp['average_tokens_used_valid'] ?? null,
                ],
                'gap_vs_runner_up' => $gap,
                'advantage_band' => $advantageBand,
                'decision_readiness' => $decisionReadiness,
                'should_explore_alternative' => $shouldExplore,
                'statistical_repeat_ready' => (bool) ($top['statistical_repeat_ready'] ?? false),
                'missing_valid_repetitions' => (int) ($top['missing_valid_repetitions'] ?? 0),
                'claim_ready' => false,
                'advisory_only' => true,
                'should_update_provider_topology' => false,
                'never_changes_atlas_decide_topology' => true,
                'owner_of_model_routing' => 'atlas_decide',
                'routing_effect' => 'none',
            ];
        }

        usort($recommendations, static function (array $a, array $b): int {
            $category = strcmp((string) ($a['task_category'] ?? ''), (string) ($b['task_category'] ?? ''));
            if ($category !== 0) {
                return $category;
            }
            $difficulty = strcmp((string) ($a['difficulty_level'] ?? ''), (string) ($b['difficulty_level'] ?? ''));
            if ($difficulty !== 0) {
                return $difficulty;
            }

            return strcmp((string) ($a['role'] ?? ''), (string) ($b['role'] ?? ''));
        });
        $modelProfiles = $this->modelProfiles($recommendations);
        $segmentAdvisory = $this->segmentAdvisory($recommendations);
        $learningPacket = $this->atlasDecideLearningPacket($snapshot, $modelProfiles, $segmentAdvisory);

        return [
            'status' => 'ok',
            'schema_version' => 'atlas.forge.rivals.decide_model_intelligence_map.v1',
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'signal' => $recommendations === [] ? self::SIGNAL_INSUFFICIENT : self::SIGNAL_OK,
            'filters' => $snapshot['filters'] ?? [],
            'total_ledger_entries' => $snapshot['total_entries'] ?? 0,
            'filtered_ledger_entries' => $snapshot['filtered_entries'] ?? 0,
            'segment_count' => count($recommendations),
            'segments' => $recommendations,
            'atlas_decide_segment_advisory_count' => count($segmentAdvisory),
            'atlas_decide_segment_advisory' => $segmentAdvisory,
            'atlas_decide_learning_packet' => $learningPacket,
            'model_profile_count' => count($modelProfiles),
            'model_profiles' => $modelProfiles,
            'statistical_repeat_readiness' => data_get($snapshot, 'aggregates.statistical_repeat_readiness'),
            'external_claim_readiness' => $snapshot['external_claim_readiness'] ?? null,
            'statistical_repeat_measurement_plan' => data_get($snapshot, 'external_claim_readiness.statistical_repeat_measurement_plan'),
            'invalid_entries_excluded_from_ranking' => true,
            'claim_ready' => false,
            'external_claim_allowed' => false,
            'score_or_claim_allowed' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'separated_from_external_rivals_certification' => true,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'canonical_phrase' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
            'next_command' => 'php artisan atlas:forge:rivals decide-map --json',
        ];
    }

    /**
     * Return only the Atlas Decide learning packet, without requiring callers
     * to parse the full segment map. This is still a read-only advisory view.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function learningPacket(array $input = []): array
    {
        $map = $this->map($input);
        $packet = (array) ($map['atlas_decide_learning_packet'] ?? []);
        $learningStatus = (string) ($packet['status'] ?? self::SIGNAL_INSUFFICIENT);
        unset($packet['status']);

        return array_replace($packet, [
            'status' => 'ok',
            'schema_version' => $packet['schema_version'] ?? 'atlas.forge.rivals.atlas_decide_learning_packet.v1',
            'generated_at' => $map['generated_at'] ?? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'learning_status' => $learningStatus,
            'filters' => $map['filters'] ?? [],
            'total_ledger_entries' => $map['total_ledger_entries'] ?? 0,
            'filtered_ledger_entries' => $map['filtered_ledger_entries'] ?? 0,
            'segment_count' => $map['segment_count'] ?? 0,
            'model_profile_count' => $map['model_profile_count'] ?? 0,
            'next_command' => 'php artisan atlas:forge:rivals decide-learning --json',
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'claim_ready' => false,
            'external_claim_allowed' => false,
            'score_or_claim_allowed' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'canonical_phrase' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
        ]);
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return list<array<string,mixed>>
     */
    private function filterRelevant(
        array $entries,
        string $taskCategory,
        string $role,
        ?string $framework,
        ?string $difficulty,
        ?string $runFamily,
        ?string $promptMode,
    ): array {
        $tc = strtolower($taskCategory);
        $r = strtolower($role);
        $fw = $framework === null ? null : strtolower($framework);
        $df = $difficulty === null ? null : strtoupper($difficulty);
        $rf = $runFamily === null ? null : strtolower($runFamily);
        $pm = $promptMode === null ? null : strtolower($promptMode);

        return array_values(array_filter($entries, static function (array $e) use ($tc, $r, $fw, $df, $rf, $pm): bool {
            if (($e['task_category'] ?? null) !== $tc) {
                return false;
            }
            if (($e['role'] ?? null) !== $r) {
                return false;
            }
            if ($fw !== null && ($e['framework'] ?? null) !== $fw) {
                return false;
            }
            if ($df !== null && ($e['difficulty_level'] ?? null) !== $df) {
                return false;
            }
            if ($rf !== null && ($e['run_family'] ?? null) !== $rf) {
                return false;
            }
            if ($pm !== null && ($e['prompt_mode'] ?? null) !== $pm) {
                return false;
            }

            return true;
        }));
    }

    /**
     * @param  list<array<string,mixed>>  $valid
     * @return list<array<string,mixed>>
     */
    private function rankByProviderModel(array $valid): array
    {
        $buckets = [];
        foreach ($valid as $entry) {
            $key = ($entry['provider'] ?? 'unknown').':'.($entry['model'] ?? 'unknown');
            $buckets[$key] ??= [];
            $buckets[$key][] = $entry;
        }
        $rows = [];
        foreach ($buckets as $key => $items) {
            $scores = array_map(static fn (array $i): float => (float) ($i['score_total'] ?? 0), $items);
            $stats = $this->ledger->scoreStats($scores);
            $avgScore = round(array_sum($scores) / max(1, count($scores)), 4);
            $costs = array_values(array_filter(
                array_map(static fn (array $i): ?float => isset($i['cost_estimate']) ? (float) $i['cost_estimate'] : null, $items),
                static fn (?float $value): bool => $value !== null,
            ));
            $durations = array_map(static fn (array $i): int => (int) ($i['duration_ms'] ?? 0), $items);
            $tokens = array_map(static fn (array $i): int => (int) ($i['tokens_used'] ?? 0), $items);
            $avgCost = $costs === [] ? null : round(array_sum($costs) / count($costs), 6);
            $latestIso = '';
            foreach ($items as $i) {
                $iso = (string) ($i['recorded_at'] ?? '');
                if ($iso !== '' && $iso > $latestIso) {
                    $latestIso = $iso;
                }
            }
            $sorted = $items;
            usort($sorted, static fn (array $a, array $b): int => strcmp((string) ($b['recorded_at'] ?? ''), (string) ($a['recorded_at'] ?? '')));
            $latestRunIds = [];
            foreach (array_slice($sorted, 0, 5) as $i) {
                $rid = (string) ($i['run_id'] ?? '');
                if ($rid !== '' && ! in_array($rid, $latestRunIds, true)) {
                    $latestRunIds[] = $rid;
                }
            }
            [$provider, $model] = explode(':', $key, 2) + ['unknown', 'unknown'];
            $rows[] = [
                'provider' => $provider,
                'model' => $model,
                'valid_count' => count($items),
                'average_score_valid' => $avgScore,
                'median_score_valid' => $stats['median_score'],
                'score_stddev' => $stats['score_stddev'],
                'confidence_interval_95' => $stats['confidence_interval_95'],
                'score_stability' => $stats['score_stability'],
                'average_cost_estimate_valid' => $avgCost,
                'average_duration_ms_valid' => $durations === [] ? null : (int) round(array_sum($durations) / count($durations)),
                'average_tokens_used_valid' => $tokens === [] ? null : (int) round(array_sum($tokens) / count($tokens)),
                'cost_per_score_point_valid' => $avgCost === null || $avgScore <= 0.0 ? null : round($avgCost / $avgScore, 8),
                'latest_recorded_at' => $latestIso === '' ? null : $latestIso,
                'latest_run_ids' => $latestRunIds,
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            if ($a['average_score_valid'] === $b['average_score_valid']) {
                return $b['valid_count'] <=> $a['valid_count'];
            }

            return $b['average_score_valid'] <=> $a['average_score_valid'];
        });

        return $rows;
    }

    private function advantageBand(float $gap): string
    {
        if ($gap < self::CLOSE_RACE_GAP) {
            return 'technical_tie';
        }
        if ($gap >= self::MATERIAL_ADVANTAGE_GAP) {
            return 'material_advantage';
        }

        return 'directional_advantage';
    }

    /**
     * @param  array<string,mixed>  $top
     * @param  array<string,mixed>  $runnerUp
     */
    private function valueBand(array $top, array $runnerUp, float $gap): string
    {
        $topCostPerPoint = $top['cost_per_score_point_valid'] ?? null;
        $runnerUpCostPerPoint = $runnerUp['cost_per_score_point_valid'] ?? null;
        if (! is_float($topCostPerPoint) || ! is_float($runnerUpCostPerPoint) || $topCostPerPoint <= 0.0) {
            return 'cost_unknown';
        }
        if ($gap < self::MATERIAL_ADVANTAGE_GAP && $runnerUpCostPerPoint <= ($topCostPerPoint * self::COST_EFFICIENT_RUNNER_UP_RATIO)) {
            return 'runner_up_more_cost_efficient_without_material_quality_gap';
        }
        if ($topCostPerPoint <= $runnerUpCostPerPoint) {
            return 'top_more_cost_efficient';
        }

        return 'top_higher_quality_more_expensive';
    }

    private function decisionReadiness(string $signal, string $confidence, bool $shouldExplore, string $advantageBand, string $stability): string
    {
        if ($signal === self::SIGNAL_HUMAN_REVIEW) {
            return 'human_review_required';
        }
        if ($confidence === AtlasForgeRivalsProviderPerformanceLedgerService::CONFIDENCE_INSUFFICIENT) {
            return 'insufficient_evidence';
        }
        if ($shouldExplore || $advantageBand === 'technical_tie' || $stability === 'unstable') {
            return 'explore_before_prefer';
        }
        if ($advantageBand === 'material_advantage' && $confidence !== AtlasForgeRivalsProviderPerformanceLedgerService::CONFIDENCE_LOW) {
            return 'strong_directional_signal';
        }

        return 'directional_signal';
    }

    /**
     * @param  array<string,mixed>  $segment
     */
    private function segmentHasResolvedCandidate(array $segment): bool
    {
        return $this->candidateResolutionBlockers(
            (string) ($segment['top_measured_provider'] ?? ''),
            (string) ($segment['top_measured_model'] ?? ''),
        ) === [];
    }

    /**
     * @return list<string>
     */
    private function candidateResolutionBlockers(string $provider, string $model): array
    {
        $blockers = [];
        $provider = strtolower(trim($provider));
        $model = strtolower(trim($model));

        if ($provider === '' || $provider === 'unknown') {
            $blockers[] = 'provider_required_for_atlas_decide_learning';
        }
        if ($model === '' || $model === 'unknown') {
            $blockers[] = 'model_required_for_atlas_decide_learning';
        }

        return $blockers;
    }

    /**
     * Build provider/model profile cards from the segment map. These cards are
     * the Atlas Decide-facing explanation layer: what each measured model seems
     * good at, where the signal is weak, and whether exploration is safer than
     * preference.
     *
     * @param  list<array<string,mixed>>  $segments
     * @return list<array<string,mixed>>
     */
    private function modelProfiles(array $segments): array
    {
        $profiles = [];
        foreach ($segments as $segment) {
            if (! $this->segmentHasResolvedCandidate($segment)) {
                continue;
            }

            $provider = (string) ($segment['top_measured_provider'] ?? 'unknown');
            $model = (string) ($segment['top_measured_model'] ?? 'unknown');
            $key = $provider.':'.$model;
            $profiles[$key] ??= [
                'provider' => $provider,
                'model' => $model,
                'segment_count' => 0,
                'strong_segment_count' => 0,
                'directional_segment_count' => 0,
                'explore_before_prefer_segment_count' => 0,
                'technical_tie_segment_count' => 0,
                'total_valid_count' => 0,
                'score_sum_weighted' => 0.0,
                'cost_sum_weighted' => 0.0,
                'duration_sum_weighted' => 0.0,
                'token_sum_weighted' => 0.0,
                'cost_weight' => 0,
                'duration_weight' => 0,
                'token_weight' => 0,
                'best_segments' => [],
                'caution_segments' => [],
                'categories' => [],
                'difficulties' => [],
            ];

            $validCount = max(1, (int) ($segment['top_valid_count'] ?? 0));
            $profiles[$key]['segment_count']++;
            $profiles[$key]['total_valid_count'] += $validCount;
            $profiles[$key]['score_sum_weighted'] += ((float) ($segment['top_average_score'] ?? 0.0)) * $validCount;

            foreach ([
                'top_average_cost_estimate' => 'cost',
                'top_average_duration_ms' => 'duration',
                'top_average_tokens_used' => 'token',
            ] as $field => $prefix) {
                if (is_numeric($segment[$field] ?? null)) {
                    $profiles[$key][$prefix.'_sum_weighted'] += ((float) $segment[$field]) * $validCount;
                    $profiles[$key][$prefix.'_weight'] += $validCount;
                }
            }

            $decisionReadiness = (string) ($segment['decision_readiness'] ?? 'insufficient_evidence');
            $advantageBand = (string) ($segment['advantage_band'] ?? 'unknown');
            if ($decisionReadiness === 'strong_directional_signal') {
                $profiles[$key]['strong_segment_count']++;
            } elseif ($decisionReadiness === 'directional_signal') {
                $profiles[$key]['directional_segment_count']++;
            } elseif ($decisionReadiness === 'explore_before_prefer') {
                $profiles[$key]['explore_before_prefer_segment_count']++;
            }
            if ($advantageBand === 'technical_tie') {
                $profiles[$key]['technical_tie_segment_count']++;
            }

            $profileSegment = [
                'segment_key' => $segment['segment_key'] ?? null,
                'task_category' => $segment['task_category'] ?? null,
                'difficulty_level' => $segment['difficulty_level'] ?? null,
                'role' => $segment['role'] ?? null,
                'average_score' => $segment['top_average_score'] ?? null,
                'gap_vs_runner_up' => $segment['gap_vs_runner_up'] ?? null,
                'advantage_band' => $advantageBand,
                'decision_readiness' => $decisionReadiness,
                'confidence' => $segment['top_confidence'] ?? null,
                'score_stability' => $segment['top_score_stability'] ?? null,
            ];
            if (in_array($decisionReadiness, ['strong_directional_signal', 'directional_signal'], true)) {
                $profiles[$key]['best_segments'][] = $profileSegment;
            } else {
                $profiles[$key]['caution_segments'][] = $profileSegment;
            }

            $category = (string) ($segment['task_category'] ?? '');
            if ($category !== '' && ! in_array($category, $profiles[$key]['categories'], true)) {
                $profiles[$key]['categories'][] = $category;
            }
            $difficulty = (string) ($segment['difficulty_level'] ?? '');
            if ($difficulty !== '' && ! in_array($difficulty, $profiles[$key]['difficulties'], true)) {
                $profiles[$key]['difficulties'][] = $difficulty;
            }
        }

        $out = [];
        foreach ($profiles as $profile) {
            $totalValid = max(1, (int) $profile['total_valid_count']);
            $profile['average_score_weighted'] = round(((float) $profile['score_sum_weighted']) / $totalValid, 4);
            $profile['average_cost_estimate_weighted'] = $profile['cost_weight'] > 0
                ? round(((float) $profile['cost_sum_weighted']) / (int) $profile['cost_weight'], 6)
                : null;
            $profile['average_duration_ms_weighted'] = $profile['duration_weight'] > 0
                ? (int) round(((float) $profile['duration_sum_weighted']) / (int) $profile['duration_weight'])
                : null;
            $profile['average_tokens_used_weighted'] = $profile['token_weight'] > 0
                ? (int) round(((float) $profile['token_sum_weighted']) / (int) $profile['token_weight'])
                : null;
            $profile['decision_posture'] = $this->modelProfilePosture($profile);
            $profile['atlas_decide_policy_hint'] = $this->modelProfilePolicyHint((string) $profile['decision_posture']);
            $profile['best_segments'] = array_slice($profile['best_segments'], 0, 10);
            $profile['caution_segments'] = array_slice($profile['caution_segments'], 0, 10);
            $profile['advisory_only'] = true;
            $profile['should_update_provider_topology'] = false;
            $profile['never_changes_atlas_decide_topology'] = true;
            $profile['owner_of_model_routing'] = 'atlas_decide';
            $profile['routing_effect'] = 'none';
            unset(
                $profile['score_sum_weighted'],
                $profile['cost_sum_weighted'],
                $profile['duration_sum_weighted'],
                $profile['token_sum_weighted'],
                $profile['cost_weight'],
                $profile['duration_weight'],
                $profile['token_weight'],
            );
            $out[] = $profile;
        }

        usort($out, static function (array $a, array $b): int {
            $strong = ((int) ($b['strong_segment_count'] ?? 0)) <=> ((int) ($a['strong_segment_count'] ?? 0));
            if ($strong !== 0) {
                return $strong;
            }
            $segments = ((int) ($b['segment_count'] ?? 0)) <=> ((int) ($a['segment_count'] ?? 0));
            if ($segments !== 0) {
                return $segments;
            }

            return ((float) ($b['average_score_weighted'] ?? 0.0)) <=> ((float) ($a['average_score_weighted'] ?? 0.0));
        });

        return $out;
    }

    /**
     * Compact, advisory-only cards that tell Atlas Decide what to do with each
     * measured segment without forcing it to infer routing posture from scores.
     *
     * @param  list<array<string,mixed>>  $segments
     * @return list<array<string,mixed>>
     */
    private function segmentAdvisory(array $segments): array
    {
        return array_values(array_map(function (array $segment): array {
            $readiness = (string) ($segment['decision_readiness'] ?? 'insufficient_evidence');
            $policyHint = $this->segmentPolicyHint($readiness);

            return [
                'segment_key' => $segment['segment_key'] ?? null,
                'task_category' => $segment['task_category'] ?? null,
                'difficulty_level' => $segment['difficulty_level'] ?? null,
                'role' => $segment['role'] ?? null,
                'candidate_provider' => $segment['top_measured_provider'] ?? null,
                'candidate_model' => $segment['top_measured_model'] ?? null,
                'runner_up_provider' => $segment['runner_up']['provider'] ?? null,
                'runner_up_model' => $segment['runner_up']['model'] ?? null,
                'decision_readiness' => $readiness,
                'policy_hint' => $policyHint,
                'candidate_resolution_status' => $segment['candidate_resolution_status'] ?? 'resolved',
                'candidate_resolution_blockers' => $segment['candidate_resolution_blockers'] ?? [],
                'next_action' => $this->segmentNextAction(
                    $readiness,
                    (int) ($segment['missing_valid_repetitions'] ?? 0),
                    (array) ($segment['candidate_resolution_blockers'] ?? []),
                ),
                'supporting_evidence' => $this->segmentSupportingEvidence($segment),
                'why' => array_values(array_filter([
                    'advantage_band='.($segment['advantage_band'] ?? 'unknown'),
                    'confidence='.($segment['top_confidence'] ?? 'unknown'),
                    'score_stability='.($segment['top_score_stability'] ?? 'unknown'),
                    'valid_count='.((int) ($segment['top_valid_count'] ?? 0)),
                    ((int) ($segment['missing_valid_repetitions'] ?? 0)) > 0
                        ? 'missing_valid_repetitions='.(int) ($segment['missing_valid_repetitions'] ?? 0)
                        : null,
                ])),
                'score_or_claim_allowed' => false,
                'advisory_only' => true,
                'should_update_provider_topology' => false,
                'never_changes_atlas_decide_topology' => true,
                'owner_of_model_routing' => 'atlas_decide',
                'routing_effect' => 'none',
            ];
        }, $segments));
    }

    private function segmentPolicyHint(string $readiness): string
    {
        return match ($readiness) {
            'strong_directional_signal' => 'atlas_decide_may_consider_segment_preference_without_topology_mutation',
            'directional_signal' => 'atlas_decide_may_consider_shadow_preference_for_this_segment',
            'explore_before_prefer' => 'atlas_decide_should_explore_or_shadow_before_preference',
            'human_review_required' => 'atlas_decide_should_require_human_review_before_using_signal',
            default => 'atlas_decide_should_not_use_this_segment_for_preference_yet',
        };
    }

    /**
     * @param  list<string>  $candidateResolutionBlockers
     */
    private function segmentNextAction(string $readiness, int $missingValidRepetitions, array $candidateResolutionBlockers = []): string
    {
        if ($candidateResolutionBlockers !== []) {
            return 'repair_provider_model_metadata';
        }

        if ($missingValidRepetitions > 0) {
            return 'run_statistical_repeat_for_segment';
        }

        return match ($readiness) {
            'strong_directional_signal' => 'eligible_for_atlas_decide_shadow_policy_review',
            'directional_signal' => 'shadow_before_preference',
            'explore_before_prefer' => 'collect_more_evidence_or_compare_runner_up_cost',
            'human_review_required' => 'human_review_required',
            default => 'collect_valid_replayable_evidence',
        };
    }

    /**
     * @param  array<string,mixed>  $segment
     * @return array<string,mixed>
     */
    private function segmentSupportingEvidence(array $segment): array
    {
        $runnerUp = (array) ($segment['runner_up'] ?? []);

        return [
            'schema_version' => 'atlas.forge.rivals.segment_supporting_evidence.v1',
            'valid_evidence_count' => (int) ($segment['top_valid_count'] ?? 0),
            'confidence' => $segment['top_confidence'] ?? null,
            'candidate_average_score' => $segment['top_average_score'] ?? null,
            'candidate_median_score' => $segment['top_median_score'] ?? null,
            'candidate_score_stability' => $segment['top_score_stability'] ?? null,
            'candidate_confidence_interval_95' => $segment['top_confidence_interval_95'] ?? null,
            'runner_up_average_score' => $runnerUp['average_score'] ?? null,
            'runner_up_valid_evidence_count' => $runnerUp['valid_count'] ?? null,
            'gap_vs_runner_up' => $segment['gap_vs_runner_up'] ?? null,
            'advantage_band' => $segment['advantage_band'] ?? null,
            'average_cost_estimate' => $segment['top_average_cost_estimate'] ?? null,
            'average_duration_ms' => $segment['top_average_duration_ms'] ?? null,
            'average_tokens_used' => $segment['top_average_tokens_used'] ?? null,
            'cost_per_score_point' => $segment['top_cost_per_score_point'] ?? null,
            'latest_run_ids' => array_slice((array) ($segment['top_latest_run_ids'] ?? []), 0, 5),
            'candidate_resolution_status' => $segment['candidate_resolution_status'] ?? 'resolved',
            'candidate_resolution_blockers' => $segment['candidate_resolution_blockers'] ?? [],
            'missing_valid_repetitions' => (int) ($segment['missing_valid_repetitions'] ?? 0),
            'statistical_repeat_ready' => (bool) ($segment['statistical_repeat_ready'] ?? false),
            'evidence_required_before_preference' => [
                'replay_green',
                'matrix_green',
                'scorecard_present',
                'statistical_repeat_complete',
                'atlas_decide_policy_review',
            ],
            'score_or_claim_allowed' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
        ];
    }

    /**
     * A stable read-model packet for Atlas Decide. It is deliberately explicit
     * about what may be learned now versus what must remain shadow/exploration.
     *
     * @param  array<string,mixed>  $snapshot
     * @param  list<array<string,mixed>>  $modelProfiles
     * @param  list<array<string,mixed>>  $segmentAdvisory
     * @return array<string,mixed>
     */
    private function atlasDecideLearningPacket(array $snapshot, array $modelProfiles, array $segmentAdvisory): array
    {
        $external = (array) ($snapshot['external_claim_readiness'] ?? []);
        $repeatPlan = (array) data_get($snapshot, 'external_claim_readiness.statistical_repeat_measurement_plan', []);
        $learnable = array_values(array_filter(
            $segmentAdvisory,
            static fn (array $row): bool => in_array((string) ($row['decision_readiness'] ?? ''), ['strong_directional_signal', 'directional_signal'], true)
        ));
        $shadowOnly = array_values(array_filter(
            $segmentAdvisory,
            static fn (array $row): bool => ($row['decision_readiness'] ?? null) === 'explore_before_prefer'
        ));
        $blocked = array_values(array_filter(
            $segmentAdvisory,
            static fn (array $row): bool => in_array((string) ($row['decision_readiness'] ?? ''), ['human_review_required', 'insufficient_evidence'], true)
        ));
        $repeatTargets = array_values(array_filter(
            $segmentAdvisory,
            static fn (array $row): bool => ($row['next_action'] ?? null) === 'run_statistical_repeat_for_segment'
        ));
        $modelFitMatrix = $this->modelFitMatrix($segmentAdvisory);
        $modelUsagePlaybook = $this->modelUsagePlaybook($modelProfiles, $modelFitMatrix, $repeatTargets);
        $preferenceCandidates = $this->preferenceCandidates($learnable);
        $dimensionalQuality = $this->dimensionalSignalQuality($segmentAdvisory, $repeatPlan);
        $dimensionalRepairPlan = $this->dimensionalSignalRepairPlan($dimensionalQuality);
        $blockedPreferenceReasons = $this->blockedPreferenceReasons($external, $repeatPlan, $learnable, $shadowOnly, $blocked, $repeatTargets, $dimensionalQuality);
        $allowedLearningEffect = $learnable === []
            ? 'advisory_shadow_signal_only'
            : 'advisory_policy_review_candidate_only';
        $repeatGapSummary = $this->statisticalRepeatGapSummary($repeatPlan, $repeatTargets);
        $atlasDecideLearningEligibility = (array) data_get($snapshot, 'aggregates.atlas_decide_learning_eligibility', []);
        $consumptionSummary = $this->atlasDecideConsumptionSummary(
            $preferenceCandidates,
            $shadowOnly,
            $blocked,
            $repeatTargets,
            $blockedPreferenceReasons,
            $allowedLearningEffect,
            $repeatGapSummary,
        );

        $packet = [
            'schema_version' => 'atlas.forge.rivals.atlas_decide_learning_packet.v1',
            'status' => $learnable === [] ? 'exploration_only' : 'has_directional_learning_candidates',
            'operator_summary' => $this->operatorSummary($learnable, $shadowOnly, $blocked, $repeatTargets),
            'external_claim_readiness_status' => $external['status'] ?? 'blocked_until_reproducible_evidence_complete',
            'statistical_repeat_plan_status' => $repeatPlan['status'] ?? 'needs_repetition',
            'total_segments' => count($segmentAdvisory),
            'learnable_segment_count' => count($learnable),
            'shadow_only_segment_count' => count($shadowOnly),
            'blocked_segment_count' => count($blocked),
            'statistical_repeat_target_count' => count($repeatTargets),
            'model_profile_count' => count($modelProfiles),
            'top_model_profiles_preview' => array_slice(array_map(static fn (array $profile): array => [
                'provider' => $profile['provider'] ?? null,
                'model' => $profile['model'] ?? null,
                'decision_posture' => $profile['decision_posture'] ?? null,
                'atlas_decide_policy_hint' => $profile['atlas_decide_policy_hint'] ?? null,
                'categories' => $profile['categories'] ?? [],
                'difficulties' => $profile['difficulties'] ?? [],
                'segment_count' => $profile['segment_count'] ?? 0,
            ], $modelProfiles), 0, 5),
            'preference_candidates' => $preferenceCandidates,
            'preference_candidate_count' => count($preferenceCandidates),
            'blocked_preference_reasons' => $blockedPreferenceReasons,
            'statistical_repeat_gap_summary' => $repeatGapSummary,
            'atlas_decide_learning_eligibility' => $atlasDecideLearningEligibility,
            'atlas_decide_consumption_summary' => $consumptionSummary,
            'dimensional_signal_quality' => $dimensionalQuality,
            'dimensional_signal_repair_plan' => $dimensionalRepairPlan,
            'shadow_policy_candidates' => $this->shadowPolicyCandidates($shadowOnly),
            'do_not_prefer_until' => [
                'statistical_repeat_ready' => ($repeatPlan['status'] ?? null) === 'complete',
                'external_claim_readiness_status' => $external['status'] ?? 'blocked_until_reproducible_evidence_complete',
                'required_before_preference' => [
                    'replay_green',
                    'matrix_green',
                    'scorecard_present',
                    'complete_segment_dimensions',
                    'statistical_repeat_complete',
                    'atlas_decide_policy_review',
                ],
            ],
            'next_segments_to_repeat_preview' => array_slice(array_map(static fn (array $row): array => [
                'segment_key' => $row['segment_key'] ?? null,
                'candidate_provider' => $row['candidate_provider'] ?? null,
                'candidate_model' => $row['candidate_model'] ?? null,
                'why' => $row['why'] ?? [],
            ], $repeatTargets), 0, 10),
            'model_fit_matrix' => $modelFitMatrix,
            'category_fit_summary' => $this->categoryFitSummary($modelFitMatrix),
            'model_usage_playbook' => $modelUsagePlaybook,
            'evidence_collection_plan' => $this->evidenceCollectionPlan($repeatTargets, $shadowOnly, $blocked),
            'allowed_learning_effect' => $allowedLearningEffect,
            'forbidden_learning_effects' => [
                'provider_topology_update',
                'automatic_model_routing_change',
                'external_claim',
                'score_claim_without_replay_matrix_repeat_evidence',
            ],
            'claim_ready' => false,
            'external_claim_allowed' => false,
            'score_or_claim_allowed' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'canonical_phrase' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
        ];
        $packet['evidence_provenance'] = $this->learningPacketProvenance($snapshot);
        $packet['learning_packet_hash'] = $this->stableHash($packet);

        return $packet;
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    private function learningPacketProvenance(array $snapshot): array
    {
        return [
            'source_schema_version' => $snapshot['schema_version'] ?? null,
            'ledger_path' => $snapshot['ledger_path'] ?? null,
            'ledger_root' => $snapshot['ledger_root'] ?? null,
            'filters' => $snapshot['filters'] ?? [],
            'total_ledger_entries' => $snapshot['total_entries'] ?? 0,
            'filtered_ledger_entries' => $snapshot['filtered_entries'] ?? 0,
            'invalid_entries_excluded_from_ranking' => (bool) ($snapshot['invalid_entries_excluded_from_ranking'] ?? true),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'routing_effect' => 'none',
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        $copy = $payload;
        unset($copy['generated_at'], $copy['learning_packet_hash']);
        $normalized = $this->normalizeForHash($copy);

        return hash('sha256', (string) json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function normalizeForHash(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_keys($value) === range(0, count($value) - 1);
        if (! $isList) {
            ksort($value);
        }

        foreach ($value as $key => $entry) {
            $value[$key] = $this->normalizeForHash($entry);
        }

        return $value;
    }

    /**
     * @param  list<array<string,mixed>>  $repeatTargets
     * @param  list<array<string,mixed>>  $shadowOnly
     * @param  list<array<string,mixed>>  $blocked
     * @return array<string,mixed>
     */
    private function evidenceCollectionPlan(array $repeatTargets, array $shadowOnly, array $blocked): array
    {
        $repeatCommands = [];
        $learningGapCommands = [];
        $arenaRepeatCommands = [];
        $externalExecutionPlanCommands = [];
        foreach (array_slice($repeatTargets, 0, 10) as $target) {
            $taskCategory = $this->cliValue((string) ($target['task_category'] ?? 'unknown'), 'unknown');
            $difficulty = $this->cliValue((string) ($target['difficulty_level'] ?? 'L3'), 'L3');
            $role = $this->cliValue((string) ($target['role'] ?? 'builder'), 'builder');
            $candidateProvider = $this->providerFamilyForCommand((string) ($target['candidate_provider'] ?? ''));
            $candidateModel = $this->modelForCommand((string) ($target['candidate_model'] ?? 'default'));
            $runnerUpProvider = $this->providerFamilyForCommand((string) ($target['runner_up_provider'] ?? ''));
            $runnerUpModel = $this->modelForCommand((string) ($target['runner_up_model'] ?? ''));
            $baseline = $runnerUpProvider !== ''
                ? ['provider' => $runnerUpProvider, 'model' => $runnerUpModel !== '' ? $runnerUpModel : $this->baselineForProvider($candidateProvider)['model']]
                : $this->baselineForProvider($candidateProvider);

            $learningGapCommands[] = [
                'segment_key' => $target['segment_key'] ?? null,
                'command' => 'php artisan atlas:forge:rivals external-learning-gap --provider='.$candidateProvider
                    .' --task-category='.$taskCategory.' --difficulty='.$difficulty.' --role='.$role.' --json',
                'external_provider_call' => false,
                'provider_tokens_spent' => false,
            ];
            $runbookPath = 'storage/app/atlas-rivals/external-runbooks/'
                .implode('-', [
                    $taskCategory,
                    $difficulty,
                    $role,
                    $candidateProvider,
                    $candidateModel,
                ])
                .'.json';
            $externalExecutionPlanCommands[] = [
                'segment_key' => $target['segment_key'] ?? null,
                'candidate_provider' => $candidateProvider,
                'candidate_model' => $candidateModel,
                'command' => 'php artisan atlas:forge:rivals external-execution-plan --provider='.$candidateProvider
                    .' --model='.$candidateModel
                    .' --task-category='.$taskCategory.' --difficulty='.$difficulty.' --role='.$role
                    .' --case-set=industrial-50 --output-path='.$runbookPath.' --json',
                'runbook_manifest_path' => $runbookPath,
                'manifest_schema_version' => 'atlas.forge.rivals.external_execution_runbook_manifest.v1',
                'writes_plan_manifest_only' => true,
                'external_provider_call' => false,
                'provider_tokens_spent' => false,
                'routing_effect' => 'none',
            ];
            $arenaRepeatCommands[] = [
                'segment_key' => $target['segment_key'] ?? null,
                'candidate_provider' => $candidateProvider,
                'candidate_model' => $candidateModel,
                'baseline_provider' => $baseline['provider'],
                'baseline_model' => $baseline['model'],
                'command' => 'php artisan atlas:forge:rivals run-arena --case-set=industrial-50 --mode=provider_arena'
                    .' --arm-a='.$this->armForProvider($candidateProvider).' --arm-a-model='.$candidateModel
                    .' --arm-b='.$this->armForProvider($baseline['provider']).' --arm-b-model='.$baseline['model']
                    .' --task-category='.$taskCategory.' --difficulty='.$difficulty.' --role='.$role.' --dry-run --json',
                'external_provider_call' => false,
                'provider_tokens_spent' => false,
            ];
            $repeatCommands[] = [
                'segment_key' => $target['segment_key'] ?? null,
                'command' => 'php artisan atlas:forge:rivals run-battery --preset=statistical-repeat --mode=provider_arena --task-category='
                    .$taskCategory.' --difficulty='.$difficulty.' --role='.$role.' --dry-run --json',
                'real_provider_required' => false,
                'provider_tokens_spent' => false,
            ];
        }

        return [
            'schema_version' => 'atlas.forge.rivals.atlas_decide_evidence_collection_plan.v1',
            'status' => $repeatTargets === [] && $blocked === [] ? 'no_immediate_repeat_targets' : 'needs_more_reproducible_evidence',
            'repeat_target_count' => count($repeatTargets),
            'shadow_only_segment_count' => count($shadowOnly),
            'blocked_segment_count' => count($blocked),
            'dry_run_first' => true,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'learning_gap_commands_preview' => $learningGapCommands,
            'external_execution_plan_commands_preview' => $externalExecutionPlanCommands,
            'arena_repeat_commands_preview' => $arenaRepeatCommands,
            'commands_preview' => $repeatCommands,
            'operator_sequence' => [
                '1_check_external_learning_gap',
                '2_export_external_execution_runbook_manifest',
                '3_run_statistical_repeat_dry_run',
                '4_review_provider_cost_and_confirmations_before_real_provider',
                '5_run_replay_and_matrix_before_ledger_record',
                '6_record_ledger_only_after_trusted_signal',
                '7_recheck_decide_map_learning_packet',
            ],
            'claim_ready' => false,
            'external_claim_allowed' => false,
            'score_or_claim_allowed' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
        ];
    }

    private function providerFamilyForCommand(string $provider): string
    {
        return match (strtolower(trim($provider))) {
            'anthropic_claude', 'claude_cli', 'claude' => 'claude',
            'openai_codex', 'openai_gpt', 'codex_cli', 'codex' => 'codex',
            'google_gemini', 'gemini_cli', 'gemini' => 'gemini',
            'cursor_cli', 'cursor' => 'cursor',
            'composer_2_5', 'composer' => 'composer',
            default => strtolower(trim($provider)),
        };
    }

    private function armForProvider(string $provider): string
    {
        return match ($this->providerFamilyForCommand($provider)) {
            'claude' => 'claude_code',
            'codex' => 'codex_cli',
            'gemini' => 'gemini_cli',
            'cursor' => 'cursor_cli',
            'composer' => 'composer_2_5',
            default => 'claude_code',
        };
    }

    /**
     * @return array{provider:string,model:string}
     */
    private function baselineForProvider(string $provider): array
    {
        return match ($this->providerFamilyForCommand($provider)) {
            'claude' => ['provider' => 'codex', 'model' => 'gpt-5.5'],
            'codex', 'gemini' => ['provider' => 'claude', 'model' => 'sonnet'],
            'cursor', 'composer' => ['provider' => 'codex', 'model' => 'gpt-5.5'],
            default => ['provider' => 'claude', 'model' => 'sonnet'],
        };
    }

    private function modelForCommand(string $model): string
    {
        return $this->cliValue(strtolower(trim($model)), 'default');
    }

    private function cliValue(string $value, string $fallback): string
    {
        $value = trim($value);
        if ($value === '') {
            return $fallback;
        }

        $safe = preg_replace('/[^A-Za-z0-9_.:-]/', '-', $value);

        return $safe === null || $safe === '' ? $fallback : $safe;
    }

    /**
     * @param  list<array<string,mixed>>  $learnable
     * @param  list<array<string,mixed>>  $shadowOnly
     * @param  list<array<string,mixed>>  $blocked
     * @param  list<array<string,mixed>>  $repeatTargets
     */
    private function operatorSummary(array $learnable, array $shadowOnly, array $blocked, array $repeatTargets): string
    {
        if ($learnable !== []) {
            return sprintf(
                'Rivals measured %d directional learning segment(s), %d shadow-only segment(s), and %d blocked segment(s); Atlas Decide may only consume this as an advisory policy-review candidate with no topology mutation.',
                count($learnable),
                count($shadowOnly),
                count($blocked),
            );
        }

        return sprintf(
            'Rivals has %d measured shadow-only segment(s) and %d repeat target(s); no model should be preferred from this signal until reproducible evidence improves.',
            count($shadowOnly),
            count($repeatTargets),
        );
    }

    /**
     * @param  array<string,mixed>  $repeatPlan
     * @param  list<array<string,mixed>>  $repeatTargets
     * @return array<string,mixed>
     */
    private function statisticalRepeatGapSummary(array $repeatPlan, array $repeatTargets): array
    {
        $bucketCount = (int) ($repeatPlan['bucket_count'] ?? 0);
        $readyBucketCount = (int) ($repeatPlan['ready_bucket_count'] ?? 0);
        $notReadyBucketCount = (int) ($repeatPlan['not_ready_bucket_count'] ?? 0);
        $unstableBucketCount = (int) ($repeatPlan['unstable_bucket_count'] ?? 0);

        return [
            'schema_version' => 'atlas.forge.rivals.statistical_repeat_gap_summary.v1',
            'status' => ($repeatPlan['status'] ?? null) === 'complete' ? 'complete' : 'needs_repetition',
            'bucket_count' => $bucketCount,
            'ready_bucket_count' => $readyBucketCount,
            'not_ready_bucket_count' => $notReadyBucketCount,
            'unstable_bucket_count' => $unstableBucketCount,
            'segment_repeat_target_count' => count($repeatTargets),
            'minimum_valid_repetitions_per_bucket' => (int) ($repeatPlan['minimum_valid_repetitions_per_bucket'] ?? 0),
            'global_repeat_gap_exists' => $notReadyBucketCount > 0 || $unstableBucketCount > 0,
            'repeat_targets_preview_count' => count((array) ($repeatPlan['repeat_targets_preview'] ?? [])),
            'unstable_targets_preview_count' => count((array) ($repeatPlan['unstable_targets_preview'] ?? [])),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'score_or_claim_allowed' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
        ];
    }

    /**
     * A policy-review candidate is only useful to Atlas Decide when the
     * "better for what" dimensions are complete. Missing difficulty, role,
     * category, prompt mode, or run family keeps the signal in evidence repair.
     *
     * @param  list<array<string,mixed>>  $segmentAdvisory
     * @param  array<string,mixed>  $repeatPlan
     * @return array<string,mixed>
     */
    private function dimensionalSignalQuality(array $segmentAdvisory, array $repeatPlan): array
    {
        $missingCategory = [];
        $missingDifficulty = [];
        $missingRole = [];
        $candidateResolutionBlockers = [];
        foreach ($segmentAdvisory as $row) {
            $segmentKey = (string) ($row['segment_key'] ?? 'unknown');
            $category = trim((string) ($row['task_category'] ?? ''));
            $difficulty = trim((string) ($row['difficulty_level'] ?? ''));
            $role = trim((string) ($row['role'] ?? ''));

            if ($category === '' || $category === 'unknown') {
                $missingCategory[] = $segmentKey;
            }
            if (preg_match('/^L[1-5]$/', $difficulty) !== 1) {
                $missingDifficulty[] = $segmentKey;
            }
            if ($role === '' || $role === 'unknown') {
                $missingRole[] = $segmentKey;
            }
            foreach ((array) ($row['candidate_resolution_blockers'] ?? []) as $blocker) {
                $blocker = trim((string) $blocker);
                if ($blocker !== '') {
                    $candidateResolutionBlockers[] = $segmentKey.':'.$blocker;
                }
            }
        }

        $repeatTargets = array_merge(
            (array) ($repeatPlan['repeat_targets'] ?? []),
            (array) ($repeatPlan['unstable_targets'] ?? []),
        );
        $repeatTargetsMissingPrompt = [];
        $repeatTargetsMissingRunFamily = [];
        foreach ($repeatTargets as $target) {
            if (! is_array($target)) {
                continue;
            }
            $label = implode('|', array_filter([
                (string) ($target['task_category'] ?? 'unknown'),
                (string) ($target['difficulty_level'] ?? 'unknown'),
                (string) ($target['role'] ?? 'unknown'),
                (string) ($target['provider'] ?? 'unknown'),
                (string) ($target['model'] ?? 'unknown'),
            ]));
            if (trim((string) ($target['prompt_mode'] ?? '')) === '') {
                $repeatTargetsMissingPrompt[] = $label;
            }
            if (trim((string) ($target['run_family'] ?? '')) === '') {
                $repeatTargetsMissingRunFamily[] = $label;
            }
        }

        $blockers = [];
        if ($missingCategory !== []) {
            $blockers[] = 'task_category_required_for_every_segment';
        }
        if ($missingDifficulty !== []) {
            $blockers[] = 'difficulty_level_required_for_every_segment';
        }
        if ($missingRole !== []) {
            $blockers[] = 'role_required_for_every_segment';
        }
        if ($candidateResolutionBlockers !== []) {
            $blockers[] = 'provider_model_resolution_required_for_every_candidate_segment';
        }
        if ($repeatTargetsMissingPrompt !== []) {
            $blockers[] = 'prompt_mode_required_for_repeat_targets';
        }
        if ($repeatTargetsMissingRunFamily !== []) {
            $blockers[] = 'run_family_required_for_repeat_targets';
        }

        return [
            'schema_version' => 'atlas.forge.rivals.dimensional_signal_quality.v1',
            'status' => $blockers === [] ? 'ok' : 'blocked_missing_required_dimensions',
            'complete_for_policy_review' => $blockers === [],
            'segment_count' => count($segmentAdvisory),
            'missing_task_category_segment_count' => count(array_unique($missingCategory)),
            'missing_difficulty_segment_count' => count(array_unique($missingDifficulty)),
            'missing_role_segment_count' => count(array_unique($missingRole)),
            'unresolved_provider_model_segment_count' => count(array_unique($candidateResolutionBlockers)),
            'repeat_target_count' => count($repeatTargets),
            'repeat_targets_missing_prompt_mode_count' => count(array_unique($repeatTargetsMissingPrompt)),
            'repeat_targets_missing_run_family_count' => count(array_unique($repeatTargetsMissingRunFamily)),
            'missing_task_category_segments_preview' => array_slice(array_values(array_unique($missingCategory)), 0, 10),
            'missing_difficulty_segments_preview' => array_slice(array_values(array_unique($missingDifficulty)), 0, 10),
            'missing_role_segments_preview' => array_slice(array_values(array_unique($missingRole)), 0, 10),
            'unresolved_provider_model_segments_preview' => array_slice(array_values(array_unique($candidateResolutionBlockers)), 0, 10),
            'repeat_targets_missing_prompt_mode_preview' => array_slice(array_values(array_unique($repeatTargetsMissingPrompt)), 0, 10),
            'repeat_targets_missing_run_family_preview' => array_slice(array_values(array_unique($repeatTargetsMissingRunFamily)), 0, 10),
            'blockers' => $blockers,
            'score_or_claim_allowed' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
        ];
    }

    /**
     * Missing dimensions can only be repaired by re-ingesting provable metadata
     * from trusted artifacts or by running new explicit-dimension repeats. This
     * plan is intentionally read-only: it tells the operator what to fix without
     * mutating the ledger or inferring values from score outcomes.
     *
     * @param  array<string,mixed>  $dimensionalQuality
     * @return array<string,mixed>
     */
    private function dimensionalSignalRepairPlan(array $dimensionalQuality): array
    {
        $complete = (bool) ($dimensionalQuality['complete_for_policy_review'] ?? false);
        $blockers = (array) ($dimensionalQuality['blockers'] ?? []);

        $repairTargets = [];
        foreach ([
            'task_category' => 'missing_task_category_segments_preview',
            'difficulty_level' => 'missing_difficulty_segments_preview',
            'role' => 'missing_role_segments_preview',
            'provider_model_resolution' => 'unresolved_provider_model_segments_preview',
            'prompt_mode' => 'repeat_targets_missing_prompt_mode_preview',
            'run_family' => 'repeat_targets_missing_run_family_preview',
        ] as $dimension => $previewKey) {
            $preview = array_values((array) ($dimensionalQuality[$previewKey] ?? []));
            if ($preview === []) {
                continue;
            }

            $repairTargets[] = [
                'dimension' => $dimension,
                'target_count' => count($preview),
                'targets_preview' => array_slice($preview, 0, 10),
                'safe_repair_source' => $dimension === 'provider_model_resolution'
                    ? 'provider_receipt_manifest_or_model_registry_alias_reingest'
                    : (in_array($dimension, ['prompt_mode', 'run_family'], true)
                    ? 'trusted_run_manifest_or_repeat_rerun_with_explicit_metadata'
                    : 'trusted_case_manifest_scorecard_or_repeat_rerun_with_explicit_metadata'),
                'automatic_inference_allowed' => false,
            ];
        }

        return [
            'schema_version' => 'atlas.forge.rivals.dimensional_signal_repair_plan.v1',
            'status' => $complete ? 'no_repair_needed' : 'repair_required_before_policy_review',
            'repair_required' => ! $complete,
            'repair_target_count' => count($repairTargets),
            'repair_targets' => $repairTargets,
            'blockers' => $blockers,
            'automatic_mutation_allowed' => false,
            'automatic_inference_allowed' => false,
            'ledger_rewrite_allowed' => false,
            'requires_human_review' => ! $complete,
            'safe_next_actions' => $complete ? [
                'continue_statistical_repeat_or_policy_review_flow',
            ] : [
                'inspect_trusted_manifest_scorecard_and_case_metadata_for_missing_dimensions',
                'only_reingest_metadata_when_the_dimension_is_explicit_in_trusted_artifacts',
                'rerun_statistical_repeat_with_explicit_task_category_difficulty_prompt_mode_and_run_family_when_metadata_is_not_provable',
                'rerun_decide_learning_after_repair',
            ],
            'commands_preview' => $complete ? [
                [
                    'command' => 'php artisan atlas:forge:rivals decide-learning --json',
                    'external_provider_call' => false,
                    'provider_tokens_spent' => false,
                ],
            ] : [
                [
                    'command' => 'php artisan atlas:forge:rivals decide-learning --json',
                    'external_provider_call' => false,
                    'provider_tokens_spent' => false,
                ],
                [
                    'command' => 'php artisan atlas:forge:rivals statistical-repeat-dry-run --json',
                    'external_provider_call' => false,
                    'provider_tokens_spent' => false,
                ],
            ],
            'claim_ready' => false,
            'external_claim_allowed' => false,
            'score_or_claim_allowed' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $preferenceCandidates
     * @param  list<array<string,mixed>>  $shadowOnly
     * @param  list<array<string,mixed>>  $blocked
     * @param  list<array<string,mixed>>  $repeatTargets
     * @param  list<string>  $blockedPreferenceReasons
     * @param  array<string,mixed>  $repeatGapSummary
     * @return array<string,mixed>
     */
    private function atlasDecideConsumptionSummary(
        array $preferenceCandidates,
        array $shadowOnly,
        array $blocked,
        array $repeatTargets,
        array $blockedPreferenceReasons,
        string $allowedLearningEffect,
        array $repeatGapSummary,
    ): array {
        $reviewCandidateCount = count($preferenceCandidates);
        $decision = $reviewCandidateCount > 0
            ? 'policy_review_candidates_available'
            : 'no_preference_available';
        $nextAction = $reviewCandidateCount > 0
            ? 'atlas_decide_policy_review_can_evaluate_candidates_without_topology_mutation'
            : ($repeatTargets === [] ? 'collect_new_replayable_evidence' : 'run_statistical_repeat_for_shadow_segments');

        return [
            'schema_version' => 'atlas.forge.rivals.atlas_decide_consumption_summary.v1',
            'decision' => $decision,
            'allowed_learning_effect' => $allowedLearningEffect,
            'review_candidate_count' => $reviewCandidateCount,
            'shadow_candidate_segment_count' => count($shadowOnly),
            'blocked_segment_count' => count($blocked),
            'repeat_target_count' => count($repeatTargets),
            'statistical_repeat_gap_status' => $repeatGapSummary['status'] ?? 'needs_repetition',
            'statistical_repeat_not_ready_bucket_count' => $repeatGapSummary['not_ready_bucket_count'] ?? 0,
            'statistical_repeat_unstable_bucket_count' => $repeatGapSummary['unstable_bucket_count'] ?? 0,
            'global_repeat_gap_exists' => (bool) ($repeatGapSummary['global_repeat_gap_exists'] ?? true),
            'blocked_preference_reasons' => $blockedPreferenceReasons,
            'next_action' => $nextAction,
            'operator_guardrail' => 'Atlas Decide may learn from this packet only as advisory evidence; Rivals never mutates provider topology.',
            'score_or_claim_allowed' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $learnable
     * @return list<array<string,mixed>>
     */
    private function preferenceCandidates(array $learnable): array
    {
        $candidates = [];
        foreach ($learnable as $row) {
            $key = ($row['candidate_provider'] ?? 'unknown').':'.($row['candidate_model'] ?? 'unknown');
            $candidates[$key] ??= [
                'provider' => $row['candidate_provider'] ?? null,
                'model' => $row['candidate_model'] ?? null,
                'segment_count' => 0,
                'segments' => [],
                'allowed_learning_effect' => 'advisory_policy_review_candidate_only',
                'policy_gate' => 'atlas_decide_policy_review_required_before_any_preference',
                'score_or_claim_allowed' => false,
                'advisory_only' => true,
                'should_update_provider_topology' => false,
                'never_changes_atlas_decide_topology' => true,
                'owner_of_model_routing' => 'atlas_decide',
                'routing_effect' => 'none',
            ];
            $candidates[$key]['segment_count']++;
            $candidates[$key]['segments'][] = [
                'segment_key' => $row['segment_key'] ?? null,
                'task_category' => $row['task_category'] ?? null,
                'difficulty_level' => $row['difficulty_level'] ?? null,
                'role' => $row['role'] ?? null,
                'decision_readiness' => $row['decision_readiness'] ?? null,
                'policy_hint' => $row['policy_hint'] ?? null,
                'next_action' => $row['next_action'] ?? null,
            ];
        }

        $out = array_values($candidates);
        foreach ($out as &$candidate) {
            $candidate['segments'] = array_slice($candidate['segments'], 0, 10);
        }
        unset($candidate);

        usort($out, static fn (array $a, array $b): int => ((int) ($b['segment_count'] ?? 0)) <=> ((int) ($a['segment_count'] ?? 0)));

        return $out;
    }

    /**
     * @param  array<string,mixed>  $external
     * @param  array<string,mixed>  $repeatPlan
     * @param  list<array<string,mixed>>  $learnable
     * @param  list<array<string,mixed>>  $shadowOnly
     * @param  list<array<string,mixed>>  $blocked
     * @param  list<array<string,mixed>>  $repeatTargets
     * @return list<string>
     */
    private function blockedPreferenceReasons(
        array $external,
        array $repeatPlan,
        array $learnable,
        array $shadowOnly,
        array $blocked,
        array $repeatTargets,
        array $dimensionalQuality
    ): array {
        $reasons = [
            'atlas_decide_policy_review_required',
        ];

        if (($dimensionalQuality['complete_for_policy_review'] ?? false) !== true) {
            $reasons[] = 'dimensional_signal_quality_blocked';
        }

        if (($repeatPlan['status'] ?? null) !== 'complete') {
            $reasons[] = 'statistical_repeat_not_ready';
        }

        if (($external['status'] ?? null) !== 'ready_for_human_certification_external_claim_still_blocked') {
            $reasons[] = 'external_claim_readiness_blocked';
        }

        if ($learnable === [] && $shadowOnly !== []) {
            $reasons[] = 'only_shadow_candidates_available';
        }

        if ($repeatTargets !== []) {
            $reasons[] = 'segments_still_require_repeat_runs';
        }

        if ($blocked !== []) {
            $reasons[] = 'some_segments_blocked_or_require_human_review';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param  list<array<string,mixed>>  $shadowOnly
     * @return list<array<string,mixed>>
     */
    private function shadowPolicyCandidates(array $shadowOnly): array
    {
        $candidates = [];
        foreach ($shadowOnly as $row) {
            $key = ($row['candidate_provider'] ?? 'unknown').':'.($row['candidate_model'] ?? 'unknown');
            $candidates[$key] ??= [
                'provider' => $row['candidate_provider'] ?? null,
                'model' => $row['candidate_model'] ?? null,
                'segment_count' => 0,
                'segments' => [],
                'policy_hint' => 'atlas_decide_should_explore_or_shadow_before_preference',
                'allowed_learning_effect' => 'shadow_observation_only',
                'score_or_claim_allowed' => false,
                'advisory_only' => true,
                'should_update_provider_topology' => false,
                'never_changes_atlas_decide_topology' => true,
                'owner_of_model_routing' => 'atlas_decide',
                'routing_effect' => 'none',
            ];
            $candidates[$key]['segment_count']++;
            $candidates[$key]['segments'][] = [
                'segment_key' => $row['segment_key'] ?? null,
                'task_category' => $row['task_category'] ?? null,
                'difficulty_level' => $row['difficulty_level'] ?? null,
                'role' => $row['role'] ?? null,
                'next_action' => $row['next_action'] ?? null,
            ];
        }

        $out = array_values($candidates);
        foreach ($out as &$candidate) {
            $candidate['segments'] = array_slice($candidate['segments'], 0, 10);
        }
        unset($candidate);

        usort($out, static fn (array $a, array $b): int => ((int) ($b['segment_count'] ?? 0)) <=> ((int) ($a['segment_count'] ?? 0)));

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $segmentAdvisory
     * @return list<array<string,mixed>>
     */
    private function modelFitMatrix(array $segmentAdvisory): array
    {
        $rows = array_map(static fn (array $row): array => [
            'segment_key' => $row['segment_key'] ?? null,
            'task_category' => $row['task_category'] ?? null,
            'difficulty_level' => $row['difficulty_level'] ?? null,
            'role' => $row['role'] ?? null,
            'candidate_provider' => $row['candidate_provider'] ?? null,
            'candidate_model' => $row['candidate_model'] ?? null,
            'runner_up_provider' => $row['runner_up_provider'] ?? null,
            'runner_up_model' => $row['runner_up_model'] ?? null,
            'candidate_resolution_status' => $row['candidate_resolution_status'] ?? null,
            'candidate_resolution_blockers' => $row['candidate_resolution_blockers'] ?? [],
            'policy_hint' => $row['policy_hint'] ?? null,
            'next_action' => $row['next_action'] ?? null,
            'supporting_evidence' => $row['supporting_evidence'] ?? null,
            'why' => $row['why'] ?? [],
            'fit_status' => match ((string) ($row['decision_readiness'] ?? '')) {
                'strong_directional_signal' => 'measured_strong_candidate',
                'directional_signal' => 'measured_directional_candidate',
                'explore_before_prefer' => 'shadow_or_repeat_before_preference',
                'human_review_required' => 'human_review_required',
                default => 'insufficient_evidence',
            },
            'score_or_claim_allowed' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
        ], $segmentAdvisory);

        usort($rows, static function (array $a, array $b): int {
            $category = strcmp((string) ($a['task_category'] ?? ''), (string) ($b['task_category'] ?? ''));
            if ($category !== 0) {
                return $category;
            }
            $difficulty = strcmp((string) ($a['difficulty_level'] ?? ''), (string) ($b['difficulty_level'] ?? ''));
            if ($difficulty !== 0) {
                return $difficulty;
            }

            return strcmp((string) ($a['role'] ?? ''), (string) ($b['role'] ?? ''));
        });

        return $rows;
    }

    /**
     * @param  list<array<string,mixed>>  $modelFitMatrix
     * @return list<array<string,mixed>>
     */
    private function categoryFitSummary(array $modelFitMatrix): array
    {
        $categories = [];
        foreach ($modelFitMatrix as $row) {
            $category = (string) ($row['task_category'] ?? 'unknown');
            $categories[$category] ??= [
                'task_category' => $category,
                'segment_count' => 0,
                'candidates' => [],
                'status_counts' => [],
                'advisory_only' => true,
                'should_update_provider_topology' => false,
                'never_changes_atlas_decide_topology' => true,
                'owner_of_model_routing' => 'atlas_decide',
                'routing_effect' => 'none',
            ];
            $categories[$category]['segment_count']++;

            $candidateKey = ($row['candidate_provider'] ?? 'unknown').':'.($row['candidate_model'] ?? 'unknown');
            $categories[$category]['candidates'][$candidateKey] ??= [
                'provider' => $row['candidate_provider'] ?? null,
                'model' => $row['candidate_model'] ?? null,
                'segment_count' => 0,
                'fit_statuses' => [],
            ];
            $categories[$category]['candidates'][$candidateKey]['segment_count']++;
            $fitStatus = (string) ($row['fit_status'] ?? 'unknown');
            if (! in_array($fitStatus, $categories[$category]['candidates'][$candidateKey]['fit_statuses'], true)) {
                $categories[$category]['candidates'][$candidateKey]['fit_statuses'][] = $fitStatus;
            }
            $categories[$category]['status_counts'][$fitStatus] ??= 0;
            $categories[$category]['status_counts'][$fitStatus]++;
        }

        $out = [];
        foreach ($categories as $category) {
            $candidates = array_values($category['candidates']);
            usort($candidates, static fn (array $a, array $b): int => ((int) ($b['segment_count'] ?? 0)) <=> ((int) ($a['segment_count'] ?? 0)));
            $category['candidates'] = $candidates;
            $category['dominant_candidate'] = $candidates[0] ?? null;
            $out[] = $category;
        }

        usort($out, static fn (array $a, array $b): int => strcmp((string) ($a['task_category'] ?? ''), (string) ($b['task_category'] ?? '')));

        return $out;
    }

    /**
     * Atlas Decide-facing usage playbook. It turns measured segments into
     * guarded "when to consider / when not to prefer yet" instructions without
     * changing routing or topology.
     *
     * @param  list<array<string,mixed>>  $modelProfiles
     * @param  list<array<string,mixed>>  $modelFitMatrix
     * @param  list<array<string,mixed>>  $repeatTargets
     * @return list<array<string,mixed>>
     */
    private function modelUsagePlaybook(array $modelProfiles, array $modelFitMatrix, array $repeatTargets): array
    {
        $repeatTargetKeys = array_values(array_filter(array_map(
            static fn (array $row): ?string => is_string($row['segment_key'] ?? null) ? $row['segment_key'] : null,
            $repeatTargets,
        )));

        $playbook = [];
        foreach ($modelProfiles as $profile) {
            $provider = (string) ($profile['provider'] ?? 'unknown');
            $model = (string) ($profile['model'] ?? 'unknown');
            $segments = array_values(array_filter(
                $modelFitMatrix,
                static fn (array $row): bool => ($row['candidate_provider'] ?? null) === $provider
                    && ($row['candidate_model'] ?? null) === $model
            ));
            $strong = array_values(array_filter(
                $segments,
                static fn (array $row): bool => in_array((string) ($row['fit_status'] ?? ''), ['measured_strong_candidate', 'measured_directional_candidate'], true)
            ));
            $shadow = array_values(array_filter(
                $segments,
                static fn (array $row): bool => ($row['fit_status'] ?? null) === 'shadow_or_repeat_before_preference'
            ));
            $blocked = array_values(array_filter(
                $segments,
                static fn (array $row): bool => in_array((string) ($row['fit_status'] ?? ''), ['human_review_required', 'insufficient_evidence'], true)
            ));
            $missingRepeat = array_values(array_filter(
                $segments,
                static fn (array $row): bool => in_array((string) ($row['segment_key'] ?? ''), $repeatTargetKeys, true)
            ));
            $usageStatus = $strong !== []
                ? 'policy_review_candidate_only'
                : ($shadow !== [] ? 'shadow_observation_only' : 'do_not_prefer_insufficient_evidence');

            $playbook[] = [
                'provider' => $provider,
                'model' => $model,
                'usage_status' => $usageStatus,
                'atlas_decide_allowed_action' => match ($usageStatus) {
                    'policy_review_candidate_only' => 'evaluate_in_policy_review_or_shadow_mode_only',
                    'shadow_observation_only' => 'observe_and_collect_repeats_only',
                    default => 'do_not_use_for_preference',
                },
                'use_when' => $this->usagePlaybookSegments($strong),
                'shadow_when' => $this->usagePlaybookSegments($shadow),
                'do_not_prefer_when' => $this->usagePlaybookDoNotPrefer($shadow, $blocked, $missingRepeat),
                'evidence_gaps' => $this->usagePlaybookEvidenceGaps($segments, $missingRepeat),
                'next_evidence_commands_preview' => $this->usagePlaybookRepeatCommands($missingRepeat),
                'segment_count' => count($segments),
                'strong_or_directional_segment_count' => count($strong),
                'shadow_segment_count' => count($shadow),
                'blocked_segment_count' => count($blocked),
                'missing_repeat_segment_count' => count($missingRepeat),
                'policy_gate' => 'atlas_decide_policy_review_required_before_any_preference',
                'score_or_claim_allowed' => false,
                'advisory_only' => true,
                'should_update_provider_topology' => false,
                'never_changes_atlas_decide_topology' => true,
                'owner_of_model_routing' => 'atlas_decide',
                'routing_effect' => 'none',
            ];
        }

        usort($playbook, static function (array $a, array $b): int {
            $strong = ((int) ($b['strong_or_directional_segment_count'] ?? 0)) <=> ((int) ($a['strong_or_directional_segment_count'] ?? 0));
            if ($strong !== 0) {
                return $strong;
            }
            $shadow = ((int) ($b['shadow_segment_count'] ?? 0)) <=> ((int) ($a['shadow_segment_count'] ?? 0));
            if ($shadow !== 0) {
                return $shadow;
            }

            return strcmp((string) ($a['provider'] ?? '').':'.(string) ($a['model'] ?? ''), (string) ($b['provider'] ?? '').':'.(string) ($b['model'] ?? ''));
        });

        return $playbook;
    }

    /**
     * @param  list<array<string,mixed>>  $segments
     * @return list<array<string,mixed>>
     */
    private function usagePlaybookSegments(array $segments): array
    {
        return array_slice(array_map(static fn (array $row): array => [
            'segment_key' => $row['segment_key'] ?? null,
            'task_category' => $row['task_category'] ?? null,
            'difficulty_level' => $row['difficulty_level'] ?? null,
            'role' => $row['role'] ?? null,
            'fit_status' => $row['fit_status'] ?? null,
            'candidate_resolution_status' => $row['candidate_resolution_status'] ?? null,
            'candidate_resolution_blockers' => $row['candidate_resolution_blockers'] ?? [],
            'policy_hint' => $row['policy_hint'] ?? null,
            'next_action' => $row['next_action'] ?? null,
            'supporting_evidence' => $row['supporting_evidence'] ?? null,
        ], $segments), 0, 10);
    }

    /**
     * @param  list<array<string,mixed>>  $shadow
     * @param  list<array<string,mixed>>  $blocked
     * @param  list<array<string,mixed>>  $missingRepeat
     * @return list<array<string,mixed>>
     */
    private function usagePlaybookDoNotPrefer(array $shadow, array $blocked, array $missingRepeat): array
    {
        $rows = [];
        foreach (array_merge($missingRepeat, $shadow, $blocked) as $row) {
            $segmentKey = (string) ($row['segment_key'] ?? '');
            if ($segmentKey === '') {
                continue;
            }
            $rows[$segmentKey] ??= [
                'segment_key' => $segmentKey,
                'task_category' => $row['task_category'] ?? null,
                'difficulty_level' => $row['difficulty_level'] ?? null,
                'role' => $row['role'] ?? null,
                'fit_status' => $row['fit_status'] ?? null,
                'reason' => in_array($row, $missingRepeat, true)
                    ? 'statistical_repeat_required_before_preference'
                    : 'signal_not_strong_enough_for_preference',
                'next_action' => $row['next_action'] ?? null,
            ];
        }

        return array_slice(array_values($rows), 0, 10);
    }

    /**
     * @param  list<array<string,mixed>>  $segments
     * @param  list<array<string,mixed>>  $missingRepeat
     * @return list<string>
     */
    private function usagePlaybookEvidenceGaps(array $segments, array $missingRepeat): array
    {
        $gaps = [];
        if ($segments === []) {
            $gaps[] = 'no_measured_segments_for_model';
        }
        if ($missingRepeat !== []) {
            $gaps[] = 'statistical_repeat_required';
        }
        foreach ($segments as $row) {
            if (($row['fit_status'] ?? null) === 'human_review_required') {
                $gaps[] = 'human_review_required_segment_present';
            }
            if (($row['fit_status'] ?? null) === 'insufficient_evidence') {
                $gaps[] = 'insufficient_evidence_segment_present';
            }
        }

        return array_values(array_unique($gaps));
    }

    /**
     * @param  list<array<string,mixed>>  $missingRepeat
     * @return list<array<string,mixed>>
     */
    private function usagePlaybookRepeatCommands(array $missingRepeat): array
    {
        return array_slice(array_map(function (array $row): array {
            $taskCategory = $this->cliValue((string) ($row['task_category'] ?? 'unknown'), 'unknown');
            $difficulty = $this->cliValue((string) ($row['difficulty_level'] ?? 'L3'), 'L3');
            $role = $this->cliValue((string) ($row['role'] ?? 'builder'), 'builder');
            $candidateProvider = $this->providerFamilyForCommand((string) ($row['candidate_provider'] ?? ''));
            $candidateModel = $this->modelForCommand((string) ($row['candidate_model'] ?? 'default'));
            $runnerUpProvider = $this->providerFamilyForCommand((string) ($row['runner_up_provider'] ?? ''));
            $runnerUpModel = $this->modelForCommand((string) ($row['runner_up_model'] ?? ''));
            $baseline = $runnerUpProvider !== ''
                ? ['provider' => $runnerUpProvider, 'model' => $runnerUpModel !== '' ? $runnerUpModel : $this->baselineForProvider($candidateProvider)['model']]
                : $this->baselineForProvider($candidateProvider);

            return [
                'segment_key' => is_string($row['segment_key'] ?? null) ? $row['segment_key'] : null,
                'learning_gap_command' => 'php artisan atlas:forge:rivals external-learning-gap --provider='.$candidateProvider
                    .' --task-category='.$taskCategory.' --difficulty='.$difficulty.' --role='.$role.' --json',
                'arena_dry_run_command' => 'php artisan atlas:forge:rivals run-arena --case-set=industrial-50 --mode=provider_arena'
                    .' --arm-a='.$this->armForProvider($candidateProvider).' --arm-a-model='.$candidateModel
                    .' --arm-b='.$this->armForProvider($baseline['provider']).' --arm-b-model='.$baseline['model']
                    .' --task-category='.$taskCategory.' --difficulty='.$difficulty.' --role='.$role.' --dry-run --json',
                'statistical_repeat_command' => 'php artisan atlas:forge:rivals run-battery --preset=statistical-repeat --mode=provider_arena --task-category='
                    .$taskCategory.' --difficulty='.$difficulty.' --role='.$role.' --dry-run --json',
                'external_provider_call' => false,
                'provider_tokens_spent' => false,
                'routing_effect' => 'none',
            ];
        }, $missingRepeat), 0, 5);
    }

    /**
     * @param  array<string,mixed>  $profile
     */
    private function modelProfilePosture(array $profile): string
    {
        if ((int) ($profile['strong_segment_count'] ?? 0) > 0
            && (int) ($profile['explore_before_prefer_segment_count'] ?? 0) === 0
            && (int) ($profile['technical_tie_segment_count'] ?? 0) === 0) {
            return 'prefer_in_listed_segments_when_atlas_decide_policy_allows';
        }
        if ((int) ($profile['strong_segment_count'] ?? 0) > 0
            || (int) ($profile['directional_segment_count'] ?? 0) > 0) {
            return 'directional_fit_explore_before_routing_change';
        }

        return 'exploration_only_insufficient_for_preference';
    }

    private function modelProfilePolicyHint(string $posture): string
    {
        return match ($posture) {
            'prefer_in_listed_segments_when_atlas_decide_policy_allows' => 'atlas_decide_may_consider_preference_without_topology_mutation',
            'directional_fit_explore_before_routing_change' => 'atlas_decide_should_explore_or_shadow_before_preference',
            default => 'atlas_decide_should_not_prefer_from_this_signal_yet',
        };
    }

    /**
     * @param  list<array<string,mixed>>  $valid
     * @param  list<string>  $reasons
     */
    private function shouldUseFullPower(array $valid, array &$reasons): bool
    {
        $fair = array_filter($valid, static fn (array $e): bool => ($e['mode'] ?? null) === 'fair');
        $full = array_filter($valid, static fn (array $e): bool => ($e['mode'] ?? null) === 'full_power');
        if ($fair === [] || $full === []) {
            return false;
        }
        $fairAvg = $this->avgScore($fair);
        $fullAvg = $this->avgScore($full);
        $delta = $fullAvg - $fairAvg;
        $reasons[] = sprintf('fair_avg=%.2f full_power_avg=%.2f delta=%.2f', $fairAvg, $fullAvg, $delta);
        if ($delta >= self::FULL_POWER_WORTH_IT_DELTA) {
            $reasons[] = 'full_power_delta_meets_threshold_'.self::FULL_POWER_WORTH_IT_DELTA;

            return true;
        }
        $reasons[] = 'full_power_not_worth_cost_below_threshold_'.self::FULL_POWER_WORTH_IT_DELTA;

        return false;
    }

    /**
     * @param  iterable<array<string,mixed>>  $entries
     */
    private function avgScore(iterable $entries): float
    {
        $sum = 0.0;
        $count = 0;
        foreach ($entries as $entry) {
            $sum += (float) ($entry['score_total'] ?? 0);
            $count++;
        }

        return $count === 0 ? 0.0 : round($sum / $count, 4);
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return list<string>
     */
    private function latestRunIds(array $entries, int $limit): array
    {
        $sorted = $entries;
        usort($sorted, static fn (array $a, array $b): int => strcmp((string) ($b['recorded_at'] ?? ''), (string) ($a['recorded_at'] ?? '')));
        $out = [];
        foreach (array_slice($sorted, 0, $limit) as $e) {
            $rid = (string) ($e['run_id'] ?? '');
            if ($rid !== '' && ! in_array($rid, $out, true)) {
                $out[] = $rid;
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private function envelope(array $body): array
    {
        $base = [
            'status' => 'ok',
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'separated_from_external_rivals_certification' => true,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ];

        return array_replace($base, $body);
    }
}
