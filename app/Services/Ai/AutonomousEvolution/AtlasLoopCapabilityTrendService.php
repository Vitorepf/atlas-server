<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ACDE lever D1 — the per-DELIVERY capability-trend instrument (the only thing that answers "is
 * capability(t) actually bending upward?").
 *
 * Rivals + the head-to-head are dead; quality is proven PER DELIVERY. This is the AGGREGATE view of that:
 * the loop's OWN clean-delivery-rate (a delivery is clean iff committed + canary-not-red — the same
 * {@see AtlasLoopDeliveryDimensionResolver} D2 definition, never a self-report) over rolling time buckets,
 * with the Wilson lower bound per bucket and the SLOPE across buckets. It is a SELF-trend — never
 * engine-vs-engine — so it can never reintroduce the forbidden Rivals comparison.
 *
 * It READS what the merge already persisted (atlas_loop_proposals.merged_to_main + quality); there is NO new
 * table and NO write on the merge critical path. Flag-gated default-OFF => an empty/inert trend =>
 * byte-identical. Every DB touch is guarded (a DB-less caller degrades to an empty trend, never throws).
 */
final class AtlasLoopCapabilityTrendService
{
    public function __construct(private readonly ?AtlasLoopDeliveryDimensionResolver $resolver = null) {}

    /**
     * @return array{enabled:bool, buckets:list<array{index:int, total:int, clean:int, rate:float, wilson_lower:float}>, slope:float, bending:bool, samples:int}
     */
    public function trend(int $windowHours = 24, int $bucketCount = 7): array
    {
        $empty = ['enabled' => false, 'buckets' => [], 'slope' => 0.0, 'bending' => false, 'samples' => 0];
        if (! (bool) config('atlas.loop.capability_trend_enabled', false)) {
            return $empty;
        }
        if (! DatabaseTableAvailability::all(['atlas_loop_proposals'])) {
            return array_merge($empty, ['enabled' => true]);
        }
        $windowHours = max(1, $windowHours);
        $bucketCount = max(2, $bucketCount);
        $resolver = $this->resolver ?? new AtlasLoopDeliveryDimensionResolver;

        try {
            $rows = DB::table('atlas_loop_proposals')
                ->where('merged_to_main', true)
                ->where('updated_at', '>=', Carbon::now()->subHours($windowHours * $bucketCount))
                ->limit(5000)
                ->get(['quality', 'updated_at']);
        } catch (Throwable) {
            return array_merge($empty, ['enabled' => true]);
        }

        // bucket index ascending = OLDEST..NEWEST window, so a rising rate is a positive slope.
        $buckets = array_fill(0, $bucketCount, ['total' => 0, 'clean' => 0]);
        $now = Carbon::now();
        $samples = 0;
        foreach ($rows as $row) {
            $quality = json_decode((string) $row->quality, true);
            $quality = is_array($quality) ? $quality : [];
            $dim = $resolver->resolve(['attempted' => true, 'committed' => true, 'canary' => $this->canary($quality)]);
            $ageHours = (int) $now->diffInHours(Carbon::parse((string) $row->updated_at));
            $idx = (int) min($bucketCount - 1, max(0, $bucketCount - 1 - intdiv($ageHours, $windowHours)));
            $buckets[$idx]['total']++;
            if ($dim['clean']) {
                $buckets[$idx]['clean']++;
            }
            $samples++;
        }

        $out = [];
        foreach ($buckets as $i => $b) {
            $rate = $b['total'] > 0 ? $b['clean'] / $b['total'] : 0.0;
            $out[] = [
                'index' => $i,
                'total' => (int) $b['total'],
                'clean' => (int) $b['clean'],
                'rate' => round($rate, 4),
                'wilson_lower' => round(self::wilsonLower((int) $b['clean'], (int) $b['total']), 4),
            ];
        }
        $slope = self::slope($out);

        return ['enabled' => true, 'buckets' => $out, 'slope' => round($slope, 4), 'bending' => $slope > 1e-9, 'samples' => $samples];
    }

    /** Resolve canary from the merged proposal's quality envelope (green iff _canary ran && passed). */
    private function canary(array $quality): string
    {
        $c = $quality['_canary'] ?? null;
        if (! is_array($c) || ($c['ran'] ?? false) !== true) {
            return 'not_run';
        }

        return ($c['passed'] ?? false) === true ? 'green' : 'red';
    }

    /** Wilson score-interval LOWER bound for k clean of n (z=1.96). Empty bucket => 0. */
    public static function wilsonLower(int $k, int $n, float $z = 1.96): float
    {
        if ($n <= 0) {
            return 0.0;
        }
        $phat = $k / $n;
        $z2 = $z * $z;
        $denom = 1.0 + $z2 / $n;
        $centre = ($phat + $z2 / (2 * $n)) / $denom;
        $margin = ($z * sqrt(($phat * (1 - $phat) + $z2 / (4 * $n)) / $n)) / $denom;

        return max(0.0, $centre - $margin);
    }

    /**
     * Least-squares slope of the clean-rate over bucket index, across NON-EMPTY buckets only (an empty
     * bucket is no evidence, not a zero). >0 => capability(t) bending upward. <2 non-empty buckets => 0.
     *
     * @param  list<array{index:int, total:int, rate:float}>  $buckets
     */
    public static function slope(array $buckets): float
    {
        $pts = array_values(array_filter($buckets, static fn (array $b): bool => (int) $b['total'] > 0));
        $n = count($pts);
        if ($n < 2) {
            return 0.0;
        }
        $sumX = 0.0;
        $sumY = 0.0;
        $sumXY = 0.0;
        $sumX2 = 0.0;
        foreach ($pts as $b) {
            $x = (float) $b['index'];
            $y = (float) $b['rate'];
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
        }
        $denom = ($n * $sumX2) - ($sumX * $sumX);
        if (abs($denom) < 1e-9) {
            return 0.0;
        }

        return (($n * $sumXY) - ($sumX * $sumY)) / $denom;
    }
}
