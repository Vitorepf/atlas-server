<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Models\AtlasLoopTestCoverageEdge;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Costed test-presence oracle: a source path is "tested" iff the persistent test-coverage edge
 * ledger {@see AtlasLoopTestCoverageEdge} carries at least one observed edge from that source.
 *
 * Backward-compat contract: this oracle is the FIRST positive signal of test absence the
 * comprehension query has — but it MUST fail open (`true`) when the database is unavailable,
 * the table is missing, or the read fails. That preserves the safe degradation path (no
 * spurious `untested->tested` transition fires on infra failure).
 *
 * Lazy + request-memoized: one query loads the full distinct-source set on first call, every
 * subsequent call is an O(1) hash lookup. {@see reset()} clears the memo (use at refill
 * boundaries — same lifecycle pattern as the runtime-facts memoizers).
 */
final class AtlasLoopTestPresenceOracle
{
    /** @var array<string,true>|null  set of source paths observed in the edge ledger */
    private ?array $coveredSourceSet = null;

    /** Has the edge ledger observed at least one coverage edge from this source path? */
    public function hasTest(string $sourcePath): bool
    {
        $needle = $this->norm($sourcePath);
        if ($needle === '') {
            return true; // unknown path ⇒ fail-open
        }
        $set = $this->coveredSourceSet();
        if ($set === null) {
            return true; // DB unavailable / table absent / read failed ⇒ fail-open
        }

        return isset($set[$needle]);
    }

    public function reset(): void
    {
        $this->coveredSourceSet = null;
    }

    /**
     * @return array<string,true>|null  null ⇒ infra failure, fail-open at caller
     */
    private function coveredSourceSet(): ?array
    {
        if ($this->coveredSourceSet !== null) {
            return $this->coveredSourceSet;
        }
        try {
            if (! DatabaseTableAvailability::all([(new AtlasLoopTestCoverageEdge)->getTable()])) {
                return null;
            }
            $rows = DB::table((new AtlasLoopTestCoverageEdge)->getTable())
                ->select('source_path')
                ->distinct()
                ->get();
        } catch (Throwable) {
            return null;
        }

        $set = [];
        foreach ($rows as $row) {
            $key = $this->norm((string) $row->source_path);
            if ($key !== '') {
                $set[$key] = true;
            }
        }

        return $this->coveredSourceSet = $set;
    }

    private function norm(string $relPath): string
    {
        return ltrim(str_replace('\\', '/', trim($relPath)), '/');
    }
}
