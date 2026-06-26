<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsProviderPerformanceLedgerService;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * COST-OUTCOME ROUTING concern, extracted from the god-class
 * {@see AtlasDecideMetaLearningService}.
 *
 * Owns the full cost×outcome routing pipeline: costOutcomeRoute (the
 * top-level entry that produces a ready/blocked route decision),
 * costOutcomeConfig, relevantCostOutcomeEntries (merging ledger + live
 * feedback), relevantLedgerEntries, relevantLiveOutcomeEntries,
 * costOutcomeCandidates (groups evidence by provider|model, scores and
 * ranks) and isCertifiedCostOutcomeEntry.
 *
 * Capabilities that STAY in the service (canonicalProviderKey,
 * canonicalModelForProvider, isKnownProviderKey, numericCost) are passed
 * in as Closures — the SAME closure-binding pattern used by
 * AtlasLoopRefillerSupplyLaneCoordinator.
 */
class AtlasDecideCostOutcomeRouter
{
    /**
     * @param  Closure(mixed): ?string  $canonicalProviderKey
     * @param  Closure(?string, mixed): ?string  $canonicalModelForProvider
     * @param  Closure(string): bool  $isKnownProviderKey
     * @param  Closure(mixed): ?float  $numericCost
     */
    public function __construct(
        private readonly AtlasForgeRivalsProviderPerformanceLedgerService $ledger,
        private readonly ?AtlasDecideLiveOutcomeFeedbackService $liveFeedback,
        private readonly Closure $canonicalProviderKey,
        private readonly Closure $canonicalModelForProvider,
        private readonly Closure $isKnownProviderKey,
        private readonly Closure $numericCost,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function costOutcomeRoute(string $taskCategory, string $role, ?string $framework, string $costOutcomeSchema): array
    {
        $cfg = $this->costOutcomeConfig();
        $generatedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        $base = [
            'schema_version' => $costOutcomeSchema,
            'generated_at' => $generatedAt,
            'enabled' => (bool) $cfg['enabled'],
            'status' => 'disabled',
            'scope' => [
                'task_category' => $taskCategory,
                'role' => $role,
                'framework' => $framework,
            ],
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'mutates_routing_table' => false,
            'activation_still_requires_operator_receipt' => true,
        ];

        if (! (bool) $cfg['enabled']) {
            return $base;
        }

        $entries = $this->relevantCostOutcomeEntries($taskCategory, $role, $framework);
        if ($entries === []) {
            return array_replace($base, [
                'status' => 'blocked',
                'blockers' => ['no_relevant_cost_outcome_evidence'],
                'candidate_count' => 0,
                'candidates' => [],
            ]);
        }

        $candidates = $this->costOutcomeCandidates($entries, $cfg);
        $blockers = [];
        foreach ($candidates as $candidate) {
            foreach ((array) ($candidate['blockers'] ?? []) as $blocker) {
                $blockers[$blocker] = true;
            }
        }

        $eligible = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => ($candidate['blockers'] ?? []) === []
        ));

        if ($eligible === []) {
            return array_replace($base, [
                'status' => 'blocked',
                'blockers' => array_values(array_keys($blockers ?: ['no_eligible_cost_outcome_candidate' => true])),
                'candidate_count' => count($candidates),
                'candidates' => $candidates,
            ]);
        }

        $bestScore = max(array_map(static fn (array $candidate): float => (float) ($candidate['average_score'] ?? 0.0), $eligible));
        $scoreFloor = max((float) $cfg['min_score'], $bestScore - (float) $cfg['max_score_drop']);
        $scorePreserving = array_values(array_filter(
            $eligible,
            static fn (array $candidate): bool => (float) ($candidate['average_score'] ?? 0.0) >= $scoreFloor
        ));

        if ($scorePreserving === []) {
            return array_replace($base, [
                'status' => 'blocked',
                'blockers' => ['score_floor_not_preserved'],
                'candidate_count' => count($candidates),
                'score_floor' => round($scoreFloor, 4),
                'candidates' => $candidates,
            ]);
        }

        usort($scorePreserving, static function (array $a, array $b): int {
            $cost = ((float) ($a['average_cost_estimate'] ?? INF)) <=> ((float) ($b['average_cost_estimate'] ?? INF));
            if ($cost !== 0) {
                return $cost;
            }
            $score = ((float) ($b['average_score'] ?? 0.0)) <=> ((float) ($a['average_score'] ?? 0.0));
            if ($score !== 0) {
                return $score;
            }

            return ((int) ($b['certified_count'] ?? 0)) <=> ((int) ($a['certified_count'] ?? 0));
        });

        $selected = $scorePreserving[0];
        $fallbackPool = $scorePreserving;
        usort($fallbackPool, static function (array $a, array $b): int {
            $score = ((float) ($b['average_score'] ?? 0.0)) <=> ((float) ($a['average_score'] ?? 0.0));
            if ($score !== 0) {
                return $score;
            }

            return ((int) ($b['certified_count'] ?? 0)) <=> ((int) ($a['certified_count'] ?? 0));
        });
        $fallback = $fallbackPool[0];
        $selectedCost = (float) ($selected['average_cost_estimate'] ?? 0.0);
        $fallbackCost = (float) ($fallback['average_cost_estimate'] ?? 0.0);
        $estimatedSavingsPct = $fallbackCost > 0.0
            ? round(max(0.0, (1.0 - ($selectedCost / $fallbackCost)) * 100.0), 2)
            : null;

        return array_replace($base, [
            'status' => 'ready',
            'blockers' => [],
            'candidate_count' => count($candidates),
            'eligible_candidate_count' => count($eligible),
            'score_floor' => round($scoreFloor, 4),
            'selected' => $selected,
            'fallback' => $fallback,
            'estimated_savings_pct' => $estimatedSavingsPct,
            'candidates' => $candidates,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function costOutcomeConfig(): array
    {
        $cfg = function_exists('config') ? (array) config('atlas.patamar4.adml_cost_outcome', []) : [];

        return [
            'enabled' => (bool) ($cfg['enabled'] ?? false),
            'min_evidence' => max(1, (int) ($cfg['min_evidence'] ?? 3)),
            'min_certification_rate' => max(0.0, min(1.0, (float) ($cfg['min_certification_rate'] ?? 0.8))),
            'min_score' => max(0.0, min(100.0, (float) ($cfg['min_score'] ?? 80.0))),
            'max_score_drop' => max(0.0, (float) ($cfg['max_score_drop'] ?? 3.0)),
            'require_measured_cost' => (bool) ($cfg['require_measured_cost'] ?? true),
            'min_cost_samples' => max(1, (int) ($cfg['min_cost_samples'] ?? 1)),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function relevantCostOutcomeEntries(string $taskCategory, string $role, ?string $framework): array
    {
        return array_values(array_merge(
            $this->relevantLedgerEntries($taskCategory, $role, $framework),
            $this->relevantLiveOutcomeEntries($taskCategory, $role, $framework),
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function relevantLedgerEntries(string $taskCategory, string $role, ?string $framework): array
    {
        $task = strtolower(trim($taskCategory));
        $r = strtolower(trim($role));
        $fw = $framework === null ? null : strtolower(trim($framework));

        return array_values(array_filter($this->ledger->loadEntries(), static function (array $entry) use ($task, $r, $fw): bool {
            if (strtolower((string) ($entry['task_category'] ?? '')) !== $task) {
                return false;
            }
            if (strtolower((string) ($entry['role'] ?? '')) !== $r) {
                return false;
            }
            if ($fw !== null && strtolower((string) ($entry['framework'] ?? '')) !== $fw) {
                return false;
            }

            return true;
        }));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function relevantLiveOutcomeEntries(string $taskCategory, string $role, ?string $framework): array
    {
        if ($this->liveFeedback === null) {
            return [];
        }

        $task = strtolower(trim($taskCategory));
        $r = strtolower(trim($role));
        $fw = $framework === null ? null : strtolower(trim($framework));
        $entries = [];

        foreach ($this->liveFeedback->listOutcomes() as $entry) {
            if (strtolower((string) ($entry['task_category'] ?? '')) !== $task) {
                continue;
            }
            if (strtolower((string) ($entry['role'] ?? '')) !== $r) {
                continue;
            }
            if ($fw !== null && strtolower((string) ($entry['framework'] ?? '')) !== $fw) {
                continue;
            }

            $quality = $entry['quality_score'] ?? null;
            $score = is_numeric($quality) ? max(0.0, min(100.0, (float) $quality * 100.0)) : null;

            $entries[] = [
                'evidence_source' => 'live_outcome_feedback',
                'source_schema_version' => $entry['schema_version'] ?? AtlasDecideLiveOutcomeFeedbackService::OUTCOME_SCHEMA,
                'recorded_at' => $entry['recorded_at'] ?? null,
                'run_id' => $entry['entry_hash'] ?? null,
                'task_category' => $entry['task_category'] ?? null,
                'role' => $entry['role'] ?? null,
                'framework' => $entry['framework'] ?? null,
                'provider' => $entry['provider'] ?? null,
                'model' => $entry['model'] ?? null,
                'result' => $entry['result'] ?? null,
                'score_total' => $score,
                'cost_estimate' => $entry['cost_usd'] ?? null,
                'tokens_used' => $entry['tokens_used'] ?? null,
                'quality_score' => $quality,
                'valid_for_ranking' => ($entry['result'] ?? null) === AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS && $score !== null,
                'tests_passed' => ($entry['result'] ?? null) === AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS && $score !== null,
                'replay_passed' => ($entry['result'] ?? null) === AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS && $score !== null,
                'hard_failures' => [],
            ];
        }

        return $entries;
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @param  array<string,mixed>  $cfg
     * @return list<array<string,mixed>>
     */
    public function costOutcomeCandidates(array $entries, array $cfg): array
    {
        $groups = [];
        foreach ($entries as $entry) {
            $provider = ($this->canonicalProviderKey)($entry['provider'] ?? null);
            $model = ($this->canonicalModelForProvider)($provider, $entry['model'] ?? null);
            if ($provider === null || $provider === '' || $model === null || $model === '') {
                continue;
            }
            $key = $provider.'|'.$model;
            $groups[$key] ??= [
                'provider' => $provider,
                'model' => $model,
                'total_count' => 0,
                'certified_count' => 0,
                'score_sum' => 0.0,
                'cost_sum' => 0.0,
                'cost_count' => 0,
                'token_sum' => 0,
                'token_count' => 0,
                'latest_recorded_at' => null,
                'latest_run_ids' => [],
                'evidence_sources' => [],
                'provider_resolvable' => ($this->isKnownProviderKey)($provider),
            ];

            $groups[$key]['total_count']++;
            $recordedAt = (string) ($entry['recorded_at'] ?? '');
            if ($recordedAt !== '' && ($groups[$key]['latest_recorded_at'] === null || $recordedAt > $groups[$key]['latest_recorded_at'])) {
                $groups[$key]['latest_recorded_at'] = $recordedAt;
            }
            $runId = (string) ($entry['run_id'] ?? '');
            if ($runId !== '' && ! in_array($runId, $groups[$key]['latest_run_ids'], true)) {
                $groups[$key]['latest_run_ids'][] = $runId;
            }
            $source = (string) ($entry['evidence_source'] ?? 'forge_rivals_provider_performance_ledger');
            if ($source !== '' && ! in_array($source, $groups[$key]['evidence_sources'], true)) {
                $groups[$key]['evidence_sources'][] = $source;
            }

            if (! $this->isCertifiedCostOutcomeEntry($entry)) {
                continue;
            }

            $groups[$key]['certified_count']++;
            $groups[$key]['score_sum'] += (float) ($entry['score_total'] ?? 0.0);

            $cost = ($this->numericCost)($entry['cost_estimate'] ?? null);
            if ($cost !== null) {
                $groups[$key]['cost_sum'] += $cost;
                $groups[$key]['cost_count']++;
            }
            if (isset($entry['tokens_used']) && is_numeric($entry['tokens_used']) && (int) $entry['tokens_used'] > 0) {
                $groups[$key]['token_sum'] += (int) $entry['tokens_used'];
                $groups[$key]['token_count']++;
            }
        }

        $candidates = [];
        foreach ($groups as $group) {
            $certifiedCount = (int) $group['certified_count'];
            $totalCount = max(1, (int) $group['total_count']);
            $certificationRate = round($certifiedCount / $totalCount, 4);
            $averageScore = $certifiedCount > 0 ? round((float) $group['score_sum'] / $certifiedCount, 4) : null;
            $averageCost = (int) $group['cost_count'] > 0 ? round((float) $group['cost_sum'] / (int) $group['cost_count'], 6) : null;
            $latestAgeDays = $group['latest_recorded_at'] !== null
                ? $this->ledger->ageDays((string) $group['latest_recorded_at'])
                : null;

            $blockers = [];
            if (! (bool) $group['provider_resolvable']) {
                $blockers[] = 'provider_not_resolvable_by_ai_provider_manager';
            }
            if ($certifiedCount < (int) $cfg['min_evidence']) {
                $blockers[] = 'insufficient_certified_evidence';
            }
            if ($certificationRate < (float) $cfg['min_certification_rate']) {
                $blockers[] = 'certification_rate_below_floor';
            }
            if ($latestAgeDays !== null && $latestAgeDays > AtlasForgeRivalsProviderPerformanceLedgerService::STALE_AGE_DAYS) {
                $blockers[] = 'stale_evidence';
            }
            if ((bool) $cfg['require_measured_cost'] && (int) $group['cost_count'] < (int) $cfg['min_cost_samples']) {
                $blockers[] = 'missing_measured_cost';
            }
            if ($averageScore === null || $averageScore < (float) $cfg['min_score']) {
                $blockers[] = 'score_below_floor';
            }

            $candidates[] = [
                'provider' => $group['provider'],
                'model' => $group['model'],
                'total_count' => $totalCount,
                'certified_count' => $certifiedCount,
                'certification_rate' => $certificationRate,
                'average_score' => $averageScore,
                'average_cost_estimate' => $averageCost,
                'cost_sample_count' => (int) $group['cost_count'],
                'average_tokens_used' => (int) $group['token_count'] > 0 ? (int) round((int) $group['token_sum'] / (int) $group['token_count']) : null,
                'confidence' => $this->ledger->confidenceFor($certifiedCount),
                'latest_recorded_at' => $group['latest_recorded_at'],
                'latest_age_days' => $latestAgeDays,
                'latest_run_ids' => array_slice((array) $group['latest_run_ids'], 0, 5),
                'evidence_sources' => array_values((array) $group['evidence_sources']),
                'provider_resolvable' => (bool) $group['provider_resolvable'],
                'blockers' => array_values(array_unique($blockers)),
            ];
        }

        usort($candidates, static fn (array $a, array $b): int => strcmp($a['provider'].$a['model'], $b['provider'].$b['model']));

        return $candidates;
    }

    public function isCertifiedCostOutcomeEntry(array $entry): bool
    {
        if (($entry['evidence_source'] ?? null) === 'live_outcome_feedback') {
            return ($entry['result'] ?? null) === AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS
                && is_numeric($entry['quality_score'] ?? null)
                && is_numeric($entry['score_total'] ?? null)
                && (array) ($entry['hard_failures'] ?? []) === [];
        }

        return (bool) ($entry['valid_for_ranking'] ?? false)
            && (bool) ($entry['tests_passed'] ?? false)
            && (bool) ($entry['replay_passed'] ?? false)
            && (array) ($entry['hard_failures'] ?? []) === [];
    }
}
