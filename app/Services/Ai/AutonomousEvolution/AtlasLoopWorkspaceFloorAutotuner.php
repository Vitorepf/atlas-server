<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Closure;
use Throwable;

/**
 * WORKSPACE-FLOOR AUTOTUNER — emits an ADVISORY recommended workspace-disk floor (MB) from observed FACTS:
 * current free disk under base_path(), the p95 workspace footprint over recent campaigns, and the number of
 * concurrent running campaigns. ADVISORY-ONLY: it NEVER deletes a workspace, NEVER changes config, and causes
 * no behaviour change — it only writes a snapshot the operator (or a gated arm) reads to decide.
 *
 * Flag atlas.loop.workspace_floor_autotuner_enabled default OFF ⇒ writeAdvisorySnapshot() is a byte-identical
 * no-op. recommend() is PURE (the disk-stat is an injected closure for deterministic tests).
 */
final class AtlasLoopWorkspaceFloorAutotuner
{
    public const SCHEMA = 'atlas.loop.workspace_floor.v1';

    private const BYTES_PER_MB = 1048576;

    /**
     * @param  Closure():int|null  $diskFreeBytes  free bytes under base_path(); default = disk_free_space(base_path())
     */
    public function __construct(
        private readonly ?Closure $diskFreeBytes = null,
        private readonly ?string $snapshotDir = null,
    ) {}

    /**
     * Pure recommendation from facts. $sample carries {footprint_p95_mb, concurrent_campaigns, history_count}.
     *
     * @param  array<string,mixed>  $sample
     * @return array{schema:string, current_floor_mb:int, recommended_floor_mb:int, free_mb:int, headroom_mb:int, reason:string}
     */
    public function recommend(int $currentFloorMb, array $sample): array
    {
        $freeMb = (int) floor($this->freeBytes() / self::BYTES_PER_MB);
        $footprintP95 = $sample['footprint_p95_mb'] ?? null;
        $historyCount = (int) ($sample['history_count'] ?? (is_numeric($footprintP95) ? 1 : 0));
        $concurrent = max(1, (int) ($sample['concurrent_campaigns'] ?? 1));

        if (! is_numeric($footprintP95) || $historyCount < 1) {
            return $this->snapshot($currentFloorMb, max(1, $currentFloorMb), $freeMb, 'no_history');
        }

        $recommended = max(1, (int) ceil(((float) $footprintP95) * $concurrent));

        if ($freeMb < $recommended) {
            $reason = 'near_starvation';
        } elseif ($freeMb < 2 * $recommended) {
            $reason = 'tight_headroom';
        } else {
            $reason = 'ample_headroom';
        }

        return $this->snapshot($currentFloorMb, $recommended, $freeMb, $reason);
    }

    /**
     * ADVISORY write of the recommendation snapshot — flag-gated (OFF ⇒ null, no write). Writes ONLY the
     * autotune JSON; never touches a workspace, never changes config.
     *
     * @param  array<string,mixed>  $sample
     * @return array<string,mixed>|null
     */
    public function writeAdvisorySnapshot(int $currentFloorMb, array $sample): ?array
    {
        if (! (bool) config('atlas.loop.workspace_floor_autotuner_enabled', false)) {
            return null; // byte-identical no-op
        }

        $snapshot = $this->recommend($currentFloorMb, $sample);

        $dir = $this->snapshotDir ?? storage_path('app/atlas/loop/workspace-autotune');
        try {
            if (! is_dir($dir) && ! @mkdir($dir, 0o775, true) && ! is_dir($dir)) {
                return $snapshot;
            }
            @file_put_contents($dir.'/latest.json', json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
        } catch (Throwable) {
            // advisory: a failed write never affects the loop.
        }

        return $snapshot;
    }

    /**
     * @return array{schema:string, current_floor_mb:int, recommended_floor_mb:int, free_mb:int, headroom_mb:int, reason:string}
     */
    private function snapshot(int $current, int $recommended, int $freeMb, string $reason): array
    {
        return [
            'schema' => self::SCHEMA,
            'current_floor_mb' => $current,
            'recommended_floor_mb' => $recommended,
            'free_mb' => $freeMb,
            'headroom_mb' => $freeMb - $recommended,
            'reason' => $reason,
        ];
    }

    private function freeBytes(): int
    {
        if ($this->diskFreeBytes !== null) {
            return max(0, (int) ($this->diskFreeBytes)());
        }
        $free = @disk_free_space(base_path());

        return is_numeric($free) ? max(0, (int) $free) : 0;
    }
}
