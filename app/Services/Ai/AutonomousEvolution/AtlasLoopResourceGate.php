<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * CRITIC GUARD — disk-budget gate + orphan reaper for the cp -R scenario workspaces.
 *
 * {@see AtlasEvolutionScenarioExplorer::prepareScenarioWorkspace()} does a full copy
 * per scenario with no free-space precheck, and a crash/kill between mkdir and the
 * finally{} rm -rf orphans a whole copy in /tmp. Over a 24h campaign that fills the
 * disk, after which cp/git/php fail silently and the loop rots. This gate is the
 * backpressure (refuse a new scenario below a free-MB floor or above a live-workspace
 * cap, never crash) and the boot/periodic reaper (rm -rf leaseless loop workspaces
 * older than a TTL — the crash path the happy-path finally{} cannot catch).
 */
final class AtlasLoopResourceGate
{
    /**
     * Temp-dir prefixes the loop creates: scenario copies, materialized task workspaces,
     * generators, the unified dispatcher's per-finding task dirs (atlas-loop-p3-/-docstruct-),
     * and the orchestrator's diff-reconstruction dirs (atlas-apply-). All must be reapable, or a
     * crash on the unified 24h path leaks them (the campaign-only list missed the last three).
     */
    private const PREFIXES = [
        'atlas-loop-scn-', 'atlas-loop-task-', 'atlas-loop-gen-', 'atlas-loop-fixture-', 'atlas-loop-fw-',
        'atlas-loop-p3-', 'atlas-loop-docstruct-', 'atlas-apply-',
    ];

    /**
     * @return array{admit:bool, reason:string, free_mb:int, live:int}
     */
    public function admitScenario(string $tmpRoot, int $minFreeMb, int $maxLiveWorkspaces): array
    {
        $freeBytes = @disk_free_space($tmpRoot);
        $freeMb = $freeBytes === false ? PHP_INT_MAX : (int) floor($freeBytes / (1024 * 1024));
        $live = $this->countLiveWorkspaces($tmpRoot);

        if ($freeMb < max(0, $minFreeMb)) {
            return ['admit' => false, 'reason' => 'disk_floor', 'free_mb' => $freeMb, 'live' => $live];
        }
        if ($maxLiveWorkspaces > 0 && $live >= $maxLiveWorkspaces) {
            return ['admit' => false, 'reason' => 'workspace_cap', 'free_mb' => $freeMb, 'live' => $live];
        }

        return ['admit' => true, 'reason' => 'ok', 'free_mb' => $freeMb, 'live' => $live];
    }

    /**
     * RESPAWN disk-floor gate — the keepalive must NOT resurrect a dead supervisor onto a near-full disk: a
     * campaign booted below the floor admits ZERO scenarios in a tight busy-fail loop, burning CPU + log lines
     * until a reap frees space. Unlike {@see admitScenario} this is a SUPERVISOR-level check, so it carries NO
     * live-workspace count — we are deciding only whether there is room to bring the supervisor BACK at all.
     *
     * Pure + side-effect-free: the SAME @disk_free_space probe + MB floor as admitScenario, no DB, no
     * filesystem mutation. A probe failure (false) ⇒ free_mb=PHP_INT_MAX ⇒ admit (fail-open, mirrors
     * admitScenario — never wedge the loop on an unreadable disk stat).
     *
     * @return array{admit:bool, reason:string, free_mb:int}
     */
    public function admitRespawn(string $tmpRoot, int $minFreeMb): array
    {
        $freeBytes = @disk_free_space($tmpRoot);
        $freeMb = $freeBytes === false ? PHP_INT_MAX : (int) floor($freeBytes / (1024 * 1024));

        if ($freeMb < max(0, $minFreeMb)) {
            return ['admit' => false, 'reason' => 'disk_floor', 'free_mb' => $freeMb];
        }

        return ['admit' => true, 'reason' => 'ok', 'free_mb' => $freeMb];
    }

    /**
     * GAP-2 (24h endurance) — reap LEAKED code-symbol rows from dead loop SCENARIO workspaces. sweepOrphans()
     * rm -rf's the workspace DIRS, but a SIGKILL'd materialization leaves its indexed
     * atlas_engineering_code_symbols rows behind FOREVER (the ~11.9M-row session-bootstrap OOM that 97% of
     * the table was). Every `atlas-loop-scn-*` workspace_id is unique + ephemeral, so any such row older than
     * the safety window is pure leak — the real `atlas` / `atlas-server` workspaces never match the prefix, and
     * a row with NULL created_at is spared (can't prove its age). Batched (no giant lock), fail-OPEN (missing
     * table/column/any error => 0, never fatal). Returns the deleted row count.
     */
    public function reapLeakedCodeSymbols(int $olderThanSeconds = 7200, int $batch = 20000, int $maxBatches = 5000): int
    {
        if (! DatabaseTableAvailability::has('atlas_engineering_code_symbols')
            || ! DatabaseTableAvailability::hasColumn('atlas_engineering_code_symbols', 'workspace_id')) {
            return 0;
        }
        try {
            $cutoff = now()->subSeconds(max(0, $olderThanSeconds))->toDateTimeString();
            $deletedTotal = 0;
            for ($i = 0; $i < max(1, $maxBatches); $i++) {
                $deleted = (int) DB::table('atlas_engineering_code_symbols')
                    ->whereIn('id', function ($q) use ($cutoff, $batch): void {
                        $q->select('id')
                            ->from('atlas_engineering_code_symbols')
                            ->where('workspace_id', 'like', 'atlas-loop-scn-%')
                            ->where('created_at', '<', $cutoff)
                            ->limit(max(1, $batch));
                    })
                    ->delete();
                $deletedTotal += $deleted;
                if ($deleted === 0) {
                    break;
                }
            }

            return $deletedTotal;
        } catch (Throwable) {
            return 0;
        }
    }

    /** rm -rf every loop workspace older than the TTL — reaps the crash-orphaned copies. */
    public function sweepOrphans(string $tmpRoot, int $olderThanSeconds): int
    {
        $cutoff = time() - max(0, $olderThanSeconds);
        $reaped = 0;
        foreach ($this->loopWorkspaceDirs($tmpRoot) as $dir) {
            $mtime = @filemtime($dir);
            if ($mtime !== false && $mtime <= $cutoff) {
                $this->rmrf($dir);
                $reaped++;
            }
        }

        return $reaped;
    }

    public function reapWorker(string $workspaceRoot): int
    {
        if ($workspaceRoot === '' || ! is_dir($workspaceRoot)) {
            return 0;
        }
        $this->rmrf($workspaceRoot);

        return 1;
    }

    public function countLiveWorkspaces(string $tmpRoot): int
    {
        return count($this->loopWorkspaceDirs($tmpRoot));
    }

    /**
     * @return list<string>
     */
    private function loopWorkspaceDirs(string $tmpRoot): array
    {
        $dirs = [];
        foreach (self::PREFIXES as $prefix) {
            foreach (glob(rtrim($tmpRoot, '/').'/'.$prefix.'*', GLOB_ONLYDIR) ?: [] as $dir) {
                $dirs[] = $dir;
            }
        }

        return $dirs;
    }

    private function rmrf(string $dir): void
    {
        try {
            (new Process(['rm', '-rf', $dir]))->setTimeout(30.0)->run();
        } catch (Throwable) {
            // best effort
        }
    }
}
