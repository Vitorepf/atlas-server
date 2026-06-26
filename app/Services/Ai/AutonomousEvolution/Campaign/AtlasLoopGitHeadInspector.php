<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Campaign;

use Closure;

/**
 * Git-head resolution and pipeline-drift detection for the Atlas loop
 * campaign supervisor.
 *
 * Extracted from AtlasLoopCampaignSupervisor to reduce the god-class.
 * Pure functions — resolvers are passed as parameters to preserve test seams.
 */
final class AtlasLoopGitHeadInspector
{
    /**
     * Resolve current git HEAD for the workspace. Returns null for empty
     * workspaces, missing directories, or when neither resolver nor git
     * can produce a valid head. Normalizes through normalizeGitHead().
     *
     * @param  Closure(string):string|null  $gitHeadResolver
     */
    public static function currentGitHead(?Closure $gitHeadResolver, string $workspace): ?string
    {
        if ($gitHeadResolver !== null) {
            return self::normalizeGitHead((string) $gitHeadResolver($workspace));
        }

        if ($workspace === '' || ! is_dir($workspace)) {
            return null;
        }

        $lines = [];
        $exitCode = 1;
        @exec('git -C '.escapeshellarg($workspace).' rev-parse HEAD 2>/dev/null', $lines, $exitCode);
        if ($exitCode !== 0) {
            return null;
        }

        return self::normalizeGitHead(implode("\n", $lines));
    }

    /**
     * Normalize a git HEAD string to a 40-char lowercase hex hash, or null
     * if the string does not match the canonical git sha format.
     */
    public static function normalizeGitHead(string $head): ?string
    {
        $head = trim($head);

        return preg_match('/\A[0-9a-f]{40}\z/i', $head) === 1 ? strtolower($head) : null;
    }

    /**
     * Engine files changed between two commits (bootHead..currentHead).
     * Empty list means the merge touched only target files → no restart needed.
     * Best-effort: any git error returns [] (degrades to "no pipeline drift",
     * the keepalive still catches actual process death).
     *
     * @param  Closure(string, string, string):array<int, string>|null  $changedFilesResolver
     * @return list<string>
     */
    public static function changedPipelineFiles(?Closure $changedFilesResolver, string $bootHead, string $currentHead, string $workspace): array
    {
        if ($workspace === '' || ! is_dir($workspace)) {
            return [];
        }
        if ($changedFilesResolver !== null) {
            $changed = $changedFilesResolver($bootHead, $currentHead, $workspace);
        } else {
            $lines = [];
            $exitCode = 1;
            @exec(
                'git -C '.escapeshellarg($workspace).' diff --name-only '
                .escapeshellarg($bootHead).' '.escapeshellarg($currentHead).' 2>/dev/null',
                $lines,
                $exitCode,
            );
            $changed = $exitCode === 0 ? $lines : [];
        }

        // Single source of truth (shared with the out-of-process keepalive
        // backstop) so the in-process and watchdog drift definitions can
        // never diverge.
        return AtlasLoopPipelineDrift::pipelineFiles($changed);
    }
}