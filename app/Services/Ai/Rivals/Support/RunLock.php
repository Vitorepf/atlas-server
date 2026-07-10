<?php

namespace App\Services\Ai\Rivals\Support;

use RuntimeException;

final class RunLock
{
    public static function exclusive(string $runId, callable $operation): mixed
    {
        RunPaths::ensureDir(RunPaths::runDir($runId));
        $path = RunPaths::runDir($runId).'/.run.lock';
        $handle = fopen($path, 'c+');
        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException("rivals_run_lock_failed:{$runId}");
        }

        try {
            ftruncate($handle, 0);
            fwrite($handle, json_encode([
                'pid' => getmypid(),
                'locked_at' => now()->toIso8601String(),
            ], JSON_UNESCAPED_SLASHES));
            fflush($handle);

            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
