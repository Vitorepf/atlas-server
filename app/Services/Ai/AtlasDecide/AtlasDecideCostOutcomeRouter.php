<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

use App\Services\Ai\Governance\GovernanceFloorRegistry;
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
    // Inlined from the retired Rivals 1.0 performance ledger (values preserved
    // verbatim); the offline ledger feeds again only via the Rivals 2.0 ledger.
    public const STALE_AGE_DAYS = 14;

    public const MULTK01_MEASURE_ID = 'atlas.decide.cost_outcome_uncertainty.v1';

    public const MULTK01_INTERVAL_MIN_N = 3;

    private const MULTK01_Z_90 = 1.644854;

    private const MAXK03_FORMULA_VERSION = 'atlas.decide.multi_objective_route.v1';

    /**
     * @param  Closure(mixed): ?string  $canonicalProviderKey
     * @param  Closure(?string, mixed): ?string  $canonicalModelForProvider
     * @param  Closure(string): bool  $isKnownProviderKey
     * @param  Closure(mixed): ?float  $numericCost
     */
    public function __construct(
        private readonly ?AtlasDecideLiveOutcomeFeedbackService $liveFeedback,
        private readonly Closure $canonicalProviderKey,
        private readonly Closure $canonicalModelForProvider,
        private readonly Closure $isKnownProviderKey,
        private readonly Closure $numericCost,
        private readonly ?GovernanceFloorRegistry $governanceFloors = null,
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

        if ((bool) ($cfg['multi_objective_enabled'] ?? false)) {
            $scorePreserving = $this->withMultiObjectiveScores($scorePreserving, $cfg);
            usort($scorePreserving, static function (array $a, array $b): int {
                $objective = ((float) data_get($b, 'multi_objective.score', 0.0)) <=> ((float) data_get($a, 'multi_objective.score', 0.0));
                if ($objective !== 0) {
                    return $objective;
                }
                $score = ((float) ($b['average_score'] ?? 0.0)) <=> ((float) ($a['average_score'] ?? 0.0));
                if ($score !== 0) {
                    return $score;
                }

                return ((int) ($b['certified_count'] ?? 0)) <=> ((int) ($a['certified_count'] ?? 0));
            });
        } else {
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
        }

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
        $enabled = function_exists('config')
            ? (bool) config('atlas.patamar4.adml_cost_outcome.enabled', false)
            : false;

        $cfg = $this->floors()->atlasDecideCostOutcomeConfig($enabled);
        $multiObjective = (array) config('atlas.patamar4.adml_cost_outcome.multi_objective', []);

        return array_replace($cfg, [
            'multi_objective_enabled' => (bool) ($multiObjective['enabled'] ?? false),
            'multi_objective_risk_class' => (string) ($multiObjective['risk_class'] ?? 'default'),
            'multi_objective_weights' => $this->operatorAuthoredMultiObjectiveWeights((array) ($multiObjective['weights'] ?? [])),
        ]);
    }

    /** @return array<string,mixed> */
    public static function multk01FreezePayload(): array
    {
        return [
            'kind' => 'measure_freeze',
            'measure_id' => self::MULTK01_MEASURE_ID,
            'formula_version' => 'multk01.beta_uncertainty.v1',
            'formula' => 'For each provider/model candidate, publish a Beta posterior uncertainty band derived only from successes=certified_count and failures=total_count-certified_count; routing order must not consume the band in MULTK-01.',
            'thresholds' => [
                'denominator_min_certified_samples' => self::MULTK01_INTERVAL_MIN_N,
                'quantile' => 0.90,
                'insufficient_status' => 'insufficient_n',
            ],
            'denominator_min' => self::MULTK01_INTERVAL_MIN_N,
            'ttl_days' => 30,
            'author_engine_id' => 'cursor-acos-max-multk01',
            'judge_engine_id' => 'codex-independent-multk01-judge',
            'reader' => [
                'surface' => 'AtlasDecideCostOutcomeRouter::costOutcomeCandidates',
                'field' => 'uncertainty_interval',
            ],
            'dual_read_required' => false,
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
        // Rivals 1.0 offline ledger retired: honest empty evidence until the
        // Rivals 2.0 ledger feeds this router (fail-closed).
        return [];
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
                'verified_basis' => $entry['verified_basis'] ?? AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_ABSENT,
                'certified_receipt_id' => $entry['certified_receipt_id'] ?? null,
                'score_total' => $score,
                'cost_estimate' => $entry['cost_usd'] ?? null,
                'latency_ms' => $entry['latency_ms'] ?? null,
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
                'latency_sum' => 0,
                'latency_count' => 0,
                'token_sum' => 0,
                'token_count' => 0,
                'zero_weight_outcome_count' => 0,
                'latest_recorded_at' => null,
                'latest_run_ids' => [],
                'evidence_sources' => [],
                'provider_resolvable' => ($this->isKnownProviderKey)($provider),
            ];

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

            if ($this->isZeroWeightLiveSuccess($entry)) {
                $groups[$key]['zero_weight_outcome_count']++;

                continue;
            }

            $groups[$key]['total_count']++;

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
            if (isset($entry['latency_ms']) && is_numeric($entry['latency_ms']) && (int) $entry['latency_ms'] >= 0) {
                $groups[$key]['latency_sum'] += (int) $entry['latency_ms'];
                $groups[$key]['latency_count']++;
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
                ? $this->ageDays((string) $group['latest_recorded_at'])
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
            if ($latestAgeDays !== null && $latestAgeDays > self::STALE_AGE_DAYS) {
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
                'average_latency_ms' => (int) $group['latency_count'] > 0 ? (int) round((int) $group['latency_sum'] / (int) $group['latency_count']) : null,
                'latency_sample_count' => (int) $group['latency_count'],
                'average_tokens_used' => (int) $group['token_count'] > 0 ? (int) round((int) $group['token_sum'] / (int) $group['token_count']) : null,
                'zero_weight_outcome_count' => (int) $group['zero_weight_outcome_count'],
                'confidence' => $this->confidenceFor($certifiedCount),
                'latest_recorded_at' => $group['latest_recorded_at'],
                'latest_age_days' => $latestAgeDays,
                'latest_run_ids' => array_slice((array) $group['latest_run_ids'], 0, 5),
                'evidence_sources' => array_values((array) $group['evidence_sources']),
                'provider_resolvable' => (bool) $group['provider_resolvable'],
                'blockers' => array_values(array_unique($blockers)),
                'evidence_deficit' => max(0, (int) $cfg['min_evidence'] - $certifiedCount),
                'uncertainty_interval' => $this->uncertaintyInterval($certifiedCount, $totalCount),
            ];
        }

        usort($candidates, static fn (array $a, array $b): int => strcmp($a['provider'].$a['model'], $b['provider'].$b['model']));

        return $candidates;
    }

    /**
     * MULTK-01: frozen read-only uncertainty band derived only from observed
     * certified/failed evidence. This field is published for downstream
     * abstention/cascade policy; routing order above remains unchanged.
     *
     * @return array<string,mixed>
     */
    private function uncertaintyInterval(int $successes, int $n): array
    {
        if ($successes < self::MULTK01_INTERVAL_MIN_N) {
            return [
                'status' => 'insufficient_n',
                'n' => $successes,
                'denominator_min' => self::MULTK01_INTERVAL_MIN_N,
                'reason' => 'certified_count_below_min',
            ];
        }

        $failures = max(0, $n - $successes);
        $alpha = $successes + 1;
        $beta = $failures + 1;
        $sum = $alpha + $beta;
        $mean = $alpha / $sum;
        $variance = ($alpha * $beta) / (($sum ** 2) * ($sum + 1));
        $radius = self::MULTK01_Z_90 * sqrt($variance);

        return [
            'status' => 'ok',
            'n' => $successes,
            'total_count' => $n,
            'successes' => $successes,
            'failures' => $failures,
            'posterior' => [
                'alpha' => $alpha,
                'beta' => $beta,
                'quantile_approximation' => 'normal_90pct_from_beta_posterior',
            ],
            'lower_bound' => round(max(0.0, $mean - $radius), 6),
            'upper_bound' => round(min(1.0, $mean + $radius), 6),
        ];
    }

    /**
     * MAXK-03 — operator-authored weights only. Caller-supplied metrics on
     * entries/candidates never influence this score.
     *
     * @param  list<array<string,mixed>>  $candidates
     * @param  array<string,mixed>  $cfg
     * @return list<array<string,mixed>>
     */
    private function withMultiObjectiveScores(array $candidates, array $cfg): array
    {
        $weights = $this->operatorAuthoredMultiObjectiveWeights((array) ($cfg['multi_objective_weights'] ?? []));
        $riskClass = (string) ($cfg['multi_objective_risk_class'] ?? 'default');

        return array_values(array_map(function (array $candidate) use ($weights, $riskClass): array {
            $scoreComponent = max(0.0, min(1.0, ((float) ($candidate['average_score'] ?? 0.0)) / 100.0));
            $cost = $candidate['average_cost_estimate'] ?? null;
            $latency = $candidate['average_latency_ms'] ?? null;
            $costComponent = is_numeric($cost) ? 1.0 / (1.0 + max(0.0, (float) $cost)) : 0.0;
            $latencyComponent = is_numeric($latency) ? 1.0 / (1.0 + (max(0.0, (float) $latency) / 1000.0)) : 0.0;
            $score = ($scoreComponent * $weights['success'])
                + ($costComponent * $weights['cost'])
                + ($latencyComponent * $weights['latency']);

            $candidate['multi_objective'] = [
                'schema_version' => self::MAXK03_FORMULA_VERSION,
                'risk_class' => $riskClass,
                'source' => 'operator_config',
                'caller_supplied_weights_allowed' => false,
                'weights' => $weights,
                'components' => [
                    'success' => round($scoreComponent, 6),
                    'cost' => round($costComponent, 6),
                    'latency' => round($latencyComponent, 6),
                ],
                'score' => round($score, 6),
            ];

            return $candidate;
        }, $candidates));
    }

    /**
     * @param  array<string,mixed>  $weights
     * @return array{success:float,cost:float,latency:float}
     */
    private function operatorAuthoredMultiObjectiveWeights(array $weights): array
    {
        $success = isset($weights['success']) && is_numeric($weights['success']) ? max(0.0, (float) $weights['success']) : 0.0;
        $cost = isset($weights['cost']) && is_numeric($weights['cost']) ? max(0.0, (float) $weights['cost']) : 1.0;
        $latency = isset($weights['latency']) && is_numeric($weights['latency']) ? max(0.0, (float) $weights['latency']) : 0.0;
        $sum = $success + $cost + $latency;
        if ($sum <= 0.0) {
            return ['success' => 0.0, 'cost' => 1.0, 'latency' => 0.0];
        }

        return [
            'success' => round($success / $sum, 6),
            'cost' => round($cost / $sum, 6),
            'latency' => round($latency / $sum, 6),
        ];
    }

    public function isCertifiedCostOutcomeEntry(array $entry): bool
    {
        if (($entry['evidence_source'] ?? null) === 'live_outcome_feedback') {
            return ($entry['result'] ?? null) === AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS
                && is_numeric($entry['quality_score'] ?? null)
                && is_numeric($entry['score_total'] ?? null)
                && in_array((string) ($entry['verified_basis'] ?? ''), AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASES_WEIGHTED, true)
                && trim((string) ($entry['certified_receipt_id'] ?? '')) !== ''
                && (array) ($entry['hard_failures'] ?? []) === [];
        }

        return (bool) ($entry['valid_for_ranking'] ?? false)
            && (bool) ($entry['tests_passed'] ?? false)
            && (bool) ($entry['replay_passed'] ?? false)
            && (array) ($entry['hard_failures'] ?? []) === [];
    }

    private function isZeroWeightLiveSuccess(array $entry): bool
    {
        return ($entry['evidence_source'] ?? null) === 'live_outcome_feedback'
            && ($entry['result'] ?? null) === AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS
            && ! $this->isCertifiedCostOutcomeEntry($entry);
    }

    /** Inlined verbatim from the retired Rivals 1.0 performance ledger. */
    private function confidenceFor(int $evidenceCount): string
    {
        if ($evidenceCount <= 0) {
            return AtlasDecideMetaLearningService::CONFIDENCE_INSUFFICIENT;
        }
        if ($evidenceCount >= $this->floors()->atlasDecideCostOutcomeConfidenceHighThreshold()) {
            return AtlasDecideMetaLearningService::CONFIDENCE_HIGH;
        }
        if ($evidenceCount >= $this->floors()->atlasDecideCostOutcomeConfidenceMediumThreshold()) {
            return AtlasDecideMetaLearningService::CONFIDENCE_MEDIUM;
        }

        return 'low';
    }

    /** Inlined verbatim from the retired Rivals 1.0 performance ledger. */
    private function ageDays(string $isoDate, ?DateTimeImmutable $now = null): int
    {
        if ($isoDate === '') {
            return PHP_INT_MAX;
        }
        try {
            $dt = new DateTimeImmutable($isoDate);
        } catch (\Throwable) {
            return PHP_INT_MAX;
        }
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $diff = $now->getTimestamp() - $dt->getTimestamp();

        return (int) max(0, intdiv($diff, 86_400));
    }

    private function floors(): GovernanceFloorRegistry
    {
        return $this->governanceFloors ?? new GovernanceFloorRegistry;
    }
}
