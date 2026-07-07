<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * P6 (Obra #19) — clone a directory with the APFS clonefile (`cp -Rc`) instead
 * of symlinking it.
 *
 * THE WIPER VECTOR: a worktree that SYMLINKS the live `vendor` lets
 * `composer dump-autoload` (run inside the worktree) follow the link and rewrite
 * the LIVE `vendor/autoload.php` — the incident that poisoned the live autoload.
 * A clonefile copy is copy-on-write (near-zero time/space on APFS) and fully
 * isolated: dump-autoload writes the worktree's OWN copy, never the live tree.
 *
 * ponytail: `cp -Rc` is macOS's clonefile flag; on a filesystem/OS without it the
 * `-c` attempt fails and we fall back to a plain `cp -R` (correctness over speed).
 * The one thing it must NEVER be is a symlink — that is the whole point.
 */
final class AtlasCloneDir
{
    /**
     * Clone $src to $dst as an isolated copy (APFS clonefile, else plain copy).
     * Returns true only when $dst exists afterwards and is NOT a symlink.
     */
    public static function copy(string $src, string $dst): bool
    {
        if (! is_dir($src) && ! is_file($src)) {
            return false;
        }

        foreach ([['cp', '-Rc', $src, $dst], ['cp', '-R', $src, $dst]] as $cmd) {
            $p = new Process($cmd);
            $p->setTimeout(180);
            try {
                $p->run();
            } catch (Throwable) {
                // fall through to the next strategy
            }
            if ($p->getExitCode() === 0 && file_exists($dst) && ! is_link($dst)) {
                return true;
            }
            // Clean a partial/failed copy before the fallback attempt.
            if (is_dir($dst) || is_file($dst)) {
                (new Process(['rm', '-rf', $dst]))->run();
            }
        }

        return false;
    }
}
