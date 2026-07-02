<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Support;

use Symfony\Component\Process\Process;

/**
 * Stable ORIGIN identity of a workspace, for the Dev memory systems
 * (green-run exemplars, M5 failure capsules).
 *
 * The envelope's workspace/workspaceHash identify the CHECKOUT PATH — which
 * in the sandboxed flows is a per-run temp dir, so no two runs ever share it
 * (audited on the real store: 73 green receipts carried 73 distinct
 * workspace hashes; every strict-equality memory lookup was mathematically
 * empty). What the memories need is the identity of the REPO those checkouts
 * came from: the git remote origin URL when one exists, otherwise the
 * workspace path itself (already realpath-resolved by IntakeNormalizer).
 *
 * The slug strips URL userinfo (https://user:token@host/... credentials must
 * never reach a DB row or receipt). hash() is the provider-safe projection —
 * receipts persist only the hash, never the slug.
 */
final class WorkspaceOriginIdentity
{
    /** @var array<string,string> */
    private static array $cache = [];

    public static function slug(string $workspace): string
    {
        $workspace = trim($workspace);
        if ($workspace === '') {
            return '';
        }
        if (isset(self::$cache[$workspace])) {
            return self::$cache[$workspace];
        }

        $slug = null;
        if (is_dir($workspace)) {
            try {
                $process = new Process(['git', '-C', $workspace, 'config', '--get', 'remote.origin.url']);
                $process->run();
                $url = trim($process->getOutput());
                if ($process->isSuccessful() && $url !== '') {
                    // Strip userinfo — remote URLs can embed credentials.
                    $slug = preg_replace('#://[^/@]+@#', '://', $url) ?? $url;
                }
            } catch (\Throwable) {
                // fail-open to the path identity below
            }
        }

        return self::$cache[$workspace] = $slug ?? $workspace;
    }

    public static function hash(string $workspace): string
    {
        $slug = self::slug($workspace);

        return $slug === '' ? '' : hash('sha256', $slug);
    }
}
