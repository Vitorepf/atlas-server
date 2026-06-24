<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex;

use Closure;
use Throwable;

/**
 * CORTEX INTENT STALENESS — detects WHEN a stored TriangulatedIntentFact has drifted from the current tree, so
 * the cortex never serves a confidently-wrong intent. An item is stale when the path's current commit_count
 * exceeds its last_extract_commit_count by >= the threshold, OR when HEAD has advanced AND the file appears in
 * `git log <last_head>..HEAD -- <path>` (a real change since extraction, even if the count delta is small).
 *
 * PURE READ: it never mutates the snapshot. The git probes are injected closures (real git by default), so the
 * detector is deterministically testable.
 */
final class AtlasCortexIntentStaleness
{
    /**
     * @param  Closure(string):int|null  $commitCountFor    fn(path) → current commit count for the path
     * @param  Closure():string|null  $headShaProbe         fn() → current HEAD sha
     * @param  Closure(string,string,string):bool|null  $changedInRange  fn(lastSha, headSha, path) → path changed in range
     */
    public function __construct(
        private readonly ?Closure $commitCountFor = null,
        private readonly ?Closure $headShaProbe = null,
        private readonly ?Closure $changedInRange = null,
        private readonly int $threshold = 1,
    ) {
    }

    /**
     * @param  list<array{path?:string, fqcn?:string, last_extract_commit_count?:int, last_extract_head_sha?:string}>  $items
     */
    public function detect(array $items): StalenessReport
    {
        $head = $this->head();
        $stale = [];
        $fresh = 0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $path = (string) ($item['path'] ?? '');
            $lastCount = (int) ($item['last_extract_commit_count'] ?? 0);
            $lastSha = (string) ($item['last_extract_head_sha'] ?? '');
            $currentCount = $this->commitCount($path);

            $reasons = [];
            if (($currentCount - $lastCount) >= $this->threshold) {
                $reasons[] = 'commit_count_diverged';
            }
            if ($head !== '' && $lastSha !== '' && $head !== $lastSha && $this->changed($lastSha, $head, $path)) {
                $reasons[] = 'head_advanced_file_changed';
            }

            if ($reasons !== []) {
                $stale[] = [
                    'path' => $path,
                    'fqcn' => (string) ($item['fqcn'] ?? ''),
                    'current_commit_count' => $currentCount,
                    'last_extract_commit_count' => $lastCount,
                    'reasons' => $reasons,
                ];
            } else {
                $fresh++;
            }
        }

        return new StalenessReport($stale, $fresh, $this->threshold);
    }

    private function commitCount(string $path): int
    {
        if ($this->commitCountFor !== null) {
            return max(0, (int) ($this->commitCountFor)($path));
        }
        try {
            $out = shell_exec('git rev-list --count HEAD -- '.escapeshellarg($path).' 2>/dev/null');

            return is_string($out) ? max(0, (int) trim($out)) : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    private function head(): string
    {
        if ($this->headShaProbe !== null) {
            return (string) ($this->headShaProbe)();
        }
        try {
            $out = shell_exec('git rev-parse HEAD 2>/dev/null');

            return is_string($out) ? trim($out) : '';
        } catch (Throwable) {
            return '';
        }
    }

    private function changed(string $lastSha, string $headSha, string $path): bool
    {
        if ($this->changedInRange !== null) {
            return (bool) ($this->changedInRange)($lastSha, $headSha, $path);
        }
        try {
            $out = shell_exec('git log '.escapeshellarg($lastSha.'..'.$headSha).' --oneline -- '.escapeshellarg($path).' 2>/dev/null');

            return is_string($out) && trim($out) !== '';
        } catch (Throwable) {
            return false;
        }
    }
}
