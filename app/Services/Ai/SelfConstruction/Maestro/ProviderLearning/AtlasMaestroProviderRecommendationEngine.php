<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ProviderLearning;

/**
 * ADVISORY-only recommendation over per-(provider, task_class) facts from
 * {@see AtlasMaestroProviderPerformanceLedger}. Never mutates Maestro routing, never invokes the
 * provider manager, never auto-applies.
 *
 * Ranking:
 *   1. success_rate      descending
 *   2. give_back_rate    ascending
 *   3. evidence_complete descending (all successes carried required_evidence beats partial/none)
 *   4. avg_token_cost    ascending
 *   5. avg_duration_ms   ascending
 *   6. atlas_native      preferred on a full tie
 *   7. provider name     ascending (alphabetical)
 *
 * Cost/duration are pure TIE-BREAKERS: they never outrank success/give_back/evidence safety, so a
 * cheaper-but-flakier or cheaper-but-evidence-incomplete provider can never beat a safer one.
 *
 * The tie reason exposed in the response is the FIRST tier where the winner overtook the runners-up.
 */
final class AtlasMaestroProviderRecommendationEngine
{
    public const DEFAULT_MIN_SAMPLE_SIZE = 5;

    /** 0 = staleness check disabled (safe default for historical data with old timestamps). */
    public const DEFAULT_FRESHNESS_WINDOW_SECONDS = 0;

    /** @var callable():int|null */
    private $clock;

    public function __construct(
        private readonly AtlasMaestroProviderPerformanceLedger $ledger,
        private readonly int $minSampleSize = self::DEFAULT_MIN_SAMPLE_SIZE,
        private readonly int $freshnessWindowSeconds = self::DEFAULT_FRESHNESS_WINDOW_SECONDS,
        ?callable $clock = null,
    ) {
        $this->clock = $clock;
    }

    /**
     * @return array<string,mixed>
     */
    public function bestProviderFor(string $taskClass): array
    {
        $facts = $this->ledger->factsForClass($taskClass);
        $now = $this->clock !== null ? (int) ($this->clock)() : time();

        $rows = [];
        $insufficient = [];
        $staleProviders = [];
        foreach ($facts as $provider => $row) {
            $total = (int) ($row['success_count'] ?? 0) + (int) ($row['give_back_count'] ?? 0);
            if ($total < $this->minSampleSize) {
                $insufficient[(string) $provider] = $total;

                continue;
            }
            if ($this->freshnessWindowSeconds > 0) {
                $lastAt = (int) ($row['last_outcome_at'] ?? 0);
                if ($lastAt > 0 && ($now - $lastAt) > $this->freshnessWindowSeconds) {
                    $staleProviders[] = (string) $provider;

                    continue;
                }
            }
            $successCount = (int) ($row['success_count'] ?? 0);
            $evidenceCount = (int) ($row['has_required_evidence_count'] ?? 0);
            $evidenceRate = $successCount > 0 ? $evidenceCount / $successCount : 1.0;
            $costCount = (int) ($row['duration_ms_count'] ?? 0);
            $avgTokenCost = $costCount > 0 ? ((int) ($row['token_cost_sum'] ?? 0)) / $costCount : 0.0;

            $rows[] = [
                'provider' => (string) $provider,
                'success_rate' => $total > 0 ? $successCount / $total : 0.0,
                'give_back_rate' => $total > 0 ? ((int) $row['give_back_count']) / $total : 0.0,
                'sample_size' => $total,
                'avg_duration_ms' => (int) ($row['avg_duration_ms'] ?? 0),
                'evidence_rate' => $evidenceRate,
                'evidence_complete' => $evidenceRate >= 1.0,
                'avg_token_cost' => $avgTokenCost,
            ];
        }

        if ($rows === []) {
            if ($staleProviders !== [] && $insufficient === []) {
                return [
                    'status' => 'stale_data',
                    'task_class' => $taskClass,
                    'stale_providers' => $staleProviders,
                    'freshness_window_seconds' => $this->freshnessWindowSeconds,
                ];
            }

            return [
                'status' => 'insufficient_data',
                'task_class' => $taskClass,
                'samples_seen' => $insufficient,
                'min_required' => $this->minSampleSize,
                'providers_needing_samples' => array_keys($insufficient),
                'stale_providers' => $staleProviders,
            ];
        }

        usort($rows, function (array $a, array $b): int {
            $c = $b['success_rate'] <=> $a['success_rate'];
            if ($c !== 0) {
                return $c;
            }
            $c = $a['give_back_rate'] <=> $b['give_back_rate'];
            if ($c !== 0) {
                return $c;
            }
            // evidence_complete: true (1) beats false (0) — descending.
            $c = ((int) $b['evidence_complete']) <=> ((int) $a['evidence_complete']);
            if ($c !== 0) {
                return $c;
            }
            $c = $a['avg_token_cost'] <=> $b['avg_token_cost'];
            if ($c !== 0) {
                return $c;
            }
            $c = $a['avg_duration_ms'] <=> $b['avg_duration_ms'];
            if ($c !== 0) {
                return $c;
            }
            // atlas_native wins before alphabetical on a full tie
            $aNative = $a['provider'] === 'atlas_native' ? 0 : 1;
            $bNative = $b['provider'] === 'atlas_native' ? 0 : 1;
            if ($aNative !== $bNative) {
                return $aNative <=> $bNative;
            }

            return strcmp($a['provider'], $b['provider']);
        });
        $winner = $rows[0];

        $tiedWith = [];
        $tieReason = 'none';
        foreach (array_slice($rows, 1) as $other) {
            if (! $this->approximatelyEqual($winner['success_rate'], $other['success_rate'])) {
                break;
            }
            $tiedWith[] = (string) $other['provider'];
            $reason = $this->tieBreakerReason($winner, $other);
            if ($tieReason === 'none') {
                $tieReason = $reason;
            }
        }

        return [
            'status' => 'ok',
            'task_class' => $taskClass,
            'provider' => (string) $winner['provider'],
            'success_rate' => $winner['success_rate'],
            'give_back_rate' => $winner['give_back_rate'],
            'sample_size' => $winner['sample_size'],
            'avg_duration_ms' => $winner['avg_duration_ms'],
            'evidence_rate' => $winner['evidence_rate'],
            'evidence_complete' => $winner['evidence_complete'],
            'avg_token_cost' => $winner['avg_token_cost'],
            'tied_with' => $tiedWith,
            'tie_reason' => $tieReason,
        ];
    }

    private function approximatelyEqual(float $a, float $b): bool
    {
        return abs($a - $b) < 1e-9;
    }

    /**
     * @param  array<string,mixed>  $winner
     * @param  array<string,mixed>  $other
     */
    private function tieBreakerReason(array $winner, array $other): string
    {
        if ($winner['give_back_rate'] !== $other['give_back_rate']) {
            return 'give_back_rate';
        }
        if ($winner['evidence_complete'] !== $other['evidence_complete']) {
            return 'evidence_complete';
        }
        if ($winner['avg_token_cost'] !== $other['avg_token_cost']) {
            return 'avg_token_cost';
        }
        if ($winner['avg_duration_ms'] !== $other['avg_duration_ms']) {
            return 'avg_duration_ms';
        }
        if ($winner['provider'] === 'atlas_native' && $other['provider'] !== 'atlas_native') {
            return 'atlas_native_preferred';
        }

        return 'provider_name';
    }
}
