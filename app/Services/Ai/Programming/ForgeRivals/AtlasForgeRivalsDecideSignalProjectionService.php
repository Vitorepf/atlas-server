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
        $relevant = $this->filterRelevant($allEntries, $taskCategory, $role, $framework === '' ? null : $framework);
        $validEntries = array_values(array_filter($relevant, static fn (array $e): bool => (bool) ($e['valid_for_ranking'] ?? false)));
        $hardFailEntries = array_values(array_filter($relevant, static fn (array $e): bool => ! (bool) ($e['valid_for_ranking'] ?? false)));
        $tieEntries = array_values(array_filter($relevant, static fn (array $e): bool => ($e['outcome'] ?? null) === 'human_review_required'));

        $evidenceCount = count($validEntries);
        $confidence = $this->ledger->confidenceFor($evidenceCount);

        if ($evidenceCount === 0) {
            return $this->envelope([
                'signal' => self::SIGNAL_INSUFFICIENT,
                'reason' => ['no_valid_evidence_for_task_category_role'],
                'task_category' => $taskCategory,
                'role' => $role,
                'framework' => $framework === '' ? null : $framework,
                'evidence_count' => 0,
                'top_measured_provider' => null,
                'top_measured_model' => null,
                'confidence' => $confidence,
                'alternative_measured_candidate' => null,
                'latest_run_ids' => $this->latestRunIds($relevant, 5),
                'should_explore_alternative' => false,
                'should_use_full_power' => false,
                'should_require_human_review' => $tieEntries !== [] || $hardFailEntries !== [],
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
            'top:provider=%s model=%s avg_score=%.2f sample=%d',
            $top['provider'],
            $top['model'],
            $top['average_score_valid'],
            $top['valid_count']
        );

        $shouldExplore = false;
        $alternative = null;
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

        return $this->envelope([
            'signal' => $signal,
            'reason' => $reasons,
            'task_category' => $taskCategory,
            'role' => $role,
            'framework' => $framework === '' ? null : $framework,
            'top_measured_provider' => $top['provider'],
            'top_measured_model' => $top['model'],
            'top_measured_average_score' => $top['average_score_valid'],
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
     * @param  list<array<string,mixed>>  $entries
     * @return list<array<string,mixed>>
     */
    private function filterRelevant(array $entries, string $taskCategory, string $role, ?string $framework): array
    {
        $tc = strtolower($taskCategory);
        $r = strtolower($role);
        $fw = $framework === null ? null : strtolower($framework);

        return array_values(array_filter($entries, static function (array $e) use ($tc, $r, $fw): bool {
            if (($e['task_category'] ?? null) !== $tc) {
                return false;
            }
            if (($e['role'] ?? null) !== $r) {
                return false;
            }
            if ($fw !== null && ($e['framework'] ?? null) !== $fw) {
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
                'average_score_valid' => round(array_sum($scores) / max(1, count($scores)), 4),
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
