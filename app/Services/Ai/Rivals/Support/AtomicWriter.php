<?php

namespace App\Services\Ai\Rivals\Support;

use RuntimeException;

final class AtomicWriter
{
    public static function write(string $path, string $contents, int $mode = 0600): void
    {
        RunPaths::ensureDir(dirname($path));
        $temp = $path.'.tmp.'.bin2hex(random_bytes(8));
        $handle = fopen($temp, 'xb');
        if ($handle === false) {
            throw new RuntimeException('rivals_atomic_write_open_failed:'.$path);
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('rivals_atomic_write_lock_failed:'.$path);
            }
            $offset = 0;
            $length = strlen($contents);
            while ($offset < $length) {
                $written = fwrite($handle, substr($contents, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('rivals_atomic_write_failed:'.$path);
                }
                $offset += $written;
            }
            fflush($handle);
            if (function_exists('fsync')) {
                fsync($handle);
            }
            chmod($temp, $mode);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        if (! rename($temp, $path)) {
            @unlink($temp);
            throw new RuntimeException('rivals_atomic_rename_failed:'.$path);
        }
    }
}
