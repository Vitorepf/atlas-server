<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use Closure;

final class AtlasLoopComprehensionStalenessDetector
{
    private const SNAPSHOT_PATTERN = '/^snapshot-\d{14}-[a-f0-9]{12}\.json$/i';

    /**
     * @param  null|Closure():int  $clock
     */
    public function __construct(
        private readonly int $thresholdSeconds = 1800,
        private readonly ?Closure $clock = null,
    ) {
    }

    /**
     * @return array{is_stale:bool,snapshot_age_seconds:?int,threshold_seconds:int,reason:'fresh'|'no_snapshot'|'older_than_threshold'}
     */
    public function detect(string $path): array
    {
        $snapshot = $this->snapshotPath($path);
        if ($snapshot === null) {
            return [
                'is_stale' => true,
                'snapshot_age_seconds' => null,
                'threshold_seconds' => $this->threshold(),
                'reason' => 'no_snapshot',
            ];
        }

        $mtime = filemtime($snapshot);
        if ($mtime === false) {
            return [
                'is_stale' => true,
                'snapshot_age_seconds' => null,
                'threshold_seconds' => $this->threshold(),
                'reason' => 'no_snapshot',
            ];
        }

        $age = max(0, $this->now() - $mtime);
        $stale = $age > $this->threshold();

        return [
            'is_stale' => $stale,
            'snapshot_age_seconds' => $age,
            'threshold_seconds' => $this->threshold(),
            'reason' => $stale ? 'older_than_threshold' : 'fresh',
        ];
    }

    private function threshold(): int
    {
        return max(1, $this->thresholdSeconds);
    }

    private function now(): int
    {
        return $this->clock instanceof Closure ? (int) ($this->clock)() : time();
    }

    private function snapshotPath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || ! file_exists($path)) {
            return null;
        }
        if (is_file($path)) {
            return $path;
        }
        if (! is_dir($path)) {
            return null;
        }

        $latest = null;
        $latestMtime = null;
        foreach ((array) scandir($path) as $entry) {
            if (! is_string($entry) || preg_match(self::SNAPSHOT_PATTERN, $entry) !== 1) {
                continue;
            }
            $candidate = rtrim($path, '/').'/'.$entry;
            if (! is_file($candidate)) {
                continue;
            }
            $mtime = filemtime($candidate);
            if ($mtime === false) {
                continue;
            }
            if ($latestMtime === null || $mtime > $latestMtime) {
                $latest = $candidate;
                $latestMtime = $mtime;
            }
        }

        return $latest;
    }
}
