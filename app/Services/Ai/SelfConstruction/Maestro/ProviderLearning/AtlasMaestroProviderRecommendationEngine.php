<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ProviderLearning;

/**
 * ADVISORY-only recommendation over per-(provider, task_class) facts from
 * {@see AtlasMaestroProviderPerformanceLedger}. Never mutates Maestro routing, never invokes the
 * provider manager, never auto-applies.
 *
 * Ranking:
 *   1. success_rate    descending
 *   2. give_back_rate  ascending
 *   3. avg_duration_ms ascending
 *   4. provider name   ascending (alphabetical)
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
            $rows[] = [
                'provider' => (string) $provider,
                'success_rate' => $total > 0 ? ((int) $row['success_count']) / $total : 0.0,
                'give_back_rate' => $total > 0 ? ((int) $row['give_back_count']) / $total : 0.0,
                'sample_size' => $total,
                'avg_duration_ms' => (int) ($row['avg_duration_ms'] ?? 0),
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
        if ($winner['avg_duration_ms'] !== $other['avg_duration_ms']) {
            return 'avg_duration_ms';
        }

        return 'provider_name';
    }
}
