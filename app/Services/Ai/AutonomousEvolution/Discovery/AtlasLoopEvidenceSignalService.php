<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * O-2 slice (b): turns the REAL failure corpus into a per-file evidence weight so the
 * Evolution Loop discovers what actually breaks — not just files with pretty structural
 * surface. A file referenced by recurring real failures (failure_signatures.context_summary
 * over a recent window) scores higher; files with zero failure evidence get 0.
 *
 * Pure-data + fail-open: any error / missing table / empty corpus returns an empty map,
 * so discovery degrades gracefully to structural scoring (never throws, never blocks the
 * supervisor). Bounded by a recency window and a row cap so a huge corpus can't stall a refill.
 */
final class AtlasLoopEvidenceSignalService
{
    /** Recency window (days) and the row cap that bound the corpus scan. */
    private const WINDOW_DAYS = 30;

    private const MAX_ROWS = 2000;

    /**
     * Build a map of repo-relative path => evidence weight in [0,1], normalized so the
     * most-failing file is 1.0 and the rest scale by recurrence. Matches a failure row to
     * a candidate path by exact path mention OR basename mention in context_summary.
     *
     * @param  list<string>  $candidatePaths  repo-relative paths discovery is scoring
     * @return array<string,float>
     */
    public function weights(array $candidatePaths): array
    {
        if ($candidatePaths === [] || ! DatabaseTableAvailability::has('failure_signatures')) {
            return [];
        }

        try {
            $summaries = DB::table('failure_signatures')
                ->where('recorded_at', '>=', now()->subDays(self::WINDOW_DAYS))
                ->orderByDesc('recorded_at')
                ->limit(self::MAX_ROWS)
                ->pluck('context_summary')
                ->all();
        } catch (Throwable) {
            return []; // fail-open: corpus unavailable -> pure structural scoring
        }

        if ($summaries === []) {
            return [];
        }

        $haystacks = array_map(static fn ($s): string => mb_strtolower((string) $s), $summaries);

        $counts = [];
        foreach ($candidatePaths as $path) {
            $needlePath = mb_strtolower($path);
            $base = mb_strtolower(basename($path));
            // Basename alone is too loose (e.g. Service.php); only count basename matches
            // for distinctive names (>= 6 chars before extension) to avoid false evidence.
            $distinctiveBase = mb_strlen(pathinfo($base, PATHINFO_FILENAME)) >= 6;
            $hits = 0;
            foreach ($haystacks as $hay) {
                $hits += max(
                    (int) str_contains($hay, $needlePath),
                    (int) $distinctiveBase * (int) str_contains($hay, $base),
                );
            }
            $counts[$path] = $hits;
        }

        $counts = array_filter($counts);

        if ($counts === []) {
            return [];
        }

        $max = max($counts);
        $weights = [];
        foreach ($counts as $path => $hits) {
            $weights[$path] = round($hits / $max, 4);
        }

        return $weights;
    }
}
