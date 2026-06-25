<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use Closure;
use Throwable;

/**
 * Cadence service — manages the recompute + invalidation lifecycle of the
 * Cortex scope-comprehension snapshot. Daily-scheduled rebuild + outcome-triggered
 * invalidation on give_back/failed task reports.
 *
 * Snapshot at storage_path('app/atlas/loop/comprehension/snapshot.json').
 * Failures logged to storage_path('logs/cortex-comprehension-build.log') —
 * fail-LOUD, never silently green.
 */
final class AtlasLoopComprehensionCadenceService
{
    public const SCHEMA = 'atlas.loop.cortex_cadence.v1';

    public const STALE_AFTER_SECONDS = 86400;

    /** @var Closure():string */
    private Closure $now;

    public function __construct(
        private readonly ?string $snapshotRoot = null,
        private readonly ?string $logPath = null,
        ?callable $nowIso = null,
    ) {
        $this->now = Closure::fromCallable($nowIso ?? static fn (): string => gmdate('Y-m-d\TH:i:s\Z'));
    }

    public function snapshotPath(): string
    {
        return rtrim($this->snapshotRoot ?? storage_path('app/atlas/loop/comprehension'), '/').'/snapshot.json';
    }

    public function logPath(): string
    {
        return $this->logPath ?? storage_path('logs/cortex-comprehension-build.log');
    }

    public function isStale(): bool
    {
        $path = $this->snapshotPath();
        if (! is_file($path)) {
            return true;
        }
        $age = time() - (int) @filemtime($path);

        return $age >= self::STALE_AFTER_SECONDS;
    }

    /** @return array{ok:bool, snapshot_path:string, stale_before:bool, error?:string} */
    public function rebuild(): array
    {
        $staleBefore = $this->isStale();
        $path = $this->snapshotPath();
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            return $this->logFailure('mkdir_failed:'.$dir, $path, $staleBefore);
        }
        try {
            $payload = [
                'schema' => 'atlas.cortex.scope_comprehension.v1',
                'built_at' => ($this->now)(),
                'scope_root' => base_path(),
                'inventory' => [
                    'php_files' => $this->countPhpFiles(base_path('app')),
                ],
            ];
            $bytes = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $tmp = $path.'.tmp.'.bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, $bytes) === false) {
                return $this->logFailure('write_failed:'.$tmp, $path, $staleBefore);
            }
            if (! @rename($tmp, $path)) {
                @unlink($tmp);

                return $this->logFailure('rename_failed:'.$path, $path, $staleBefore);
            }
        } catch (Throwable $e) {
            return $this->logFailure('build_threw:'.$e::class.':'.$e->getMessage(), $path, $staleBefore);
        }

        return [
            'ok' => true,
            'snapshot_path' => $path,
            'stale_before' => $staleBefore,
        ];
    }

    /** @return array{ok:bool, snapshot_path:string, reason:string} */
    public function invalidate(string $reason): array
    {
        $path = $this->snapshotPath();
        $existed = is_file($path);
        if ($existed) {
            @unlink($path);
        }
        $this->writeLog(sprintf('[%s] invalidate reason=%s existed=%s', ($this->now)(), $reason, $existed ? '1' : '0'));

        return ['ok' => true, 'snapshot_path' => $path, 'reason' => $reason];
    }

    /** @return array{ok:false, snapshot_path:string, stale_before:bool, error:string} */
    private function logFailure(string $error, string $path, bool $staleBefore): array
    {
        $this->writeLog(sprintf('[%s] build_failure error=%s path=%s', ($this->now)(), $error, $path));

        return [
            'ok' => false,
            'snapshot_path' => $path,
            'stale_before' => $staleBefore,
            'error' => $error,
        ];
    }

    private function writeLog(string $line): void
    {
        $log = $this->logPath();
        $dir = dirname($log);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
        @file_put_contents($log, $line."\n", FILE_APPEND);
    }

    private function countPhpFiles(string $root): int
    {
        if (! is_dir($root)) {
            return 0;
        }
        $count = 0;
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                $count++;
            }
        }

        return $count;
    }
}
