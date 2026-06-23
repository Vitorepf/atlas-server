<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * PART 1 · §1.3 — the LIVE source of the two FREE runtime facts, read from what the loop already persisted
 * (no `--coverage`, no new table, no write):
 *
 *   - hasGateBlock(file): the set of files a recent grind RESULT shows the mutation-adequacy gate blocked —
 *     derived by running {@see AtlasLoopCoverageGapDetector} over `atlas_loop_tasks.result`.
 *   - lastMergeClean(file): per file, whether its MOST RECENT `merged_to_main=true` proposal had a green
 *     canary — read from `atlas_loop_proposals` (the same row {@see AtlasLoopCapabilityTrendService} reads).
 *
 * Both loads are LAZY + memoized (one query each) and GUARDED exactly like the trend service: a DB-less or
 * degraded caller gets an empty map (=> every fact is the safe "no evidence" default), never an exception.
 */
final class AtlasLoopScopeRuntimeFacts implements ScopeRuntimeFacts
{
    /** @var array<string,bool>|null set of gate-blocked rel paths (lazy) */
    private ?array $gateBlocked = null;

    /** @var array<string,bool>|null rel path => last merge clean? (lazy) */
    private ?array $mergeClean = null;

    public function __construct(
        private readonly ?AtlasLoopCoverageGapDetector $detector = null,
        private readonly int $recentTaskLimit = 500,
        private readonly int $mergedProposalLimit = 5000,
    ) {
    }

    public function hasGateBlock(string $relPath): bool
    {
        $this->gateBlocked ??= $this->loadGateBlocked();

        return $this->gateBlocked[$this->norm($relPath)] ?? false;
    }

    public function lastMergeClean(string $relPath): bool
    {
        $this->mergeClean ??= $this->loadMergeClean();

        return $this->mergeClean[$this->norm($relPath)] ?? false;
    }

    /**
     * The set of files a recent grind result shows the mutation-adequacy gate blocked. Guarded + fail-open.
     *
     * @return array<string,bool>
     */
    private function loadGateBlocked(): array
    {
        if (! DatabaseTableAvailability::all(['atlas_loop_tasks'])) {
            return [];
        }
        $detector = $this->detector ?? new AtlasLoopCoverageGapDetector;

        try {
            $rows = DB::table('atlas_loop_tasks')
                ->whereNotNull('result')
                ->orderByDesc('updated_at')
                ->limit(max(1, $this->recentTaskLimit))
                ->get(['result']);
        } catch (Throwable) {
            return [];
        }

        $set = [];
        foreach ($rows as $row) {
            $result = json_decode((string) $row->result, true);
            if (! is_array($result)) {
                continue;
            }
            foreach ($detector->gapsFromTaskResult($result) as $gap) {
                $file = $this->norm((string) ($gap['target_file'] ?? ''));
                if ($file !== '') {
                    $set[$file] = true;
                }
            }
        }

        return $set;
    }

    /**
     * Per file, whether its MOST RECENT merged-to-main proposal had a green canary. Ascending updated_at so a
     * later merge overwrites an earlier one (the "last" merge wins). Guarded + fail-open.
     *
     * @return array<string,bool>
     */
    private function loadMergeClean(): array
    {
        if (! DatabaseTableAvailability::all(['atlas_loop_proposals'])) {
            return [];
        }

        try {
            $rows = DB::table('atlas_loop_proposals')
                ->where('merged_to_main', true)
                ->whereNotNull('target_path')
                ->orderBy('updated_at')
                ->limit(max(1, $this->mergedProposalLimit))
                ->get(['target_path', 'quality']);
        } catch (Throwable) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $rel = $this->norm((string) $row->target_path);
            if ($rel === '') {
                continue;
            }
            $quality = json_decode((string) $row->quality, true);
            $map[$rel] = $this->canaryGreen(is_array($quality) ? $quality : []);
        }

        return $map;
    }

    /**
     * Green iff the canary ran AND passed — the SAME definition {@see AtlasLoopCapabilityTrendService::canary}
     * uses (strict true, never a truthy 1).
     *
     * @param  array<string,mixed>  $quality
     */
    private function canaryGreen(array $quality): bool
    {
        $c = $quality['_canary'] ?? null;

        return is_array($c) && ($c['ran'] ?? false) === true && ($c['passed'] ?? false) === true;
    }

    private function norm(string $relPath): string
    {
        return ltrim(str_replace('\\', '/', trim($relPath)), '/');
    }
}
